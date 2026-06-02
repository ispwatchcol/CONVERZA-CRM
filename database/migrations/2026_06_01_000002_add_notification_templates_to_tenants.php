<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mapeo por-tenant de qué plantilla aprobada usar en los envíos automáticos.
 * Cada tenant nombra sus plantillas distinto (ej. Colombianet Tocaima usa
 * `envio_factura` y `recordatorio_pago_factura`), así que la asociación
 * "factura generada" / "recordatorio" se guarda aquí y se configura en
 * Configuración → WhatsApp.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->string('wa_invoice_template', 100)->nullable()->after('wa_status');
            $table->string('wa_reminder_template', 100)->nullable()->after('wa_invoice_template');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['wa_invoice_template', 'wa_reminder_template']);
        });
    }
};
