<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            // Payload crudo de WhatsApp para mensajes que no pudimos renderizar
            // "tal cual" (type=unsupported, tipos nuevos de Meta sin caso propio).
            // El body guarda un texto amigable para el agente; esto guarda el
            // detalle técnico (código/título de error de Meta, o el payload del
            // tipo desconocido) para poder auditar el caso después sin depender
            // de los logs, que rotan.
            $table->json('raw_metadata')->nullable()->after('caption');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('messages', function (Blueprint $table) {
            $table->dropColumn('raw_metadata');
        });
    }
};
