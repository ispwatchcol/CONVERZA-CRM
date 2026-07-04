<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de agua (high-water mark) de los avisos disparados por EVENTO —no por
 * fecha del ciclo— que lee `whatsapp:events-notify`:
 *
 *   - service_activated  → bienvenida cuando se activa el servicio de un cliente.
 *   - payment_registered → confirmación cuando se registra un pago.
 *
 * Converza lee ispwatch en SOLO LECTURA: no hay webhooks ni triggers. La única
 * forma de detectar "lo nuevo" es hacer polling incremental de la tabla origen
 * (user_services / payments), guardando aquí el último `id` ya procesado por
 * cada (tenant, evento). Cada corrida trae solo `id > last_id`.
 *
 * Cold-start: la PRIMERA vez que un evento se activa, `last_id` se siembra en el
 * MAX(id) actual de ispwatch y NO se envía nada — así solo reciben aviso las
 * altas/pagos POSTERIORES a la activación, nunca la base histórica completa.
 *
 * Una fila por (tenant, event_key). Sembrar un evento nuevo no requiere schema.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ispwatch_event_cursors', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Clave del evento del catálogo (service_activated, payment_registered).
            $table->string('event_key', 50);

            // Último id de la tabla origen de ispwatch ya procesado (user_services.id
            // o payments.id). null = aún sin sembrar (la próxima corrida hace baseline).
            $table->unsignedBigInteger('last_id')->nullable();

            // created_at del último registro visto (observabilidad / depuración).
            $table->timestamp('last_seen_at')->nullable();

            $table->timestamps();

            $table->unique(['tenant_id', 'event_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ispwatch_event_cursors');
    }
};
