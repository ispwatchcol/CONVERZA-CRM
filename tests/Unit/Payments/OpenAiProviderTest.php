<?php

namespace Tests\Unit\Payments;

use App\Services\Ai\AiProvider;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiRequest;
use App\Services\Ai\OpenAiProvider;
use App\Services\Ai\XaiProvider;
use App\Services\Payments\ReceiptReader;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * OpenAI habla la misma Responses API que xAI; lo que cambia es la llave, el
 * modelo, el schema (sin límites de lista) y que no informa el costo.
 */
class OpenAiProviderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('ai.openai.api_key', 'sk-test');
        config()->set('ai.openai.base_url', 'https://api.openai.com/v1');
        config()->set('ai.openai.model', 'gpt-5-mini');
        config()->set('ai.openai.reasoning_effort', 'low');
    }

    public function test_el_proveedor_lo_elige_la_configuracion(): void
    {
        config()->set('ai.provider', 'openai');
        $this->assertInstanceOf(OpenAiProvider::class, app(AiProvider::class));

        config()->set('ai.provider', 'xai');
        $this->assertInstanceOf(XaiProvider::class, app(AiProvider::class));
    }

    public function test_lee_un_comprobante_con_su_llave_su_modelo_y_sin_retencion(): void
    {
        Http::fake(['api.openai.com/*' => Http::response([
            'status' => 'completed',
            'model'  => 'gpt-5-mini-2026',
            'output' => [
                ['type' => 'reasoning', 'summary' => []],
                ['type' => 'message', 'content' => [['type' => 'output_text', 'text' => '{"valor":50000}']]],
            ],
            'usage' => [
                'input_tokens'          => 2000,
                'output_tokens'         => 300,
                'output_tokens_details' => ['reasoning_tokens' => 120],
            ],
        ])]);

        $response = (new OpenAiProvider())->respond(new AiRequest(
            purpose: 'receipt',
            instructions: 'Transcribe.',
            messages: [['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => 'Lee esto'],
                ['type' => 'image', 'mime' => 'image/jpeg', 'base64' => 'QUJD'],
            ]]],
            schema: ['name' => 'comprobante_de_pago', 'schema' => ReceiptReader::SCHEMA],
        ));

        $this->assertSame(['valor' => 50000], $response->json);
        $this->assertSame('openai', $response->provider);
        $this->assertSame(2000, $response->inputTokens);
        $this->assertSame(120, $response->reasoningTokens);
        $this->assertNull($response->costUsd, 'OpenAI no informa el costo: lo estima AiGateway.');

        Http::assertSent(function (Request $request) {
            $alertas = $request['text']['format']['schema']['properties']['alertas'];

            return $request->url() === 'https://api.openai.com/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer sk-test')
                && $request['model'] === 'gpt-5-mini'
                && $request['store'] === false
                && $request['reasoning'] === ['effort' => 'low']
                && $request['text']['format']['strict'] === true
                && ! array_key_exists('maxItems', $alertas)
                && $alertas['items'] === ['type' => 'string']
                && $request['input'][0]['content'][1]['image_url'] === 'data:image/jpeg;base64,QUJD';
        });
    }

    public function test_una_llave_invalida_no_se_reintenta_y_un_429_si(): void
    {
        Http::fake(['api.openai.com/*' => Http::sequence()
            ->push(['error' => ['message' => 'Incorrect API key']], 401)
            ->push(['error' => ['message' => 'Rate limit']], 429)]);

        $request = new AiRequest('receipt', 'x', [['role' => 'user', 'content' => 'hola']]);

        try {
            (new OpenAiProvider())->respond($request);
            $this->fail('Un 401 tiene que lanzar.');
        } catch (AiProviderException $e) {
            $this->assertFalse($e->retryable);
            $this->assertStringContainsString('OpenAI respondió 401', $e->getMessage());
        }

        try {
            (new OpenAiProvider())->respond($request);
            $this->fail('Un 429 tiene que lanzar.');
        } catch (AiProviderException $e) {
            $this->assertTrue($e->retryable);
        }
    }
}
