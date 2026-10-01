<?php

namespace Tests\Feature\Flows;

use App\Models\BotFlow;
use App\Models\BotFlowVersion;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Flows\GraphBuilder;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * El Workspace desde la UI: solo el admin entra, el borrador no toca lo
 * publicado, publicar valida, y el simulador no le escribe a nadie.
 */
class FlowControllerTest extends FlowsTestCase
{
    private User $admin;
    private User $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = $this->userWithRole('admin@isp.test', 'admin');
        $this->asesor = $this->userWithRole('asesor@isp.test', 'agent');
    }

    public function test_solo_el_admin_entra_al_workspace(): void
    {
        $this->actingAs($this->asesor)->get('/flows')->assertForbidden();
        $this->actingAs($this->asesor)->post('/flows', ['name' => 'X', 'template' => 'blank'])->assertForbidden();

        $this->actingAs($this->admin)->get('/flows')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Flows/Index')->has('flows', 0)->has('templates', 3));
    }

    public function test_crear_desde_una_plantilla_deja_un_borrador_apagado(): void
    {
        $this->actingAs($this->admin)
            ->post('/flows', ['name' => 'Soporte', 'template' => 'support'])
            ->assertRedirect();

        $flow = BotFlow::firstOrFail();
        $this->assertSame('Soporte', $flow->name);
        $this->assertFalse($flow->is_active);
        $this->assertNull($flow->published_version_id);
        $this->assertContains('menu', array_column($flow->draft['nodes'], 'id'));

        $this->actingAs($this->admin)->get("/flows/{$flow->id}/edit")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Flows/Editor')
                ->where('flow.id', $flow->id)
                ->where('flow.has_unpublished_changes', true)
                ->has('graph.nodes')
                ->has('catalog.variables'));
    }

    public function test_guardar_el_borrador_no_toca_la_version_publicada(): void
    {
        $flow = $this->publishedFlow($this->menuFlow());
        $published = $flow->publishedVersion->graph;

        $graph = $this->menuFlow(['text' => 'Texto nuevo sin publicar'])->toArray();
        $this->actingAs($this->admin)
            ->putJson("/flows/{$flow->id}", ['graph' => $graph, 'name' => 'Atención v2'])
            ->assertOk()
            ->assertJsonPath('flow.has_unpublished_changes', true);

        $flow->refresh();
        $this->assertSame('Atención v2', $flow->name);
        $this->assertSame('Texto nuevo sin publicar', collect($flow->draft['nodes'])->firstWhere('id', 'menu')['data']['text']);
        $this->assertSame($published, $flow->publishedVersion->graph, 'El motor sigue con lo publicado.');
    }

    public function test_un_flujo_con_errores_no_se_publica_y_el_error_senala_el_bloque(): void
    {
        $flow = BotFlow::create([
            'tenant_id' => $this->tenant->id,
            'name'      => 'Roto',
            'draft'     => (new GraphBuilder())
                ->node('start', 'start', ['trigger' => ['type' => 'conversation_start']])
                ->node('m', 'message', ['text' => ''])
                ->edge('start', 'next', 'm')
                ->toArray(),
        ]);

        $this->actingAs($this->admin)
            ->postJson("/flows/{$flow->id}/publish")
            ->assertStatus(422)
            ->assertJsonPath('errors.0.node_id', 'm');

        $this->assertNull($flow->fresh()->published_version_id);
    }

    public function test_publicar_crea_versiones_y_encender_exige_haber_publicado(): void
    {
        $flow = BotFlow::create(['tenant_id' => $this->tenant->id, 'name' => 'Nuevo', 'draft' => $this->menuFlow()->toArray()]);

        $this->actingAs($this->admin)
            ->patchJson("/flows/{$flow->id}/toggle", ['active' => true])
            ->assertStatus(422);

        $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/publish", ['note' => 'Primera'])
            ->assertOk()
            ->assertJsonPath('flow.version', 1)
            ->assertJsonPath('flow.has_unpublished_changes', false);

        $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/publish")
            ->assertOk()
            ->assertJsonPath('flow.version', 2)
            ->assertJsonCount(2, 'versions');

        $this->actingAs($this->admin)
            ->patchJson("/flows/{$flow->id}/toggle", ['active' => true])
            ->assertOk()
            ->assertJsonPath('flow.is_active', true);

        $flow->refresh();
        $this->assertSame('conversation_start', $flow->trigger_type, 'El disparador se copia al publicar.');
    }

    public function test_restaurar_una_version_vieja_la_deja_en_el_borrador_y_deja_rastro(): void
    {
        $flow = $this->publishedFlow($this->menuFlow(['text' => 'Menú de la v1']));

        $flow->update(['draft' => $this->menuFlow(['text' => 'Menú de la v2'])->toArray()]);
        $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/publish")->assertOk();

        $v1 = BotFlowVersion::where('version', 1)->firstOrFail();
        $this->actingAs($this->admin)
            ->postJson("/flows/{$flow->id}/versions/{$v1->id}/restore")
            ->assertOk()
            ->assertJsonPath('flow.draft_source_version', 1);

        $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/publish")->assertOk();

        $v3 = BotFlowVersion::where('version', 3)->firstOrFail();
        $this->assertSame('Restaurada desde la v1', $v3->note);
        $this->assertSame('Menú de la v1', collect($v3->graph['nodes'])->firstWhere('id', 'menu')['data']['text']);
    }

    public function test_el_simulador_conversa_con_el_flujo_sin_enviar_nada_a_whatsapp(): void
    {
        $flow  = BotFlow::create(['tenant_id' => $this->tenant->id, 'name' => 'Sim', 'draft' => []]);
        $graph = $this->menuFlow()->toArray(); // lo que hay en el lienzo, aunque no esté guardado

        $first = $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/simulate", [
            'graph' => $graph, 'text' => 'hola', 'name' => 'Marta',
        ])->assertOk();

        $first->assertJsonPath('state.status', 'waiting_input')
            ->assertJsonPath('state.current_node', 'menu')
            ->assertJsonPath('outbox.0.kind', 'text');
        $this->assertStringContainsString('¡Hola Marta!', $first->json('outbox.0.text'));

        $second = $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/simulate", [
            'graph' => $graph, 'state' => $first->json('state'), 'text' => '2',
        ])->assertOk();
        $this->assertSame('¿Qué está pasando?', $second->json('outbox.0.text'));

        $third = $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/simulate", [
            'graph' => $graph, 'state' => $second->json('state'), 'text' => 'no tengo internet',
        ])->assertOk();

        $third->assertJsonPath('state.status', 'handed_off')
            ->assertJsonPath('effects.0.kind', 'handoff');
        $this->assertStringContainsString('falla: no tengo internet', $third->json('effects.0.note'));
        $this->assertSame(['start', 'menu'], array_column($first->json('trace'), 'node_id'), 'Muestra qué bloque se evaluó en cada paso.');

        // Lo único que tocó HTTP fue la página; a Meta no salió ningún mensaje.
        Http::assertNotSent(fn ($request) => str_contains($request->url(), '/messages'));
        $this->assertSame(0, \DB::table('messages')->count());
        $this->assertSame(0, \DB::table('bot_flow_runs')->count());
    }

    public function test_el_simulador_avisa_si_el_mensaje_no_arrancaria_el_flujo_de_verdad(): void
    {
        $flow = BotFlow::create(['tenant_id' => $this->tenant->id, 'name' => 'Sim', 'draft' => []]);

        $this->actingAs($this->admin)->postJson("/flows/{$flow->id}/simulate", [
            'graph' => $this->menuFlow(trigger: ['type' => 'keyword', 'keywords' => ['menu']])->toArray(),
            'text'  => 'hola',
        ])->assertOk()->assertJsonCount(1, 'notices');
    }

    public function test_el_flujo_de_otro_isp_no_existe_para_mi(): void
    {
        $otro  = Tenant::create(['slug' => 'otro', 'name' => 'Otro ISP', 'is_active' => true]);
        $ajeno = BotFlow::create(['tenant_id' => $otro->id, 'name' => 'Ajeno', 'draft' => $this->menuFlow()->toArray()]);

        $this->actingAs($this->admin)->get("/flows/{$ajeno->id}/edit")->assertNotFound();
        $this->actingAs($this->admin)->putJson("/flows/{$ajeno->id}", ['name' => 'Mío'])->assertNotFound();
        $this->actingAs($this->admin)->postJson("/flows/{$ajeno->id}/publish")->assertNotFound();
        $this->actingAs($this->admin)->postJson("/flows/{$ajeno->id}/simulate", ['graph' => []])->assertNotFound();
        $this->actingAs($this->admin)->delete("/flows/{$ajeno->id}")->assertNotFound();

        $this->assertSame('Ajeno', $ajeno->fresh()->name);
    }

    public function test_un_flujo_encendido_no_se_puede_borrar(): void
    {
        $flow = $this->publishedFlow($this->menuFlow());

        $this->actingAs($this->admin)->from('/flows')->delete("/flows/{$flow->id}")
            ->assertRedirect('/flows')
            ->assertSessionHas('error');
        $this->assertNotNull($flow->fresh());

        $this->actingAs($this->admin)->patch("/flows/{$flow->id}/toggle", ['active' => false]);
        $this->actingAs($this->admin)->delete("/flows/{$flow->id}")->assertRedirect('/flows');
        $this->assertNull($flow->fresh());
    }

    public function test_la_actividad_muestra_las_ejecuciones_con_su_traza(): void
    {
        $flow = $this->publishedFlow($this->menuFlow());
        $this->incoming('hola');
        $this->incoming('1');

        $this->actingAs($this->admin)->get("/flows/{$flow->id}/runs")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Flows/Runs')
                ->has('runs', 1)
                ->where('runs.0.status', 'completed')
                ->where('runs.0.steps.3.delivery', 'sent'));
    }

    private function userWithRole(string $email, string $role): User
    {
        $user = User::create(['name' => $email, 'email' => $email, 'password' => 'x', 'tenant_id' => $this->tenant->id]);

        \DB::table('staff_members')->insert([
            'tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => $role, 'is_active' => true,
        ]);

        return $user;
    }
}
