<?php

namespace App\Http\Controllers;

use App\Models\BillingNotificationLog;
use Illuminate\Http\Request;
use Inertia\Inertia;

/**
 * Bitácora de los avisos automáticos por WhatsApp (factura generada /
 * recordatorio de pago). Su valor principal es mostrar POR QUÉ un aviso no se
 * envió (estado skipped/failed con su motivo), además del histórico de enviados.
 */
class NotificationLogController extends Controller
{
    public function index(Request $request)
    {
        $tenantId = app('tenant')->id;

        $status = $request->query('status', '');   // sent | skipped | failed
        $kind   = $request->query('kind', '');     // invoice_created | payment_reminder
        $search = trim((string) $request->query('search', ''));

        $query = BillingNotificationLog::query()->where('tenant_id', $tenantId);

        if (in_array($status, ['sent', 'skipped', 'failed'], true)) {
            $query->where('status', $status);
        }

        if (in_array($kind, [BillingNotificationLog::KIND_INVOICE, BillingNotificationLog::KIND_REMINDER], true)) {
            $query->where('kind', $kind);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('customer_name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('invoice_number', 'like', "%{$search}%");
            });
        }

        $logs = $query->orderByDesc('created_at')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (BillingNotificationLog $l) => [
                'id'             => $l->id,
                'kind'           => $l->kind,
                'customer_name'  => $l->customer_name,
                'phone'          => $l->phone,
                'invoice_number' => $l->invoice_number,
                'router_name'    => $l->router_name,
                'cycle_key'      => $l->cycle_key,
                'template'       => $l->template,
                'status'         => $l->status,
                'reason'         => $l->reason,
                'sent_at'        => $l->sent_at?->toIso8601String(),
                'created_at'     => $l->created_at?->toIso8601String(),
            ]);

        return Inertia::render('Notifications/Index', [
            'logs'    => $logs,
            'filters' => ['search' => $search, 'status' => $status, 'kind' => $kind],
            'stats'   => $this->stats($tenantId),
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId): array
    {
        $base = BillingNotificationLog::query()->where('tenant_id', $tenantId);

        return [
            'total'   => (clone $base)->count(),
            'sent'    => (clone $base)->where('status', 'sent')->count(),
            'skipped' => (clone $base)->where('status', 'skipped')->count(),
            'failed'  => (clone $base)->where('status', 'failed')->count(),
        ];
    }
}
