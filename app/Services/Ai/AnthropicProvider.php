<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

/**
 * Claude, por la Messages API: POST {base_url}/messages.
 *
 * Va por HTTP directo, como los demás proveedores, y no por el SDK de Anthropic:
 * así los nueve proveedores comparten el mismo manejo de errores, plazos y
 * reintentos (HttpAiProvider) y la prueba de todos es la misma (Http::fake).
 *
 * Diferencias con los otros:
 *
 *   - La llave va en `x-api-key` y la versión de la API en `anthropic-version`.
 *   - El prompt de sistema va aparte (`system`), no como un mensaje.
 *   - La salida JSON estricta es `output_config.format` (json_schema) y el
 *     esfuerzo de razonamiento `output_config.effort`. Un modelo viejo que no los
 *     acepta responde 400 y se repite sin ellos (el schema pasa a instrucción).
 *   - Con la versión 2026-10-21, `temperature`/`top_p`/`top_k` dan 400: no se mandan.
 *   - `stop_reason = refusal`: el modelo se negó. No se reintenta.
 *   - No informa el costo: AiGateway lo estima con los precios de config.
 */
final class AnthropicProvider extends HttpAiProvider
{
    /** Lo que el modo estricto de JSON schema no admite (límites numéricos y de tamaño). */
    private const UNSUPPORTED_KEYWORDS = ['maxItems', 'minItems', 'minimum', 'maximum', 'multipleOf'];

    public function name(): string
    {
        return 'anthropic';
    }

    protected function label(): string
    {
        return 'Anthropic';
    }

    protected function http(): PendingRequest
    {
        return Http::withHeaders([
            'x-api-key'         => (string) $this->apiKey,
            'anthropic-version' => (string) $this->config('version', '2026-10-21'),
        ])->acceptJson()->asJson()->withoutRedirecting();
    }

    public function respond(AiRequest $request): AiResponse
    {
        $model = (string) ($request->model ?: $this->model());

        $payload = [
            'model'      => $model,
            'max_tokens' => $request->maxOutputTokens,
            'system'     => $request->instructions,
            'messages'   => array_map(fn (array $message) => [
                'role'    => $message['role'],
                'content' => $this->parts(
                    $message,
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    fn (array $part) => ['type' => 'image', 'source' => [
                        'type'       => 'base64',
                        'media_type' => $part['mime'] === 'image/jpg' ? 'image/jpeg' : $part['mime'],
                        'data'       => $part['base64'],
                    ]],
                ),
            ], $request->messages),
        ];

        // La Messages API exige que la conversación empiece por el usuario; la
        // del agente puede empezar con el saludo del bot.
        if (($payload['messages'][0]['role'] ?? 'user') !== 'user') {
            array_unshift($payload['messages'], ['role' => 'user', 'content' => '(El cliente abrió la conversación.)']);
        }

        $outputConfig = [];

        if ($this->effort() !== '') {
            $outputConfig['effort'] = $this->effort();
        }

        if ($request->schema !== null) {
            $outputConfig['format'] = ['type' => 'json_schema', 'schema' => $this->schema($request->schema['schema'])];
        }

        if ($outputConfig !== []) {
            $payload['output_config'] = $outputConfig;
        }

        [$body, $sent] = $this->post('/messages', $payload, function (array $payload) use ($request): ?array {
            if (! isset($payload['output_config'])) {
                return null;
            }

            if (isset($payload['output_config']['format'])) {
                $payload['system'] .= $this->schemaAsInstructions($request->schema);
            }

            unset($payload['output_config']);

            return $payload;
        });

        $stop = (string) ($body['stop_reason'] ?? 'end_turn');

        if ($stop === 'refusal') {
            throw new AiProviderException('Anthropic se negó a responder (refusal).', retryable: false);
        }

        if ($stop === 'max_tokens') {
            throw new AiProviderException('Anthropic no terminó la respuesta (se cortó por largo).', retryable: false);
        }

        // Solo los bloques de texto: los de razonamiento (thinking) no son la respuesta.
        $text = trim(implode('', array_map(
            fn ($block) => (string) ($block['text'] ?? ''),
            array_filter((array) ($body['content'] ?? []), fn ($block) => ($block['type'] ?? null) === 'text'),
        )));

        $json = null;

        if ($request->schema !== null) {
            $json = $this->decodeJson($text);

            if ($json === null) {
                throw new AiProviderException('Anthropic devolvió algo que no es el JSON pedido.', retryable: true);
            }
        }

        $usage = (array) ($body['usage'] ?? []);

        return new AiResponse(
            text: $text,
            json: $json,
            provider: $this->name(),
            model: (string) ($body['model'] ?? $sent['model']),
            inputTokens: (int) ($usage['input_tokens'] ?? 0) + (int) ($usage['cache_read_input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cachedTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
        );
    }

    /** GET /models pagina con `has_more` / `last_id` → `after_id`. */
    public function models(): array
    {
        $data  = [];
        $after = null;

        for ($page = 0; $page < 5; $page++) {
            try {
                $response = $this->http()->connectTimeout(10)->timeout(20)
                    ->get($this->baseUrl() . '/models', array_filter(['limit' => 1000, 'after_id' => $after]));
            } catch (ConnectionException) {
                throw new AiProviderException('Sin conexión con Anthropic.', retryable: true);
            }

            if (! $response->successful()) {
                throw $this->failure($response);
            }

            $data  = array_merge($data, (array) $response->json('data', []));
            $after = $response->json('last_id');

            if (! $response->json('has_more') || ! $after) {
                break;
            }
        }

        return $this->normalizeModels($data);
    }

    private function schema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (in_array($key, self::UNSUPPORTED_KEYWORDS, true)) {
                unset($schema[$key]);
            } elseif (is_array($value)) {
                $schema[$key] = $this->schema($value);
            }
        }

        return $schema;
    }
}
