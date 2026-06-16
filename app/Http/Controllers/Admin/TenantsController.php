<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\StaffMember;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;

/**
 * Gestión de TENANTS a nivel SaaS — solo accesible por superadmins.
 * Cross-tenant: ve y modifica datos de TODOS los tenants.
 *
 * NO confundir con el role 'admin' de staff_members, que es admin DENTRO de un tenant.
 */
class TenantsController extends Controller
{
    public function __construct(protected IspwatchRepository $ispwatch) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));

        $query = Tenant::query()
            ->withCount(['users', 'contacts', 'conversations']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('slug', 'like', "%{$search}%");
            });
        }

        $allTenants = $query->orderByDesc('created_at')->get();

        // Resolver TODOS los links de ispwatch en un solo batch (3 queries),
        // en vez de 3 queries por cada tenant dentro del map (N+1 remoto).
        $ispwatchIds   = $allTenants->pluck('ispwatch_tenant_id')->filter()->all();
        $ispwatchInfos = $ispwatchIds !== []
            ? $this->ispwatch->tenantInfoBatch($ispwatchIds)
            : [];

        $tenants = $allTenants->map(function (Tenant $t) use ($ispwatchInfos) {
            // Status del link ISPWatch (resuelto en el batch previo)
            $ispwatchStatus = null;
            if ($t->ispwatch_tenant_id) {
                $info = $ispwatchInfos[(int) $t->ispwatch_tenant_id] ?? null;
                $ispwatchStatus = $info
                    ? ['connected' => true, 'name' => $info['name'], 'customers' => $info['customers_count']]
                    : ['connected' => false, 'message' => "Tenant #{$t->ispwatch_tenant_id} no existe en ispwatch"];
            }

            return [
                'id'                  => $t->id,
                'name'                => $t->name,
                'slug'                => $t->slug,
                'is_active'           => (bool) $t->is_active,
                'ispwatch_tenant_id'  => $t->ispwatch_tenant_id,
                'ispwatch_status'     => $ispwatchStatus,
                'has_whatsapp'        => $t->hasWhatsAppConfigured(),
                'wa_status'           => $t->wa_status,
                'users_count'         => (int) $t->users_count,
                'contacts_count'      => (int) $t->contacts_count,
                'conversations_count' => (int) $t->conversations_count,
                'created_at'          => $t->created_at?->toIso8601String(),
            ];
        });

        return Inertia::render('Admin/Tenants/Index', [
            'tenants' => $tenants,
            'filters' => ['search' => $search],
            'stats'   => $this->stats(),
        ]);
    }

    /**
     * Crea un tenant nuevo + el primer User admin + su StaffMember en una transacción.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            // Tenant
            'name'               => ['required', 'string', 'max:120'],
            'slug'               => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')],
            'ispwatch_tenant_id' => ['nullable', 'integer', 'min:1'],

            // Primer admin del tenant
            'admin_name'     => ['required', 'string', 'max:120'],
            'admin_email'    => ['required', 'email', 'max:120', Rule::unique('users', 'email')],
            'admin_password' => ['required', 'confirmed', Password::min(8)],
        ], [
            'slug.regex'  => 'Slug solo puede tener minúsculas, números y guiones.',
            'slug.unique' => 'Ya existe un tenant con ese slug.',
        ]);

        // Validar contra ispwatch ANTES de crear (si se pasó un id)
        if (! empty($validated['ispwatch_tenant_id'])) {
            if ($this->ispwatch->tenantInfo((int) $validated['ispwatch_tenant_id']) === null) {
                return back()->withErrors([
                    'ispwatch_tenant_id' => 'No existe un tenant con ese ID en ispwatch.',
                ]);
            }
        }

        $tenant = DB::transaction(function () use ($validated) {
            $tenant = Tenant::create([
                'name'               => $validated['name'],
                'slug'               => $validated['slug'],
                'ispwatch_tenant_id' => $validated['ispwatch_tenant_id'] ?? null,
                'is_active'          => true,
            ]);

            $user = User::create([
                'tenant_id'     => $tenant->id,
                'name'          => $validated['admin_name'],
                'email'         => $validated['admin_email'],
                'password'      => Hash::make($validated['admin_password']),
                'is_superadmin' => false,
            ]);

            StaffMember::create([
                'tenant_id' => $tenant->id,
                'user_id'   => $user->id,
                'role'      => 'admin',
                'is_active' => true,
            ]);

            return $tenant;
        });

        return back()->with('success', "Tenant '{$tenant->name}' creado. El admin puede entrar con {$validated['admin_email']}.");
    }

    public function update(Request $request, Tenant $tenant)
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'slug'               => ['required', 'string', 'max:60', 'regex:/^[a-z0-9-]+$/', Rule::unique('tenants', 'slug')->ignore($tenant->id)],
            'is_active'          => ['required', 'boolean'],
            'ispwatch_tenant_id' => ['nullable', 'integer', 'min:1'],
        ]);

        if (! empty($validated['ispwatch_tenant_id'])
            && $validated['ispwatch_tenant_id'] != $tenant->ispwatch_tenant_id
        ) {
            if ($this->ispwatch->tenantInfo((int) $validated['ispwatch_tenant_id']) === null) {
                return back()->withErrors([
                    'ispwatch_tenant_id' => 'No existe un tenant con ese ID en ispwatch.',
                ]);
            }
        }

        $tenant->update($validated);

        return back()->with('success', "Tenant '{$tenant->name}' actualizado.");
    }

    public function destroy(Tenant $tenant)
    {
        // Salvaguarda: no permitir eliminar el tenant del superadmin actual
        // (te dejaría sin acceso a tu propio workspace).
        $myTenantId = request()->user()->tenant_id;
        if ($tenant->id === $myTenantId) {
            return back()->withErrors(['delete' => 'No puedes eliminar tu propio tenant. Crea otro superadmin primero y trabaja desde allá.']);
        }

        // Cuidado: borrar un tenant deja huérfanos a users/conversations/etc.
        // Por seguridad solo lo desactivamos en vez de hard-delete.
        $tenant->update(['is_active' => false]);

        return back()->with('success', "Tenant '{$tenant->name}' desactivado. Los datos quedan intactos para auditoría.");
    }

    /**
     * @return array<string, int>
     */
    private function stats(): array
    {
        $base = Tenant::query();

        return [
            'total'                => (clone $base)->count(),
            'active'               => (clone $base)->where('is_active', true)->count(),
            'with_whatsapp'        => (clone $base)->whereNotNull('wa_phone_number_id')->count(),
            'linked_to_ispwatch'   => (clone $base)->whereNotNull('ispwatch_tenant_id')->count(),
        ];
    }
}
