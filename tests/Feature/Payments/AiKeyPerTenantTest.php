<?php

namespace Tests\Feature\Payments;

use App\Models\AiUsageLog;
use App\Models\PaymentReport;
use App\Models\Tenant;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AiUnavailable;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * La llave de IA es de cada empresa: se guarda cifrada, nunca viaja al
 * navegador y una empresa jamás usa la de otra (ni hay una de plataforma).
 */
class AiKeyPerTenantTest extends PaymentsTestCase
{
    public function test_la_lectura_usa_la_llave_de_la_empresa(): void
    {
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        [$xai] = $this->requestsTo('https://api.x.ai/');
        $this->assertTrue($xai->hasHeader('Authorization', 'Bearer xai-test-key'));
    }

    public function test_sin_llave_no_se_lee_y_no_se_usa_ninguna_otra(): void
    {
        $this->tenant->update(['ai_api_key' => null]);
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $this->assertSame('sin_llave', $this->report()->reason);
        $this->assertSame([], $this->requestsTo('https://api.x.ai/'));
        $this->assertSame([], $this->requestsTo('https://api.openai.com/'));
    }

    public function test_una_llave_de_otro_proveedor_no_cuenta(): void
    {
        // Tiene llave pero sin proveedor elegido: no hay con quién hablar.
        $this->tenant->update(['ai_provider' => null]);

        $this->assertSame('sin_llave', app(AiGateway::class)->unavailableReason($this->tenant->fresh()));
    }

    public function test_cada_empresa_usa_su_proveedor_y_su_llave(): void
    {
        $otra = Tenant::create([
            'slug' => 'otra-empresa', 'name' => 'Otra', 'is_active' => true,
            'ai_enabled' => true, 'ai_provider' => 'openai', 'ai_api_key' => 'sk-de-la-otra',
        ]);

        $this->openaiQueue = [[200, [
            'status' => 'completed', 'model' => 'gpt-5-mini',
            'output' => [['type' => 'message', 'content' => [['type' => 'output_text', 'text' => 'hola']]]],
            'usage'  => ['input_tokens' => 1000, 'output_tokens' => 100],
        ]]];

        $response = app(AiGateway::class)->respond($otra, new AiRequest('receipt', 'x', [['role' => 'user', 'content' => 'hola']]));

        $this->assertSame('hola', $response->text);
        $this->assertSame('openai', $response->provider);
        Http::assertSent(fn (Request $r) => str_starts_with($r->url(), 'https://api.openai.com/')
            && $r->hasHeader('Authorization', 'Bearer sk-de-la-otra'));
        Http::assertNotSent(fn (Request $r) => $r->hasHeader('Authorization', 'Bearer xai-test-key'));

        // El gasto queda en SU bitácora, estimado (OpenAI no informa el costo).
        $log = AiUsageLog::withoutGlobalScopes()->where('tenant_id', $otra->id)->firstOrFail();
        $this->assertSame('openai', $log->provider);
        $this->assertTrue($log->cost_estimated);
        $this->assertEquals(0, AiUsageLog::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->count());
    }

    public function test_el_tope_es_opcional_y_de_la_empresa(): void
    {
        $gateway = app(AiGateway::class);

        $this->assertNull($gateway->monthlyBudget($this->tenant), 'Sin tope por defecto: el gasto es de la empresa.');
        AiUsageLog::create(['tenant_id' => $this->tenant->id, 'purpose' => 'receipt', 'provider' => 'xai', 'model' => 'm', 'status' => 'ok', 'cost_usd' => 999, 'created_at' => now()]);
        $this->assertNull($gateway->unavailableReason($this->tenant->fresh()));

        $this->tenant->update(['ai_monthly_budget_usd' => 5]);
        $this->assertSame('presupuesto_agotado', $gateway->unavailableReason($this->tenant->fresh()));
    }

