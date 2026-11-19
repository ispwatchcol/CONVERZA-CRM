<?php

namespace Tests\Feature\Ai;

use App\Models\AiAgentTurn;
use App\Models\AiUsageLog;
use App\Models\BotFlowRun;
use App\Models\Message;
use App\Services\Flows\FlowValidator;
use App\Services\Flows\Validation\ValidationContext;

/**
 * El bloque «Agente IA» de punta a punta, por el webhook de verdad: el cliente
 * escribe, el flujo pide la respuesta (tarea en la cola `ai`), la IA de la
 * empresa contesta con el prompt de su agente y el bot la envía.
 */
class AgentFlowTest extends AgentTestCase
{
    public function test_el_agente_contesta_con_el_prompt_y_el_modelo_de_la_empresa(): void
    {
        $this->replies = ['¡Hola! El plan de 20 megas cuesta *$60.000* al mes. ¿Te interesa?'];
        $this->publishedFlow($this->agentFlow());

        $this->incoming('hola, cuánto vale el plan de 20 megas?');

        $this->assertSame(['¡Hola! El plan de 20 megas cuesta *$60.000* al mes. ¿Te interesa?'], $this->whatsappTexts());
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $this->flowRun()->status, 'Después de responder, espera al cliente.');

        [$request] = $this->aiRequests();
        $this->assertTrue($request->hasHeader('Authorization', 'Bearer xai-de-la-empresa'), 'Con la llave de la empresa.');
        $this->assertSame('grok-4.3-mini', $request['model'], 'Con el modelo que eligió la empresa.');
        $this->assertStringContainsString('Vendes internet de Fibra Chaguaní', $request['instructions'], 'El prompt del agente, con sus variables.');
        $this->assertStringContainsString('[ASESOR]', $request['instructions'], 'Dentro del marco de Converza.');
        $this->assertSame('hola, cuánto vale el plan de 20 megas?', $request['input'][0]['content']);

        $turn = AiAgentTurn::withoutGlobalScopes()->sole();
        $this->assertSame(AiAgentTurn::STATUS_DONE, $turn->status);
        $this->assertSame($this->agent->id, $turn->ai_agent_id);
        $this->assertSame('agent', AiUsageLog::sole()->purpose);

