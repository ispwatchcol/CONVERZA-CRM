<?php

namespace App\Http\Controllers;

use App\Models\Template;
use App\Services\Notifications\EventCatalog;
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
            // Catálogo de eventos: alimenta el selector de propósito y la paleta
            // de variables nombradas que el cliente puede insertar en el body.
            'events'    => EventCatalog::forFrontend(),
            'metaConfigured' => $this->isMetaConfigured(),
        ]);
    }

    public function store(Request $request)
    {
        $tenant   = app('tenant');
        $tenantId = $tenant->id;

        $validated = $this->validateData($request, $tenantId);

        // `variable_examples` no es columna de BD: son las muestras que Meta EXIGE
        // por cada variable del body. Se mandan a Meta pero no se persisten, así que
        // se sacan del array antes de crear. Las claves pueden ser nombres
        // ({{nombre_cliente}}) o números ({{1}}) según el formato del body.
        $submitted = $this->normalizeExamples($validated['variable_examples'] ?? []);
        unset($validated['variable_examples']);

        // Coherencia evento ↔ variables: si el body usa variables nombradas, deben
        // pertenecer al evento elegido. Atrapa typos antes de tocar la BD/Meta.
        if ($err = $this->bodyVariableErrors($validated['body'], $validated['event_key'] ?? null)) {
            return back()->withErrors(['body' => $err]);
        }

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

        // 3) Meta EXIGE una muestra por cada variable del body o rechaza. Para las
        //    variables nombradas de un evento, la muestra se autocompleta desde el
        //    catálogo, así el cliente no tiene que inventarlas.
        $examples = $this->resolveExamples($validated['body'], $validated['event_key'] ?? null, $submitted);

        $missing = $this->missingVariableExamples($validated['body'], $examples);
        if ($missing) {
            $template->delete();
            return back()->withErrors([
                'meta' => 'Faltan muestras para las variables: ' . implode(', ', $missing) . '. Meta exige un valor de ejemplo por cada variable.',
            ]);
        }

        // 4) Enviar a revisión a Meta (crear vía Graph API = revisión automática).
        $result = $this->submitTemplateToMeta($tenant, $validated, $examples);

        if (! $result['ok']) {
            // Revertir el borrador local para poder reintentar sin chocar por nombre
            // duplicado. El formulario conserva lo que escribiste (errores Inertia).
            $template->delete();
            return back()->withErrors(['meta' => $result['error']]);
        }

        // 5) Persistir el meta_id y el estado real que devolvió Meta.
        $template->update([
            'meta_id'        => $result['meta_id'],
            'status'         => $result['status'],
            'last_synced_at' => now(),
        ]);

        return back()->with('success', "Plantilla enviada a revisión de Meta (estado: {$result['status']}). Usa Sincronizar para actualizar el estado cuando Meta la apruebe.");
    }

    /**
     * Envía a revisión de Meta una plantilla que ya existe como borrador local
     * (creada sin credenciales). Reusa la misma lógica que store(): resuelve las
     * muestras, valida coherencia evento↔variables y crea la plantilla en Meta.
     *
     * Solo aplica a borradores sin meta_id; las que ya están en Meta se actualizan
     * vía Sincronizar.
     */
    public function submitToMeta(Request $request, Template $template)
    {
        $this->authorizeTenantOwnership($template);

        if ($template->meta_id) {
            return back()->withErrors(['meta' => 'Esta plantilla ya está en Meta. Usa Sincronizar para actualizar su estado.']);
        }

        if (! $this->isMetaConfigured()) {
            return back()->withErrors(['meta' => 'Falta configurar Business Account ID o Access Token en Configuración → WhatsApp.']);
        }

        $validated = $request->validate([
            'variable_examples'   => ['nullable', 'array'],
            'variable_examples.*' => ['nullable', 'string', 'max:1024'],
        ]);

        // Coherencia evento ↔ variables nombradas (mismo criterio que store()).
        if ($err = $this->bodyVariableErrors($template->body, $template->event_key)) {
            return back()->withErrors(['meta' => $err]);
        }

        // Las muestras de variables nombradas se autocompletan del catálogo; las
        // posicionales ({{1}}) vienen del formulario de envío.
        $submitted = $this->normalizeExamples($validated['variable_examples'] ?? []);
        $examples  = $this->resolveExamples($template->body, $template->event_key, $submitted);

        $missing = $this->missingVariableExamples($template->body, $examples);
        if ($missing) {
            return back()->withErrors([
                'meta' => 'Faltan muestras para las variables: ' . implode(', ', $missing) . '. Meta exige un valor de ejemplo por cada variable.',
            ]);
        }

        $tenant = app('tenant');

        $data = $template->only([
            'name', 'category', 'language', 'body',
            'header_text', 'footer_text', 'button_text', 'button_url',
        ]);

        $result = $this->submitTemplateToMeta($tenant, $data, $examples);

        if (! $result['ok']) {
            return back()->withErrors(['meta' => $result['error']]);
        }

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
        unset($validated['variable_examples']); // no es columna; solo se usa al enviar a Meta

        if ($err = $this->bodyVariableErrors($validated['body'], $validated['event_key'] ?? null)) {
            return back()->withErrors(['body' => $err]);
        }

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

        // Si la plantilla está en Meta hay que borrarla TAMBIÉN allá. Borrar solo el
        // mirror local no sirve: la próxima Sincronización reconstruye el espejo desde
        // Meta y la plantilla "revive". Solo tras confirmar el borrado en Meta —o si ya
        // no existe allá— limpiamos el registro local.
        $hadMetaId = (bool) $template->meta_id;

        if ($hadMetaId) {
            if (! $this->isMetaConfigured()) {
                return back()->withErrors([
                    'delete' => 'Esta plantilla existe en Meta. Para borrarla de verdad configura Business Account ID + Access Token en Configuración → WhatsApp; si solo se borra local, la sincronización la vuelve a crear.',
                ]);
            }

            $result = $this->deleteTemplateFromMeta(app('tenant'), $template);
            if (! $result['ok']) {
                return back()->withErrors(['delete' => $result['error']]);
            }
        }

        $template->delete();

        return back()->with('success', $hadMetaId ? 'Plantilla eliminada (local y Meta).' : 'Plantilla eliminada.');
    }

    /**
     * Borra la plantilla en Meta vía Graph API (DELETE message_templates). Imprescindible:
     * sin esto, borrar solo el mirror local no sirve porque {@see sync()} la recrea desde
     * Meta. Borra la versión exacta (este idioma) por hsm_id + name. Si Meta responde que
     * la plantilla ya no existe, se trata como borrado idempotente (ok) para poder limpiar
     * igual el registro local.
     *
     * @return array{ok: bool, error?: string}
     */
    private function deleteTemplateFromMeta($tenant, Template $template): array
    {
        try {
            $accessToken = $tenant->wa_access_token; // dispara cast 'encrypted'
        } catch (\Illuminate\Contracts\Encryption\DecryptException) {
            return ['ok' => false, 'error' => 'El Access Token guardado no se puede descifrar. Reemplázalo en Configuración → WhatsApp.'];
        }

        $version = config('services.whatsapp.graph_version', 'v20.0');
        $wabaId  = $tenant->wa_business_account_id;
        $query   = http_build_query(['hsm_id' => $template->meta_id, 'name' => $template->name]);

        try {
            $response = Http::withToken($accessToken)
                ->timeout(15)
                ->delete("https://graph.facebook.com/{$version}/{$wabaId}/message_templates?{$query}");
        } catch (\Throwable $e) {
            return ['ok' => false, 'error' => 'No se pudo conectar a Meta para borrar la plantilla: ' . $e->getMessage()];
        }

        if ($response->successful()) {
            return ['ok' => true];
        }

        $msg = $response->json('error.error_user_msg')
            ?? $response->json('error.message')
            ?? ('HTTP ' . $response->status());

        // Idempotencia: si Meta dice que ya no existe, la damos por borrada para no
        // dejar el registro local imposible de limpiar.
        $lower = strtolower($msg);
        if (str_contains($lower, 'does not exist')
            || str_contains($lower, 'not found')
            || str_contains($lower, 'no existe')) {
            return ['ok' => true];
        }

        return ['ok' => false, 'error' => 'Meta no pudo borrar la plantilla: ' . $msg];
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
     * @param  array<int, string>  $examples  Muestra por número de variable ({{1}} => valor).
     * @return array{ok: bool, meta_id?: string, status?: string, error?: string}
     */
    private function submitTemplateToMeta($tenant, array $data, array $examples = []): array
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
            'components' => $this->buildMetaComponents($data, $examples),
        ];

        // Variables nombradas ({{nombre_cliente}}) requieren declarar el formato.
        if (Template::namedVariablesIn($data['body']) !== []) {
            $payload['parameter_format'] = 'NAMED';
        }

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
     * @param  array<string, string>  $examples  Muestra real por variable, indexada por token (nombre o número).
     * @return array<int, array<string, mixed>>
     */
    private function buildMetaComponents(array $data, array $examples = []): array
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

        $named = Template::namedVariablesIn($data['body']);

        if ($named !== []) {
            // Variables nombradas: Meta espera body_text_named_params con el nombre
            // de cada variable y su ejemplo.
            $namedParams = [];
            foreach ($named as $name) {
                $value = trim((string) ($examples[$name] ?? ''));
                $namedParams[] = ['param_name' => $name, 'example' => $value !== '' ? $value : 'Ejemplo'];
            }
            $body['example'] = ['body_text_named_params' => $namedParams];
        } else {
            // Variables posicionales (legado): muestras EN ORDEN ({{1}}, {{2}}, …).
            $positional = Template::positionalVariablesIn($data['body']);
            $varCount   = $positional ? max($positional) : 0;

            if ($varCount > 0) {
                $bodyExamples = [];
                for ($i = 1; $i <= $varCount; $i++) {
                    $value = trim((string) ($examples[(string) $i] ?? ''));
                    $bodyExamples[] = $value !== '' ? $value : 'Ejemplo ' . $i;
                }
                $body['example'] = ['body_text' => [$bodyExamples]];
            }
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
            // Propósito de la plantilla dentro del catálogo de eventos (o null = general).
            'event_key'   => ['nullable', Rule::in(EventCatalog::keys())],
            'body'        => ['required', 'string', 'max:1024'],
            'header_text' => ['nullable', 'string', 'max:60'],
            'footer_text' => ['nullable', 'string', 'max:60'],
            'button_text' => ['nullable', 'string', 'max:25', 'required_with:button_url'],
            'button_url'  => ['nullable', 'url', 'max:2000', 'required_with:button_text'],
            'team_label'  => ['nullable', 'string', 'max:60'],
            // Muestras por variable indexadas por token (nombre o número):
            // { "nombre_cliente": "Jhon", "1": "$2541", … }.
            'variable_examples'   => ['nullable', 'array'],
            'variable_examples.*' => ['nullable', 'string', 'max:1024'],
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
     * Normaliza las muestras a un mapa { token => valor }, con el token tal cual
     * (nombre "nombre_cliente" o número "1"). Descarta claves/valores vacíos para
     * no enviar muestras en blanco a Meta.
     *
     * @param  array<int|string, mixed>  $raw
     * @return array<string, string>
     */
    private function normalizeExamples(array $raw): array
    {
        $out = [];
        foreach ($raw as $key => $value) {
            $key   = trim((string) $key);
            $value = trim((string) $value);
            if ($key !== '' && $value !== '') {
                $out[$key] = $value;
            }
        }
        return $out;
    }

    /**
     * Muestras finales por token: para variables nombradas de un evento, se
     * autocompletan desde el catálogo (el cliente no las escribe); las posicionales
     * y cualquier override vienen de lo que mandó el cliente.
     *
     * @param  array<string, string>  $submitted
     * @return array<string, string>
     */
    private function resolveExamples(string $body, ?string $eventKey, array $submitted): array
    {
        $samples = EventCatalog::samples($eventKey); // [nombre => muestra]

        $out = [];
        foreach (Template::namedVariablesIn($body) as $name) {
            $out[$name] = trim((string) ($submitted[$name] ?? $samples[$name] ?? ''));
        }
        foreach (Template::positionalVariablesIn($body) as $n) {
            $out[(string) $n] = trim((string) ($submitted[(string) $n] ?? ''));
        }
        return $out;
    }

    /**
     * Tokens del body ({{nombre}} o {{n}}) que NO tienen muestra. Meta rechaza la
     * plantilla si falta alguna, así que avisamos antes de enviar.
     *
     * @param  array<string, string>  $examples
     * @return array<int, string>  Tokens formateados, ej. ['{{monto}}', '{{2}}'].
     */
    private function missingVariableExamples(string $body, array $examples): array
    {
        $missing = [];
        foreach (Template::namedVariablesIn($body) as $name) {
            if (blank($examples[$name] ?? null)) {
                $missing[] = '{{' . $name . '}}';
            }
        }
        foreach (Template::positionalVariablesIn($body) as $n) {
            if (blank($examples[(string) $n] ?? null)) {
                $missing[] = '{{' . $n . '}}';
            }
        }
        return $missing;
    }

    /**
     * Valida que las variables nombradas del body pertenezcan al evento elegido.
     * Devuelve el mensaje de error o null si todo está bien. Las plantillas
     * posicionales ({{1}}) o sin variables no se validan contra el catálogo.
     */
    private function bodyVariableErrors(string $body, ?string $eventKey): ?string
    {
        $named = Template::namedVariablesIn($body);
        if ($named === []) {
            return null;
        }

        if (! EventCatalog::has($eventKey)) {
            return 'El mensaje usa variables con nombre ({{...}}). Elige primero el propósito (evento) de la plantilla para habilitarlas.';
        }

        $unknown = array_values(array_diff($named, EventCatalog::variableNames($eventKey)));
        if ($unknown !== []) {
            return 'Variables no disponibles para "' . EventCatalog::label($eventKey) . '": '
                . implode(', ', array_map(fn ($n) => '{{' . $n . '}}', $unknown))
                . '. Usa solo las del panel de variables.';
        }

        return null;
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
