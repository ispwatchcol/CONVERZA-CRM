<?php

namespace Tests\Feature\Flows;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\BotFlow;
use App\Models\BotFlowRun;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Flows\FlowPublisher;
use App\Services\Flows\GraphBuilder;
use App\Services\WhatsAppService;
use Carbon\CarbonInterface;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Base de las pruebas del Workspace de flujos.
 *
 * El esquema de las tablas que ya existían se arma a mano (las migraciones
 * reales son de Postgres); las tablas de flujos se crean con SU migración
 * real, para que la prueba también cubra la migración.
 */
abstract class FlowsTestCase extends TestCase
{
    protected Tenant $tenant;

    private int $inbound = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->createSchema();

        // slug 'default' = el único tenant al que le aplica la config del .env:
        // sin esto WhatsAppService entra en modo mock y no sale ninguna petición
        // que se pueda afirmar.
        config()->set('services.whatsapp.url', 'https://graph.facebook.com/v20.0/123');
        config()->set('services.whatsapp.token', 'test-token');

        $counter = 0;
        Http::fake(function () use (&$counter) {
            $counter++;

            return Http::response(['messages' => [['id' => 'wamid.OUT' . $counter]]], 200);
        });

        $this->tenant = Tenant::create(['slug' => 'default', 'name' => 'Fibra Chaguaní', 'is_active' => true]);
    }

    protected function createSchema(): void
    {
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('auto_assign_enabled')->default(false);
            $t->unsignedBigInteger('ispwatch_tenant_id')->nullable();
            $t->string('wa_phone_number_id')->nullable();
            $t->text('wa_access_token')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->boolean('is_superadmin')->default(false);
            $t->string('internal_role')->nullable();
            $t->rememberToken();
            $t->timestamps();
        });

        Schema::create('teams', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('name');
            $t->string('description')->nullable();
            $t->string('color')->nullable();
            $t->timestamps();
        });

        Schema::create('staff_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->string('role')->default('agent');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->string('phone')->nullable();
            $t->string('wa_user_id')->nullable();
            $t->string('wa_username')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('avatar')->nullable();
            $t->text('notes')->nullable();
            $t->string('external_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'phone']);
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contact_id');
            $t->string('status')->default('open');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->boolean('bot_active')->default(false);
            $t->string('bot_step')->nullable();
            $t->smallInteger('bot_failed_intents')->default(0);
            $t->json('bot_context')->nullable();
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->text('body')->nullable();
            $t->string('status')->nullable();
            $t->string('wa_message_id')->nullable()->unique();
            $t->string('type')->nullable();
            $t->string('media_id')->nullable();
            $t->string('media_path')->nullable();
            $t->string('media_mime')->nullable();
            $t->string('media_filename')->nullable();
            $t->text('caption')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('sent_by_user_id')->nullable();
            $t->json('raw_metadata')->nullable();
            $t->timestamps();
        });

        Schema::create('labels', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('name');
            $t->string('color')->nullable();
            $t->string('type')->nullable();
            $t->string('description')->nullable();
            $t->timestamps();
        });

        Schema::create('contact_label', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contact_id');
            $t->unsignedBigInteger('label_id');
            $t->timestamps();
        });

        Schema::create('bot_settings', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->unique();
            $t->boolean('bot_enabled')->default(false);
            $t->boolean('schedule_enabled')->default(false);
            $t->string('schedule_mode')->nullable();
            $t->string('schedule_timezone')->nullable();
            $t->json('schedule_days')->nullable();
            $t->string('schedule_start')->nullable();
            $t->string('schedule_end')->nullable();
            foreach (['greeting', 'branches', 'qualify_subscribers', 'qualify_name', 'fallback'] as $step) {
                $t->boolean("step_{$step}_enabled")->default(true);
            }
            foreach (['greeting', 'info', 'socio', 'demo', 'price', 'ask_subscribers', 'ask_name', 'handoff', 'fallback_1', 'fallback_2'] as $msg) {
                $t->text("msg_{$msg}")->nullable();
            }
            $t->timestamps();
        });

        Schema::create('bot_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('conversation_id');
            $t->text('incoming_body')->nullable();
            $t->string('intent_detected')->nullable();
            $t->text('bot_response')->nullable();
            $t->boolean('escalated')->default(false);
            $t->text('context_data')->nullable();
            $t->timestamps();
        });

        // El webhook mira las campañas (bajas y respuestas) por teléfono.
        Schema::create('campaign_opt_outs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->string('phone');
            $t->string('reason')->nullable();
            $t->timestamps();
        });

        Schema::create('campaign_recipients', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('campaign_id')->nullable();
            $t->string('phone');
            $t->string('enrollment_status')->nullable();
            $t->string('status')->nullable();
            $t->string('skip_reason')->nullable();
            $t->timestamp('next_action_at')->nullable();
            $t->timestamp('replied_at')->nullable();
            $t->timestamps();
        });

        (require base_path('database/migrations/2026_09_30_000001_create_bot_flows_tables.php'))->up();
    }

    // ── Ayudas ──────────────────────────────────────────────────────────────

    /** Crea, publica y (por defecto) enciende un flujo. Falla la prueba si no valida. */
    protected function publishedFlow(GraphBuilder|array $graph, bool $active = true, string $name = 'Atención'): BotFlow
    {
        $flow = BotFlow::create([
            'tenant_id' => $this->tenant->id,
            'name'      => $name,
            'draft'     => $graph instanceof GraphBuilder ? $graph->toArray() : $graph,
            'is_active' => false,
        ]);

        $result = app(FlowPublisher::class)->publish($flow, null);
        $this->assertTrue($result['ok'], 'El flujo de la prueba no valida: ' . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));

        if ($active) {
            app(FlowPublisher::class)->setActive($flow, true);
        }

        return $flow->fresh();
    }

    /** Un mensaje del cliente, entrando por el webhook de verdad. */
    protected function incoming(string $body, string $from = '573001112233', array $extra = [], ?string $profileName = 'Cliente Prueba'): void
    {
        $this->inbound++;

        $message = array_merge([
            'from' => $from,
            'id'   => 'wamid.IN' . $this->inbound,
            'type' => 'text',
            'text' => ['body' => $body],
        ], $extra);

        // Sin nombre de perfil, la ficha queda sin nombre (y el webhook no se lo
        // vuelve a poner en cada mensaje).
        $contact = $profileName !== null
            ? ['profile' => ['name' => $profileName], 'wa_id' => $from]
            : ['wa_id' => $from];

        (new ProcessIncomingWhatsAppMessage($message, [$contact], $this->tenant->id))
            ->handle(app(WhatsAppService::class));
    }

    /** El cliente toca un botón o una fila de lista. */
    protected function tap(string $replyId, string $title, string $from = '573001112233'): void
    {
        $this->incoming('', $from, [
            'type'        => 'interactive',
            'interactive' => ['type' => 'button_reply', 'button_reply' => ['id' => $replyId, 'title' => $title]],
        ]);
    }

    /**
     * Lo que se le mandó a Meta, en orden: el texto de los mensajes de texto y
     * el cuerpo de los interactivos.
     *
     * @return list<string>
     */
    protected function sentTexts(): array
    {
        return collect(Http::recorded())
            ->map(fn ($pair) => $pair[0]->data())
            ->map(fn (array $d) => $d['text']['body'] ?? $d['interactive']['body']['text'] ?? '[' . ($d['type'] ?? '?') . ']')
            ->values()
            ->all();
    }

    /** @return list<array> Los payloads completos enviados a Meta. */
    protected function sentPayloads(): array
    {
        return collect(Http::recorded())->map(fn ($pair) => $pair[0]->data())->values()->all();
    }

    protected function conversation(string $phone = '573001112233'): Conversation
    {
        $contact = Contact::withoutGlobalScopes()->where('phone', $phone)->firstOrFail();

        return Conversation::withoutGlobalScopes()->where('contact_id', $contact->id)->firstOrFail();
    }

    protected function flowRun(?int $id = null): BotFlowRun
    {
        return $id
            ? BotFlowRun::withoutGlobalScopes()->findOrFail($id)
            : BotFlowRun::withoutGlobalScopes()->latest('id')->firstOrFail();
    }

    /** Message::create() ignora created_at (no es fillable): hay que forzarlo. */
    protected function backdate(Message $message, CarbonInterface $at): void
    {
        $message->forceFill(['created_at' => $at, 'updated_at' => $at])->saveQuietly();
    }

    /** Un menú de texto con dos opciones y "no entendió" hacia un traspaso. */
    protected function menuFlow(array $menu = [], array $trigger = ['type' => 'conversation_start', 'idle_hours' => 12]): GraphBuilder
    {
        return (new GraphBuilder())
            ->node('start', 'start', ['trigger' => $trigger, 'timezone' => 'America/Bogota'])
            ->node('menu', 'menu', array_merge([
                'text'        => '¡Hola {{contacto.primer_nombre}}! ¿En qué te ayudo?',
                'style'       => 'text',
                'options'     => [
                    ['id' => 'saldo', 'label' => 'Consultar mi saldo', 'keywords' => 'saldo, cuanto debo'],
                    ['id' => 'falla', 'label' => 'Reportar una falla', 'keywords' => 'sin internet, falla'],
                ],
                'retry_text'  => 'No te entendí. Elige una opción:',
                'max_retries' => 1,
            ], $menu))
            ->node('m_saldo', 'message', ['text' => 'Tu saldo es cero 🙌'])
            ->node('fin', 'end')
            ->node('q_falla', 'question', ['text' => '¿Qué está pasando?', 'save_as' => 'falla'])
            ->node('equipo', 'handoff', ['text' => 'Te paso con el equipo técnico.', 'assign' => 'auto', 'note' => true])
            ->node('nomatch', 'handoff', ['text' => 'Te paso con un asesor.', 'assign' => 'auto', 'note' => true])
            ->edge('start', 'next', 'menu')
            ->edge('menu', 'opt_saldo', 'm_saldo')
            ->edge('m_saldo', 'next', 'fin')
            ->edge('menu', 'opt_falla', 'q_falla')
            ->edge('q_falla', 'next', 'equipo')
            ->edge('menu', 'no_match', 'nomatch');
    }
}