        // El mensaje del bot queda en el chat, como cualquier otro.
        $this->assertTrue(Message::withoutGlobalScopes()->where('body', 'like', '%$60.000%')->exists());
    }

    public function test_la_conversacion_sigue_con_el_historial(): void
    {
        $this->replies = ['¿Qué plan te interesa?', 'Perfecto, el de 50 megas cuesta $90.000.'];
        $this->publishedFlow($this->agentFlow());

        $this->incoming('hola');
        $this->incoming('el de 50');

        $this->assertSame(['¿Qué plan te interesa?', 'Perfecto, el de 50 megas cuesta $90.000.'], $this->whatsappTexts());

        $second = $this->aiRequests()[1];
        $this->assertSame(['user', 'assistant', 'user'], array_column($second['input'], 'role'));
        $this->assertSame('¿Qué plan te interesa?', $second['input'][1]['content']);
    }

    public function test_un_agente_con_otro_modelo_usa_ese_modelo(): void
    {
        $this->agent->update(['model' => 'grok-4.3']);
        $this->publishedFlow($this->agentFlow());

        $this->incoming('hola');

        $this->assertSame('grok-4.3', $this->aiRequests()[0]['model']);
    }

    public function test_si_pide_un_asesor_sale_por_esa_salida_sin_mostrar_la_etiqueta(): void
    {
        $this->replies = ["Claro, te comunico con una persona del equipo.\n[ASESOR]"];
        $this->publishedFlow($this->agentFlow());

        $this->incoming('quiero hablar con una persona');

        $this->assertSame(['Claro, te comunico con una persona del equipo.'], $this->whatsappTexts());
        $run = $this->flowRun();
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $run->status);
        $this->assertSame('asesor', $run->variables['agente.salida']);
    }

    public function test_si_resolvio_termina(): void
    {
        $this->replies = ['¡Con gusto! Que tengas buen día. [FIN]'];
        $this->publishedFlow($this->agentFlow());

        $this->incoming('gracias, eso era todo');

        $this->assertSame(['¡Con gusto! Que tengas buen día.'], $this->whatsappTexts());
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $this->flowRun()->status);
    }

    public function test_con_saludo_el_bot_saluda_y_el_agente_contesta_la_respuesta(): void
    {
        $this->replies = ['El de 20 megas cuesta $60.000.'];
        $this->publishedFlow($this->agentFlow(['greeting' => '¡Hola {{contacto.primer_nombre}}! Soy el asistente de {{empresa.nombre}}. ¿En qué te ayudo?']));

        $this->incoming('hola');
        $this->assertSame(['¡Hola Cliente! Soy el asistente de Fibra Chaguaní. ¿En qué te ayudo?'], $this->whatsappTexts());
        $this->assertSame([], $this->aiRequests(), 'El saludo no gasta IA.');

        $this->incoming('precio del de 20?');
        $this->assertSame('El de 20 megas cuesta $60.000.', $this->whatsappTexts()[1]);
        $this->assertSame(['assistant', 'user'], array_column($this->aiRequests()[0]['input'], 'role'));
    }

    public function test_el_tope_de_respuestas_pasa_a_un_asesor(): void
    {
        $this->replies = ['Uno', 'Dos', 'Tres'];
        $this->publishedFlow($this->agentFlow(['max_turns' => 2]));

        $this->incoming('a');
        $this->incoming('b');
        $this->incoming('c');

        $this->assertSame(['Uno', 'Dos'], $this->whatsappTexts());
        $this->assertCount(2, $this->aiRequests());
        $this->assertSame('tope', $this->flowRun()->variables['agente.salida']);
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $this->flowRun()->status);
    }

    public function test_sin_ia_disponible_sale_por_ia_no_disponible_sin_llamar_a_nadie(): void
    {
        $this->tenant->update(['ai_enabled' => false]);
        $this->publishedFlow($this->agentFlow());

        $this->incoming('hola');

        $this->assertSame([], $this->aiRequests());
        $this->assertSame(['Te comunico con un asesor 🙌'], $this->whatsappTexts());
        $this->assertSame('no_disponible', $this->flowRun()->variables['agente.salida']);
    }

    public function test_si_el_proveedor_falla_sale_por_ia_no_disponible(): void
    {
        $this->aiStatus = 401;
        $this->publishedFlow($this->agentFlow());

        $this->incoming('hola');

        $this->assertSame(['Te comunico con un asesor 🙌'], $this->whatsappTexts());
        $this->assertSame(AiAgentTurn::STATUS_FAILED, AiAgentTurn::withoutGlobalScopes()->sole()->status);
        $this->assertSame('error', AiUsageLog::sole()->status);
    }

    public function test_un_agente_desactivado_no_conversa(): void
    {
        $this->publishedFlow($this->agentFlow());
        $this->agent->update(['is_active' => false]);

        $this->incoming('hola');

        $this->assertSame([], $this->aiRequests());
        $this->assertSame('no_disponible', $this->flowRun()->variables['agente.salida']);
    }

    public function test_una_foto_se_le_describe_al_agente(): void
    {
        $this->publishedFlow($this->agentFlow(['greeting' => '¿En qué te ayudo?']));

        $this->incoming('hola');
        $this->incoming('', extra: ['type' => 'image', 'image' => ['id' => 'MEDIA1', 'mime_type' => 'image/jpeg']]);

        $last = collect($this->aiRequests()[0]['input'])->last();
        $this->assertStringContainsString('El cliente envió una imagen', $last['content']);
    }

    public function test_el_validador_exige_un_agente_activo_de_la_empresa(): void
    {
        $validator = app(FlowValidator::class);
        $ctx       = ValidationContext::forTenant($this->tenant->id);

        $ok = $validator->validate($this->agentFlow()->toArray(), $ctx);
        $this->assertSame([], $ok['errors']);

        $bad = $validator->validate($this->agentFlow(['agent_id' => 999])->toArray(), $ctx);
        $this->assertStringContainsString('elige uno de tus agentes activos', $bad['errors'][0]['message']);

        $this->tenant->update(['ai_enabled' => false]);
        $warn = $validator->validate($this->agentFlow()->toArray(), ValidationContext::forTenant($this->tenant->id));
        $this->assertStringContainsString('la IA no está lista', $warn['warnings'][0]['message']);
    }
}
