<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // La corre el deploy en `converza` (converza_dev se retiró el 19/11/2026).
    //
    // El agente IA configurable (docs/agente-ia.md §11, Fase 3): la empresa elige
    // proveedor y MODELO, escribe el prompt de su agente y lo pone a conversar
    // con el bloque «Agente IA» del Workspace.
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            // El modelo que eligió de la lista de su proveedor. null = el de config.
            $table->string('ai_model', 120)->nullable();
            // Solo para ai_provider = 'custom' (otra API compatible con OpenAI).
            $table->string('ai_base_url', 255)->nullable();
        });

        // Un agente: un prompt con nombre. La empresa puede tener varios (ventas,
        // soporte) y cada bloque «Agente IA» de un flujo usa uno.
        Schema::create('ai_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 80);
            // El prompt que escribe la empresa. Admite {{variables}} del flujo.
            $table->text('instructions');
            // null = el modelo de la empresa (tenants.ai_model).
            $table->string('model', 120)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        // Una respuesta del agente pedida por un flujo. Es la «tarea» del motor
        // (Step::waitTask): el bloque la crea, el job de la cola `ai` la responde
        // y despierta a la ejecución. Guarda lo que se le mandó a la IA (prompt +
        // historial) para que el job no dependa del flujo.
        Schema::create('ai_agent_turns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ai_agent_id')->nullable()->constrained('ai_agents')->nullOnDelete();
            $table->unsignedBigInteger('bot_flow_run_id')->nullable();
            $table->unsignedBigInteger('conversation_id')->nullable();
            // pending | done | failed | unavailable
            $table->string('status', 15)->default('pending');
            $table->text('instructions');
            $table->json('messages');
            $table->text('reply')->nullable();
            // continue | human | end
            $table->string('action', 10)->nullable();
            // Por qué no hubo respuesta (una llave de PaymentReport::REASONS o el error).
            $table->string('reason', 300)->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'bot_flow_run_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_agent_turns');
        Schema::dropIfExists('ai_agents');

        Schema::table('tenants', function (Blueprint $table) {
            $table->dropColumn(['ai_model', 'ai_base_url']);
        });
    }
};
