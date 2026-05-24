<?php

namespace App\Http\Controllers;

use App\Models\QuickReply;
use Illuminate\Http\Request;
use Inertia\Inertia;

class QuickReplyController extends Controller
{
    /** Tope sugerido por WhatsApp para mensajes de texto. */
    private const BODY_MAX = 4096;

    /** Plantillas que se cargan al hacer click en "Cargar ejemplos" si el tenant no tiene QRs. */
    private const SAMPLE_TEMPLATES = [
        [
            'title'    => 'Saludo inicial',
            'shortcut' => 'saludo',
            'category' => 'Generales',
            'body'     => '¡Hola! Gracias por contactarnos. ¿En qué podemos ayudarte hoy?',
        ],
        [
            'title'    => 'Reporte de falla',
            'shortcut' => 'falla',
            'category' => 'Soporte',
            'body'     => 'Lamentamos los inconvenientes con tu servicio. ¿Podrías indicarnos tu cédula o número de cliente para revisar el estado de tu conexión?',
        ],
        [
            'title'    => 'Verificación de pago',
            'shortcut' => 'pago',
            'category' => 'Facturación',
            'body'     => 'Para verificar tu pago, por favor envíanos el comprobante de la transacción junto con tu número de cédula. Nuestro horario de validación es de lunes a sábado de 8am a 6pm.',
        ],
        [
            'title'    => 'Planes disponibles',
            'shortcut' => 'planes',
            'category' => 'Ventas',
            'body'     => 'Estos son nuestros planes actuales:' . "\n" .
                          '• Plan 20 Megas — $XX.XXX' . "\n" .
                          '• Plan 50 Megas — $XX.XXX' . "\n" .
                          '• Plan 100 Megas — $XX.XXX' . "\n" .
                          '¿Cuál te interesa?',
        ],
        [
            'title'    => 'Visita técnica',
            'shortcut' => 'visita',
            'category' => 'Soporte',
            'body'     => 'Programaremos una visita técnica. Por favor confírmanos: 1) Dirección 2) Una ventana horaria (mañana/tarde) 3) Persona de contacto en sitio.',
        ],
    ];

    public function index(Request $request)
    {
        $tenantId = app('tenant')->id;

        $search   = trim((string) $request->query('search', ''));
        $category = trim((string) $request->query('category', ''));
        $status   = $request->query('status', 'all'); // all | active | inactive

        $query = QuickReply::query()->where('tenant_id', $tenantId);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                  ->orWhere('body', 'like', "%{$search}%")
                  ->orWhere('shortcut', 'like', "%{$search}%");
            });
        }

        if ($category !== '') {
            $query->where('category', $category);
        }

        if ($status === 'active') {
            $query->where('is_active', true);
        } elseif ($status === 'inactive') {
            $query->where('is_active', false);
        }

        $quickReplies = $query
            ->orderByDesc('is_active')
            ->orderBy('category')
            ->orderBy('title')
            ->get();

        // Lista de categorías existentes (para filtro y datalist del modal)
        $categories = QuickReply::query()
            ->where('tenant_id', $tenantId)
            ->whereNotNull('category')
            ->where('category', '!=', '')
            ->distinct()
            ->orderBy('category')
            ->pluck('category')
            ->all();

        return Inertia::render('QuickReplies/Index', [
            'quickReplies' => $quickReplies,
            'categories'   => $categories,
            'filters'      => ['search' => $search, 'category' => $category, 'status' => $status],
            'stats'        => $this->stats($tenantId),
            'bodyMax'      => self::BODY_MAX,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant')->id;

        $validated = $this->validateData($request, $tenantId);

        QuickReply::create([...$validated, 'tenant_id' => $tenantId]);

        return back()->with('success', 'Respuesta rápida creada.');
    }

    public function update(Request $request, QuickReply $quickReply)
    {
        $this->authorizeTenantOwnership($quickReply);

        $validated = $this->validateData($request, $quickReply->tenant_id, ignoreId: $quickReply->id);

        $quickReply->update($validated);

        return back()->with('success', 'Respuesta rápida actualizada.');
    }

    public function destroy(QuickReply $quickReply)
    {
        $this->authorizeTenantOwnership($quickReply);
        $quickReply->delete();

        return back()->with('success', 'Respuesta rápida eliminada.');
    }

    /**
     * Toggle activo/inactivo desde la card (sin abrir modal).
     */
    public function toggle(QuickReply $quickReply)
    {
        $this->authorizeTenantOwnership($quickReply);
        $quickReply->update(['is_active' => ! $quickReply->is_active]);

        return back()->with('success', $quickReply->is_active ? 'Activada.' : 'Desactivada.');
    }

    /**
     * Duplica una QR — útil para crear variantes (ej. "Saludo formal" / "Saludo casual").
     * El nuevo registro queda inactivo y con el atajo vacío para evitar colisiones.
     */
    public function duplicate(QuickReply $quickReply)
    {
        $this->authorizeTenantOwnership($quickReply);

        $copy = $quickReply->replicate(['shortcut']);
        $copy->title = $quickReply->title . ' (copia)';
        $copy->shortcut = null;
        $copy->is_active = false;
        $copy->save();

        return back()->with('success', 'Respuesta rápida duplicada (inactiva).');
    }

    /**
     * Carga plantillas de ejemplo si el tenant no tiene ninguna QR.
     * Solo funciona cuando el tenant está vacío para no spamear creaciones.
     */
    public function loadSamples()
    {
        $tenantId = app('tenant')->id;

        if (QuickReply::where('tenant_id', $tenantId)->exists()) {
            return back()->withErrors(['samples' => 'Solo se pueden cargar ejemplos cuando no hay respuestas rápidas. Borra primero las existentes si quieres empezar de cero.']);
        }

        foreach (self::SAMPLE_TEMPLATES as $tpl) {
            QuickReply::create([...$tpl, 'tenant_id' => $tenantId, 'is_active' => true]);
        }

        return back()->with('success', count(self::SAMPLE_TEMPLATES) . ' respuestas rápidas de ejemplo cargadas.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function authorizeTenantOwnership(QuickReply $qr): void
    {
        $tenantId = app('tenant')->id;
        abort_if($qr->tenant_id !== $tenantId, 403, 'No autorizado para este tenant.');
    }

    /**
     * Valida shortcut único por tenant (ignorando el propio registro en update).
     */
    private function validateData(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'title'     => ['required', 'string', 'max:120'],
            'body'      => ['required', 'string', 'max:' . self::BODY_MAX],
            'shortcut'  => [
                'nullable', 'string', 'max:30', 'regex:/^[a-z0-9_-]+$/i',
                function ($attr, $value, $fail) use ($tenantId, $ignoreId) {
                    if (! $value) return;
                    $exists = QuickReply::where('tenant_id', $tenantId)
                        ->where('shortcut', $value)
                        ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
                        ->exists();
                    if ($exists) {
                        $fail("El atajo /{$value} ya está en uso por otra respuesta.");
                    }
                },
            ],
            'category'  => ['nullable', 'string', 'max:60'],
            'is_active' => ['nullable', 'boolean'],
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId): array
    {
        $base = QuickReply::query()->where('tenant_id', $tenantId);

        return [
            'total'      => (clone $base)->count(),
            'active'     => (clone $base)->where('is_active', true)->count(),
            'inactive'   => (clone $base)->where('is_active', false)->count(),
            'categories' => (clone $base)->whereNotNull('category')->where('category', '!=', '')->distinct('category')->count('category'),
        ];
    }
}
