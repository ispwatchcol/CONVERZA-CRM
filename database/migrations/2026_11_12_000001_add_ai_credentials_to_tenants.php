<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Cada empresa usa SU llave de IA (OpenAI o xAI), no una de Converza: el uso
    // se le cobra directo a ella y una llave filtrada solo expone a su dueña.
    // Ver docs/agente-ia.md §2.6.
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // xai | openai. null = todavía no eligió.
            $table->string('ai_provider', 20)->nullable();
            // La llave del proveedor, CIFRADA (cast encrypted). Nunca vuelve al navegador.
            $table->text('ai_api_key')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['ai_provider', 'ai_api_key']);
        });
    }
};
