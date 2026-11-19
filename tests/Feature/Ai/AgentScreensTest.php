<?php

namespace Tests\Feature\Ai;

use App\Models\AiAgent;
use App\Models\BotFlow;
use App\Models\Tenant;
use App\Services\Ai\PublicUrl;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Las pantallas: Agentes IA (prompt, modelo, chat de prueba), Configuración
 * (proveedor, modelo de la lista de la API, URL propia) y el simulador del
 * Workspace con un bloque «Agente IA».
 */
class AgentScreensTest extends AgentTestCase
{
    protected function tearDown(): void
    {
        PublicUrl::$resolver = null;

        parent::tearDown();
    }

    public function test_solo_el_admin_configura_agentes(): void
    {
        $asesor = $this->userWithRole('asesor@isp.test', 'agent');

        $this->actingAs($asesor)->get('/ai-agents')->assertForbidden();
        $this->actingAs($asesor)->postJson('/ai-agents/test', ['instructions' => 'x', 'history' => [['role' => 'user', 'content' => 'hola']]])->assertForbidden();
    }

    public function test_crear_editar_y_borrar_un_agente(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');

        $this->actingAs($admin)->get('/ai-agents')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Agents/Index')
                ->has('agents', 1)
                ->where('ai.ready', true)
                ->where('ai.model', 'grok-4.3-mini'));

        $this->actingAs($admin)->post('/ai-agents', ['name' => ' Soporte ', 'instructions' => 'Ayudas con fallas.', 'model' => 'grok-4.3', 'is_active' => true])
            ->assertSessionHas('success');
        $soporte = AiAgent::where('name', 'Soporte')->firstOrFail();
        $this->assertSame('grok-4.3', $soporte->model);

        $this->actingAs($admin)->put("/ai-agents/{$soporte->id}", ['name' => 'Soporte', 'instructions' => 'Otra cosa.', 'model' => '', 'is_active' => false])
            ->assertSessionHas('success');
        $this->assertNull($soporte->fresh()->model);
        $this->assertFalse($soporte->fresh()->is_active);

        $this->actingAs($admin)->post('/ai-agents', ['name' => 'X', 'instructions' => 'y', 'model' => 'gpt 5; rm -rf', 'is_active' => true])
            ->assertSessionHasErrors('model');

