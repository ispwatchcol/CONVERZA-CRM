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
        $tenantId = app('tenant')->id;

        $validated = $this->validateData($request, $tenantId);

        // Status SIEMPRE arranca en 'pending' al crear local.
        // Meta es quien decide approved/rejected vía sync.
        Template::create([
            ...$validated,
            'tenant_id' => $tenantId,
            'status'    => 'pending',
            'is_active' => true,
        ]);

        return back()->with('success', 'Plantilla creada (queda en pending hasta sincronizar con Meta).');
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
            $body = $this->extractBodyFromComponents($remote['components'] ?? []);

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
            'category'   => ['required', Rule::in(self::CATEGORIES)],
            'language'   => ['required', Rule::in(array_keys(self::LANGUAGES))],
            'body'       => ['required', 'string', 'max:1024'],
            'team_label' => ['nullable', 'string', 'max:60'],
        ], [
            'name.regex'  => 'El nombre solo puede tener minúsculas, números y guión bajo (formato Meta).',
            'name.unique' => 'Ya existe una plantilla con este nombre en el mismo idioma.',
            'body.max'    => 'El cuerpo no puede exceder 1024 caracteres (límite de Meta).',
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
