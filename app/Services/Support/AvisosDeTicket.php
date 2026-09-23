<?php

namespace App\Services\Support;

use App\Mail\TicketNuevoDelPortal;
use App\Models\Brain\Account;
use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use App\Models\Brain\TicketNotificationLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Avisarle al ISP que su requerimiento avanzó (CON-57).
 *
 * "Ver los avances" no puede depender de que el cliente se acuerde de entrar al
 * panel: si no le avisamos, el portal no reemplaza al WhatsApp de siempre, que es
 * justo el problema que se quería resolver.
 *
 * Tres momentos y nada más —recibido, respondido, resuelto—: un aviso por cada
 * movimiento interno convierte la función en ruido y el cliente la apaga.
 *
 * Reglas que valen más que el código:
 *
 * 1. **Opt-in por cuenta.** `accounts.support_notify_enabled` nace apagado.
 * 2. **Nunca texto libre fuera de la ventana de 24 h.** Meta no lo entrega y cada
 *    rechazo gasta quality rating del número. Sin plantilla configurada, el aviso
 *    se registra como `skipped` y no se intenta.
 * 3. **Idempotente.** La fila de bitácora se crea ANTES del envío, con
 *    `(ticket_event_id, kind)` único: un reintento del job choca y no reenvía.
 * 4. **Queda rastro donde se mira.** Cada intento escribe además una `note` en la
 *    bitácora del propio ticket. Es interna: el ISP no la ve nunca.
 *
 * Y el aviso en espejo, `nuevoParaNosotros()`: cuando el ISP abre un ticket nos
 * llega un correo (CON-82). Ese va siempre —no depende del opt-in del cliente— y
 * por correo justamente para no depender de la ventana de 24 h ni de que Meta
 * apruebe una plantilla.
 */
class AvisosDeTicket
{
    public function __construct(private WhatsAppService $whatsapp) {}

    public function acuse(SupportTicket $ticket, TicketEvent $origen): void
    {
        $this->enviar(
            $ticket,
            $origen,
            TicketNotificationLog::KIND_ACUSE,
            "Recibimos tu requerimiento #{$ticket->id}: «{$ticket->subject}». "
            . 'Te avisamos por acá en cuanto avance. Puedes seguirlo en ' . $this->enlace($ticket),
        );
    }

    public function respuesta(SupportTicket $ticket, TicketEvent $origen): void
    {
        $this->enviar(
            $ticket,
            $origen,
            TicketNotificationLog::KIND_RESPUESTA,
            "Respondimos tu requerimiento #{$ticket->id}: «{$ticket->subject}». "
            . 'Léelo y contéstanos en ' . $this->enlace($ticket),
        );
    }

    public function resuelto(SupportTicket $ticket, TicketEvent $origen): void
    {
        $this->enviar(
            $ticket,
            $origen,
            TicketNotificationLog::KIND_RESUELTO,
            "Dimos por resuelto tu requerimiento #{$ticket->id}: «{$ticket->subject}». "
            . 'Si el tema sigue, respóndenos ahí mismo y se vuelve a abrir: ' . $this->enlace($ticket),
        );
    }

    /**
     * El aviso en espejo: a NOSOTROS, por correo, cuando un ISP abre un ticket.
     *
     * Sin esto la bandeja solo avisa a quien ya está mirando la pantalla, y el
     * portal sería peor que el WhatsApp de siempre — el cliente cree que ya lo
     * recibimos. Va por correo y no por WhatsApp a propósito: para avisarnos a
     * nosotros mismos no hace falta pelear con la ventana de 24 h ni esperar que
     * Meta apruebe una plantilla.
     *
     * No mira el opt-in de la cuenta: ese es la decisión del cliente sobre lo que
     * él recibe, no sobre lo que nos enteramos nosotros.
     */
    public function nuevoParaNosotros(SupportTicket $ticket, TicketEvent $origen): void
    {
        $account = $ticket->account;

        if (! $account) {
            return;
        }

        $log = $this->abrirBitacora($ticket, $account->id, $origen, TicketNotificationLog::KIND_INTERNO);

        if (! $log) {
            return;
        }

        $destino = (string) config('support.notify.internal_email');

        if ($destino === '') {
            $this->cerrar($ticket, $log, 'skipped', 'sin_correo');

            return;
        }

        // Con el mailer en `log` o `array` el correo no sale del servidor. Se
        // registra como omitido en vez de dejar una fila que dice "enviado" y a
        // nadie avisado: el día que haga falta revisar por qué no llegó, la
        // bitácora tiene que decir la verdad.
        if (in_array((string) config('mail.default'), ['log', 'array'], true)) {
            $log->channel = 'email';
            $log->phone   = null;
            $this->cerrar($ticket, $log, 'skipped', 'correo_sin_transporte');

            return;
        }

        $log->channel = 'email';

        try {
            Mail::to($destino)->send(new TicketNuevoDelPortal(
                $ticket,
                $account->name,
                (string) $origen->body,
            ));
        } catch (\Throwable $e) {
            $this->cerrar($ticket, $log, 'failed', substr($e->getMessage(), 0, 200));

            return;
        }

        $this->cerrar($ticket, $log, 'sent', null);
    }

