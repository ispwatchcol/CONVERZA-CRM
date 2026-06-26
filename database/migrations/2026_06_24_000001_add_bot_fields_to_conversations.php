<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            // bot_active: true mientras el bot gestiona esta conversación.
            // Pasa a false cuando un agente toma el hilo (observer) o cuando
            // el bot mismo envía el mensaje de handoff.
            $table->boolean('bot_active')->default(false)->after('team_id');

            // bot_step: posición actual en la máquina de estados del bot.
            // null → aún no ha saludado
            // greeting_sent → saludo enviado, esperando intención
            // qualifying_subscribers → preguntando suscriptores
            // qualifying_name → preguntando nombre
            // handed_off → bot cedió el control
            $table->string('bot_step', 50)->nullable()->after('bot_active');

            // Contador de intentos de detección de intención fallidos.
            // Al llegar a 2, escala a humano automáticamente.
            $table->tinyInteger('bot_failed_intents')->default(0)->after('bot_step');

            // Datos capturados durante la calificación: suscriptores, nombre, ciudad.
            // Almacenado como JSON, disponible en el handoff para el asesor.
            $table->json('bot_context')->nullable()->after('bot_failed_intents');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropColumn(['bot_active', 'bot_step', 'bot_failed_intents', 'bot_context']);
        });
    }
};
