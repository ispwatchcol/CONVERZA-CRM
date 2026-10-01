<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Workspace de flujos del bot (CON-36 / CON-47). Un flujo es un grafo de
    // bloques guardado como datos; el motor lo interpreta conversación por
    // conversación. Ver docs/workflows-bot.md.
    public function up(): void
    {
        Schema::create('bot_flows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 120);
            $table->text('description')->nullable();

            // El BORRADOR: lo que el admin edita. Nunca lo ejecuta el motor; el
            // motor solo corre versiones publicadas. Así nadie le rompe el bot a
            // sus clientes a media edición.
            $table->json('draft');
            $table->timestamp('draft_updated_at')->nullable();
            // Si el borrador se armó restaurando una versión vieja, cuál: la
            // publicación siguiente lo deja anotado en la versión nueva.
            $table->unsignedInteger('draft_source_version')->nullable();

            // Versión que ejecuta el motor. Sin FK a propósito: la referencia es
            // circular con bot_flow_versions y las versiones solo se borran en
            // cascada con su flujo.
            $table->unsignedBigInteger('published_version_id')->nullable();

            // El interruptor del flujo. Solo puede estar encendido con una
            // versión publicada.
            $table->boolean('is_active')->default(false);

            // Disparador de la versión PUBLICADA, copiado al publicar para que el
            // enrutador de mensajes lo consulte sin abrir el grafo.
            $table->string('trigger_type', 30)->nullable();
            $table->json('trigger_config')->nullable();

            // Desempate entre flujos activos cuyo disparador coincide: menor
            // prioridad gana, luego el id más viejo.
            $table->integer('priority')->default(0);

            $table->timestamps();

            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('bot_flow_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_flow_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('version');
            // Copia INMUTABLE del grafo. Una ejecución en curso termina con la
            // versión con la que empezó aunque se publique otra en el medio.
            $table->json('graph');
            $table->string('note', 255)->nullable();
            $table->foreignId('published_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('published_at');
            $table->timestamps();

            $table->unique(['bot_flow_id', 'version']);
        });

        Schema::create('bot_flow_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_flow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_flow_version_id')->constrained()->cascadeOnDelete();
            $table->foreignId('conversation_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('contact_id')->nullable();

            // Igual a conversation_id mientras la ejecución está viva y NULL
            // cuando termina. El índice único garantiza UNA ejecución viva por
            // conversación aunque dos webhooks lleguen en el mismo instante, sin
            // depender de índices parciales: NULL no choca con NULL ni en
            // Postgres ni en SQLite.
            $table->unsignedBigInteger('live_conversation_id')->nullable()->unique();

            // running | waiting_input | waiting_timer
            // completed | handed_off | cancelled | failed
            $table->string('status', 20);
            $table->string('current_node', 64)->nullable();
            $table->json('variables')->nullable();
            // Estado interno del motor (reintentos por bloque).
            $table->json('state')->nullable();

            $table->unsignedBigInteger('trigger_message_id')->nullable();
            // Último mensaje ENTRANTE ya consumido. El motor procesa la bandeja de
            // la conversación en orden a partir de aquí, así que dos mensajes en
            // ráfaga no se procesan desordenados ni dos veces.
            $table->unsignedBigInteger('last_message_id')->nullable();

            $table->timestamp('resume_at')->nullable();
            $table->timestamp('resume_dispatched_at')->nullable();
            $table->unsignedInteger('steps_count')->default(0);
            // Por qué terminó: completed, handoff, assigned, human_replied,
            // flow_deactivated, input_timeout, step_limit, window_closed, error…
            $table->string('ended_reason', 60)->nullable();
            $table->timestamp('last_activity_at')->nullable();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'resume_at']);
            $table->index(['bot_flow_id', 'created_at']);
            $table->index('conversation_id');
        });

        // Traza de cada bloque ejecutado. Es la observabilidad del día uno: qué
        // bloque corrió, con qué entrada, qué salió y —vía message_id— si ese
        // mensaje llegó, lo leyeron o Meta lo rechazó.
        Schema::create('bot_flow_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('bot_flow_run_id')->constrained()->cascadeOnDelete();
            $table->string('node_id', 64);
            $table->string('node_type', 30);
            // La salida que tomó el bloque (next, opt_x, no_match, true, found…)
            // o lo que pasó (sent, waiting, skipped_window, error…).
            $table->string('outcome', 60)->nullable();
            $table->text('input')->nullable();
            $table->text('output')->nullable();
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->json('detail')->nullable();
            $table->timestamp('created_at')->nullable();

            $table->index(['bot_flow_run_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bot_flow_steps');
        Schema::dropIfExists('bot_flow_runs');
        Schema::dropIfExists('bot_flow_versions');
        Schema::dropIfExists('bot_flows');
    }
};
