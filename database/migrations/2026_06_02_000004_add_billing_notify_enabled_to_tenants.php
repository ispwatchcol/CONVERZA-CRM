<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Interruptor MAESTRO por tenant de los avisos automáticos. Es el "botón de
 * pausa" que el cliente controla desde Configuración → Avisos automáticos:
 * cuando está en false, el comando whatsapp:billing-notify NO envía nada a ese
 * tenant, sin importar las rutas por evento.
 *
 * Default true: un tenant ya configurado envía a menos que lo pause a propósito.
 * (El interruptor GLOBAL del SaaS sigue siendo WHATSAPP_BILLING_NOTIFY_ENABLED.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('billing_notify_enabled')->default(true)->after('wa_status');
        });
    }

    public function down(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn('billing_notify_enabled');
        });
    }
};
