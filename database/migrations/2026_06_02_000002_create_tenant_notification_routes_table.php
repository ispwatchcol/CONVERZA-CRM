<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Enrutamiento dinámico de avisos: por cada (tenant, evento) define QUÉ plantilla
 * usar y si el aviso está activo. Reemplaza las dos columnas fijas
 * tenants.wa_invoice_template / wa_reminder_template, que no escalaban a más
 * eventos.
 *
 * Una fila por (tenant, event_key). Los eventos válidos los define
 * App\Services\Notifications\EventCatalog. Agregar un evento nuevo no requiere
 * tocar el schema: basta una fila aquí.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenant_notification_routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Clave del evento del catálogo (invoice_created, payment_reminder, …).
            $table->string('event_key', 50);

            // Plantilla asignada. nullOnDelete: si borran la plantilla, la ruta
            // queda sin plantilla (aviso "configurado pero sin plantilla") en vez
            // de desaparecer, para que el cliente lo note.
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();

            $table->boolean('enabled')->default(false);

            $table->timestamps();

            $table->unique(['tenant_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenant_notification_routes');
    }
};