        $this->actingAs($admin)->delete("/ai-agents/{$soporte->id}")->assertSessionHas('success');
        $this->assertNull($soporte->fresh());
    }

    public function test_el_agente_de_otra_empresa_no_se_toca(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        $otra  = Tenant::create(['slug' => 'otra', 'name' => 'Otra', 'is_active' => true]);
        $ajeno = AiAgent::create(['tenant_id' => $otra->id, 'name' => 'Ajeno', 'instructions' => 'x', 'is_active' => true]);

        $this->actingAs($admin)->put("/ai-agents/{$ajeno->id}", ['name' => 'Mío', 'instructions' => 'x', 'is_active' => true])->assertNotFound();
        $this->actingAs($admin)->delete("/ai-agents/{$ajeno->id}")->assertNotFound();
        $this->assertSame('Ajeno', $ajeno->fresh()->name);
    }

    public function test_el_chat_de_prueba_responde_con_la_ia_de_verdad_sin_guardar_ni_enviar(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        $this->replies = ["Te paso con alguien del equipo.\n[ASESOR]"];

        $this->actingAs($admin)->postJson('/ai-agents/test', [
            'instructions' => 'Eres el agente de {{empresa.nombre}}. Hoy es {{fecha.dia}}.',
            'model'        => 'grok-4.3',
            'history'      => [['role' => 'user', 'content' => 'quiero cancelar']],
        ])->assertOk()
            ->assertJsonPath('reply', 'Te paso con alguien del equipo.')
            ->assertJsonPath('action', 'human')
            ->assertJsonPath('exit', 'En un flujo, aquí saldría por «Pasar a un asesor».');

        [$request] = $this->aiRequests();
        $this->assertSame('grok-4.3', $request['model']);
        $this->assertStringContainsString('Eres el agente de Fibra Chaguaní', $request['instructions']);
        $this->assertStringNotContainsString('{{', $request['instructions']);
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertSame('agent_test', \App\Models\AiUsageLog::sole()->purpose);
    }

    public function test_el_chat_de_prueba_explica_por_que_no_hay_ia(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        $this->tenant->update(['ai_api_key' => null]);

        $this->actingAs($admin)->postJson('/ai-agents/test', ['instructions' => 'x', 'history' => [['role' => 'user', 'content' => 'hola']]])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Falta elegir tu proveedor y cargar tu llave en Configuración → Pagos e IA.');
        $this->assertSame([], $this->aiRequests());
    }

    public function test_la_lista_de_modelos_sale_de_la_api_del_proveedor_con_la_llave_de_la_empresa(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        $this->tenant->update(['ai_provider' => 'gemini', 'ai_api_key' => 'AIza-empresa', 'ai_model' => null]);

        $this->otherApis['https://generativelanguage.googleapis.com/'] = [[200, ['data' => [
            ['id' => 'models/gemini-2.5-flash'],
            ['id' => 'models/gemini-2.5-pro'],
            ['id' => 'models/text-embedding-004'],
        ]]]];

        $this->actingAs($admin)->getJson('/ai-agents/models')->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('models', [
                ['id' => 'gemini-2.5-flash', 'label' => 'gemini-2.5-flash'],
                ['id' => 'gemini-2.5-pro', 'label' => 'gemini-2.5-pro'],
            ]);

        Http::assertSent(fn (HttpRequest $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/models'
            && $r->hasHeader('Authorization', 'Bearer AIza-empresa'));
    }

    public function test_configuracion_guarda_el_proveedor_y_el_modelo_elegidos(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');

        $this->actingAs($admin)->put('/settings/ai', [
            'ai_enabled' => true, 'ai_provider' => 'anthropic', 'ai_api_key' => 'sk-ant-nueva', 'ai_model' => 'claude-opus-5-5',
        ])->assertSessionHas('success');

        $tenant = $this->tenant->fresh();
        $this->assertSame('anthropic', $tenant->ai_provider);
        $this->assertSame('claude-opus-5-5', $tenant->ai_model);
        $this->assertTrue($tenant->hasAiKey());

        // Cambiar de proveedor sin elegir modelo: el modelo era del otro, se borra.
        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'openai', 'ai_api_key' => 'sk-proj-x']);
        $this->assertNull($this->tenant->fresh()->ai_model);
        $this->assertSame('gpt-5-mini', $this->tenant->fresh()->aiModel(), 'Sin modelo elegido, el del proveedor por defecto.');
    }

    public function test_probar_la_llave_devuelve_los_modelos_para_elegir(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');

        $this->otherApis['https://api.anthropic.com/'] = [
            [200, ['data' => [['id' => 'claude-opus-5-5', 'display_name' => 'Claude Opus 5.5']], 'has_more' => true, 'last_id' => 'claude-opus-5-5']],
            [200, ['data' => [['id' => 'claude-haiku-5-5', 'display_name' => 'Claude Haiku 5.5']], 'has_more' => false, 'last_id' => 'claude-haiku-5-5']],
        ];

        $this->actingAs($admin)->postJson('/settings/ai/test', ['ai_provider' => 'anthropic', 'ai_api_key' => 'sk-ant-x'])
            ->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('models.0.id', 'claude-haiku-5-5')
            ->assertJsonPath('models.1.label', 'Claude Opus 5.5 (claude-opus-5-5)');

        Http::assertSent(fn (HttpRequest $r) => str_starts_with($r->url(), 'https://api.anthropic.com/v1/models')
            && $r->hasHeader('x-api-key', 'sk-ant-x')
            && $r->hasHeader('anthropic-version'));
        Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'after_id=claude-opus-5-5'));
    }

    public function test_otro_compatible_exige_una_url_publica_con_https(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        PublicUrl::$resolver = fn (string $host) => match ($host) {
            'api.publica.test'  => ['203.0.113.10'],
            'interno.publica.test' => ['10.0.0.5'],
            default             => [],
        };

        $base = ['ai_enabled' => true, 'ai_provider' => 'custom', 'ai_api_key' => 'k', 'ai_model' => 'llama3'];

        $this->actingAs($admin)->put('/settings/ai', $base + ['ai_base_url' => 'http://api.publica.test/v1'])->assertSessionHasErrors('ai_base_url');
        $this->actingAs($admin)->put('/settings/ai', $base + ['ai_base_url' => 'https://interno.publica.test/v1'])->assertSessionHasErrors('ai_base_url');
        $this->actingAs($admin)->put('/settings/ai', $base + ['ai_base_url' => 'https://169.254.169.254/latest'])->assertSessionHasErrors('ai_base_url');
        $this->actingAs($admin)->put('/settings/ai', $base + ['ai_base_url' => 'https://127.0.0.1:6379'])->assertSessionHasErrors('ai_base_url');
        $this->actingAs($admin)->put('/settings/ai', ['ai_model' => ''] + $base + ['ai_base_url' => 'https://api.publica.test/v1'])->assertSessionHasErrors('ai_model');

        $this->actingAs($admin)->put('/settings/ai', $base + ['ai_base_url' => 'https://api.publica.test/v1/'])->assertSessionHas('success');
        $this->assertSame('https://api.publica.test/v1', $this->tenant->fresh()->ai_base_url);

        $this->actingAs($admin)->postJson('/settings/ai/test', ['ai_provider' => 'custom', 'ai_api_key' => 'k', 'ai_base_url' => 'https://10.1.1.1/v1'])
            ->assertStatus(422);
    }

    public function test_el_simulador_prueba_el_agente_con_la_ia_de_verdad_sin_enviar_nada(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        $flow  = BotFlow::create(['tenant_id' => $this->tenant->id, 'name' => 'Sim', 'draft' => []]);
        $graph = $this->agentFlow()->toArray();
        $this->replies = ['¡Hola! ¿Qué plan buscas?', 'Gracias a ti 👋 [FIN]'];

        $first = $this->actingAs($admin)->postJson("/flows/{$flow->id}/simulate", ['graph' => $graph, 'text' => 'hola'])->assertOk();
        $first->assertJsonPath('state.status', 'waiting_input')
            ->assertJsonPath('outbox.0.text', '¡Hola! ¿Qué plan buscas?')
            ->assertJsonPath('effects.0.kind', 'agent');

        $second = $this->actingAs($admin)->postJson("/flows/{$flow->id}/simulate", ['graph' => $graph, 'state' => $first->json('state'), 'text' => 'nada más, gracias'])->assertOk();
        $second->assertJsonPath('state.status', 'completed')
            ->assertJsonPath('outbox.0.text', 'Gracias a ti 👋');

        $this->assertSame(['user', 'assistant', 'user'], array_column($this->aiRequests()[1]['input'], 'role'));
        Http::assertNotSent(fn (HttpRequest $r) => str_contains($r->url(), 'graph.facebook.com'));
        $this->assertSame(0, \DB::table('messages')->count());
        $this->assertSame(0, \DB::table('ai_agent_turns')->count());
        $this->assertSame(['agent_sim', 'agent_sim'], \App\Models\AiUsageLog::pluck('purpose')->all());
    }

    public function test_el_editor_recibe_los_agentes_de_la_empresa(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        $flow  = BotFlow::create(['tenant_id' => $this->tenant->id, 'name' => 'F', 'draft' => []]);

        $this->actingAs($admin)->get("/flows/{$flow->id}/edit")->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('catalog.agents.0.name', 'Ventas'));
    }
}
