<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Componentes opcionales de una plantilla de WhatsApp además del BODY:
 * header (texto), footer (texto) y un botón de URL (ej. link de pago).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('header_text', 60)->nullable()->after('body');
            $table->string('footer_text', 60)->nullable()->after('header_text');
            $table->string('button_text', 25)->nullable()->after('footer_text');
            $table->string('button_url', 2000)->nullable()->after('button_text');
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropColumn(['header_text', 'footer_text', 'button_text', 'button_url']);
        });
    }
};
