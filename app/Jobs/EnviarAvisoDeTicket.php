<?php

namespace App\Jobs;

use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use App\Models\Brain\TicketNotificationLog;
use App\Services\Support\AvisosDeTicket;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Manda a la cola el aviso al ISP para que la petición HTTP no espere a Meta.
 *
 * Viaja con IDs y no con modelos a propósito: un modelo serializado se rehidrata
 * con los global scopes del worker, donde el tenant que quedó en el container es
 * el del job anterior. Acá no hay nada que rehidratar mal.
 *
 * Si el envío revienta, el reintento es seguro: la bitácora tiene
 * `(ticket_event_id, kind)` único, así que lo que ya salió no sale dos veces.
 */
class EnviarAvisoDeTicket implements ShouldQueue
{
    use Queueable;

    public function __construct(
        private int $ticketId,
        private int $eventoId,
        private string $kind,
    ) {}

    public function handle(AvisosDeTicket $avisos): void
    {
        $ticket = SupportTicket::find($this->ticketId);
        $evento = TicketEvent::find($this->eventoId);

        if (! $ticket || ! $evento) {
            return;
        }

        match ($this->kind) {
            TicketNotificationLog::KIND_ACUSE     => $avisos->acuse($ticket, $evento),
            TicketNotificationLog::KIND_RESPUESTA => $avisos->respuesta($ticket, $evento),
            TicketNotificationLog::KIND_RESUELTO  => $avisos->resuelto($ticket, $evento),
            default                               => null,
        };
    }
}
