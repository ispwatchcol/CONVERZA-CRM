<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bot_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('conversation_id')->index();

            // Texto del mensaje del cliente que disparó esta respuesta del bot.
            $table->text('incoming_body')->nullable();

            // Intención detectada: demo, info, socio, price, agent, greeting,
            // qualifying_name, fallback_1, fallback_2, handoff, unknown.
            $table->string('intent_detected', 50)->nullable();

            // Texto exacto que el bot envió como respuesta.
            $table->text('bot_response')->nullable();

            // true si esta interacción terminó en handoff a humano.
            $table->boolean('escalated')->default(false);

            // Datos calificadores acumulados hasta este punto:
            // { "intent": "demo", "suscriptores": "500", "nombre": "Juan" }
            $table->text('context_data')->nullable();

            $table->timestamps();

            $table->index(['tenant_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_logs');
    }
};
