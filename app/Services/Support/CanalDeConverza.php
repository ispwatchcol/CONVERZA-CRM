<?php

namespace App\Services\Support;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Tenant;
use App\Services\WhatsAppService;

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
