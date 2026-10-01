<?php

namespace Tests\Feature\Flows;

use App\Jobs\RunBotFlow;
use App\Models\BotFlowRun;
use App\Models\BotFlowStep;
use App\Models\BotFlowVersion;
use App\Models\Contact;
use App\Models\Label;
use App\Models\Message;
use App\Models\StaffMember;
use App\Models\Team;
use App\Models\User;
use App\Services\Flows\FlowPublisher;
use App\Services\Flows\FlowRunner;
use App\Services\Flows\GraphBuilder;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Queue;

/**
 * El motor de flujos de punta a punta: un mensaje entra por el webhook real,
 * el flujo corre y lo que sale a WhatsApp se afirma sobre la petición HTTP.
 */
class FlowEngineTest extends FlowsTestCase
{
    public function test_una_conversacion_nueva_arranca_el_flujo_y_el_bot_espera_la_respuesta(): void
    {
        $this->publishedFlow($this->menuFlow());

        $this->incoming('Hola, buenas tardes');

        $this->assertCount(1, $this->sentTexts());
        $menu = $this->sentTexts()[0];
        $this->assertStringContainsString('¡Hola Cliente! ¿En qué te ayudo?', $menu);
        $this->assertStringContainsString("1️⃣ Consultar mi saldo\n2️⃣ Reportar una falla", $menu);

        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $run->status);
        $this->assertSame('menu', $run->current_node);
        $this->assertTrue($this->conversation()->bot_active, 'El flujo reclama la conversación.');

