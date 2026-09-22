<?php

namespace App\Models\Brain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Bitácora + idempotencia de los avisos que le mandamos al ISP sobre sus tickets.
 *
 * La fila se crea ANTES de intentar el envío, con `ticket_event_id` + `kind` como
 * llave única: si el job se reintenta, el insert choca y el aviso no sale dos
 * veces. Es el mismo criterio de `BillingNotificationLog`, con la diferencia de
 * que acá la idempotencia es por evento y no por ciclo de facturación.
 *
 * No usa `BelongsToTenant`: es del Core Brain, como el resto de la familia
 * `support_*`, y el que envía somos nosotros, no el tenant.
 */
class TicketNotificationLog extends Model
{
    public const KIND_ACUSE     = 'acuse';
    public const KIND_RESPUESTA = 'respuesta';
    public const KIND_RESUELTO  = 'resuelto';

    protected $fillable = [
        'support_ticket_id', 'account_id', 'ticket_event_id',
        'kind', 'status', 'channel', 'phone', 'template', 'wa_message_id', 'reason',
    ];

    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function account(): BelongsTo { return $this->belongsTo(Account::class); }
    public function event(): BelongsTo { return $this->belongsTo(TicketEvent::class, 'ticket_event_id'); }
}
