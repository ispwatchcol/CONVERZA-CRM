<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

class PaymentReminderLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'ispwatch_invoice_id',
        'ispwatch_customer_id',
        'cycle_key',
        'channel',
        'phone',
        'template',
        'status',
        'wa_message_id',
        'error',
    ];
}