        // El asesor ve en el chat exactamente lo que recibió el cliente.
        $this->assertDatabaseHas('messages', ['type' => 'bot', 'body' => $menu, 'status' => 'sent']);
    }

    public function test_el_numero_elige_la_opcion_y_el_flujo_termina(): void
    {
        $this->publishedFlow($this->menuFlow());

        $this->incoming('hola');
        $this->incoming('1');

        $this->assertSame('Tu saldo es cero 🙌', $this->sentTexts()[1]);

        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $run->status);
        $this->assertNull($run->live_conversation_id);
        $this->assertSame('Consultar mi saldo', $run->variables['opcion']);
        $this->assertFalse($this->conversation()->bot_active, 'Al terminar, la conversación vuelve a la bandeja.');
    }

    public function test_una_palabra_clave_o_el_nombre_de_la_opcion_tambien_eligen(): void
    {
        $this->publishedFlow($this->menuFlow());

        $this->incoming('hola');
        $this->incoming('Buenas, ¿cuánto debo este mes?');

        $this->assertSame('Tu saldo es cero 🙌', $this->sentTexts()[1], '"cuanto debo" es palabra clave de la opción 1, con o sin tilde.');
    }

    public function test_la_palabra_clave_se_busca_completa_no_como_pedazo_de_otra(): void
    {
        $this->publishedFlow($this->menuFlow([
            'options' => [
                ['id' => 'saldo', 'label' => 'Consultar mi saldo', 'keywords' => 'ver'],
                ['id' => 'falla', 'label' => 'Reportar una falla', 'keywords' => 'falla'],
            ],
            'max_retries' => 0,
        ]));

        $this->incoming('hola');
        // El bot cableado haría str_contains y "verificar" caería en "ver".
        $this->incoming('quiero verificar algo');

        $this->assertSame('Te paso con un asesor.', $this->sentTexts()[1]);
    }

    public function test_si_no_entiende_reintenta_y_a_la_segunda_pasa_al_equipo_con_nota(): void
    {
        $this->publishedFlow($this->menuFlow());

        $this->incoming('hola');
        $this->incoming('ñlkajsdf');

        $this->assertStringStartsWith('No te entendí. Elige una opción:', $this->sentTexts()[1]);
        $this->assertStringContainsString('1️⃣ Consultar mi saldo', $this->sentTexts()[1], 'El reintento repite las opciones.');
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $this->flowRun()->status);

        $this->incoming('???');

        $this->assertSame('Te paso con un asesor.', $this->sentTexts()[2]);
        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $run->status);
        $this->assertSame('handoff', $run->ended_reason);
        $this->assertSame(1, BotFlowStep::where('outcome', 'no_match')->count(), 'El "no entendí" queda medido.');
    }

    public function test_el_boton_tocado_gana_sobre_el_texto(): void
    {
        $this->publishedFlow($this->menuFlow([
            'style'   => 'buttons',
            'options' => [
                ['id' => 'saldo', 'label' => 'Saldo'],
                ['id' => 'falla', 'label' => 'Falla'],
            ],
        ]));

        $this->incoming('hola');

        $payload = $this->sentPayloads()[0];
        $this->assertSame('interactive', $payload['type']);
        $this->assertSame('button', $payload['interactive']['type']);
        $this->assertSame(
            [['type' => 'reply', 'reply' => ['id' => 'opt_saldo', 'title' => 'Saldo']], ['type' => 'reply', 'reply' => ['id' => 'opt_falla', 'title' => 'Falla']]],
            $payload['interactive']['action']['buttons'],
        );

        // El título visible dice "Falla", pero el id es el de la opción 1: manda el id.
        $this->tap('opt_saldo', 'Falla');

        $this->assertSame('Tu saldo es cero 🙌', $this->sentTexts()[1]);
    }

    public function test_la_lista_se_arma_como_pide_meta(): void
    {
        $this->publishedFlow($this->menuFlow([
            'style'        => 'list',
            'button_label' => 'Ver opciones',
        ]));

        $this->incoming('hola');

        $interactive = $this->sentPayloads()[0]['interactive'];
        $this->assertSame('list', $interactive['type']);
        $this->assertSame('Ver opciones', $interactive['action']['button']);
        $this->assertSame(['opt_saldo', 'opt_falla'], array_column($interactive['action']['sections'][0]['rows'], 'id'));
    }

    public function test_la_pregunta_guarda_la_respuesta_la_usa_despues_y_actualiza_la_ficha(): void
    {
        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('q', 'question', ['text' => '¿Cómo te llamas?', 'save_as' => 'nombre', 'save_to_contact' => 'name'])
            ->node('m', 'message', ['text' => 'Mucho gusto, {{nombre}}. Tu nombre en la ficha: {{contacto.nombre}}'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'q')
            ->edge('q', 'next', 'm')
            ->edge('m', 'next', 'fin'));

        $this->incoming('hola');
        $this->incoming('  Ana María  ');

        $this->assertSame('Mucho gusto, Ana María. Tu nombre en la ficha: Ana María', $this->sentTexts()[1]);
        $this->assertSame('Ana María', Contact::withoutGlobalScopes()->first()->name);
    }

    public function test_una_pregunta_de_numero_rechaza_texto_y_reintenta(): void
    {
        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('q', 'question', ['text' => '¿Cuántos suscriptores?', 'save_as' => 'suscriptores', 'validation' => 'number', 'max_retries' => 1])
            ->node('grande', 'condition', ['rules' => [['kind' => 'variable', 'variable' => 'suscriptores', 'operator' => 'greater_than', 'value' => '1000']]])
            ->node('si', 'message', ['text' => 'ISP grande'])
            ->node('no', 'message', ['text' => 'ISP pequeño'])
            ->node('fin', 'end')
            ->node('invalida', 'handoff', ['text' => 'Te paso con un asesor.'])
            ->edge('start', 'next', 'q')
            ->edge('q', 'next', 'grande')
            ->edge('q', 'invalid', 'invalida')
            ->edge('grande', 'true', 'si')
            ->edge('grande', 'false', 'no')
            ->edge('si', 'next', 'fin')
            ->edge('no', 'next', 'fin'));

        $this->incoming('hola');
        $this->incoming('muchos');
        $this->assertSame('Por favor respóndeme con un número 🙏', $this->sentTexts()[1]);

        $this->incoming('unos 2.500 más o menos');
        $this->assertSame('ISP grande', $this->sentTexts()[2]);
        $this->assertSame('2.500', $this->flowRun()->variables['suscriptores']);
    }

    public function test_si_un_asesor_toma_la_conversacion_el_flujo_se_corta_en_el_acto(): void
    {
        $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');

        $staff = $this->staff('agent');
        $this->conversation()->update(['assigned_to' => $staff->id]);

        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_CANCELLED, $run->status);
        $this->assertSame('assigned', $run->ended_reason);
        $this->assertNull($run->live_conversation_id);

        $this->incoming('1');
        $this->assertCount(1, $this->sentTexts(), 'El bot no le contesta a un cliente que ya tiene asesor.');
    }

    public function test_si_un_asesor_le_escribe_al_cliente_el_bot_se_calla(): void
    {
        $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');

        $conversation = $this->conversation();
        Message::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id, 'body' => 'Hola, soy Laura del equipo',
            'status' => 'sent', 'type' => 'text', 'sent_by_user_id' => $this->staff('agent')->user_id,
        ]);

        $this->incoming('1');

        $this->assertCount(1, $this->sentTexts());
        $this->assertSame('human_replied', $this->flowRun()->ended_reason);
    }

    public function test_una_nota_interna_no_cuenta_como_que_un_asesor_le_escribio(): void
    {
        $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');

        $conversation = $this->conversation();
        Message::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $conversation->id,
            'contact_id' => $conversation->contact_id, 'body' => 'ojo: cliente moroso',
            'status' => 'sent', 'type' => 'note', 'sent_by_user_id' => $this->staff('agent')->user_id,
        ]);

        $this->incoming('1');

        $this->assertSame('Tu saldo es cero 🙌', $this->sentTexts()[1]);
    }

    public function test_publicar_una_version_nueva_no_rompe_la_ejecucion_en_curso(): void
    {
        $flow = $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');
        $v1 = $this->flowRun()->bot_flow_version_id;

        // v2 cambia la respuesta de la opción 1 y quita la opción 2.
        $flow->update(['draft' => (new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('menu', 'menu', ['text' => 'Menú nuevo', 'options' => [['id' => 'solo', 'label' => 'Única opción']]])
            ->node('m', 'message', ['text' => 'Respuesta de la v2'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'menu')
            ->edge('menu', 'opt_solo', 'm')
            ->edge('menu', 'no_match', 'fin')
            ->edge('m', 'next', 'fin')
            ->toArray()]);
        $this->assertTrue(app(FlowPublisher::class)->publish($flow->fresh(), null)['ok']);

        $this->incoming('2');

        // Sigue en la v1: la opción 2 existe y lleva a la pregunta de la falla.
        $this->assertSame('¿Qué está pasando?', $this->sentTexts()[1]);
        $this->assertSame($v1, $this->flowRun()->bot_flow_version_id);
        $this->assertSame(2, BotFlowVersion::count());
    }

    public function test_mensajes_en_rafaga_antes_de_que_el_bot_pregunte_no_cuentan_como_respuesta(): void
    {
        $this->publishedFlow($this->menuFlow());

        // La cola en pausa: los tres mensajes llegan ANTES de que corra el job
        // del arranque, como en producción con los workers ocupados.
        Queue::fake();
        $this->incoming('hola');
        $this->incoming('1');
        $this->incoming('gracias');

        $jobs = Queue::pushed(RunBotFlow::class);
        $this->assertCount(3, $jobs, 'Un arranque y dos "despertares" para la misma ejecución.');
        $this->assertCount(1, BotFlowRun::all(), 'Una sola ejecución viva por conversación.');

        foreach ($jobs as $job) {
            $job->handle(app(FlowRunner::class));
        }

        // El cliente escribió "1" antes de ver el menú: no sabía qué elegía.
        $this->assertSame(1, count($this->sentTexts()), 'Un solo menú, y ni "1" ni "gracias" se tomaron como respuesta.');
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $this->flowRun()->status);

        // Ahora sí, con el menú a la vista, la respuesta cuenta.
        $this->incoming('2');
        Queue::pushed(RunBotFlow::class)->slice(3)->each(fn (RunBotFlow $job) => $job->handle(app(FlowRunner::class)));

        $this->assertSame('¿Qué está pasando?', $this->sentTexts()[1]);
    }

    public function test_dos_respuestas_en_rafaga_se_leen_en_orden_y_una_sola_vez(): void
    {
        $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');

        Queue::fake();
        $this->incoming('1');
        $this->incoming('2');

        // Aunque los workers tomen los jobs al revés, la bandeja se lee en orden.
        foreach (array_reverse(Queue::pushed(RunBotFlow::class)->all()) as $job) {
            $job->handle(app(FlowRunner::class));
        }

        $this->assertSame(['Tu saldo es cero 🙌'], array_slice($this->sentTexts(), 1), 'El "1" llegó primero y ganó; el "2" ya no tiene a quién responderle.');
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $this->flowRun()->status);
    }

    public function test_un_ciclo_que_se_colara_lo_corta_el_tope_de_pasos(): void
    {
        // Se salta el validador a propósito: la red de seguridad del motor
        // tiene que aguantar aunque algo se le escape al validador.
        $flow = $this->publishedFlow($this->menuFlow());
        $graph = (new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('a', 'condition', ['rules' => [['kind' => 'variable', 'variable' => 'x', 'operator' => 'is_empty']]])
            ->node('b', 'tag', ['label_id' => 1])
            ->edge('start', 'next', 'a')
            ->edge('a', 'true', 'b')
            ->edge('a', 'false', 'b')
            ->edge('b', 'next', 'a')
            ->toArray();
        $version = BotFlowVersion::create([
            'tenant_id' => $this->tenant->id, 'bot_flow_id' => $flow->id, 'version' => 9,
            'graph' => $graph, 'published_at' => now(),
        ]);
        $flow->update(['published_version_id' => $version->id]);

        $this->incoming('hola');

        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_FAILED, $run->status);
        $this->assertSame('step_limit', $run->ended_reason);
        $this->assertLessThanOrEqual(config('flows.max_steps_per_event') + 1, $run->steps_count);
        $this->assertFalse($this->conversation()->bot_active);
        $this->assertDatabaseHas('messages', ['type' => 'note', 'body' => '🤖 El flujo se detuvo porque superó el tope de pasos (posible ciclo). Revisa el flujo en el Workspace.']);
    }

    public function test_la_espera_la_reanuda_el_tick(): void
    {
        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('a', 'message', ['text' => 'Déjame revisar…'])
            ->node('w', 'wait', ['minutes' => 5])
            ->node('b', 'message', ['text' => 'Listo, ya quedó.'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'a')
            ->edge('a', 'next', 'w')
            ->edge('w', 'next', 'b')
            ->edge('b', 'next', 'fin'));

        $this->incoming('hola');
        $this->assertSame(BotFlowRun::STATUS_WAITING_TIMER, $this->flowRun()->status);

        $this->artisan('flows:tick');
        $this->assertCount(1, $this->sentTexts(), 'Antes de tiempo el tic no reanuda nada.');

        $this->travel(6)->minutes();
        $this->artisan('flows:tick');

        $this->assertSame('Listo, ya quedó.', $this->sentTexts()[1]);
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $this->flowRun()->status);
    }

    public function test_con_la_ventana_de_24h_cerrada_el_bot_no_envia_y_pasa_al_equipo(): void
    {
        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('w', 'wait', ['minutes' => 60])
            ->node('b', 'message', ['text' => '¿Pudiste resolver?'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'w')
            ->edge('w', 'next', 'b')
            ->edge('b', 'next', 'fin'));

        $this->incoming('hola');

        // El mensaje del cliente resulta ser de hace 25 h (p. ej. el tic estuvo
        // caído): cuando el flujo despierta, la ventana ya cerró.
        $entrante = Message::withoutGlobalScopes()->where('status', 'received')->first();
        $this->backdate($entrante, now()->subHours(25));
        $this->travel(61)->minutes();
        $this->artisan('flows:tick');

        $this->assertSame([], $this->sentTexts(), 'Nada llega a Meta: se habría rechazado y gastado calidad del número.');
        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $run->status);
        $this->assertSame('window_closed', $run->ended_reason);
        $this->assertDatabaseHas('messages', ['type' => 'note', 'body' => '🤖 El bot no pudo seguir: la ventana de 24 h de WhatsApp está cerrada. Para escribirle al cliente usa una plantilla aprobada.']);
    }

    public function test_datos_del_cliente_lee_ispwatch_y_llena_las_variables(): void
    {
        $this->tenant->update(['ispwatch_tenant_id' => 42]);
        $this->app->instance(IspwatchRepository::class, new class extends IspwatchRepository {
            public function customerByPhone(int $ispwatchTenantId, ?string $phone, ?string $contactName = null): ?array
            {
                return $ispwatchTenantId === 42 && $phone === '573001112233'
                    ? ['user_id' => 7, 'tenant_id' => 42, 'name' => 'Pedro Gómez', 'cedula' => '123', 'document_number' => null,
                        'service_status' => 'suspended', 'credit_balance' => 0, 'pppoe_username' => 'pedro', 'address' => 'Cra 1', 'ambiguous' => false]
                    : null;
            }

            public function pendingInvoicesForCustomer(int $ispwatchTenantId, int $customerUserId): array
            {
                return [
                    ['id' => 1, 'number' => 'FAC-1', 'total' => '50000', 'balance_due' => '45000', 'status' => 'pending', 'due_date' => '2026-09-05T00:00:00Z', 'currency' => 'COP'],
                    ['id' => 2, 'number' => 'FAC-2', 'total' => '50000', 'balance_due' => '50000', 'status' => 'pending', 'due_date' => '2026-10-05T00:00:00Z', 'currency' => 'COP'],
                ];
            }
        });

        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('buscar', 'ispwatch')
            ->node('m', 'message', ['text' => '{{cliente.primer_nombre}}: servicio {{cliente.estado_servicio}}, debes {{cliente.total_pendiente}} en {{cliente.facturas_pendientes}} facturas; vence {{cliente.proximo_vencimiento}}.'])
            ->node('no', 'handoff', ['text' => 'No te encontré.'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'buscar')
            ->edge('buscar', 'found', 'm')
            ->edge('buscar', 'not_found', 'no')
            ->edge('m', 'next', 'fin'));

        $this->incoming('hola');

        // balance_due, nunca total: 45.000 + 50.000.
        $this->assertSame('Pedro: servicio Suspendido, debes $95.000 en 2 facturas; vence 05/09/2026.', $this->sentTexts()[0]);
    }

    public function test_sin_vinculo_con_ispwatch_sale_por_no_encontrado(): void
    {
        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('buscar', 'ispwatch')
            ->node('si', 'message', ['text' => 'Te encontré'])
            ->node('no', 'handoff', ['text' => 'No te encontré.'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'buscar')
            ->edge('buscar', 'found', 'si')
            ->edge('buscar', 'not_found', 'no')
            ->edge('si', 'next', 'fin'));

        $this->incoming('hola');

        $this->assertSame('No te encontré.', $this->sentTexts()[0]);
        $this->assertSame(['motivo' => 'sin_vinculo'], BotFlowStep::where('node_id', 'buscar')->first()->detail);
    }

    public function test_etiquetar_y_ramificar_por_etiqueta(): void
    {
        $vip = Label::create(['tenant_id' => $this->tenant->id, 'name' => 'VIP']);

        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('tag', 'tag', ['label_id' => $vip->id, 'action' => 'add'])
            ->node('es_vip', 'condition', ['rules' => [['kind' => 'label', 'label_id' => $vip->id, 'operator' => 'has']]])
            ->node('si', 'message', ['text' => 'Atención preferencial'])
            ->node('no', 'message', ['text' => 'Atención normal'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'tag')
            ->edge('tag', 'next', 'es_vip')
            ->edge('es_vip', 'true', 'si')
            ->edge('es_vip', 'false', 'no')
            ->edge('si', 'next', 'fin')
            ->edge('no', 'next', 'fin'));

        $this->incoming('hola');

        $this->assertSame('Atención preferencial', $this->sentTexts()[0]);
        $this->assertTrue(Contact::withoutGlobalScopes()->first()->labels()->where('labels.id', $vip->id)->exists());
    }

    public function test_condicion_de_horario_en_la_zona_del_flujo(): void
    {
        $graph = fn () => (new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start'], 'timezone' => 'America/Bogota'])
            ->node('h', 'condition', ['rules' => [['kind' => 'schedule', 'days' => [1, 2, 3, 4, 5], 'start' => '08:00', 'end' => '18:00']]])
            ->node('abierto', 'message', ['text' => 'Estamos atendiendo'])
            ->node('cerrado', 'message', ['text' => 'Te respondemos mañana'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'h')
            ->edge('h', 'true', 'abierto')
            ->edge('h', 'false', 'cerrado')
            ->edge('abierto', 'next', 'fin')
            ->edge('cerrado', 'next', 'fin');

        $this->publishedFlow($graph());

        // Martes 30/09/2026 22:00 UTC = 17:00 en Bogotá: dentro.
        Carbon::setTestNow('2026-09-30 22:00:00');
        $this->incoming('hola', '573000000001');
        // 23:30 UTC = 18:30 en Bogotá: fuera.
        Carbon::setTestNow('2026-09-30 23:30:00');
        $this->incoming('hola', '573000000002');

        $this->assertSame(['Estamos atendiendo', 'Te respondemos mañana'], $this->sentTexts());
    }

    public function test_el_traspaso_manda_al_equipo_asigna_y_deja_la_nota(): void
    {
        $this->tenant->update(['auto_assign_enabled' => true]);
        $soporte = Team::create(['tenant_id' => $this->tenant->id, 'name' => 'Soporte técnico']);
        $ventas  = $this->staff('agent');                                  // otro equipo
        $tecnico = $this->staff('agent', $soporte->id);

        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
            ->node('q', 'question', ['text' => '¿Qué pasa con tu servicio?', 'save_as' => 'falla'])
            ->node('h', 'handoff', ['text' => 'Te paso con soporte.', 'team_id' => $soporte->id, 'assign' => 'auto', 'note' => true])
            ->edge('start', 'next', 'q')
            ->edge('q', 'next', 'h'));

        $this->incoming('hola');
        $this->assertNull($this->conversation()->assigned_to, 'Mientras el bot atiende, la auto-asignación no le quita el chat.');

        $this->incoming('no tengo internet desde ayer');

        $conversation = $this->conversation();
        $this->assertSame($tecnico->id, $conversation->assigned_to, 'Solo entre los del equipo elegido.');
        $this->assertNotSame($ventas->id, $conversation->assigned_to);
        $this->assertSame($soporte->id, $conversation->team_id);
        $this->assertFalse($conversation->bot_active);

        $nota = Message::withoutGlobalScopes()->where('type', 'note')->first();
        $this->assertStringContainsString('falla: no tengo internet desde ayer', $nota->body);
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $this->flowRun()->status);
    }

    public function test_la_traza_guarda_cada_bloque_y_enlaza_el_mensaje_enviado(): void
    {
        $this->publishedFlow($this->menuFlow());

        $this->incoming('hola');
        $this->incoming('1');

        $steps = BotFlowStep::orderBy('id')->get();
        $this->assertSame(['start', 'menu', 'menu', 'm_saldo', 'fin'], $steps->pluck('node_id')->all());
        $this->assertSame(['next', 'waiting_input', 'opt_saldo', 'next', 'completed'], $steps->pluck('outcome')->all());
        $this->assertSame('1', $steps[2]->input);
        $this->assertNotNull($steps[3]->message_id, 'Con el message_id se sabe si Meta lo entregó o lo rechazó.');
        $this->assertSame('Tu saldo es cero 🙌', Message::find($steps[3]->message_id)->body);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function staff(string $role, ?int $teamId = null): StaffMember
    {
        static $n = 0;
        $n++;

        $user = User::create([
            'name' => "Asesor {$n}", 'email' => "asesor{$n}@isp.test", 'password' => 'x', 'tenant_id' => $this->tenant->id,
        ]);

        return StaffMember::create([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'team_id' => $teamId,
            'role' => $role, 'is_active' => true,
        ]);
    }
}
