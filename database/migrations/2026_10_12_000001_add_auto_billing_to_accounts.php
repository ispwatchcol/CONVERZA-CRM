<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Cobro automático del SaaS: el Brain factura solo cada mes, le avisa al ISP y
    // le corta el panel si la factura vence sin pagar. Tres piezas:
    //
    // 1. En la cuenta, el interruptor y sus dos perillas. Nace APAGADO: encenderlo
    //    es una decisión por cliente, no algo que le cae a todos en el deploy.
    // 2. En la factura, de dónde salió y a qué mes corresponde. La terna
    //    (cuenta, moneda, mes) es única: es el candado que impide facturar dos
    //    veces el mismo mes aunque el comando corra cada hora.
    // 3. La bitácora de avisos de cobro, que además es la idempotencia del envío.
    public function up(): void
    {
        Schema::table('accounts', function (Blueprint $table) {
            $table->boolean('auto_billing_enabled')->default(false);
            // Desde cuándo factura solo. Los meses anteriores NO se cobran aunque
            // falten: puede que se hayan cobrado por fuera, y una factura que
            // aparece sola por un mes viejo termina en un corte injusto.
            $table->date('auto_billing_since')->nullable();
            // Días entre la emisión y el vencimiento. Vencida sin pagar = corte.
            $table->unsignedTinyInteger('billing_grace_days')->default(5);

            $table->timestamp('suspended_at')->nullable();
            // 'billing' = lo cortó el cobro automático, y el pago lo reactiva solo.
            // 'manual'  = lo cortó una persona, y solo una persona lo levanta.
            $table->string('suspension_reason', 20)->nullable();
        });

        Schema::table('account_invoices', function (Blueprint $table) {
            $table->string('source', 10)->default('manual');
            // 'YYYY-MM' del inicio del período. Solo lo llenan las automáticas.
            $table->string('billing_period', 7)->nullable();
            // NULL no choca con NULL (Postgres y SQLite), así que las facturas
            // manuales siguen sin restricción.
            $table->unique(['account_id', 'currency', 'billing_period']);
        });

        Schema::create('account_billing_notices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_id')->constrained()->cascadeOnDelete();
            $table->foreignId('account_invoice_id')->nullable()->constrained()->nullOnDelete();

            $table->enum('kind', ['factura', 'recordatorio', 'suspension', 'reactivacion']);
            // Llave de idempotencia junto a (cuenta, tipo): 'inv:12' para los avisos
            // de una factura, 'susp:<timestamp>' para los de un episodio de corte.
            $table->string('ref', 40);

            $table->enum('status', ['sent', 'skipped', 'failed']);
            $table->enum('channel', ['whatsapp_text', 'whatsapp_template', 'none'])->default('none');
            $table->string('phone', 30)->nullable();
            $table->string('template')->nullable();
            $table->string('wa_message_id')->nullable();
            $table->string('reason')->nullable();

            $table->timestamps();

            $table->unique(['account_id', 'kind', 'ref']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('account_billing_notices');

        Schema::table('account_invoices', function (Blueprint $table) {
            $table->dropUnique(['account_id', 'currency', 'billing_period']);
            $table->dropColumn(['source', 'billing_period']);
        });

        Schema::table('accounts', function (Blueprint $table) {
            $table->dropColumn([
                'auto_billing_enabled', 'auto_billing_since', 'billing_grace_days',
                'suspended_at', 'suspension_reason',
            ]);
        });
    }
};
