<?php

namespace App\Http\Controllers;

use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class SettingsController extends Controller
{
    public function __construct(protected IspwatchRepository $ispwatch) {}

    public function index()
    {
        $tenant = app('tenant');

        return Inertia::render('Settings/Index', [
            'tenant' => $this->serializeTenant($tenant),
            'workspace' => $this->workspaceInfo($tenant),
            'ispwatchStatus' => $this->ispwatchStatusFor($tenant),
        ]);
    }

    public function updateProfile(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'name'      => ['required', 'string', 'max:120'],
            'slug'      => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'is_active' => ['required', 'boolean'],
        ]);

        $tenant->fill($data)->save();

        return back()->with('success', 'Perfil actualizado.');
    }

    public function updateWhatsApp(Request $request)
    {
        $tenant = app('tenant');

        $data = $request->validate([
            'wa_phone_number_id'     => ['nullable', 'string', 'max:64'],
            'wa_business_account_id' => ['nullable', 'string', 'max:64'],
            'wa_verify_token'        => ['nullable', 'string', 'max:120'],
            'wa_access_token'        => ['nullable', 'string'],
            'wa_app_secret'          => ['nullable', 'string'],
        ]);

        // Tokens en blanco = "no tocar el que ya está guardado".
        // El frontend nunca recibe los tokens, los muestra como •••••• con botón "Reemplazar".
        foreach (['wa_access_token', 'wa_app_secret'] as $secret) {
            if (blank($data[$secret] ?? null)) {
                unset($data[$secret]);
            }
        }

        $tenant->fill($data)->save();

        return back()->with('success', 'Credenciales WhatsApp actualizadas.');
    }

    /**
     * GET a la API de Meta para validar que phone_number_id + access_token sirven.
     * No envía mensajes, no cuesta créditos.
     */
    public function testWhatsAppConnection()
    {
        $tenant = app('tenant');

        if (blank($tenant->wa_phone_number_id) || blank($tenant->getRawOriginal('wa_access_token'))) {
            return response()->json([
                'ok'      => false,
                'message' => 'Faltan Phone Number ID o Access Token.',
            ]);
        }

        try {
            $accessToken = $tenant->wa_access_token; // dispara el cast (puede tirar DecryptException)
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return response()->json([
                'ok'      => false,
                'message' => 'El Access Token guardado no se puede descifrar (probablemente cambió APP_KEY o se guardó en texto plano). Reemplázalo en el formulario de arriba.',
            ]);
        }

        try {
            $response = Http::withToken($accessToken)
                ->timeout(10)
                ->get("https://graph.facebook.com/v20.0/{$tenant->wa_phone_number_id}", [
                    'fields' => 'display_phone_number,verified_name,quality_rating',
                ]);
        } catch (\Throwable $e) {
            return response()->json([
                'ok'      => false,
                'message' => 'No se pudo conectar a Meta: ' . $e->getMessage(),
            ]);
        }

        if ($response->successful()) {
            $tenant->update(['wa_status' => 'connected']);

            return response()->json([
                'ok'             => true,
                'phone'          => $response->json('display_phone_number'),
                'verified_name'  => $response->json('verified_name'),
                'quality_rating' => $response->json('quality_rating'),
            ]);
        }

        $tenant->update(['wa_status' => 'error']);

        return response()->json([
            'ok'      => false,
            'message' => $response->json('error.message') ?? 'Meta devolvió código ' . $response->status(),
            'code'    => $response->status(),
        ]);
    }

    /**
     * Refresca el lookup de ISPWatch sin recargar toda la página.
     */
    public function ispwatchStatus()
    {
        return response()->json($this->ispwatchStatusFor(app('tenant')));
    }

    private function serializeTenant($tenant): array
    {
        // Para los campos 'encrypted', leemos el raw (sin descifrar) y solo
        // verificamos presencia. Así sobrevivimos a valores con APP_KEY vieja
        // o texto plano legado: el UI los pinta como "configurado" y el usuario
        // los reemplaza desde el form (el nuevo valor sí se cifra bien).
        $accessTokenRaw = $tenant->getRawOriginal('wa_access_token');
        $appSecretRaw   = $tenant->getRawOriginal('wa_app_secret');

        return [
            // Nota: NO exponemos $tenant->id ni ispwatch_tenant_id al frontend.
            // Son identificadores internos de administración del SaaS.
            'name'                   => $tenant->name,
            'slug'                   => $tenant->slug,
            'is_active'              => (bool) $tenant->is_active,
            'ispwatch_linked'        => filled($tenant->ispwatch_tenant_id),
            'wa_phone_number_id'     => $tenant->wa_phone_number_id,
            'wa_business_account_id' => $tenant->wa_business_account_id,
            'wa_verify_token'        => $tenant->wa_verify_token,
            'wa_status'              => $tenant->wa_status,
            // Los tokens NO se devuelven nunca; solo si están seteados o no.
            'wa_access_token_set'    => filled($accessTokenRaw),
            'wa_app_secret_set'      => filled($appSecretRaw),
            'wa_access_token_broken' => filled($accessTokenRaw) && ! $this->canDecrypt($tenant, 'wa_access_token'),
            'wa_app_secret_broken'   => filled($appSecretRaw) && ! $this->canDecrypt($tenant, 'wa_app_secret'),
            'has_whatsapp'           => filled($tenant->wa_phone_number_id) && filled($accessTokenRaw),
            'created_at'             => $tenant->created_at?->toIso8601String(),
        ];
    }

    private function canDecrypt($tenant, string $attribute): bool
    {
        try {
            $tenant->getAttribute($attribute);
            return true;
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return false;
        }
    }

    private function workspaceInfo($tenant): array
    {
        $env       = config('app.env');
        $publicUrl = rtrim(config('app.public_url'), '/');

        return [
            'app_name'      => config('app.name'),
            // URL canónica del SaaS — la misma para todos los clientes.
            'public_url'    => $publicUrl,
            'webhook_url'   => $publicUrl . '/webhook',
            'env'           => $env,
            'is_production' => $env === 'production',
        ];
    }

    private function ispwatchStatusFor($tenant): ?array
    {
        if (! $tenant->ispwatch_tenant_id) {
            return null;
        }

        $info = $this->ispwatch->tenantInfo((int) $tenant->ispwatch_tenant_id);

        if (! $info) {
            return [
                'connected' => false,
                'message'   => 'Tenant ID configurado pero no se encontró en ISPWatch.',
            ];
        }

        return [
            'connected'       => true,
            'name'            => $info['name'],
            'customers_count' => $info['customers_count'],
            'invoices_count'  => $info['invoices_count'],
            'checked_at'      => now()->toIso8601String(),
        ];
    }
}
