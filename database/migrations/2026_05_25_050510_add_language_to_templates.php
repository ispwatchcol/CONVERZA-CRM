<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            // Meta exige language para cada template (es_CO, es, en_US, etc.).
            // Default es_CO porque el caso de uso principal del SaaS es Colombia.
            $table->string('language', 10)->default('es_CO')->after('category');

            // Plantillas sincronizadas desde Meta vienen con timestamp; lo guardamos
            // para saber cuándo fue la última sincronización por template.
            $table->timestamp('last_synced_at')->nullable()->after('meta_id');
        });

        // Backfill defensivo de filas existentes (default ya las cubre, pero por si acaso).
        \DB::table('templates')->whereNull('language')->update(['language' => 'es_CO']);
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['language', 'last_synced_at']);
        });
    }
};
