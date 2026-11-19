<?php

namespace App\Services\Ai;

/**
 * Un proveedor que habla la «Responses API»: POST {base_url}/responses. La
 * definió OpenAI y xAI la implementa igual, así que los dos comparten esta
 * clase; cada uno es una subclase que solo dice su nombre (= su bloque en
 * config/ai.php) y lo poco en que difiere.
 *
 * Decisiones que importan:
 *
 *   - `store: false`. Con `true`, el proveedor guarda la entrada y la respuesta
 *     (30 días) para poder retomarlas; aquí la entrada es la foto de un
 *     comprobante (con nombres y cuentas) o una conversación con un cliente, y
 *     no hay nada que retomar.
 *   - La imagen viaja como data URL en base64: los medios viven detrás de
 *     sesión, sin URL pública que el proveedor pueda bajar.
 *   - El costo sale de `usage.cost_in_usd_ticks` (10^10 ticks = 1 USD) cuando
 *     el proveedor lo informa (xAI). Si no (OpenAI), queda en null y AiGateway
 *     lo estima con los precios de config.
 *   - `reasoning` es opcional: un modelo que no razona (gpt-4.1, grok-4) lo
 *     rechaza con 400 y la petición se repite sin él.
 */
abstract class ResponsesApiProvider extends HttpAiProvider
{
    private const TICKS_PER_USD = 10_000_000_000;

    /**
     * El JSON schema tal como lo acepta este proveedor.
     *
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    protected function schema(array $schema): array
    {
        return $schema;
    }

    public function respond(AiRequest $request): AiResponse
    {
        $model = (string) ($request->model ?: $this->model());

        $payload = [
            'model'             => $model,
            'instructions'      => $request->instructions,
            'input'             => array_map(fn (array $message) => $this->message($message), $request->messages),
            'store'             => false,
            'max_output_tokens' => $request->maxOutputTokens,
        ];

        if ($this->effort() !== '') {
            $payload['reasoning'] = ['effort' => $this->effort()];
        }

        if ($request->schema !== null) {
            $payload['text'] = ['format' => [
                'type'   => 'json_schema',
                'name'   => $request->schema['name'],
                'schema' => $this->schema($request->schema['schema']),
                'strict' => true,
            ]];
        }

        [$body] = $this->post('/responses', $payload, function (array $payload): ?array {
            if (! isset($payload['reasoning'])) {
                return null;
            }

            unset($payload['reasoning']);

            return $payload;
        });

        // `incomplete` = se cortó (p. ej. por max_output_tokens) y un JSON a
        // medias no sirve. Reintentar con lo mismo daría lo mismo.
        if (($body['status'] ?? 'completed') !== 'completed') {
            throw new AiProviderException(
                "{$this->label()} no terminó la respuesta: " . json_encode($body['incomplete_details'] ?? $body['status']),
                retryable: false,
            );
        }

        $text = $this->outputText($body);
        $json = null;

        if ($request->schema !== null) {
            $json = $this->decodeJson($text);

            if ($json === null) {
                throw new AiProviderException("{$this->label()} devolvió algo que no es el JSON pedido.", retryable: true);
            }
        }

        $usage = (array) ($body['usage'] ?? []);

        return new AiResponse(
            text: $text,
            json: $json,
            provider: $this->name(),
            model: (string) ($body['model'] ?? $model),
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['output_tokens_details']['reasoning_tokens'] ?? 0),
            cachedTokens: (int) ($usage['input_tokens_details']['cached_tokens'] ?? 0),
            costUsd: is_numeric($usage['cost_in_usd_ticks'] ?? null)
                ? (float) $usage['cost_in_usd_ticks'] / self::TICKS_PER_USD
                : null,
        );
    }

    /** @param array{role: string, content: string|list<array<string, string>>} $message */
    private function message(array $message): array
    {
        return [
            'role'    => $message['role'],
            'content' => $this->parts(
                $message,
                fn (string $text) => ['type' => $message['role'] === 'assistant' ? 'output_text' : 'input_text', 'text' => $text],
                fn (array $part) => [
                    'type'      => 'input_image',
                    'image_url' => 'data:' . $part['mime'] . ';base64,' . $part['base64'],
                    // Un comprobante es letra chica: valores y referencias.
                    'detail'    => 'high',
                ],
            ),
        ];
    }

    /** El texto de los ítems `message` de la salida (los de razonamiento no cuentan). */
    private function outputText(array $body): string
    {
        $parts = [];

        foreach ((array) ($body['output'] ?? []) as $item) {
            if (($item['type'] ?? null) !== 'message') {
                continue;
            }

            foreach ((array) ($item['content'] ?? []) as $content) {
                if (($content['type'] ?? null) === 'output_text') {
                    $parts[] = (string) ($content['text'] ?? '');
                }
            }
        }

        return trim(implode('', $parts));
    }
}