    // ── El camino común ──────────────────────────────────────────────────────

    private function enviar(SupportTicket $ticket, TicketEvent $origen, string $kind, string $texto): void
    {
        $account = $ticket->account;

        if (! $account) {
            return;
        }

        $log = $this->abrirBitacora($ticket, $account->id, $origen, $kind);

        if (! $log) {
            return;
        }

        if (! $account->support_notify_enabled) {
            $this->cerrar($ticket, $log, 'skipped', 'apagado');

            return;
        }

        $telefono = $this->telefono($account);

        if (! $telefono) {
            $this->cerrar($ticket, $log, 'skipped', 'sin_telefono');

            return;
        }

        $log->phone = $telefono;

        // Dentro de la ventana podemos escribir como una persona; fuera, solo
        // plantilla. El orden importa: preguntar primero por la ventana evita
        // gastar una plantilla cuando el hilo está abierto.
        if ($this->ventanaAbierta($telefono)) {
            $resultado = $this->whatsappDeConverza()->sendMessage($telefono, $texto);
            $log->channel = 'whatsapp_text';
        } else {
            $plantilla = config("support.notify.templates.{$kind}");

            if (! $plantilla) {
                $this->cerrar($ticket, $log, 'skipped', 'sin_plantilla');

                return;
            }

            $resultado = $this->whatsappDeConverza()->sendTemplate(
                $telefono,
                $plantilla,
                (string) config('support.notify.language', 'es_CO'),
                ['numero' => (string) $ticket->id, 'asunto' => $ticket->subject],
            );
            $log->channel  = 'whatsapp_template';
            $log->template = $plantilla;
        }

        if ($resultado['success'] ?? false) {
            $log->wa_message_id = $resultado['data']['messages'][0]['id'] ?? null;
            $this->cerrar($ticket, $log, 'sent', null);

            return;
        }

        $this->cerrar($ticket, $log, 'failed', substr((string) ($resultado['error'] ?? 'error'), 0, 200));
    }

    /**
     * Abre la fila de bitácora, que es el candado de idempotencia.
     *
     * Se crea ANTES de intentar el envío: `(ticket_event_id, kind)` es único, así
     * que un segundo intento del mismo aviso choca contra la BD y devuelve null en
     * vez de mandar el mensaje otra vez.
     */
    private function abrirBitacora(SupportTicket $ticket, int $accountId, TicketEvent $origen, string $kind): ?TicketNotificationLog
    {
        try {
            return TicketNotificationLog::create([
                'support_ticket_id' => $ticket->id,
                'account_id'        => $accountId,
                'ticket_event_id'   => $origen->id,
                'kind'              => $kind,
                'status'            => 'skipped',
                'channel'           => 'none',
            ]);
        } catch (QueryException $e) {
            Log::info('Aviso de ticket ya registrado, no se reenvía', [
                'ticket' => $ticket->id, 'evento' => $origen->id, 'kind' => $kind,
            ]);

            return null;
        }
    }

