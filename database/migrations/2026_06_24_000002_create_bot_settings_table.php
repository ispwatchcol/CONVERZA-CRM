<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_settings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->unique()->constrained()->cascadeOnDelete();

            // Interruptor maestro: si está en false el bot no responde aunque
            // bot_active=true en la conversación.
            $table->boolean('bot_enabled')->default(false);

            // Mensajes configurables. Guardados en DB para que el admin los edite
            // sin tocar código. Los valores default se envían desde el controlador
            // cuando aún no existe la fila para este tenant.
            $table->text('msg_greeting');
            $table->text('msg_info');
            $table->text('msg_socio');
            $table->text('msg_demo');
            $table->text('msg_price');
            $table->text('msg_handoff');
            $table->text('msg_fallback_1');
            $table->text('msg_fallback_2');

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_settings');
    }
};