    public function test_la_llave_se_guarda_cifrada_y_nunca_se_serializa(): void
    {
        $this->tenant->update(['ai_provider' => 'openai', 'ai_api_key' => 'sk-proj-secreta-1234']);

        $raw = (string) DB::table('tenants')->where('id', $this->tenant->id)->value('ai_api_key');
        $this->assertStringNotContainsString('sk-proj-secreta', $raw);
        $this->assertSame('sk-proj-secreta-1234', $this->tenant->fresh()->ai_api_key);
        $this->assertStringNotContainsString('sk-proj-secreta', $this->tenant->fresh()->toJson());
        $this->assertTrue($this->tenant->fresh()->hasAiKey());
    }

    public function test_el_admin_carga_cambia_y_quita_su_llave(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');
        $this->tenant->update(['ai_provider' => null, 'ai_api_key' => null, 'ai_enabled' => false]);

        // Encender sin proveedor ni llave no se deja.
        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true])->assertSessionHas('error');
        $this->assertFalse($this->tenant->fresh()->ai_enabled);

        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'openai', 'ai_api_key' => '  sk-uno  '])
            ->assertSessionHas('success');
        $t = $this->tenant->fresh();
        $this->assertTrue($t->ai_enabled);
        $this->assertSame('openai', $t->ai_provider);
        $this->assertSame('sk-uno', $t->ai_api_key);

        // En blanco = conservar la guardada.
        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'openai', 'ai_api_key' => ''])->assertSessionHas('success');
        $this->assertSame('sk-uno', $this->tenant->fresh()->ai_api_key);

        // Cambiar de proveedor sin llave nueva borra la vieja (era de otro).
        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'xai'])->assertSessionHas('success');
        $this->assertNull($this->tenant->fresh()->ai_api_key);
        $this->assertFalse($this->tenant->fresh()->hasAiKey());

        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'xai', 'ai_api_key' => 'xai-dos', 'ai_monthly_budget_usd' => 20]);
        $this->assertEquals(20, $this->tenant->fresh()->ai_monthly_budget_usd);

        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'xai', 'ai_api_key_clear' => true]);
        $this->assertFalse($this->tenant->fresh()->hasAiKey());

        $this->actingAs($admin)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'otro'])->assertSessionHasErrors('ai_provider');
    }

    public function test_un_asesor_no_toca_la_llave(): void
    {
        $asesor = $this->userWithRole('asesor@isp.test', 'agent');

        $this->actingAs($asesor)->put('/settings/ai', ['ai_enabled' => true, 'ai_provider' => 'xai', 'ai_api_key' => 'x'])->assertForbidden();
        $this->actingAs($asesor)->postJson('/settings/ai/test', ['ai_provider' => 'xai', 'ai_api_key' => 'x'])->assertForbidden();
    }

    public function test_probar_la_llave_no_cuesta_y_distingue_una_invalida(): void
    {
        $admin = $this->userWithRole('admin@isp.test', 'admin');

        $this->openaiQueue = [[200, ['data' => []]], [401, ['error' => ['message' => 'Incorrect API key']]]];

        $this->actingAs($admin)->postJson('/settings/ai/test', ['ai_provider' => 'openai', 'ai_api_key' => 'sk-buena'])
            ->assertOk()->assertJsonPath('ok', true);
        $this->actingAs($admin)->postJson('/settings/ai/test', ['ai_provider' => 'openai', 'ai_api_key' => 'sk-mala'])
            ->assertOk()->assertJsonPath('ok', false);
        $this->actingAs($admin)->postJson('/settings/ai/test', ['ai_provider' => 'openai'])
            ->assertStatus(422);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://api.openai.com/v1/models' && $r->hasHeader('Authorization', 'Bearer sk-buena'));
    }

    public function test_un_reporte_sin_llave_explica_que_falta(): void
    {
        $this->assertStringContainsString('llave de IA de tu empresa', PaymentReport::REASONS['sin_llave']);
        $this->expectException(AiUnavailable::class);

        $this->tenant->update(['ai_api_key' => null]);
        app(AiGateway::class)->respond($this->tenant->fresh(), new AiRequest('receipt', 'x', [['role' => 'user', 'content' => 'x']]));
    }
}
