<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // `plan_key` referencia una clave del catálogo App\Services\Brain\PlanCatalog
    // (p. ej. ispwatch_crecimiento, converza_pro, combo_800). Los dos productos de
    // un combo comparten el mismo combo_* como plan_key. `plan` (ya existente) sigue
    // guardando la etiqueta legible; `plan_key` es la clave estable para reportes.
    public function up(): void
    {
        Schema::table('account_products', function (Blueprint $table) {
            $table->string('plan_key', 60)->nullable()->after('product');
            $table->index('plan_key');
        });
    }

    public function down(): void
    {
        Schema::table('account_products', function (Blueprint $table) {
            $table->dropIndex(['plan_key']);
            $table->dropColumn('plan_key');
        });
    }
};
