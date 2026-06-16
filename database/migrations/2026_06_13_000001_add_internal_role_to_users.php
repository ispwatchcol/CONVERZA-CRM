<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Rol del equipo interno del founder. null = usuario normal de tenant.
            // Valores válidos: 'owner' | 'viewer'
            // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
            $table->string('internal_role', 20)->nullable()->after('is_superadmin');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('internal_role');
        });
    }
};
