<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Jobs\ProcessWhatsAppStatusUpdate;
use App\Models\Tenant;
use App\Services\WhatsApp\WebhookDispatcher;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(
        private WhatsAppService $whatsapp,
        private WebhookDispatcher $dispatcher,
    ) {
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

        // PRIMERO de todo, antes de tocar nada que pueda fallar: dejar el payload
        // en disco. Es la única copia del mensaje hasta que el worker lo persista,
        // y sobrevive a que se caigan Postgres y Redis a la vez.
        //
        // El 20/08/2026 perdimos 18 mensajes porque esto era un Log::info() que
        // LOG_LEVEL=error descartaba. El canal webhook_raw ignora LOG_LEVEL.
        //
        // Va el cuerpo CRUDO (string), no el array: Monolog normaliza como máximo
        // 9 niveles de anidamiento y el payload de Meta los supera —entry.changes.
        // value.messages[0].from queda justo por debajo del corte—, así que pasarle
        // el array reemplaza el remitente y el texto por "Over 9 levels deep,
        // aborting normalization". Como string se guarda tal cual llegó, que además
        // es lo que hace falta para reprocesar y para validar la firma.
        Log::channel('webhook_raw')->debug('inbound', [
            'received_at' => now()->toIso8601String(),
            'signature'   => $request->header('X-Hub-Signature-256', ''),
            'body'        => $request->getContent(),
        ]);

        $this->forwardWebhook($request);

        // De aquí en adelante ya nada puede costarnos el mensaje: el payload está
        // en disco. Si el despacho falla (BD caída, Redis caído), lo registramos
        // como error —para que dispare la alerta— pero le respondemos 200 a Meta.
        //
        // Devolver 500 no nos protege: el 20/08/2026 Meta reintentó unas 4 veces
        // en 20 segundos y desistió. La red de seguridad es webhook-raw.log, no
        // sus reintentos.
        try {
            $this->dispatcher->dispatch($payload);
        } catch (\Throwable $e) {
            Log::error('Webhook: falló el despacho; el payload quedó en webhook-raw.log', [
                'excepcion' => get_class($e),
                'error'     => $e->getMessage(),
            ]);
        }

        // Respond immediately — never make Meta wait or it will retry
        return response()->json(['status' => 'EVENT_RECEIVED']);
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
