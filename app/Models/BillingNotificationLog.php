<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Bitácora + idempotencia de los envíos automáticos por fechas del router
 * (factura generada / recordatorio de pago). Ver migración
 * 2026_06_01_000001_create_billing_notification_logs_table.
 */
class BillingNotificationLog extends Model
{
    use BelongsToTenant;

    public const KIND_INVOICE  = 'invoice_created';
    public const KIND_REMINDER = 'payment_reminder';

    protected $fillable = [
        'tenant_id',
        'ispwatch_tenant_id',
        'ispwatch_customer_id',
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
