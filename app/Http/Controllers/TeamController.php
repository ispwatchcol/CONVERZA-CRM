<?php

namespace App\Http\Controllers;

use App\Models\Conversation;
use App\Models\StaffMember;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class TeamController extends Controller
{
    private const SAMPLE_TEAMS = [
        ['name' => 'Soporte',     'color' => '#3b82f6', 'description' => 'Atención a fallas técnicas y soporte general.'],
        ['name' => 'Ventas',      'color' => '#10b981', 'description' => 'Captación, conversión y up-selling de clientes.'],
        ['name' => 'Cobranza',    'color' => '#f59e0b', 'description' => 'Recordatorios de pago y gestión de cartera.'],
        ['name' => 'Instalación', 'color' => '#8b5cf6', 'description' => 'Coordinación de visitas técnicas e instalaciones.'],
    ];

    public function index()
    {
        $tenantId = app('tenant')->id;

        $teams = Team::query()
            ->where('tenant_id', $tenantId)
            // staff_members_count cuenta solo del mismo tenant (defensa en profundidad)
            ->withCount(['staffMembers' => fn ($q) => $q->where('staff_members.tenant_id', $tenantId)])
            ->orderBy('name')
            ->get();

        return Inertia::render('Teams/Index', [
            'teams' => $teams,
            'stats' => $this->stats($tenantId),
        ]);
    }

    /**
     * Devuelve los miembros del team y conteos de conversaciones — para el detail modal.
     */
    public function members(Team $team)
    {
        $this->authorizeTenantOwnership($team);

        $members = StaffMember::query()
            ->where('tenant_id', $team->tenant_id)
            ->where('team_id', $team->id)
            ->with('user:id,name,email')
            ->get()
            ->map(fn (StaffMember $s) => [
                'id'        => $s->id,
                'name'      => $s->user?->name,
                'email'     => $s->user?->email,
                'role'      => $s->role,
                'is_active' => (bool) $s->is_active,
            ]);

        $convs = Conversation::where('tenant_id', $team->tenant_id)
            ->where('team_id', $team->id)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return response()->json([
            'members'             => $members,
            'open_conversations'  => (int) ($convs['open'] ?? 0),
            'closed_conversations'=> (int) ($convs['closed'] ?? 0),
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant')->id;

        $validated = $this->validateData($request, $tenantId);

        Team::create([...$validated, 'tenant_id' => $tenantId]);

        return back()->with('success', 'Equipo creado.');
    }

    public function update(Request $request, Team $team)
    {
        $this->authorizeTenantOwnership($team);

        $validated = $this->validateData($request, $team->tenant_id, ignoreId: $team->id);

        $team->update($validated);

        return back()->with('success', 'Equipo actualizado.');
    }

    public function destroy(Team $team)
    {
        $this->authorizeTenantOwnership($team);

        // Avisar si tiene miembros — la FK los dejará huérfanos (team_id NULL)
        $team->delete();

        return back()->with('success', 'Equipo eliminado. Los miembros quedan sin equipo asignado.');
    }

    /**
     * Carga 4 equipos de ejemplo si el tenant no tiene ninguno.
     */
    public function loadSamples()
    {
        $tenantId = app('tenant')->id;

        if (Team::where('tenant_id', $tenantId)->exists()) {
            return back()->withErrors(['samples' => 'Solo se pueden cargar ejemplos cuando no hay equipos.']);
        }

        foreach (self::SAMPLE_TEAMS as $tpl) {
            Team::create([...$tpl, 'tenant_id' => $tenantId]);
        }

        return back()->with('success', count(self::SAMPLE_TEAMS) . ' equipos de ejemplo cargados.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function authorizeTenantOwnership(Team $team): void
    {
        abort_if($team->tenant_id !== app('tenant')->id, 403, 'No autorizado para este tenant.');
    }

    private function validateData(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'name' => [
                'required', 'string', 'max:60',
                Rule::unique('teams', 'name')->where('tenant_id', $tenantId)->ignore($ignoreId),
            ],
            'description' => ['nullable', 'string', 'max:300'],
            'color'       => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
        ], [
            'name.unique' => 'Ya existe un equipo con ese nombre.',
            'color.regex' => 'El color debe estar en formato hex (#a1b2c3).',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId): array
    {
        $base = Team::query()->where('tenant_id', $tenantId);

        return [
            'total'        => (clone $base)->count(),
            'with_members' => (clone $base)
                ->whereHas('staffMembers', fn ($q) => $q->where('staff_members.tenant_id', $tenantId))
                ->count(),
            'staff_total'  => StaffMember::where('tenant_id', $tenantId)->count(),
            'unassigned'   => StaffMember::where('tenant_id', $tenantId)->whereNull('team_id')->count(),
        ];
    }
}
