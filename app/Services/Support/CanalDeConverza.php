<?php

namespace App\Services\Support;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * El número de Converza (el tenant `default`), visto como canal para hablarle
 * NOSOTROS a un ISP: avisos de tickets (AvisosDeTicket) y de cobro
 * (AvisosDeCobro).
 *
 * Esos avisos no pasan por EventCatalog: son nuestros, no del ISP, y salen del
 * número nuestro se disparen desde donde se disparen.
 */
class CanalDeConverza
{
    public function __construct(private WhatsAppService $whatsapp) {}

    /**
     * ¿Hay ventana de 24 h abierta con ese número EN NUESTRO propio workspace?
     *
     * Se consulta sin el global scope de tenant y con el id explícito del tenant
     * `default`: esto corre en un worker, donde el container puede traer el tenant
     * del job anterior, y preguntar por la conversación equivocada sería mirar el
     * hilo de otro ISP.
     */
    public function ventanaAbierta(string $telefono): bool
    {
        $converza = $this->tenant();

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

    /**
     * Deja el aviso que ya salió en el hilo de ese número en NUESTRA bandeja, para
     * que la respuesta del ISP se lea con su contexto (antes solo quedaba en la
     * bitácora del Brain y en el chat aparecía la respuesta suelta).
     *
     * Con el `wa_message_id` el webhook de estados le pone los checks de
     * entregado / leído como a cualquier saliente.
     *
     * Tenant explícito y sin global scope, por lo mismo que ventanaAbierta(). Si
     * falla, se registra y sigue: el aviso ya salió y no puede quedar como fallido
     * por no haberse podido anotar en el chat.
     */
    public function registrarEnChat(
        string $telefono,
        ?string $nombre,
        string $cuerpo,
        string $tipo,
        ?string $waMessageId,
    ): void {
        try {
            $converza = $this->tenant();

            if (! $converza) {
                return;
            }

            $contacto = Contact::withoutGlobalScopes()->firstOrCreate(
                ['tenant_id' => $converza->id, 'phone' => $telefono],
                ['name' => $nombre],
            );

            $conversacion = Conversation::resolveForContact($converza->id, $contacto->id);

            Message::withoutGlobalScopes()->create([
                'tenant_id'       => $converza->id,
                'conversation_id' => $conversacion->id,
                'contact_id'      => $contacto->id,
                'body'            => $cuerpo,
                'status'          => 'sent',
                'type'            => $tipo,
                'wa_message_id'   => $waMessageId,
            ]);

            $conversacion->touch();
        } catch (Throwable $e) {
            Log::warning('El aviso salió pero no se pudo anotar en el chat de Converza', [
                'phone' => $telefono, 'wa_message_id' => $waMessageId, 'error' => $e->getMessage(),
            ]);
        }
    }

    public function whatsapp(): WhatsAppService
    {
        // forTenant() en vez de confiar en app('tenant'): el aviso sale SIEMPRE
        // del número de Converza, se dispare desde donde se dispare.
        return $this->whatsapp->forTenant($this->tenant());
    }

    public function tenant(): ?Tenant
    {
        return Tenant::where('slug', 'default')->first();
    }

    /** Meta quiere solo dígitos, con indicativo y sin '+'. */
    public static function normalizarTelefono(?string $crudo): ?string
    {
        if (! $crudo) {
            return null;
        }

        return preg_replace('/\D+/', '', $crudo) ?: null;
    }
}
