<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Al activar el enforcement de roles (middleware 'role'), la gestión de
 * staff/equipos pasa a ser exclusiva del rol 'admin'. Esta migración garantiza
 * que cada tenant que ya tiene staff conserve al menos un admin: si ninguno lo
 * es, promueve al StaffMember más antiguo. Así nadie queda incapaz de
 * administrar su propio tenant. Es idempotente.
 */
return new class extends Migration {
    public function up(): void
    {
        $tenantIds = DB::table('staff_members')->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            $hasAdmin = DB::table('staff_members')
                ->where('tenant_id', $tenantId)
                ->where('role', 'admin')
                ->exists();

            if ($hasAdmin) {
                continue;
            }

            $oldest = DB::table('staff_members')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->first();

            if ($oldest) {
                DB::table('staff_members')
                    ->where('id', $oldest->id)
                    ->update([
                        'role'       => 'admin',
                        'is_active'  => true,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // No se revierte: degradar admins podría dejar tenants sin gestor.
    }
};
