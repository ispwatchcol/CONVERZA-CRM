<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\Account;
use App\Models\Brain\AccountInvoice;
use App\Models\Brain\AccountPayment;
use App\Services\Brain\CobroAutomatico;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
    public function __construct(private CobroAutomatico $cobro) {}

    // ── Facturas ──────────────────────────────────────────────────────────────

    public function storeInvoice(Request $request, Account $account)
    {
        $validated = $request->validate([
            'account_product_id' => ['nullable', 'integer', Rule::exists('account_products', 'id')],
            'number'             => ['required', 'string', 'max:40', Rule::unique('account_invoices', 'number')],
            'concept'            => ['required', 'string', 'max:200'],
            'amount'             => ['required', 'numeric', 'min:0'],
            'tax'                => ['nullable', 'numeric', 'min:0'],
            'currency'           => ['required', 'string', 'size:3'],
            'issued_at'          => ['required', 'date'],
            'due_at'             => ['nullable', 'date'],
            'period_start'       => ['nullable', 'date'],
            'period_end'         => ['nullable', 'date'],
            'apply_credit'       => ['nullable', 'boolean'],
        ]);

        $amount = (float) $validated['amount'];
        $tax    = (float) ($validated['tax'] ?? 0);

        $invoice = AccountInvoice::create([
            ...collect($validated)->except('apply_credit')->all(),
            'tax'        => $tax,
            'total'      => $amount + $tax,
            'status'     => 'sent',
            'created_by' => Auth::id(),
            'account_id' => $account->id,
        ]);

        $credit = $validated['apply_credit'] ?? true
            ? $account->allocateCredit(Auth::id())
            : 0;

        return back()->with('success', $credit > 0
            ? 'Factura creada. Se aplicaron ' . number_format($credit, 2) . ' ' . $invoice->currency . ' de saldo a favor.'
            : 'Factura creada.');
    }

    public function updateInvoice(Request $request, Account $account, AccountInvoice $invoice)
    {
        abort_if($invoice->account_id !== $account->id, 404);

        $validated = $request->validate([
            'concept'   => ['required', 'string', 'max:200'],
            'amount'    => ['required', 'numeric', 'min:0'],
            'tax'       => ['nullable', 'numeric', 'min:0'],
            'currency'  => ['required', 'string', 'size:3'],
            'status'    => ['required', Rule::in(['draft', 'sent', 'paid', 'partial', 'overdue', 'void'])],
            'issued_at' => ['required', 'date'],
            'due_at'    => ['nullable', 'date'],
        ]);

        $amount = (float) $validated['amount'];
        $tax    = (float) ($validated['tax'] ?? 0);

        $invoice->update([
            ...$validated,
            'tax'   => $tax,
            'total' => $amount + $tax,
        ]);

        // Correrle la fecha o marcarla pagada a mano puede dejar al día a una
        // cuenta cortada por cobro.
        $this->cobro->reactivarSiQuedoAlDia($account->fresh());

        return back()->with('success', 'Factura actualizada.');
    }

    public function voidInvoice(Account $account, AccountInvoice $invoice)
    {
        abort_if($invoice->account_id !== $account->id, 404);

        // El saldo a favor que se le había aplicado vuelve a la cuenta: se borra la
        // fila de aplicación. Los pagos reales NO se tocan — esa plata entró igual y
        // al quedar la factura anulada se convierte otra vez en saldo a favor.
        $invoice->payments()->where('is_credit_application', true)->delete();
        $invoice->update(['status' => 'void']);

        // Lo que se había pagado sobre la anulada queda libre y cubre la siguiente
        // factura abierta; y si era la vencida, la cuenta puede quedar al día.
        $account->allocateCredit(Auth::id());
        $this->cobro->reactivarSiQuedoAlDia($account->fresh());

        return back()->with('success', 'Factura anulada.');
    }

    // ── Pagos ─────────────────────────────────────────────────────────────────

    public function storePayment(Request $request, Account $account)
    {
        $validated = $request->validate([
            // De ESTA cuenta: si no, un pago podía colgarse de la factura de otra.
            'account_invoice_id' => ['nullable', 'integer', Rule::exists('account_invoices', 'id')->where('account_id', $account->id)],
            'amount'             => ['required', 'numeric', 'min:0.01'],
            'currency'           => ['required', 'string', 'size:3'],
            'method'             => ['required', Rule::in(['transfer', 'cash', 'card', 'other'])],
            'reference'          => ['nullable', 'string', 'max:120'],
            'paid_at'            => ['required', 'date'],
        ]);

        AccountPayment::create([
            ...$validated,
            'account_id'  => $account->id,
            'recorded_by' => Auth::id(),
        ]);

        // Recalcular estado de la factura vinculada
        if (! empty($validated['account_invoice_id'])) {
            $invoice = AccountInvoice::find($validated['account_invoice_id']);
            $invoice?->load('payments')->recalculateStatus();
        }

        // Un pago suelto (o lo que sobró de uno) va a la factura abierta más vieja.
        // Antes se quedaba esperando a la PRÓXIMA factura, y con el cobro
        // automático eso es un cliente que pagó y sigue cortado.
        $account->allocateCredit(Auth::id());

        $reactivada = $this->cobro->reactivarSiQuedoAlDia($account->fresh());

        return back()->with('success', $reactivada
            ? 'Pago registrado. La cuenta quedó al día y el servicio se reactivó.'
            : 'Pago registrado.');
    }

    public function destroyPayment(Account $account, AccountPayment $payment)
    {
        abort_if($payment->account_id !== $account->id, 404);
        $invoiceId = $payment->account_invoice_id;
        $payment->delete();

        // Recalcular estado de la factura
        if ($invoiceId) {
            $invoice = AccountInvoice::find($invoiceId);
            $invoice?->load('payments')->recalculateStatus();
        }

        return back()->with('success', 'Pago eliminado.');
    }

    // ── Cobro automático ──────────────────────────────────────────────────────

    public function updateAutoBilling(Request $request, Account $account)
    {
        $validated = $request->validate([
            'auto_billing_enabled' => ['required', 'boolean'],
            'billing_day'          => ['required_if:auto_billing_enabled,true', 'nullable', 'integer', 'between:1,31'],
            'billing_grace_days'   => ['required', 'integer', 'between:0,60'],
        ], [
            'billing_day.required_if' => 'Para cobrar solo hace falta el día del mes en que se cobra.',
        ]);

        $enabled = $request->boolean('auto_billing_enabled');

        $account->update([
            'auto_billing_enabled' => $enabled,
            'billing_day'          => $validated['billing_day'] ?? $account->billing_day,
            'billing_grace_days'   => $validated['billing_grace_days'],
            // Desde HOY: encenderlo no factura los meses anteriores. Al apagarlo se
            // olvida, y al volver a encenderlo arranca de nuevo desde ese día.
            'auto_billing_since'   => $enabled
                ? ($account->auto_billing_enabled ? $account->auto_billing_since : CobroAutomatico::hoy()->toDateString())
                : null,
        ]);

        return back()->with('success', $enabled
            ? 'Cobro automático encendido.'
            : 'Cobro automático apagado. Las facturas ya creadas siguen igual.');
    }
}
