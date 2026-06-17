<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    // CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
    //
    // Fase 2 de campañas: secuencias multi-paso (estilo Smartlead). Una campaña
    // pasa de ser "un disparo" a una secuencia de N pasos; cada destinatario es
    // una "inscripción" que avanza por los pasos. Para no perder lo existente,
    // el up() hace BACKFILL: cada campaña actual se vuelve una secuencia de 1
    // paso y cada envío ya hecho se copia a campaign_sends. Cero pérdida de datos.
    public function up(): void
    {
        // Definición de la secuencia: los pasos de cada campaña.
        Schema::create('campaign_steps', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();

            $table->unsignedSmallInteger('step_order'); // 1 = envío inicial
            $table->foreignId('template_id')->nullable()->constrained('templates')->nullOnDelete();
            $table->json('variable_mapping')->nullable(); // mapeo propio del paso

            // Espera tras el paso anterior, en horas (la UI ofrece días+horas).
            $table->unsignedInteger('delay_hours')->default(0);
            // Condición para enviar este paso (se evalúa contra el historial del
            // destinatario). El paso 1 siempre es 'always'.
            $table->string('send_condition', 20)->default('if_not_replied');

            $table->timestamps();

            $table->unique(['campaign_id', 'step_order']);
            $table->index('tenant_id');
        });

        // Un registro por MENSAJE real enviado (paso × destinatario). Acá viven
        // ahora wa_message_id y los timestamps de estado (antes en recipients).
        Schema::create('campaign_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_id')->constrained('campaign_recipients')->cascadeOnDelete();
            $table->foreignId('step_id')->nullable()->constrained('campaign_steps')->nullOnDelete();

            $table->unsignedSmallInteger('step_order');
            $table->string('status', 20)->default('queued'); // queued|sent|delivered|read|failed|skipped
            $table->string('wa_message_id')->nullable();
            $table->text('error')->nullable();

            $table->timestamp('queued_at')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('read_at')->nullable();
            $table->timestamp('replied_at')->nullable();
            $table->timestamp('failed_at')->nullable();

            $table->timestamps();

            $table->unique(['recipient_id', 'step_order']); // un envío por paso por destinatario
            $table->index('wa_message_id');
            $table->index(['tenant_id', 'sent_at']);          // para el conteo de warm-up
            $table->index(['campaign_id', 'step_order']);     // para el embudo por paso
        });

        // campaign_recipients pasa a ser la INSCRIPCIÓN: dónde va cada destinatario
        // dentro de la secuencia. Las columnas de envío que ya tenía quedan como
        // legacy (no se borran: guardan datos y el warm-up las leía).
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->unsignedSmallInteger('current_step')->default(1)->after('variables');
            // active|sending|completed|replied|opted_out|failed (varchar, no enum,
            // para no pelear con el CHECK de Postgres al alterar).
            $table->string('enrollment_status', 20)->default('active')->after('current_step');
            $table->timestamp('next_action_at')->nullable()->after('enrollment_status'); // cuándo toca el próximo paso

            $table->index(['campaign_id', 'enrollment_status', 'next_action_at'], 'cr_sequence_idx');
        });

        $this->backfill();
    }

    /**
     * Convierte lo existente al modelo de secuencias: 1 paso por campaña, los
     * envíos hechos a campaign_sends y el enrollment_status de cada destinatario.
     * Idempotente (se salta lo ya migrado) por si se corre parcial.
     */
    private function backfill(): void
    {
        DB::table('campaigns')->orderBy('id')->chunkById(200, function ($campaigns) {
            foreach ($campaigns as $c) {
                if (DB::table('campaign_steps')->where('campaign_id', $c->id)->exists()) {
                    continue;
                }
                DB::table('campaign_steps')->insert([
                    'tenant_id' => $c->tenant_id,
                    'campaign_id' => $c->id,
                    'step_order' => 1,
                    'template_id' => $c->template_id,
                    'variable_mapping' => $c->variable_mapping,
                    'delay_hours' => 0,
                    'send_condition' => 'always',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        });

        DB::table('campaign_recipients')->orderBy('id')->chunkById(500, function ($recipients) {
            foreach ($recipients as $r) {
                $step = DB::table('campaign_steps')
                    ->where('campaign_id', $r->campaign_id)
                    ->where('step_order', 1)
                    ->first();

                $hadSend = $r->wa_message_id !== null
                    || in_array($r->status, ['sent', 'delivered', 'read', 'failed'], true);

                if ($hadSend && $step
                    && ! DB::table('campaign_sends')->where('recipient_id', $r->id)->where('step_order', 1)->exists()) {
                    DB::table('campaign_sends')->insert([
                        'tenant_id' => $r->tenant_id,
                        'campaign_id' => $r->campaign_id,
                        'recipient_id' => $r->id,
                        'step_id' => $step->id,
                        'step_order' => 1,
                        'status' => $r->status,
                        'wa_message_id' => $r->wa_message_id,
                        'error' => $r->error,
                        'queued_at' => $r->queued_at,
                        'sent_at' => $r->sent_at,
                        'delivered_at' => $r->delivered_at,
                        'read_at' => $r->read_at,
                        'replied_at' => $r->replied_at,
                        'failed_at' => $r->failed_at,
                        'created_at' => now(),
                        'updated_at' => now(),
                    ]);
                }

                // En 1 paso, cualquier envío ya hecho es terminal.
                $enrollment = match ($r->status) {
                    'pending', 'queued' => 'active',
                    'opted_out' => 'opted_out',
                    default => $r->replied_at !== null ? 'replied' : 'completed',
                };

                DB::table('campaign_recipients')->where('id', $r->id)->update([
                    'current_step' => 1,
                    'enrollment_status' => $enrollment,
                ]);
            }
        });
    }

    public function down(): void
    {
        Schema::table('campaign_recipients', function (Blueprint $table) {
            $table->dropIndex('cr_sequence_idx');
            $table->dropColumn(['current_step', 'enrollment_status', 'next_action_at']);
        });

        Schema::dropIfExists('campaign_sends');
        Schema::dropIfExists('campaign_steps');
    }
};