    /** Guarda el resultado y lo deja escrito en la bitácora del ticket. */
    private function cerrar(SupportTicket $ticket, TicketNotificationLog $log, string $status, ?string $reason): void
    {
        $log->status = $status;
        $log->reason = $reason;
        $log->save();

        // El aviso interno que SÍ salió no deja nota: sería una línea de ruido en
        // cada ticket del portal para contar algo que nadie va a mirar. Si no salió,
        // sí — eso hay que verlo sin ir a buscar la tabla.
        if ($log->kind === TicketNotificationLog::KIND_INTERNO && $status === 'sent') {
            return;
        }

        TicketEvent::create([
            'support_ticket_id' => $ticket->id,
            'author_user_id'    => null,
            // `note`: es información nuestra sobre el ticket, no conversación. El
            // ISP no la ve — ver TicketEvent::VISIBLE_AL_ISP.
            'type'              => 'note',
            'body'              => $this->resumen($log),
            'meta'              => ['aviso' => $log->kind, 'status' => $status, 'reason' => $reason],
        ]);
    }

    private function resumen(TicketNotificationLog $log): string
    {
        // El aviso interno se cuenta aparte: no es algo que le mandamos al ISP.
        if ($log->kind === TicketNotificationLog::KIND_INTERNO) {
            return match ($log->reason) {
                'correo_sin_transporte' => 'No se pudo avisar al equipo por correo: el servidor no tiene transporte de correo configurado (MAIL_MAILER).',
                'sin_correo'            => 'No se pudo avisar al equipo por correo: no hay dirección configurada.',
                default                 => "No se pudo avisar al equipo por correo: {$log->reason}.",
            };
        }

        $que = match ($log->kind) {
            TicketNotificationLog::KIND_ACUSE     => 'acuse de recibo',
            TicketNotificationLog::KIND_RESPUESTA => 'aviso de respuesta',
            default                               => 'aviso de resolución',
        };

        return match ($log->status) {
            'sent'  => "Se le envió al ISP el {$que} por WhatsApp ({$log->phone}).",
            'failed' => "No se pudo enviar el {$que} al ISP: {$log->reason}.",
            default => match ($log->reason) {
                'apagado'          => "No se envió el {$que}: la cuenta tiene los avisos de soporte apagados.",
                'sin_telefono'     => "No se envió el {$que}: la cuenta no tiene teléfono para avisos.",
                'sin_plantilla'    => "No se envió el {$que}: el hilo está fuera de la ventana de 24 h y no hay plantilla aprobada para este aviso.",
                default            => "No se envió el {$que}.",
            },
        };
    }

    private function telefono(Account $account): ?string
    {
        $crudo = $account->support_notify_phone ?: $account->contact_phone;

        if (! $crudo) {
            return null;
        }

        // Meta quiere solo dígitos, con indicativo y sin '+'.
        return preg_replace('/\D+/', '', $crudo) ?: null;
    }

    /**
     * ¿Hay ventana de 24 h abierta con ese número EN NUESTRO propio workspace?
     *
     * Se consulta sin el global scope de tenant y con el id explícito del tenant
     * `default`: esto corre en un worker, donde el container puede traer el tenant
     * del job anterior, y preguntar por la conversación equivocada sería mirar el
     * hilo de otro ISP.
     */
    private function ventanaAbierta(string $telefono): bool
    {
        $converza = $this->tenantDeConverza();

        if (! $converza) {
            return false;
        }

        $contacto = Contact::withoutGlobalScopes()
            ->where('tenant_id', $converza->id)
            ->where('phone', $telefono)
            ->first();

        if (! $contacto) {
            return false;
        }

        $conversacion = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $converza->id)
            ->where('contact_id', $contacto->id)
            ->latest('id')
            ->first();

        return $conversacion?->serviceWindowIsOpen() ?? false;
    }

    private function whatsappDeConverza(): WhatsAppService
    {
        // forTenant() en vez de confiar en app('tenant'): el aviso sale SIEMPRE
        // del número de Converza, se dispare desde donde se dispare.
        return $this->whatsapp->forTenant($this->tenantDeConverza());
    }

    private function tenantDeConverza(): ?Tenant
    {
        return Tenant::where('slug', 'default')->first();
    }

    private function enlace(SupportTicket $ticket): string
    {
        return route('support.show', $ticket->id);
    }
}
