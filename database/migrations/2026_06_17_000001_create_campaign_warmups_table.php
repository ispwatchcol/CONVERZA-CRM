<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    public function up(): void
    {
        Schema::create('campaign_warmups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();

            // El "calentamiento" (warm-up) es por NÚMERO de WhatsApp; hoy hay un
            // número por tenant, así que se llavea por tenant_id (único). Si un
            // tenant llega a tener varios números, esto evoluciona a
            // (tenant_id, wa_phone_number_id).
            $table->boolean('enabled')->default(false);     // opt-in: no cambia campañas existentes
            $table->date('started_on')->nullable();          // ancla de la rampa (día 0)
            $table->unsignedInteger('start_per_day')->default(50);
            $table->unsignedInteger('daily_increment')->default(50);
            $table->unsignedInteger('max_per_day')->default(1000);

            $table->timestamps();

            $table->unique('tenant_id');
        });

        // El presupuesto diario se calcula contando los destinatarios contactados
        // por el tenant en las últimas 24h. Índice para que ese COUNT por tick
        // (cada minuto, por tenant) sea barato.
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->index(['tenant_id', 'sent_at']);
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'sent_at']);
        });

        Schema::dropIfExists('campaign_warmups');
    }
};
