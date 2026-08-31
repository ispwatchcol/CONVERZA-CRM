<?php

namespace App\Services\WhatsApp;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Verifica `X-Hub-Signature-256`: HMAC-SHA256 del cuerpo CRUDO con el app secret
 * de Meta. Sin esto, la ruta del webhook es pública, está exenta de CSRF y el
 * `phone_number_id` es descubrible: cualquiera puede inyectar mensajes falsos en
 * el chat de un tenant, disparar el bot y contaminar las métricas.
 *
 * ── Por qué esto NO responde 403 ──────────────────────────────────────────────
 *
 * La corrección "de manual" es `abort_unless(hash_equals(...), 403)`. Acá no,
 * por lo que enseñó el 20/08/2026: cuando el webhook devolvió error, Meta
 * reintentó cuatro veces en 20 segundos, desistió, y se perdieron 18 mensajes.
 * Sus reintentos no son una red de seguridad.
 *
 * Entonces un 403 solo tiene dos desenlaces posibles: si el payload es falso, le
 * confirma al atacante que existe la comprobación (y no evita nada que no evite
 * ya el descarte); y si la comprobación se equivoca —secreto mal cargado, rotado
 * en Meta y no acá— se lleva por delante mensajes reales de clientes.
 *
 * Por eso la decisión que toma esta clase es **si se procesa el payload**, no
 * qué código HTTP se devuelve. El controlador sigue respondiendo 200 siempre, el
 * cuerpo crudo sigue quedando en `webhook-raw.log` ANTES de llegar acá, y un
 * descarte equivocado se recupera con `webhooks:replay`. Nada se pierde.
 *
 * ── Los tres modos ───────────────────────────────────────────────────────────
 *
 *   off      — ni se comprueba.
 *   log      — comprueba y registra el fallo, pero procesa igual. **Default.**
 *   enforce  — comprueba y descarta lo que no cuadre.
 *
 * El default es `log` a propósito: activar `enforce` sin haber visto antes los
 * registros es apostar a que el secreto está bien cargado para todos los tenants.
 * Se mira `Webhook: firma inválida` unos días; si no aparece, se pasa a enforce.
 *
 * ⚠️ Una instancia que recibe webhooks REENVIADOS (`WEBHOOK_FORWARD_URL`) no
 * puede usar `enforce`: el reenvío vuelve a serializar el JSON con `$request->all()`,
 * así que los bytes ya no son los que Meta firmó y el HMAC nunca va a coincidir.
 */
class SignatureVerifier
{
    public const MODE_OFF     = 'off';
    public const MODE_LOG     = 'log';
    public const MODE_ENFORCE = 'enforce';

    public function __construct(private WebhookDispatcher $dispatcher)
    {
    }

    /**
     * ¿Hay que procesar este payload?
     *
     * Devuelve true en todo caso dudoso —modo off, sin secreto configurado, base
     * caída— y solo false cuando en `enforce` la firma efectivamente no cuadra.
     * Fallar hacia "procesar" es lo correcto acá: el riesgo de un mensaje falso
     * es acotado y visible; el de descartar mensajes reales, no.
     */
    public function shouldProcess(Request $request, array $payload): bool
    {
        $mode = $this->mode();

        if ($mode === self::MODE_OFF) {
            return true;
        }

        $signature = (string) $request->header('X-Hub-Signature-256', '');
        $secrets   = $this->candidateSecrets($payload);

        if ($secrets === []) {
            // Sin ningún secreto que comparar no hay verificación posible, y
            // `enforce` significaría descartar absolutamente todo. Se avisa
            // fuerte porque es una configuración a medio hacer.
            Log::warning('Webhook: la firma no se puede verificar, no hay app secret configurado', [
                'modo' => $mode,
                'pista' => 'Definí WHATSAPP_APP_SECRET o tenants.wa_app_secret',
            ]);

            return true;
        }

        if ($signature === '') {
            Log::warning('Webhook: llegó un payload sin cabecera X-Hub-Signature-256', [
                'modo' => $mode,
                'ip'   => $request->ip(),
            ]);

            return $mode !== self::MODE_ENFORCE;
        }

        if ($this->matchesAny($signature, $request->getContent(), $secrets)) {
            return true;
        }

        Log::warning('Webhook: firma inválida', [
            'modo'             => $mode,
            'ip'               => $request->ip(),
            'secretos_probados' => count($secrets),
            'accion'           => $mode === self::MODE_ENFORCE ? 'descartado' : 'procesado igual (modo log)',
        ]);

        return $mode !== self::MODE_ENFORCE;
    }

