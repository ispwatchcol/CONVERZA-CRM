<?php

namespace Tests\Unit\Ai;

use App\Models\Tenant;
use App\Services\Ai\Agents\AgentReply;
use App\Services\Ai\Agents\AgentResponder;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiProviders;
use App\Services\Ai\AiRequest;
use App\Services\Ai\AnthropicProvider;
use App\Services\Ai\ChatCompletionsProvider;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\PublicUrl;
use App\Services\Ai\XaiProvider;
use App\Services\Payments\ReceiptReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Los proveedores de IA: cada empresa elige el suyo, su modelo y (si es «otro
 * compatible») su URL; cada uno habla su API y, si el modelo elegido no acepta
 * algo opcional, lo repite sin eso.
 */
class ProvidersTest extends TestCase
{
    protected function tearDown(): void
    {
        PublicUrl::$resolver = null;

        parent::tearDown();
    }

    private function receiptRequest(): AiRequest
    {
        return new AiRequest(
            purpose: 'receipt',
            instructions: 'Transcribe.',
            messages: [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Lee esto'],
                ['type' => 'image', 'mime' => 'image/jpg', 'base64' => 'QUJD'],
            ]]],
            schema: ['name' => 'comprobante_de_pago', 'schema' => ReceiptReader::SCHEMA],
        );
    }

    public function test_cada_proveedor_tiene_su_clase_y_la_empresa_pone_modelo_y_url(): void
    {
        $providers = new AiProviders();

        $this->assertInstanceOf(OpenAiProvider::class, $providers->make('openai', 'k'));
        $this->assertInstanceOf(XaiProvider::class, $providers->make('xai', 'k'));
        $this->assertInstanceOf(AnthropicProvider::class, $providers->make('anthropic', 'k'));
        foreach (['gemini', 'deepseek', 'groq', 'openrouter', 'mistral', 'custom'] as $name) {
            $this->assertInstanceOf(ChatCompletionsProvider::class, $providers->make($name, 'k'), $name);
        }
        $this->assertNull($providers->make('inventado', 'k'));

        $this->assertSame('gpt-5-mini', $providers->make('openai', 'k')->model(), 'Sin elegir: el de config.');
        $this->assertSame('gpt-4.1', $providers->make('openai', 'k', 'gpt-4.1')->model());
        $this->assertFalse($providers->make('custom', 'k', 'llama3')->isConfigured(), '«Otro» sin URL no tiene a quién llamar.');
        $this->assertTrue($providers->make('custom', 'k', 'llama3', 'https://api.otro.test/v1')->isConfigured());

        $tenant = new Tenant(['ai_provider' => 'groq', 'ai_api_key' => 'gsk', 'ai_model' => 'llama-3.1-8b-instant']);
        $tenant->syncOriginal();
        $this->assertSame('llama-3.1-8b-instant', $providers->forTenant($tenant)->model());
    }

    public function test_chat_completions_manda_el_modelo_elegido_la_imagen_y_el_schema(): void
    {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
            'model'   => 'gemini-2.5-pro',
            'choices' => [['finish_reason' => 'stop', 'message' => ['content' => '{"valor":50000}']]],
            'usage'   => ['prompt_tokens' => 1500, 'completion_tokens' => 90, 'completion_tokens_details' => ['reasoning_tokens' => 40]],
        ])]);

        $response = (new ChatCompletionsProvider('gemini', 'AIza-k', 'gemini-2.5-pro'))->respond($this->receiptRequest());

        $this->assertSame(['valor' => 50000], $response->json);
        $this->assertSame('gemini', $response->provider);
        $this->assertSame(1500, $response->inputTokens);
        $this->assertSame(40, $response->reasoningTokens);

        Http::assertSent(fn (Request $r) => $r->url() === 'https://generativelanguage.googleapis.com/v1beta/openai/chat/completions'
            && $r->hasHeader('Authorization', 'Bearer AIza-k')
            && $r['model'] === 'gemini-2.5-pro'
            && $r['messages'][0] === ['role' => 'system', 'content' => 'Transcribe.']
            && $r['messages'][1]['content'][1] === ['type' => 'image_url', 'image_url' => ['url' => 'data:image/jpg;base64,QUJD']]
            && $r['response_format']['json_schema']['strict'] === true
            && $r['reasoning_effort'] === 'low');
    }

    public function test_si_el_modelo_no_acepta_el_schema_se_repite_sin_el_y_se_rescata_el_json(): void
    {
        Http::fake(['api.deepseek.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'response_format json_schema is unavailable']], 400)
            ->push(['choices' => [['finish_reason' => 'stop', 'message' => ['content' => "Aquí está:\n```json\n{\"valor\": 12000}\n```"]]]])]);

        $response = (new ChatCompletionsProvider('deepseek', 'sk'))->respond($this->receiptRequest());

        $this->assertSame(['valor' => 12000], $response->json);

        $sent = collect(Http::recorded())->map(fn ($p) => $p[0])->values();
        $this->assertCount(2, $sent);
        $this->assertArrayHasKey('response_format', $sent[0]->data());
        $this->assertArrayNotHasKey('response_format', $sent[1]->data());
        $this->assertStringContainsString('Responde ÚNICAMENTE con un objeto JSON', $sent[1]['messages'][0]['content']);
    }

    public function test_un_400_sin_nada_opcional_que_quitar_no_se_repite(): void
    {
        Http::fake(['api.groq.com/*' => Http::response(['error' => ['message' => 'model not found']], 400)]);

        try {
            (new ChatCompletionsProvider('groq', 'gsk'))->respond(new AiRequest('agent', 'x', [['role' => 'user', 'content' => 'hola']]));
            $this->fail('Tiene que lanzar.');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('Groq respondió 400: model not found', $e->getMessage());
        }

        Http::assertSentCount(1);
    }

    public function test_anthropic_usa_su_encabezado_su_system_y_output_config(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response([
            'model'       => 'claude-opus-5-5',
            'stop_reason' => 'end_turn',
            'content'     => [['type' => 'thinking', 'thinking' => ''], ['type' => 'text', 'text' => '{"valor":7000}']],
            'usage'       => ['input_tokens' => 1200, 'output_tokens' => 80, 'cache_read_input_tokens' => 300],
        ])]);

        $response = (new AnthropicProvider('sk-ant-k'))->respond($this->receiptRequest());

        $this->assertSame(['valor' => 7000], $response->json);
        $this->assertSame(1500, $response->inputTokens);
        $this->assertSame(300, $response->cachedTokens);
        $this->assertNull($response->costUsd);

        Http::assertSent(function (Request $r) {
            $alertas = $r['output_config']['format']['schema']['properties']['alertas'];

            return $r->url() === 'https://api.anthropic.com/v1/messages'
                && $r->hasHeader('x-api-key', 'sk-ant-k')
                && $r->hasHeader('anthropic-version', '2026-10-21')
                && ! $r->hasHeader('Authorization')
                && $r['model'] === 'claude-opus-5-5'
                && $r['system'] === 'Transcribe.'
                && $r['output_config']['effort'] === 'low'
                && $r['output_config']['format']['type'] === 'json_schema'
                && ! array_key_exists('maxItems', $alertas)
                && ! array_key_exists('temperature', $r->data())
                && $r['messages'][0]['content'][1] === ['type' => 'image', 'source' => ['type' => 'base64', 'media_type' => 'image/jpeg', 'data' => 'QUJD']];
        });
    }

    public function test_anthropic_empieza_por_el_usuario_y_no_reintenta_una_negativa(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['stop_reason' => 'refusal', 'content' => []])]);

        try {
            (new AnthropicProvider('k'))->respond(new AiRequest('agent', 'x', [
                ['role' => 'assistant', 'content' => '¡Hola! ¿En qué te ayudo?'],
                ['role' => 'user', 'content' => 'hola'],
            ]));
            $this->fail('Tiene que lanzar.');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->retryable);
        }

        Http::assertSent(fn (Request $r) => $r['messages'][0]['role'] === 'user' && $r['messages'][1]['role'] === 'assistant');
    }

    public function test_la_lista_de_modelos_deja_solo_los_de_texto(): void
    {
        Http::fake(['api.openai.com/*' => Http::response(['data' => [
            ['id' => 'gpt-5-mini'], ['id' => 'text-embedding-3-small'], ['id' => 'whisper-1'],
            ['id' => 'gpt-image-1'], ['id' => 'gpt-4.1'], ['id' => 'tts-1'], ['id' => 'gpt-4o-realtime-preview'],
        ]])]);

        $this->assertSame(['gpt-4.1', 'gpt-5-mini'], array_column((new OpenAiProvider('sk'))->models(), 'id'));
    }

    public function test_una_url_propia_tiene_que_ser_https_y_publica(): void
    {
        PublicUrl::$resolver = fn (string $host) => ['publica.test' => ['198.51.100.7'], 'privada.test' => ['192.168.1.10']][$host] ?? [];

        $this->assertNull(PublicUrl::problem('https://publica.test/v1'));
        $this->assertNotNull(PublicUrl::problem('http://publica.test/v1'));
        $this->assertNotNull(PublicUrl::problem('https://privada.test/v1'));
        $this->assertNotNull(PublicUrl::problem('https://nadie.test/v1'));
        $this->assertNotNull(PublicUrl::problem('https://user:pass@publica.test/v1'));
        $this->assertNotNull(PublicUrl::problem('https://[::1]/v1'));
        $this->assertNotNull(PublicUrl::problem('https://169.254.169.254/'));
        $this->assertNotNull(PublicUrl::problem(''));
    }

    public function test_el_agente_separa_el_mensaje_de_la_etiqueta_de_salida(): void
    {
        $this->assertEquals(new AgentReply('Hola, ¿en qué te ayudo?', AgentReply::CONTINUE), AgentResponder::parse('Hola, ¿en qué te ayudo?'));
        $this->assertSame(AgentReply::HUMAN, AgentResponder::parse("Te paso con el equipo.\n[asesor]")->action);
        $this->assertSame('Te paso con el equipo.', AgentResponder::parse("Te paso con el equipo.\n[ASESOR]")->text);
        $this->assertSame(AgentReply::END, AgentResponder::parse('Chao 👋 [FIN]')->action);
        $this->assertSame(AgentReply::HUMAN, AgentResponder::parse('[FIN] [ASESOR]')->action, 'Si pide las dos, gana la persona.');
        $this->assertSame('', AgentResponder::parse('[ASESOR]')->text);
    }

    public function test_el_historial_viaja_acotado_y_termina_en_el_cliente(): void
    {
        config()->set('ai.agents.history_messages', 4);

        $history = [];
        for ($i = 1; $i <= 6; $i++) {
            $history[] = ['role' => 'user', 'content' => "u{$i}"];
            $history[] = ['role' => 'assistant', 'content' => "a{$i}"];
        }

        $sent = AgentResponder::history($history);

        $this->assertCount(5, $sent, 'Los últimos 4, más el turno del cliente al final.');
        $this->assertSame('user', end($sent)['role']);
        $this->assertSame(['u5', 'a5', 'u6', 'a6'], array_column(array_slice($sent, 0, 4), 'content'));
    }

    public function test_el_prompt_de_la_empresa_va_dentro_del_marco_de_converza(): void
    {
        $prompt = AgentResponder::instructions('Solo vendes fibra.', ['empresa' => 'Fibra X', 'contacto' => 'Marta', 'dia' => 'lunes', 'fecha' => '19/11/2026', 'hora' => '10:30']);

        $this->assertStringContainsString('«Fibra X»', $prompt);
        $this->assertStringContainsString('Hoy es lunes 19/11/2026, son las 10:30.', $prompt);
        $this->assertStringContainsString('Estás hablando con Marta.', $prompt);
        $this->assertStringContainsString("\"\"\"\nSolo vendes fibra.\n\"\"\"", $prompt);
        $this->assertStringContainsString('nunca inventes precios', $prompt);
    }
}
