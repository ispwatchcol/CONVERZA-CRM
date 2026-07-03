<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora + idempotencia de los avisos automáticos por WhatsApp. Cubre dos
 * familias de kind:
 *   - Por fecha del ciclo (idempotencia por `cycle_key`): factura generada /
 *     recordatorio de pago. Ver 2026_06_01_000001.
 *   - Por evento (idempotencia por `ispwatch_ref_id`, `cycle_key` NULL):
 *     bienvenida al activar servicio / pago registrado. Ver 2026_07_03_000002.
 */
class BillingNotificationLog extends Model
{
    use BelongsToTenant;

    // Kinds por fecha del ciclo.
    public const KIND_INVOICE    = 'invoice_created';
    public const KIND_REMINDER   = 'payment_reminder';
    public const KIND_SUSPENSION = 'service_suspension';

    // Kinds por evento (whatsapp:events-notify).
    public const KIND_WELCOME = 'service_activated';
    public const KIND_PAYMENT = 'payment_registered';

    protected $fillable = [
        'tenant_id',
        'ispwatch_tenant_id',
        'ispwatch_customer_id',
        'ispwatch_ref_id',
        'kind',
        'customer_name',
        'phone',
        'ispwatch_invoice_id',
        'invoice_number',
        'billing_id',
        'router_name',
        'cycle_key',
        'channel',
        'template',
        'status',
        'reason',
        'wa_message_id',
        'sent_at',
    ];

    protected function casts(): array
    {
        return [
            'sent_at' => 'datetime',
        ];
    }
}
