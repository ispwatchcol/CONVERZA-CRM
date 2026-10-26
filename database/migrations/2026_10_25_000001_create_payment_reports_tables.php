<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Comprobantes de pago leídos con IA (Fase 1 del agente IA). Converza lee el
    // comprobante, lo verifica y lo deja como «pago reportado»; el dinero lo
    // registra SIEMPRE ispwatch, por su API (la base es de solo lectura). Ver
    // docs/agente-ia.md.
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // Opt-in por empresa: con la IA encendida, las imágenes de los
            // comprobantes salen a un proveedor externo para leerlas.
            $table->boolean('ai_enabled')->default(false);
            // Tope de gasto mensual en USD. null = config('ai.default_monthly_budget_usd').
            $table->decimal('ai_monthly_budget_usd', 8, 2)->nullable();
            // Tope por comprobante para registrarlo sin revisión. null = config.
            $table->decimal('receipts_auto_max_amount', 14, 2)->nullable();
            // Credencial de la API de ispwatch para registrar pagos, CIFRADA. La
            // emite ispwatch para UNA empresa: filtrada, solo sirve en esa.
            $table->text('ispwatch_api_token')->nullable();
        });

        // Dónde le pagan los clientes al ISP. Sirve para dos cosas: decírselo al
        // cliente ({{empresa.medios_pago}}) y comprobar que el comprobante sea de
        // una consignación a una cuenta DEL ISP. Cada canal se cuelga de una forma
        // de pago de ispwatch (Efectivo, Transacción…), que es con la que se
        // registra el pago allá.
        Schema::create('payment_channels', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // payment_methods.id de ispwatch. Sin FK: es otra base.
            $table->unsignedBigInteger('ispwatch_payment_method_id')->nullable();
            $table->string('account_number', 60)->nullable();
            $table->string('account_holder', 120)->nullable();
            $table->string('instructions', 500)->nullable();
            $table->boolean('show_in_bot')->default(true);
            $table->boolean('is_active')->default(true);
            $table->integer('sort_order')->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // Un comprobante que mandó un cliente, de la lectura al registro.
        Schema::create('payment_reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // nullOnDelete y no cascada: si alguien borra el chat, el rastro de
            // un pago (y el id del pago en ispwatch) tiene que sobrevivir.
            $table->foreignId('conversation_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('contact_id')->nullable()->constrained()->nullOnDelete();
            // UN reporte por imagen: leerla dos veces devuelve el mismo reporte.
            $table->foreignId('message_id')->nullable()->unique()->constrained()->nullOnDelete();
            $table->foreignId('bot_flow_run_id')->nullable()->constrained()->nullOnDelete();

            // flow = lo pidió el bloque «Comprobante de pago»; chat = un asesor.
            $table->string('source', 10);
            $table->foreignId('requested_by')->nullable()->constrained('users')->nullOnDelete();

            // reading → not_receipt | pending_review | registering → registered
            // pending_review → registering | registered | rejected
            // duplicate (otro reporte ya trae esa referencia o esa imagen)
            $table->string('status', 20);
            // El bloque pidió registrar sin revisión (si pasa todo).
            $table->boolean('auto_requested')->default(false);
            // Por qué quedó donde quedó, en código estable (revision_manual,
            // cuenta_no_coincide, ia_apagada…). La pantalla lo traduce.
            $table->string('reason', 60)->nullable();

            // ── Lo que dice el comprobante ──────────────────────────────────
            $table->string('image_hash', 64)->nullable();
            $table->decimal('ai_confidence', 4, 3)->nullable();
            $table->decimal('amount', 14, 2)->nullable();
            $table->string('currency', 3)->nullable();
            $table->date('paid_on')->nullable();
            $table->string('reference', 80)->nullable();
            // La referencia normalizada (mayúsculas, sin espacios ni guiones):
            // es la llave anti-duplicados.
            $table->string('reference_key', 80)->nullable();
            $table->string('channel', 60)->nullable();
            $table->string('destination_account', 60)->nullable();
            $table->string('destination_holder', 120)->nullable();
            $table->string('payer_name', 120)->nullable();
            // Salida cruda del modelo y resultado de cada verificación.
            $table->json('extraction')->nullable();
            $table->json('checks')->nullable();

            // ── A quién se le abona ─────────────────────────────────────────
            $table->unsignedBigInteger('ispwatch_customer_id')->nullable();
            $table->string('customer_name', 160)->nullable();
            // telefono | telefono_ambiguo | manual
            $table->string('customer_match', 20)->nullable();
            $table->foreignId('payment_channel_id')->nullable()->constrained()->nullOnDelete();

            // ── El registro en ispwatch ─────────────────────────────────────
            $table->unsignedBigInteger('ispwatch_payment_id')->nullable();
            // Respuesta de ispwatch: facturas abonadas y reconexión.
            $table->json('registration')->nullable();
            $table->timestamp('registered_at')->nullable();
            // null con status=registered = lo registró el bot.
            $table->foreignId('registered_by')->nullable()->constrained('users')->nullOnDelete();
            // Lo registró alguien directamente en ispwatch y solo se marcó aquí.
            $table->boolean('registered_manually')->default(false);

            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('review_note', 500)->nullable();

            $table->unsignedSmallInteger('attempts')->default(0);
            $table->string('last_error', 500)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status', 'id']);
            $table->index(['tenant_id', 'reference_key']);
            $table->index(['tenant_id', 'image_hash']);
            $table->index(['tenant_id', 'ispwatch_customer_id', 'registered_at']);
        });

        // Cada llamada al proveedor de IA: para cobrarla, ponerle tope y
        // diagnosticarla. Sin el contenido (comprobantes y chats son datos
        // personales): solo tokens, costo, latencia y error.
        Schema::create('ai_usage_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('purpose', 20);
            $table->string('provider', 20);
            $table->string('model', 60);
            $table->string('status', 10);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->unsignedInteger('reasoning_tokens')->default(0);
            $table->unsignedInteger('cached_tokens')->default(0);
            $table->decimal('cost_usd', 12, 6)->default(0);
            // true = calculado con los precios de config, no informado por el proveedor.
            $table->boolean('cost_estimated')->default(false);
            $table->unsignedInteger('latency_ms')->nullable();
            $table->string('error', 300)->nullable();
            $table->string('subject_type', 40)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_usage_logs');
        Schema::dropIfExists('payment_reports');
        Schema::dropIfExists('payment_channels');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['ai_enabled', 'ai_monthly_budget_usd', 'receipts_auto_max_amount', 'ispwatch_api_token']);
        });
    }
};
