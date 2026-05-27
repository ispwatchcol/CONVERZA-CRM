<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Tenant;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    /**
     * Meta verifica el webhook una sola vez por App. Aceptamos el verify_token
     * global del .env (compat) y también cualquier wa_verify_token guardado en
     * un tenant activo, para que cada cliente pueda suscribir su número con su
     * propio token si así lo prefiere.
     */
    public function verifyWebhook(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = (string) $request->query('hub_verify_token', '');
        $challenge = $request->query('hub_challenge');

        if ($mode !== 'subscribe' || $token === '') {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        $globalToken = (string) config('services.whatsapp.verify_token', 'ispwatch-token');
        if ($globalToken !== '' && hash_equals($globalToken, $token)) {
            return response($challenge, 200);
        }

        $tenantMatches = Tenant::where('is_active', true)
            ->where('wa_verify_token', $token)
            ->exists();

        if ($tenantMatches) {
            return response($challenge, 200);
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        Log::info('Webhook received:', $payload);

        $this->forwardWebhook($request);

        // Meta puede batchear múltiples mensajes en un solo webhook. Cada entry
        // representa una WABA (entry.id) y cada change incluye el phone_number_id
        // bajo value.metadata. Resolvemos tenant por phone_number_id (más
        // específico que WABA) y caemos a WABA si no hay match.
        $dispatched = 0;
        $skippedNoTenant = 0;

        foreach ($payload['entry'] ?? [] as $entry) {
            $wabaId = $entry['id'] ?? null;

            foreach ($entry['changes'] ?? [] as $change) {
                $value         = $change['value'] ?? [];
                $messages      = $value['messages'] ?? [];
                $contacts      = $value['contacts'] ?? [];
                $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;

                if (empty($messages)) {
                    continue; // statuses, errors, account_alerts, etc. — ignorados por ahora
                }

                $tenant = $this->resolveTenantFromPayload($phoneNumberId, $wabaId);

                if (! $tenant) {
                    $skippedNoTenant += count($messages);
                    Log::warning('Webhook: ningún tenant coincide con phone_number_id/WABA', [
                        'phone_number_id' => $phoneNumberId,
                        'waba_id'         => $wabaId,
                        'message_count'   => count($messages),
                    ]);
                    continue;
                }

                foreach ($messages as $message) {
                    ProcessIncomingWhatsAppMessage::dispatch($message, $contacts, $tenant->id);
                    $dispatched++;
                }
            }
        }

        if ($dispatched === 0 && $skippedNoTenant === 0) {
            Log::warning('Webhook received but no messages found in payload', ['payload' => $payload]);
        }

        // Respond immediately — never make Meta wait or it will retry
        return response()->json(['status' => 'EVENT_RECEIVED']);
    }

    /**
     * Busca el tenant dueño de este phone_number_id. Si no hay match exacto,
     * intenta por WABA (un cliente con varios números bajo la misma WABA).
     * En última instancia cae al tenant "default" para no perder mensajes
     * durante la migración desde la config monoinquilino del .env.
     */
    private function resolveTenantFromPayload(?string $phoneNumberId, ?string $wabaId): ?Tenant
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

    private function forwardWebhook(Request $request): void
    {
        $forwardUrl = config('services.whatsapp.forward_url');

        if (! $forwardUrl) {
            return;
        }

        try {
            Http::timeout(3)
                ->withHeaders([
                    'X-Hub-Signature-256' => $request->header('X-Hub-Signature-256', ''),
                    'X-Forwarded-Webhook' => '1',
                ])
                ->post($forwardUrl, $request->all());
        } catch (\Throwable $e) {
            Log::warning('Webhook forward failed', ['error' => $e->getMessage(), 'url' => $forwardUrl]);
        }
    }
}
