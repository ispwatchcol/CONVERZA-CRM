<?php

namespace App\Http\Controllers;

use App\Models\Label;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class LabelController extends Controller
{
    private const SAMPLE_LABELS = [
        ['name' => 'VIP',              'color' => '#ef4444', 'type' => 'contact', 'description' => 'Cliente prioritario, atender primero.'],
        ['name' => 'Cliente activo',   'color' => '#10b981', 'type' => 'contact', 'description' => 'Cliente con servicio en uso normal.'],
        ['name' => 'Moroso',           'color' => '#f59e0b', 'type' => 'contact', 'description' => 'Tiene facturas pendientes de pago.'],
        ['name' => 'Prospecto frío',   'color' => '#94a3b8', 'type' => 'contact', 'description' => 'Mostró interés pero no convirtió.'],
        ['name' => 'Reclamo abierto',  'color' => '#ec4899', 'type' => 'contact', 'description' => 'Tiene un problema sin resolver.'],
    ];

    public function index(Request $request)
    {
        $tenantId = app('tenant')->id;

        $search = trim((string) $request->query('search', ''));
        $type   = $request->query('type', '');

        $query = Label::query()
            ->where('tenant_id', $tenantId)
            // contacts_count debe contar SOLO contactos del mismo tenant.
            ->withCount(['contacts' => fn ($q) => $q->where('contacts.tenant_id', $tenantId)]);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if ($type === 'contact' || $type === 'template') {
            $query->where('type', $type);
        }

        $labels = $query->orderBy('type')->orderBy('name')->get();

        return Inertia::render('Labels/Index', [
            'labels'  => $labels,
            'filters' => ['search' => $search, 'type' => $type],
            'stats'   => $this->stats($tenantId),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant')->id;

        $validated = $this->validateData($request, $tenantId);

        Label::create([...$validated, 'tenant_id' => $tenantId]);

        return back()->with('success', 'Etiqueta creada.');
    }

    public function update(Request $request, Label $label)
    {
        $this->authorizeTenantOwnership($label);

        $validated = $this->validateData($request, $label->tenant_id, ignoreId: $label->id);

        $label->update($validated);

        return back()->with('success', 'Etiqueta actualizada.');
    }

    public function destroy(Label $label)
    {
        $this->authorizeTenantOwnership($label);
        $label->delete();

        return back()->with('success', 'Etiqueta eliminada.');
    }

    /**
     * Carga 5 etiquetas de ejemplo. Solo funciona si el tenant no tiene ninguna.
     */
    public function loadSamples()
    {
        $tenantId = app('tenant')->id;

        if (Label::where('tenant_id', $tenantId)->exists()) {
            return back()->withErrors(['samples' => 'Solo se pueden cargar ejemplos cuando no hay etiquetas.']);
        }

        foreach (self::SAMPLE_LABELS as $tpl) {
            Label::create([...$tpl, 'tenant_id' => $tenantId]);
        }

        return back()->with('success', count(self::SAMPLE_LABELS) . ' etiquetas de ejemplo cargadas.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function authorizeTenantOwnership(Label $label): void
    {
        abort_if($label->tenant_id !== app('tenant')->id, 403, 'No autorizado para este tenant.');
    }

    /**
     * Nombre único por (tenant, type) — evita "Urgente" duplicado en mismo type.
     * Color debe ser un hex válido tipo #abc123.
     */
    private function validateData(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('labels', 'name')
                    ->where('tenant_id', $tenantId)
                    ->where('type', $request->input('type'))
                    ->ignore($ignoreId),
            ],
            'color'       => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'type'        => ['required', Rule::in(['contact', 'template'])],
            'description' => ['nullable', 'string', 'max:200'],
        ], [
            'name.unique'  => 'Ya existe una etiqueta con ese nombre del mismo tipo.',
            'color.regex'  => 'El color debe estar en formato hex (#a1b2c3).',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId): array
    {
        $base = Label::query()->where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'contact'  => (clone $base)->where('type', 'contact')->count(),
            'template' => (clone $base)->where('type', 'template')->count(),
            // Etiquetas que tienen al menos 1 contacto asignado (en este tenant)
            'in_use'   => (clone $base)
                ->whereHas('contacts', fn ($q) => $q->where('contacts.tenant_id', $tenantId))
                ->count(),
        ];
    }
}
