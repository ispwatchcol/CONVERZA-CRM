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
        ]);

        $amount = (float) $validated['amount'];
        $tax    = (float) ($validated['tax'] ?? 0);

        AccountInvoice::create([
            ...$validated,
            'tax'        => $tax,
            'total'      => $amount + $tax,
            'status'     => 'sent',
            'created_by' => Auth::id(),
            'account_id' => $account->id,
        ]);

        return back()->with('success', 'Factura creada.');
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
