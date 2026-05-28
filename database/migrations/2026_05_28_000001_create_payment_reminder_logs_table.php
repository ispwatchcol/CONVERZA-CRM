<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bitácora de recordatorios de pago enviados por Converza.
 *
 * Converza lee ispwatch en SOLO LECTURA, así que no puede marcar
 * `invoices.last_reminder_sent` allá. Esta tabla es la fuente de idempotencia:
 * una fila por (tenant, factura de ispwatch, ciclo) evita reenviar el mismo
 * recordatorio dos veces en el mismo ciclo de facturación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_reminder_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // Identificadores del lado ispwatch (no son FK: otra BD).
            $table->unsignedBigInteger('ispwatch_invoice_id');
            $table->unsignedBigInteger('ispwatch_customer_id');

            // Clave de ciclo (period_start de la factura, o YYYY-MM si viene null).
            // Junto con la factura define "este recordatorio ya salió este ciclo".
            $table->string('cycle_key', 20);

            $table->string('channel', 20)->default('whatsapp');
            $table->string('phone', 20)->nullable();
            $table->string('template', 100)->nullable();
            $table->string('status', 20)->default('sent'); // sent | failed
            $table->string('wa_message_id')->nullable();
            $table->text('error')->nullable();

            $table->timestamps();

            $table->unique(
                ['tenant_id', 'ispwatch_invoice_id', 'cycle_key'],
                'reminder_unique_per_cycle'
            );
            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_reminder_logs');
    }
};
