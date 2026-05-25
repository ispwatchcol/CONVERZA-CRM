<?php

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

class StaffController extends Controller
{
    public const ROLES = ['admin', 'agent', 'viewer'];

    public function index(Request $request)
    {
        $tenantId = app('tenant')->id;

        $search = trim((string) $request->query('search', ''));
        $roleFilter = $request->query('role', '');
        $statusFilter = $request->query('status', 'all'); // all | active | inactive

        $query = StaffMember::query()
            ->where('tenant_id', $tenantId)
            ->with(['user:id,name,email', 'team:id,name,color']);

        if ($search !== '') {
            $query->whereHas('user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if (in_array($roleFilter, self::ROLES, true)) {
            $query->where('role', $roleFilter);
        }

        if ($statusFilter === 'active') {
            $query->where('is_active', true);
        } elseif ($statusFilter === 'inactive') {
            $query->where('is_active', false);
        }

        $staff = $query->orderByDesc('is_active')->orderBy('id')->get()
            ->map(fn (StaffMember $s) => [
                'id'         => $s->id,
                'user_id'    => $s->user_id,
                'name'       => $s->user?->name ?? 'Usuario eliminado',
                'email'      => $s->user?->email,
                'team'       => $s->team?->name,
                'team_id'    => $s->team_id,
                'team_color' => $s->team?->color,
                'role'       => $s->role,
                'is_active'  => (bool) $s->is_active,
                'is_me'      => $s->user_id === $request->user()?->id,
                'created_at' => $s->created_at?->toIso8601String(),
            ]);

        // Users del tenant que AÚN NO son staff (para "Agregar staff existente")
        $availableUsers = User::query()
            ->where('tenant_id', $tenantId)
            ->whereNotIn('id', StaffMember::where('tenant_id', $tenantId)->pluck('user_id'))
            ->orderBy('name')
            ->get(['id', 'name', 'email']);

        $teams = Team::where('tenant_id', $tenantId)
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('Staff/Index', [
            'staff'          => $staff,
            'teams'          => $teams,
            'availableUsers' => $availableUsers,
            'filters'        => ['search' => $search, 'role' => $roleFilter, 'status' => $statusFilter],
            'stats'          => $this->stats($tenantId),
        ]);
    }

    /**
     * Agrega como staff a un usuario que YA existe en el tenant.
     */
    public function store(Request $request)
    {
        $tenantId = app('tenant')->id;

        $validated = $request->validate([
            'user_id' => [
                'required', 'integer',
                Rule::exists('users', 'id')->where('tenant_id', $tenantId),
                Rule::unique('staff_members', 'user_id')->where('tenant_id', $tenantId),
            ],
            'team_id' => [
                'nullable', 'integer',
                Rule::exists('teams', 'id')->where('tenant_id', $tenantId),
            ],
            'role'      => ['required', Rule::in(self::ROLES)],
            'is_active' => ['nullable', 'boolean'],
        ], [
            'user_id.unique' => 'Este usuario ya está en el staff.',
        ]);

        StaffMember::create([...$validated, 'tenant_id' => $tenantId]);

        return back()->with('success', 'Miembro agregado al staff.');
    }

    /**
     * Crea un User nuevo + lo enrola como StaffMember en un solo flow.
     * Útil cuando quieres invitar a alguien que aún no tiene cuenta.
     */
    public function invite(Request $request)
    {
        $tenantId = app('tenant')->id;

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:120'],
            'email'    => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
            'password' => ['required', 'confirmed', Password::min(8)],
            'team_id'  => [
                'nullable', 'integer',
                Rule::exists('teams', 'id')->where('tenant_id', $tenantId),
            ],
            'role' => ['required', Rule::in(self::ROLES)],
        ]);

        $user = User::create([
            'tenant_id' => $tenantId,
            'name'      => $validated['name'],
            'email'     => $validated['email'],
            'password'  => Hash::make($validated['password']),
        ]);

        StaffMember::create([
            'tenant_id' => $tenantId,
            'user_id'   => $user->id,
            'team_id'   => $validated['team_id'] ?? null,
            'role'      => $validated['role'],
            'is_active' => true,
        ]);

        return back()->with('success', "{$user->name} fue invitado al staff.");
    }

    public function update(Request $request, StaffMember $staff)
    {
        $this->authorizeTenantOwnership($staff);

        $validated = $request->validate([
            'team_id' => [
                'nullable', 'integer',
                Rule::exists('teams', 'id')->where('tenant_id', $staff->tenant_id),
            ],
            'role'      => ['required', Rule::in(self::ROLES)],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $staff->update($validated);

        return back()->with('success', 'Staff actualizado.');
    }

    /**
     * Toggle activo/inactivo inline desde la card.
     */
    public function toggle(StaffMember $staff)
    {
        $this->authorizeTenantOwnership($staff);

        // Salvaguarda: no permitir auto-desactivarse (te dejaría afuera).
        if ($staff->user_id === request()->user()->id && $staff->is_active) {
            return back()->withErrors(['toggle' => 'No puedes desactivarte a ti mismo.']);
        }

        $staff->update(['is_active' => ! $staff->is_active]);

        return back()->with('success', $staff->is_active ? 'Staff activado.' : 'Staff desactivado.');
    }

    public function destroy(StaffMember $staff)
    {
        $this->authorizeTenantOwnership($staff);

        // Salvaguarda: no permitir auto-eliminarse.
        if ($staff->user_id === request()->user()->id) {
            return back()->withErrors(['delete' => 'No puedes eliminarte a ti mismo del staff.']);
        }

        $staff->delete();

        return back()->with('success', 'Staff eliminado.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function authorizeTenantOwnership(StaffMember $staff): void
    {
        abort_if($staff->tenant_id !== app('tenant')->id, 403, 'No autorizado para este tenant.');
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId): array
    {
        $base = StaffMember::query()->where('tenant_id', $tenantId);

        return [
            'total'    => (clone $base)->count(),
            'active'   => (clone $base)->where('is_active', true)->count(),
            'inactive' => (clone $base)->where('is_active', false)->count(),
            'admins'   => (clone $base)->where('role', 'admin')->count(),
            'agents'   => (clone $base)->where('role', 'agent')->count(),
            'viewers'  => (clone $base)->where('role', 'viewer')->count(),
        ];
    }
}