    private function mode(): string
    {
        $mode = strtolower((string) config('services.whatsapp.signature_mode', self::MODE_LOG));

        return in_array($mode, [self::MODE_OFF, self::MODE_LOG, self::MODE_ENFORCE], true)
            ? $mode
            : self::MODE_LOG;
    }

    /**
     * Compara contra cada secreto en tiempo constante.
     *
     * @param array<int, string> $secrets
     */
    private function matchesAny(string $signature, string $body, array $secrets): bool
    {
        foreach ($secrets as $secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $body, $secret);

            if (hash_equals($expected, $signature)) {
                return true;
            }
        }

        return false;
    }

    /**
     * El secreto global más el de cada tenant referenciado en el payload.
     *
     * El app secret pertenece a la **App de Meta**, no al número: si todos los
     * números cuelgan de la misma app —el caso normal hoy— alcanza con el
     * global, y por eso va primero y evita tocar la base. `tenants.wa_app_secret`
     * cubre al cliente que trae su propia app de Meta.
     *
     * @return array<int, string>
     */
    private function candidateSecrets(array $payload): array
    {
        $secrets = [];

        $global = (string) config('services.whatsapp.app_secret', '');
        if ($global !== '') {
            $secrets[] = $global;
        }

        try {
            foreach ($this->tenantsInPayload($payload) as $tenant) {
                // getRawOriginal evita que un valor legado en texto plano (o
                // cifrado con una APP_KEY vieja) tire una DecryptException y se
                // lleve puesta la verificación entera. Mismo cuidado que
                // Tenant::hasWhatsAppConfigured().
                if (! filled($tenant->getRawOriginal('wa_app_secret'))) {
                    continue;
                }

                try {
                    $secret = (string) $tenant->wa_app_secret;
                } catch (Throwable $e) {
                    Log::warning('Webhook: no se pudo descifrar wa_app_secret', [
                        'tenant_id' => $tenant->id,
                        'error'     => $e->getMessage(),
                    ]);
                    continue;
                }

                if ($secret !== '') {
                    $secrets[] = $secret;
                }
            }
        } catch (Throwable $e) {
            // Resolver el tenant toca la base. Si está caída no se puede
            // verificar nada — pero el despacho tampoco iba a funcionar, y el
            // cuerpo crudo ya está en disco. Se sigue con lo que haya.
            Log::warning('Webhook: no se pudieron leer los secretos por tenant', [
                'error' => $e->getMessage(),
            ]);
        }

        return array_values(array_unique($secrets));
    }

    /**
     * Tenants referenciados por el payload, sin repetir.
     *
     * Meta batchea varias WABAs en un mismo webhook, así que se recorren todos
     * los `entry.changes` igual que hace WebhookDispatcher.
     *
     * @return array<int, \App\Models\Tenant>
     */
    private function tenantsInPayload(array $payload): array
    {
        $tenants = [];

        foreach ($payload['entry'] ?? [] as $entry) {
            $wabaId = $entry['id'] ?? null;

            foreach ($entry['changes'] ?? [] as $change) {
                $phoneNumberId = $change['value']['metadata']['phone_number_id'] ?? null;

                $tenant = $this->dispatcher->resolveTenant($phoneNumberId, $wabaId);

                if ($tenant && ! isset($tenants[$tenant->id])) {
                    $tenants[$tenant->id] = $tenant;
                }
            }
        }

        return array_values($tenants);
    }
}
