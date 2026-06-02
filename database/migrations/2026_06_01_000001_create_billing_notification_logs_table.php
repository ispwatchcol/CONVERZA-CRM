<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de los envíos automáticos por fechas del router (ispwatch):
 * "factura generada" (kind=invoice_created) y "recordatorio de pago"
 * (kind=payment_reminder).
 *
 * Cumple DOS funciones:
 *   1. Idempotencia: Converza lee ispwatch en SOLO LECTURA, no puede marcar
 *      `invoices.last_reminder_sent` allá. Una fila por (tenant, kind, cliente,
 *      ciclo) impide reenviar el mismo aviso dos veces en el mismo ciclo.
 *   2. Bitácora visible en el sidebar: registra TODO intento — enviado,
 *      omitido (con el motivo) o fallido — para que el ISP entienda por qué un
 *      aviso no salió.
 *
 * El comando corre a diario (catch-up): la misma fila se actualiza vía
 * updateOrCreate hasta llegar a `sent`, así no se acumulan filas por día.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_notification_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Lado ispwatch (no son FK: otra BD / solo lectura).
            $table->unsignedBigInteger('ispwatch_tenant_id')->nullable();
            $table->unsignedBigInteger('ispwatch_customer_id');

            // invoice_created = aviso de factura generada · payment_reminder = recordatorio.
            $table->string('kind', 30);

            $table->string('customer_name')->nullable();
            $table->string('phone', 20)->nullable();

            $table->unsignedBigInteger('ispwatch_invoice_id')->nullable();
            $table->string('invoice_number', 60)->nullable();
            $table->unsignedBigInteger('billing_id')->nullable();
            $table->string('router_name')->nullable();

            // Ciclo de facturación (YYYY-MM del mes de la corrida). Junto con
            // (tenant, kind, cliente) define "este aviso ya salió este ciclo".
            $table->string('cycle_key', 20);

            $table->string('channel', 20)->default('whatsapp');
            $table->string('template', 100)->nullable();

            // sent | skipped | failed
            $table->string('status', 20)->default('skipped');
            $table->text('reason')->nullable();

            $table->string('wa_message_id')->nullable();
            $table->timestamp('sent_at')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'kind', 'ispwatch_customer_id', 'cycle_key'],
                'billing_notif_unique_per_cycle'
            );
            $table->index(['tenant_id', 'status', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_notification_logs');
    }
};
