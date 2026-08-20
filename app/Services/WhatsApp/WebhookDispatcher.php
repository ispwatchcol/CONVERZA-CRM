<?php

namespace App\Services\WhatsApp;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\ProcessWhatsAppStatusUpdate;
use App\Models\Tenant;
use Illuminate\Support\Facades\Log;

/**
 * Reparte un payload de webhook de WhatsApp a los jobs que lo procesan.
 *
 * Vive fuera del controlador para que el comando `webhooks:replay` recorra
 * EXACTAMENTE el mismo camino que un webhook en vivo. Si el replay tuviera su
 * propia copia de esta lógica, con el tiempo divergirían y descubriríamos la
 * diferencia justo cuando más importa: reprocesando mensajes tras una caída.
 *
 * Reprocesar es seguro: `messages.wa_message_id` tiene índice único y
 * ProcessIncomingWhatsAppMessage atrapa la violación de unicidad sin ruido.
 */
class WebhookDispatcher
{
    /**
     * @return array{dispatched:int, skipped_no_tenant:int} cuántos jobs salieron
     *         y cuántos eventos se descartaron por no reconocer el número.
     */
    public function dispatch(array $payload): array
    {
        $dispatched = 0;
        $skippedNoTenant = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            $wabaId = $entry['id'] ?? null;

            foreach ($entry['changes'] ?? [] as $change) {
                $value         = $change['value'] ?? [];
                $messages      = $value['messages'] ?? [];
                $statuses      = $value['statuses'] ?? [];
                $contacts      = $value['contacts'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                if (empty($messages) && empty($statuses)) {
                    continue; // errors, account_alerts, etc. — ignorados por ahora
                }

                $tenant = $this->resolveTenant($phoneNumberId, $wabaId);

                if (! $tenant) {
                    $skippedNoTenant += count($messages) + count($statuses);
                    Log::warning('Webhook: ningún tenant coincide con phone_number_id/WABA', [
                        'phone_number_id' => $phoneNumberId,
                        'waba_id'         => $wabaId,
                        'message_count'   => count($messages),
                        'status_count'    => count($statuses),
                    ]);
                    continue;
                }

                foreach ($messages as $message) {
                    ProcessIncomingWhatsAppMessage::dispatch($message, $contacts, $tenant->id);
                    $dispatched++;
                }

                if ($statuses !== []) {
                    // sent/delivered/read/failed — alimenta el seguimiento de
                    // entregas de las campañas masivas y el estado del chat.
                    ProcessWhatsAppStatusUpdate::dispatch($statuses, $tenant->id);
                    $dispatched += count($statuses);
                }
            }
        }

        if ($dispatched === 0 && $skippedNoTenant === 0) {
            Log::warning('Webhook received but no messages found in payload', ['payload' => $payload]);
        }

        return ['dispatched' => $dispatched, 'skipped_no_tenant' => $skippedNoTenant];
    }

    /**
     * Busca el tenant dueño de este phone_number_id. Si no hay match exacto,
     * intenta por WABA (un cliente con varios números bajo la misma WABA).
     * En última instancia cae al tenant "default" para no perder mensajes
     * durante la migración desde la config monoinquilino del .env.
     */
    public function resolveTenant(?string $phoneNumberId, ?string $wabaId): ?Tenant
    {
        if ($phoneNumberId) {
            $tenant = Tenant::where('is_active', true)
                ->where('wa_phone_number_id', $phoneNumberId)
                ->first();
            if ($tenant) {
                return $tenant;
            }
        }

        if ($wabaId) {
            $tenant = Tenant::where('is_active', true)
                ->where('wa_business_account_id', $wabaId)
                ->first();
            if ($tenant) {
                return $tenant;
            }
        }

        // Fallback: tenant 'default' usa las credenciales del .env (config legacy).
        // Solo aplica si el phone_number_id del payload coincide con el del .env,
        // así evitamos meter mensajes ajenos en el tenant por defecto.
        $envUrl = (string) config('services.whatsapp.url', '');
        if ($envUrl !== '' && $phoneNumberId && str_ends_with(rtrim($envUrl, '/'), '/' . $phoneNumberId)) {
            return Tenant::where('slug', 'default')->where('is_active', true)->first();
        }

        return null;
    }
}
