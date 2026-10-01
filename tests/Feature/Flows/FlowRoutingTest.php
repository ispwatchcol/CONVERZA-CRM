<?php

namespace Tests\Feature\Flows;

use App\Jobs\HandleBotResponse;
use App\Jobs\RunBotFlow;
use App\Models\BotFlowRun;
use App\Models\BotSetting;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\StaffMember;
use App\Models\User;
use App\Services\Flows\FlowPublisher;
use App\Services\Flows\FlowTemplates;
use App\Services\Flows\FlowValidator;
use App\Services\Flows\GraphBuilder;
use App\Services\Flows\Validation\ValidationContext;
use Illuminate\Support\Facades\Queue;

/**
 * Quién atiende cada mensaje: un flujo, el bot clásico o nadie (el equipo).
 */
class FlowRoutingTest extends FlowsTestCase
{
    public function test_con_un_flujo_activo_el_bot_clasico_queda_en_pausa(): void
    {
        BotSetting::create(array_merge(BotSetting::defaults(), ['tenant_id' => $this->tenant->id, 'bot_enabled' => true]));
        $this->publishedFlow($this->menuFlow());

        Queue::fake();
        $this->incoming('hola');

        Queue::assertPushed(RunBotFlow::class);
        Queue::assertNotPushed(HandleBotResponse::class);
    }

    public function test_sin_flujos_activos_el_bot_clasico_sigue_exactamente_igual(): void
    {
        BotSetting::create(array_merge(BotSetting::defaults(), ['tenant_id' => $this->tenant->id, 'bot_enabled' => true]));
        $this->publishedFlow($this->menuFlow(), active: false);

        Queue::fake();
        $this->incoming('hola');

        Queue::assertPushed(HandleBotResponse::class);
        Queue::assertNotPushed(RunBotFlow::class);
        $this->assertSame(0, BotFlowRun::count());
    }

    public function test_una_conversacion_reabierta_arranca_el_flujo(): void
    {
        $this->publishedFlow($this->menuFlow(trigger: ['type' => 'conversation_start']));

        $this->incoming('hola');
        $this->incoming('1');
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $this->flowRun()->status);

        // Sin cerrar el hilo y sin silencio largo, escribir de nuevo no reinicia el bot.
        $this->incoming('otra pregunta');
        $this->assertSame(1, BotFlowRun::count());

        $this->conversation()->update(['status' => 'closed']);
        $this->incoming('hola de nuevo');

