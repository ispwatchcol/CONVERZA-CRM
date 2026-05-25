<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * is_superadmin marca a los dueños del SaaS Converza (Axel y futuros co-fundadores).
     * Pueden crear/eliminar tenants, ver listado global, etc.
     *
     * Esto NO es lo mismo que el role 'admin' de staff_members, que solo aplica
     * DENTRO de un tenant específico.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_superadmin')->default(false)->after('email');
        });

        // Backfill: el primer user (id=1) es el dueño del SaaS.
        DB::table('users')->where('id', 1)->update(['is_superadmin' => true]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('is_superadmin');
        });
    }
};
