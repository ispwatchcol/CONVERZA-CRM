<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Propósito de la plantilla dentro del catálogo de eventos (App\Services\Notifications\EventCatalog):
 * invoice_created, payment_reminder, … null = plantilla "general" sin un evento asociado.
 *
 * Tagear la plantilla con su evento es lo que permite, al crearla, mostrar las
 * variables disponibles de ese evento, y en Settings ofrecer solo las plantillas
 * que sirven para cada aviso.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->string('event_key', 50)->nullable()->after('category');
            $table->index(['tenant_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::table('templates', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'event_key']);
            $table->dropColumn('event_key');
        });
    }
};
