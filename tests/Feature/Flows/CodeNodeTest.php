<?php

namespace Tests\Feature\Flows;

use App\Models\BotFlow;
use App\Models\User;
use App\Services\Flows\FlowValidator;
use App\Services\Flows\GraphBuilder;
use App\Services\Flows\Validation\ValidationContext;

/** El bloque «Código» en un flujo de verdad, en el validador, en el simulador y en «Probar código». */
class CodeNodeTest extends FlowsTestCase
{
    private const CODE = <<<'JS'
        const megas = numero(vars.plan)
        const precios = { 20: 60000, 50: 90000 }
        if (!precios[megas]) return "No existe"
        vars.precio = moneda(precios[megas])
        vars.plan_texto = `${megas} megas`
        return megas >= 50 ? "Premium" : "Básico"
        JS;

    private function codeFlow(string $code = self::CODE): GraphBuilder
    {
        return (new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start'], 'timezone' => 'America/Bogota'])
            ->node('plan', 'question', ['text' => '¿Qué plan quieres? (20 o 50 megas)', 'save_as' => 'plan', 'validation' => 'any'])
            ->node('codigo', 'code', ['code' => $code, 'exits' => [['id' => 'p', 'name' => 'Premium'], ['id' => 'n', 'name' => 'No existe']]])
            ->node('basico', 'message', ['text' => 'Básico: {{plan_texto}} a {{precio}}'])
            ->node('premium', 'message', ['text' => '🚀 Premium: {{plan_texto}} a {{precio}}'])
            ->node('no', 'message', ['text' => 'Ese plan no existe'])
            ->node('error', 'handoff', ['text' => 'Te paso con un asesor ({{codigo.error}})', 'assign' => 'none', 'note' => false])
            ->node('fin', 'end')
            ->edge('start', 'next', 'plan')
            ->edge('plan', 'next', 'codigo')
            ->edge('codigo', 'next', 'basico')
            ->edge('codigo', 'exit_p', 'premium')
            ->edge('codigo', 'exit_n', 'no')
            ->edge('codigo', 'error', 'error')
            ->edge('basico', 'next', 'fin')
            ->edge('premium', 'next', 'fin')
            ->edge('no', 'next', 'fin');
    }

    private function admin(): User
    {
        $user = User::create(['name' => 'Admin', 'email' => 'admin@isp.test', 'password' => 'x', 'tenant_id' => $this->tenant->id]);
        \DB::table('staff_members')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $user->id, 'role' => 'admin', 'is_active' => true]);

        return $user;
    }

    public function test_el_codigo_calcula_deja_variables_y_elige_la_salida(): void
    {
        $this->publishedFlow($this->codeFlow());

        $this->incoming('hola');
        $this->incoming('50 megas');

        $this->assertSame(['¿Qué plan quieres? (20 o 50 megas)', '🚀 Premium: 50 megas a $90.000'], $this->sentTexts());
        $run = $this->flowRun();
        $this->assertSame('completed', $run->status);
        $this->assertSame('$90.000', $run->variables['precio']);
        $this->assertSame('Premium', $run->variables['codigo.resultado']);
    }

    public function test_sin_salida_que_calce_sigue_por_siguiente_y_un_error_sale_por_error(): void
    {
        $this->publishedFlow($this->codeFlow());
        $this->incoming('hola');
        $this->incoming('20');
        $this->assertSame('Básico: 20 megas a $60.000', $this->sentTexts()[1]);

        BotFlow::query()->delete();
        \DB::table('bot_flow_runs')->delete();
        // Define las variables (el validador exige que existan) pero falla al ejecutar.
        $this->publishedFlow($this->codeFlow('vars.precio = 1; vars.plan_texto = ""; let x = null; vars.y = x.length'), name: 'Roto');
        $this->incoming('hola', '573009998877');
        $this->incoming('20', '573009998877');

        $this->assertStringContainsString('Te paso con un asesor (Línea 1: No se puede leer «length»', $this->sentTexts()[3]);
    }

    public function test_el_validador_revisa_la_sintaxis_y_las_salidas(): void
    {
        $validator = app(FlowValidator::class);
        $ctx       = ValidationContext::forTenant($this->tenant->id);

        $this->assertSame([], $validator->validate($this->codeFlow()->toArray(), $ctx)['errors'], 'Las variables que escribe el código se pueden usar después.');

        $bad = $validator->validate($this->codeFlow("let a = \n")->toArray(), $ctx)['errors'];
        $this->assertStringContainsString('Código: Línea', $bad[0]['message']);

        $graph = $this->codeFlow()->toArray();
        $graph['nodes'][2]['data']['exits'][] = ['id' => 'x', 'name' => 'premium'];
        $this->assertStringContainsString('dos salidas que se llaman', $validator->validate($graph, $ctx)['errors'][0]['message']);

        $this->assertStringContainsString('variable del sistema', $validator->validate($this->codeFlow('vars.respuesta = 1')->toArray(), $ctx)['errors'][0]['message']);
    }

    public function test_el_simulador_corre_el_codigo(): void
    {
        $admin = $this->admin();
        $flow  = BotFlow::create(['tenant_id' => $this->tenant->id, 'name' => 'Sim', 'draft' => []]);
        $graph = $this->codeFlow()->toArray();

        $first  = $this->actingAs($admin)->postJson("/flows/{$flow->id}/simulate", ['graph' => $graph, 'text' => 'hola'])->assertOk();
        $second = $this->actingAs($admin)->postJson("/flows/{$flow->id}/simulate", ['graph' => $graph, 'state' => $first->json('state'), 'text' => '20'])->assertOk();

        $second->assertJsonPath('outbox.0.text', 'Básico: 20 megas a $60.000');
        $code = collect($second->json('trace'))->firstWhere('node_id', 'codigo');
        $this->assertSame('$60.000', $code['detail']['variables']['precio']);
    }

    public function test_probar_codigo_con_variables_de_ejemplo(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)->postJson('/flows/code/test', [
            'code'      => self::CODE . "\nlog('nunca')",
            'variables' => ['plan' => '50'],
            'exits'     => ['Premium', 'No existe'],
        ])->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('returned', 'Premium')
            ->assertJsonPath('exit', 'Premium')
            ->assertJsonPath('variables.precio', '$90.000');

        $this->actingAs($admin)->postJson('/flows/code/test', ['code' => 'while (true) {}'])
            ->assertOk()
            ->assertJsonPath('ok', false)
            ->assertJsonPath('exit', 'Error');

        $asesor = User::create(['name' => 'Asesor', 'email' => 'asesor@isp.test', 'password' => 'x', 'tenant_id' => $this->tenant->id]);
        \DB::table('staff_members')->insert(['tenant_id' => $this->tenant->id, 'user_id' => $asesor->id, 'role' => 'agent', 'is_active' => true]);
        $this->actingAs($asesor)->postJson('/flows/code/test', ['code' => '1'])->assertForbidden();
    }
}