        $this->assertSame(2, BotFlowRun::count(), 'El hilo cerrado se reabre y es un inicio de conversación.');
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $this->flowRun()->status);
    }

    public function test_despues_de_n_horas_de_silencio_el_cliente_vuelve_a_recibir_el_flujo(): void
    {
        $this->publishedFlow($this->menuFlow(trigger: ['type' => 'conversation_start', 'idle_hours' => 12]));

        $this->incoming('hola');
        $this->incoming('1');

        // Todo lo anterior pasó hace 13 h.
        Message::withoutGlobalScopes()->get()->each(fn (Message $m) => $this->backdate($m, now()->subHours(13)));

        $this->incoming('buenas, otra vez yo');
        $this->assertSame(2, BotFlowRun::count());
    }

    public function test_menos_horas_de_silencio_no_reinician_el_flujo(): void
    {
        $this->publishedFlow($this->menuFlow(trigger: ['type' => 'conversation_start', 'idle_hours' => 12]));

        $this->incoming('hola');
        $this->incoming('1');
        Message::withoutGlobalScopes()->get()->each(fn (Message $m) => $this->backdate($m, now()->subHours(2)));

        $this->incoming('gracias!');
        $this->assertSame(1, BotFlowRun::count());
    }

    public function test_palabra_clave_contenida_sin_importar_tildes_ni_mayusculas(): void
    {
        $this->publishedFlow($this->menuFlow(trigger: ['type' => 'keyword', 'keywords' => ['menú', 'ayuda']]));

        $this->incoming('hola');
        $this->assertSame(0, BotFlowRun::count(), '"hola" no trae la palabra clave.');

        $this->incoming('Quiero ver el MENU por favor');
        $this->assertSame(1, BotFlowRun::count());
    }

    public function test_palabra_clave_exacta_exige_el_mensaje_completo(): void
    {
        $this->publishedFlow($this->menuFlow(trigger: ['type' => 'keyword', 'keywords' => 'menu', 'match' => 'exact']));

        $this->incoming('quiero el menu');
        $this->assertSame(0, BotFlowRun::count());

        $this->incoming('Menú');
        $this->assertSame(1, BotFlowRun::count());
    }

    public function test_la_palabra_clave_gana_sobre_el_inicio_de_conversacion(): void
    {
        $inicio  = $this->publishedFlow($this->menuFlow(), name: 'Bienvenida');
        $soporte = $this->publishedFlow($this->menuFlow(trigger: ['type' => 'keyword', 'keywords' => ['soporte']]), name: 'Soporte');

        $this->incoming('necesito soporte');

        $this->assertSame($soporte->id, $this->flowRun()->bot_flow_id, 'El cliente pidió algo concreto.');
        $this->assertNotSame($inicio->id, $this->flowRun()->bot_flow_id);
    }

    public function test_una_conversacion_con_asesor_no_arranca_ningun_flujo(): void
    {
        $this->publishedFlow($this->menuFlow(trigger: ['type' => 'keyword', 'keywords' => ['menu']]));

        $contact      = Contact::create(['tenant_id' => $this->tenant->id, 'phone' => '573001112233', 'name' => 'Cliente']);
        $conversation = Conversation::create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id]);
        $conversation->updateQuietly(['assigned_to' => $this->staff()->id]);

        $this->incoming('menu');

        $this->assertSame(0, BotFlowRun::count());
        $this->assertSame([], $this->sentTexts());
    }

    public function test_una_reaccion_no_arranca_un_flujo(): void
    {
        $this->publishedFlow($this->menuFlow());

        $this->incoming('', extra: ['type' => 'reaction', 'reaction' => ['message_id' => 'wamid.X', 'emoji' => '👍']]);

        $this->assertSame(0, BotFlowRun::count());
    }

    public function test_la_autoasignacion_espera_a_que_el_flujo_suelte_la_conversacion(): void
    {
        $this->tenant->update(['auto_assign_enabled' => true]);
        $asesor = $this->staff();
        $this->publishedFlow($this->menuFlow());

        $this->incoming('hola');
        $this->assertNull($this->conversation()->assigned_to, 'Bot en curso: la auto-asignación no le quita el chat.');

        $this->incoming('1'); // el flujo termina en un Fin: resolvió sin asesor
        $this->assertNull($this->conversation()->assigned_to);

        $this->incoming('una cosa más');
        $this->assertSame($asesor->id, $this->conversation()->assigned_to, 'Ya sin bot, el siguiente mensaje sí se asigna.');
    }

    public function test_una_conversacion_a_medias_con_el_bot_clasico_se_libera_al_encender_flujos(): void
    {
        $this->tenant->update(['auto_assign_enabled' => true]);
        $asesor = $this->staff();

        $contact      = Contact::create(['tenant_id' => $this->tenant->id, 'phone' => '573001112233', 'name' => 'Cliente']);
        $conversation = Conversation::create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id]);
        $conversation->updateQuietly(['bot_active' => true, 'bot_step' => 'greeting_sent']);
        $this->backdate(Message::create([
            'tenant_id' => $this->tenant->id, 'conversation_id' => $conversation->id, 'contact_id' => $contact->id,
            'body' => 'hola', 'status' => 'received', 'type' => 'text',
        ]), now()->subMinutes(5));

        $this->publishedFlow($this->menuFlow());
        $this->incoming('3');

        $conversation->refresh();
        $this->assertFalse($conversation->bot_active, 'Con el clásico en pausa nadie la atendería.');
        $this->assertSame($asesor->id, $conversation->assigned_to);
    }

    public function test_una_pregunta_sin_respuesta_caduca_y_libera_la_conversacion(): void
    {
        $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');

        $this->travel(25)->hours();
        $this->artisan('flows:tick');

        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_CANCELLED, $run->status);
        $this->assertSame('input_timeout', $run->ended_reason);
        $this->assertFalse($this->conversation()->bot_active);
        $this->assertSame(0, Message::withoutGlobalScopes()->where('type', 'note')->count(), 'Nadie espera nada: no hace falta avisar.');
    }

    public function test_si_el_cliente_vuelve_despues_de_caducar_la_pregunta_arranca_de_nuevo(): void
    {
        $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');
        Message::withoutGlobalScopes()->get()->each(fn (Message $m) => $this->backdate($m, now()->subHours(26)));
        $this->flowRun()->forceFill(['last_activity_at' => now()->subHours(26)])->save();

        $this->incoming('1');

        $this->assertSame(2, BotFlowRun::count(), 'El "1" de hoy no contesta una pregunta de ayer: arranca un flujo nuevo.');
        $this->assertSame('input_timeout', BotFlowRun::orderBy('id')->first()->ended_reason);
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $this->flowRun()->status);
    }

    public function test_apagar_el_flujo_corta_las_ejecuciones_y_avisa_al_equipo(): void
    {
        $flow = $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');

        $cancelled = app(FlowPublisher::class)->setActive($flow, false);

        $this->assertSame(1, $cancelled);
        $this->assertSame('flow_deactivated', $this->flowRun()->ended_reason);
        $this->assertFalse($this->conversation()->bot_active);
        $this->assertDatabaseHas('messages', ['type' => 'note', 'body' => '🤖 Se apagó el flujo que atendía esta conversación. El cliente puede estar esperando respuesta.']);
    }

    public function test_si_el_enrutador_falla_el_mensaje_igual_se_guarda_y_se_asigna(): void
    {
        // Un esquema sin la migración de flujos (pasó con converza_dev más de
        // una vez con otras tablas): el bot no puede romper la ingesta.
        $this->tenant->update(['auto_assign_enabled' => true]);
        $asesor = $this->staff();
        \Illuminate\Support\Facades\Schema::drop('bot_flow_steps');
        \Illuminate\Support\Facades\Schema::drop('bot_flow_runs');

        // Conversación que ya existía: en una recién creada la auto-asignación
        // no corre con el primer mensaje (el modelo no trae el status por
        // defecto de la base), y eso es aparte de esta prueba.
        $contact = Contact::create(['tenant_id' => $this->tenant->id, 'phone' => '573001112233', 'name' => 'Cliente']);
        Conversation::create(['tenant_id' => $this->tenant->id, 'contact_id' => $contact->id, 'status' => 'open']);

        $this->incoming('hola, ¿hay alguien?');

        $this->assertDatabaseHas('messages', ['body' => 'hola, ¿hay alguien?', 'status' => 'received']);
        $this->assertSame($asesor->id, $this->conversation()->assigned_to, 'Sin bot, pero el mensaje llega a un asesor.');
    }

    public function test_el_bot_clasico_se_convierte_en_flujo_con_sus_textos(): void
    {
        // array_merge y no `+`: con `+` ganan los defaults y el texto propio se pierde.
        BotSetting::create(array_merge(BotSetting::defaults(), [
            'tenant_id'    => $this->tenant->id,
            'bot_enabled'  => true,
            'msg_greeting' => "Bienvenido a Fibra Chaguaní 👋\n1️⃣ Info\n2️⃣ Socio\n3️⃣ Demo\n4️⃣ Precio\n5️⃣ Asesor",
            'msg_demo'     => 'Demo de 15 min. ¿Cuántos suscriptores tienes?',
            'msg_ask_name' => '¿Cómo te llamas?',
            'msg_handoff'  => 'Te paso con Laura.',
        ]));

        $graph = app(FlowTemplates::class)->build('legacy', $this->tenant);
        $this->assertSame([], app(FlowValidator::class)->validate($graph, ValidationContext::forTenant($this->tenant->id))['errors']);

        $this->publishedFlow($graph);

        // Sin nombre de perfil de WhatsApp: el bot tiene que preguntarlo.
        $this->incoming('hola', profileName: null);
        $this->incoming('quiero ver la demo', profileName: null);
        $this->incoming('unos 800', profileName: null);
        $this->incoming('Laura Gómez', profileName: null);

        $this->assertSame([
            "Bienvenido a Fibra Chaguaní 👋\n1️⃣ Info\n2️⃣ Socio\n3️⃣ Demo\n4️⃣ Precio\n5️⃣ Asesor", // tal cual, sin opciones agregadas
            'Demo de 15 min. ¿Cuántos suscriptores tienes?',
            '¿Cómo te llamas?',
            'Te paso con Laura.',
        ], $this->sentTexts());

        $run = $this->flowRun();
        $this->assertSame('Ver una demo', $run->variables['opcion']);
        $this->assertSame('unos 800', $run->variables['suscriptores']);
        $this->assertSame('Laura Gómez', Contact::withoutGlobalScopes()->first()->name);
    }

    private function staff(): StaffMember
    {
        $user = User::create(['name' => 'Laura', 'email' => 'laura@isp.test', 'password' => 'x', 'tenant_id' => $this->tenant->id]);

        return StaffMember::create(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'agent', 'is_active' => true]);
    }
}
