<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TemplateController extends Controller
{
    /** Idiomas soportados por Meta (subset común — la API acepta más). */
    public const LANGUAGES = [
        'es_CO' => 'Español (Colombia)',
        'es'    => 'Español',
        'es_MX' => 'Español (México)',
        'es_AR' => 'Español (Argentina)',
        'en_US' => 'Inglés (US)',
        'en'    => 'Inglés',
        'pt_BR' => 'Portugués (Brasil)',
    ];

    public const CATEGORIES = ['marketing', 'utility', 'authentication'];

    /**
     * Botón de URL (link de pago) temporalmente deshabilitado: no se envía a Meta
     * hasta tener listo el portal de pago. Poner en true para reactivarlo.
     */
    private const ENABLE_URL_BUTTON = false;

    public function index(Request $request)
    {
        $tenantId = app('tenant')->id;

        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        $query = Template::query()->where('tenant_id', $tenantId);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%");
            });
        }

        if (in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $query->where('status', $status);
        }

        $templates = $query->orderByDesc('updated_at')->get();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'filters'   => ['search' => $search, 'status' => $status],
            'stats'     => $this->stats($tenantId),
            'languages' => self::LANGUAGES,
            'metaConfigured' => $this->isMetaConfigured(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant   = app('tenant');
        $tenantId = $tenant->id;

        $validated = $this->validateData($request, $tenantId);

        // 1) Guardar SIEMPRE primero el registro local (pending, sin meta_id).
        //    Si este insert falla, Meta nunca se llama → no quedan plantillas huérfanas
        //    en Meta que después bloqueen reintentos por nombre duplicado.
        $template = Template::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'status'    => 'pending',
            'is_active' => true,
        ]);

        // 2) Sin credenciales Meta: queda como borrador local hasta configurar WhatsApp.
        if (! $this->isMetaConfigured()) {
            return back()->with('success', 'Plantilla guardada localmente. Configura WhatsApp (Business Account ID + token) en Configuración para enviarla a revisión de Meta.');
        }

        // 3) Enviar a revisión a Meta (crear vía Graph API = revisión automática).
        $result = $this->submitTemplateToMeta($tenant, $validated);

        if (! $result['ok']) {
            // Revertir el borrador local para poder reintentar sin chocar por nombre
            // duplicado. El formulario conserva lo que escribiste (errores Inertia).
            $template->delete();
            return back()->withErrors(['meta' => $result['error']]);
        }

        // 4) Persistir el meta_id y el estado real que devolvió Meta.
        $template->update([
            'meta_id'        => $result['meta_id'],
            'status'         => $result['status'],
            'last_synced_at' => now(),
        ]);

        return back()->with('success', "Plantilla enviada a revisión de Meta (estado: {$result['status']}). Usa Sincronizar para actualizar el estado cuando Meta la apruebe.");
    }

    public function update(Request $request, Template $template)
    {
        $this->authorizeTenantOwnership($template);

        // Plantillas ya sincronizadas con Meta NO se editan localmente
        // (Meta es la fuente de verdad). Solo editables las creadas a mano.
        if ($template->meta_id) {
            return back()->withErrors([
                'name' => 'Esta plantilla viene de Meta. Para modificarla, edítala en Meta Business y sincroniza.',
            ]);
        }

        $validated = $this->validateData($request, $template->tenant_id, ignoreId: $template->id);

        $template->update($validated);

        return back()->with('success', 'Plantilla actualizada.');
    }

    public function toggleActive(Template $template)
    {
        $this->authorizeTenantOwnership($template);

        // Si está rechazada, no se debería activar — no se puede enviar.
        if (! $template->is_active && $template->status === 'rejected') {
            return back()->withErrors(['is_active' => 'No se puede activar una plantilla rechazada por Meta.']);
        }

        $template->update(['is_active' => ! $template->is_active]);

        return back()->with('success', $template->is_active ? 'Plantilla activada.' : 'Plantilla desactivada.');
    }

    public function destroy(Template $template)
    {
        $this->authorizeTenantOwnership($template);

        // Si tiene meta_id, borrar local NO la borra de Meta — solo del mirror.
        // Avisamos al usuario en el frontend que para borrar real, vaya a Meta.
        $template->delete();

        return back()->with('success', 'Plantilla eliminada del mirror local.');
    }

    /**
     * Sincroniza plantillas desde Meta API.
     *
     * Hace GET a graph.facebook.com/v20.0/{business_account_id}/message_templates
     * y crea/actualiza el mirror local. NO borra plantillas locales que ya no
     * existen en Meta (puede ser una pausa de Meta o un error de red).
     */
    public function sync()
    {
        $tenant = app('tenant');

        if (! $this->isMetaConfigured()) {
            return back()->withErrors(['sync' => 'Falta configurar Business Account ID o Access Token en Configuración → WhatsApp.']);
        }

        try {
            $accessToken = $tenant->wa_access_token; // dispara cast 'encrypted'
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return back()->withErrors(['sync' => 'El Access Token guardado no se puede descifrar. Reemplázalo en Configuración.']);
        }

        $businessAccountId = $tenant->wa_business_account_id;

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->get("https://graph.facebook.com/v20.0/{$businessAccountId}/message_templates", [
                    'fields' => 'name,language,category,status,components,id',
                    'limit'  => 200,
                ]);
        } catch (\Throwable $e) {
            return back()->withErrors(['sync' => 'No se pudo conectar a Meta: ' . $e->getMessage()]);
        }

        if (! $response->successful()) {
            return back()->withErrors([
                'sync' => 'Meta devolvió error: ' . ($response->json('error.message') ?? 'HTTP ' . $response->status()),
            ]);
        }

        $remoteTemplates = $response->json('data') ?? [];
        $created = 0;
        $updated = 0;

        foreach ($remoteTemplates as $remote) {
            $components = $remote['components'] ?? [];
            $body       = $this->extractBodyFromComponents($components);
            $extras     = $this->extractExtrasFromComponents($components);

            $local = Template::firstOrNew([
                'tenant_id' => $tenant->id,
                'meta_id'   => $remote['id'],
            ]);

            $wasNew = ! $local->exists;

            $local->fill([
                'name'           => $remote['name'],
                'category'       => strtolower($remote['category'] ?? 'utility'),
                'language'       => $remote['language'] ?? 'es_CO',
                'body'           => $body,
                'header_text'    => $extras['header_text'],
                'footer_text'    => $extras['footer_text'],
                'button_text'    => $extras['button_text'],
                'button_url'     => $extras['button_url'],
                'status'         => strtolower($remote['status'] ?? 'pending'),
                'last_synced_at' => now(),
            ]);

            if ($wasNew) {
                $local->is_active = $local->status === 'approved';
            }

            $local->save();

            $wasNew ? $created++ : $updated++;
        }

        return back()->with('success',
            "Sincronización OK. {$created} nuevas, {$updated} actualizadas. Total Meta: " . count($remoteTemplates)
        );
    }

    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Crea la plantilla en Meta vía Graph API, lo que la pone en revisión.
     *
     * @param  \App\Models\Tenant  $tenant
     * @param  array<string, mixed>  $data  Datos ya validados (name, category, language, body).
     * @return array{ok: bool, meta_id?: string, status?: string, error?: string}
     */
    private function submitTemplateToMeta($tenant, array $data): array
    {
        try {
            $accessToken = $tenant->wa_access_token; // dispara cast 'encrypted'
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return ['ok' => false, 'error' => 'El Access Token guardado no se puede descifrar. Reemplázalo en Configuración → WhatsApp.'];
        }

        $version = config('services.whatsapp.graph_version', 'v20.0');
        $wabaId  = $tenant->wa_business_account_id;

        $payload = [
            'name'       => $data['name'],
            'language'   => $data['language'],
            'category'   => strtoupper($data['category']),
            'components' => $this->buildMetaComponents($data),
        ];

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->post("https://graph.facebook.com/{$version}/{$wabaId}/message_templates", $payload);
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo conectar a Meta: ' . $e->getMessage()];
        }

        if (! $response->successful()) {
            // Meta devuelve un mensaje legible en error_user_msg cuando rechaza el formato.
            $msg = $response->json('error.error_user_msg')
                ?? $response->json('error.message')
                ?? ('HTTP ' . $response->status());
            return ['ok' => false, 'error' => 'Meta rechazó la creación: ' . $msg];
        }

        return [
            'ok'      => true,
            'meta_id' => (string) $response->json('id'),
            'status'  => strtolower($response->json('status') ?? 'pending'),
        ];
    }

    /**
     * Componentes para la API de Meta: HEADER (texto) + BODY + FOOTER + BUTTONS (URL).
     * Meta EXIGE valores de ejemplo para cada variable {{n}} del BODY o rechaza.
     * El header/footer/botón se mandan como estáticos (sin variables).
     *
     * @param  array<string, mixed>  $data  name, body, header_text, footer_text, button_text, button_url
     * @return array<int, array<string, mixed>>
     */
    private function buildMetaComponents(array $data): array
    {
        $components = [];

        if (filled($data['header_text'] ?? null)) {
            $components[] = [
                'type'   => 'HEADER',
                'format' => 'TEXT',
                'text'   => $data['header_text'],
            ];
        }

        $body = ['type' => 'BODY', 'text' => $data['body']];

        preg_match_all('/\{\{(\d+)\}\}/', $data['body'], $matches);
        $varCount = $matches[1] ? max(array_map('intval', $matches[1])) : 0;

        if ($varCount > 0) {
            $examples = [];
            for ($i = 1; $i <= $varCount; $i++) {
                $examples[] = 'Ejemplo ' . $i;
            }
            $body['example'] = ['body_text' => [$examples]];
        }

        $components[] = $body;

        if (filled($data['footer_text'] ?? null)) {
            $components[] = ['type' => 'FOOTER', 'text' => $data['footer_text']];
        }

        if (self::ENABLE_URL_BUTTON && filled($data['button_text'] ?? null) && filled($data['button_url'] ?? null)) {
            $components[] = [
                'type'    => 'BUTTONS',
                'buttons' => [[
                    'type' => 'URL',
                    'text' => $data['button_text'],
                    'url'  => $data['button_url'],
                ]],
            ];
        }

        return $components;
    }

    private function authorizeTenantOwnership(Template $template): void
    {
        abort_if($template->tenant_id !== app('tenant')->id, 403, 'No autorizado para este tenant.');
    }

    private function isMetaConfigured(): bool
    {
        $tenant = app('tenant');
        return filled($tenant->wa_business_account_id) && filled($tenant->getRawOriginal('wa_access_token'));
    }

    /**
     * Validación Meta-style:
     *   - name: solo [a-z0-9_], min 1 max 60 (regla de Meta)
     *   - único por (tenant, language) — Meta permite mismo name en distintos idiomas
     */
    private function validateData(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60', 'regex:/^[a-z0-9_]+$/',
                Rule::unique('templates', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('language', $request->input('language', 'es_CO'))
                    ->ignore($ignoreId),
            ],
            'category'    => ['required', Rule::in(self::CATEGORIES)],
            'language'    => ['required', Rule::in(array_keys(self::LANGUAGES))],
            'body'        => ['required', 'string', 'max:1024'],
            'header_text' => ['nullable', 'string', 'max:60'],
            'footer_text' => ['nullable', 'string', 'max:60'],
            'button_text' => ['nullable', 'string', 'max:25', 'required_with:button_url'],
            'button_url'  => ['nullable', 'url', 'max:2000', 'required_with:button_text'],
            'team_label'  => ['nullable', 'string', 'max:60'],
        ], [
            'name.regex'           => 'El nombre solo puede tener minúsculas, números y guión bajo (formato Meta).',
            'name.unique'          => 'Ya existe una plantilla con este nombre en el mismo idioma.',
            'body.max'             => 'El cuerpo no puede exceder 1024 caracteres (límite de Meta).',
            'header_text.max'      => 'El encabezado no puede exceder 60 caracteres (límite de Meta).',
            'footer_text.max'      => 'El pie no puede exceder 60 caracteres (límite de Meta).',
            'button_text.max'      => 'El texto del botón no puede exceder 25 caracteres (límite de Meta).',
            'button_text.required_with' => 'Si pones un enlace, el botón necesita un texto.',
            'button_url.required_with'  => 'Si pones un texto de botón, necesita un enlace (URL).',
            'button_url.url'       => 'El enlace del botón debe ser una URL válida (https://…).',
        ]);
    }

    /**
     * Extrae el `text` del componente BODY de la respuesta de Meta.
     * Una plantilla puede tener HEADER, BODY, FOOTER, BUTTONS — nosotros
     * solo guardamos BODY (lo más importante). Header/footer/buttons quedan
     * para una iteración futura.
     */
    private function extractBodyFromComponents(array $components): string
    {
        foreach ($components as $c) {
            if (strtoupper($c['type'] ?? '') === 'BODY') {
                return $c['text'] ?? '';
            }
        }
        return '';
    }

    /**
     * Extrae header (TEXT), footer y el primer botón URL desde los componentes
     * que devuelve Meta, para mantener el mirror local completo.
     *
     * @param  array<int, array<string, mixed>>  $components
     * @return array{header_text: ?string, footer_text: ?string, button_text: ?string, button_url: ?string}
     */
    private function extractExtrasFromComponents(array $components): array
    {
        $extras = [
            'header_text' => null,
            'footer_text' => null,
            'button_text' => null,
            'button_url'  => null,
        ];

        foreach ($components as $c) {
            $type = strtoupper($c['type'] ?? '');

            if ($type === 'HEADER' && strtoupper($c['format'] ?? '') === 'TEXT') {
                $extras['header_text'] = $c['text'] ?? null;
            } elseif ($type === 'FOOTER') {
                $extras['footer_text'] = $c['text'] ?? null;
            } elseif ($type === 'BUTTONS') {
                foreach ($c['buttons'] ?? [] as $btn) {
                    if (strtoupper($btn['type'] ?? '') === 'URL') {
                        $extras['button_text'] = $btn['text'] ?? null;
                        $extras['button_url']  = $btn['url'] ?? null;
                        break;
                    }
                }
            }
        }

        return $extras;
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId): array
    {
        $base = Template::query()->where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'approved' => (clone $base)->where('status', 'approved')->count(),
            'pending'  => (clone $base)->where('status', 'pending')->count(),
            'rejected' => (clone $base)->where('status', 'rejected')->count(),
            'active'   => (clone $base)->where('is_active', true)->where('status', 'approved')->count(),
            'synced'   => (clone $base)->whereNotNull('meta_id')->count(),
        ];
    }
}
