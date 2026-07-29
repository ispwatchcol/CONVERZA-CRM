<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\Account;
use App\Models\Brain\AccountInvoice;
use App\Models\Brain\AccountPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class BillingController extends Controller
{
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
            ? $this->applyCredit($account, $invoice)
            : 0;

        return back()->with('success', $credit > 0
            ? 'Factura creada. Se aplicaron ' . number_format($credit, 2) . ' ' . $invoice->currency . ' de saldo a favor.'
            : 'Factura creada.');
    }

    /**
     * Aplica el saldo a favor de la cuenta a una factura recién creada, como descuento.
     *
     * Se materializa como un pago marcado `is_credit_application` por el menor entre el
     * saldo disponible y el total de la factura — así la factura queda con su saldo
     * pendiente ya neteado y el saldo de la cuenta no cuenta la plata dos veces (ver
     * `Account::balanceByCurrency`). Si no hay saldo a favor en esa moneda, no hace nada.
     *
     * @return float Monto aplicado (0 si no hubo saldo).
     */
    private function applyCredit(Account $account, AccountInvoice $invoice): float
    {
        // Se excluye la factura recién creada: el saldo a aplicar es el que había ANTES.
        $available = $account->load(['payments', 'invoices'])->availableCredit($invoice->currency, $invoice->id);
        $toApply   = min($available, (float) $invoice->total);

        if ($toApply <= 0) {
            return 0;
        }

        AccountPayment::create([
            'account_id'            => $account->id,
            'account_invoice_id'    => $invoice->id,
            'recorded_by'           => Auth::id(),
            'amount'                => $toApply,
            'currency'              => $invoice->currency,
            'method'                => 'other',
            'is_credit_application' => true,
            'reference'             => 'Saldo a favor aplicado',
            'paid_at'               => $invoice->issued_at ?? now(),
        ]);

        $invoice->load('payments')->recalculateStatus();

        return $toApply;
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

        return back()->with('success', 'Factura anulada.');
    }

    // ── Pagos ─────────────────────────────────────────────────────────────────

    public function storePayment(Request $request, Account $account)
    {
        $validated = $request->validate([
            'account_invoice_id' => ['nullable', 'integer', Rule::exists('account_invoices', 'id')],
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

        return back()->with('success', 'Pago registrado.');
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
}
