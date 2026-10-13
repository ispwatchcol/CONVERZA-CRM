<?php

namespace App\Models\Brain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora de los avisos de cobro al ISP. Es además el candado: (cuenta, tipo,
 * ref) es único, así que el mismo aviso no sale dos veces aunque el comando
 * corra cada hora.
 */
class AccountBillingNotice extends Model
{
    public const KIND_FACTURA      = 'factura';
    public const KIND_RECORDATORIO = 'recordatorio';
    public const KIND_SUSPENSION   = 'suspension';
    public const KIND_REACTIVACION = 'reactivacion';

    protected $fillable = [
        'account_id', 'account_invoice_id', 'kind', 'ref', 'status', 'channel',
        'phone', 'template', 'wa_message_id', 'reason',
    ];

    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function invoice(): BelongsTo { return $this->belongsTo(AccountInvoice::class, 'account_invoice_id'); }
}
