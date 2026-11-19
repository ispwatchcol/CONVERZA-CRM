<?php

namespace App\Services\Ai;

/**
 * Un proveedor «compatible con OpenAI»: POST {base_url}/chat/completions. Es el
 * estándar de facto, así que con esta sola clase entran Gemini, DeepSeek, Groq,
 * OpenRouter, Mistral y cualquier otro que la empresa configure con su URL.
 *
 * Lo opcional, que se quita si el modelo responde 400 (ver HttpAiProvider):
 *
 *   - `reasoning_effort`: solo los modelos que razonan lo aceptan.
 *   - `response_format` con JSON schema: no todos lo soportan. Sin él, el schema
 *     va como instrucción y el JSON se rescata del texto (decodeJson).
 *
 * Ninguno de estos informa el costo: AiGateway lo estima con los precios de config.
 */
final class ChatCompletionsProvider extends HttpAiProvider
{
    public function __construct(
        private readonly string $provider,
        ?string $apiKey = null,
        ?string $model = null,
        ?string $baseUrl = null,
    ) {
        parent::__construct($apiKey, $model, $baseUrl);
    }

    public function name(): string
    {
        return $this->provider;
    }

    public function respond(AiRequest $request): AiResponse
    {
        $model = (string) ($request->model ?: $this->model());

        $messages = [['role' => 'system', 'content' => $request->instructions]];

        foreach ($request->messages as $message) {
            $messages[] = [
                'role'    => $message['role'],
                'content' => $this->parts(
                    $message,
                    fn (string $text) => ['type' => 'text', 'text' => $text],
                    fn (array $part) => ['type' => 'image_url', 'image_url' => ['url' => 'data:' . $part['mime'] . ';base64,' . $part['base64']]],
                ),
            ];
        }

        $payload = [
            'model'      => $model,
            'messages'   => $messages,
            'max_tokens' => $request->maxOutputTokens,
        ];

        if ($this->effort() !== '') {
            $payload['reasoning_effort'] = $this->effort();
        }

        if ($request->schema !== null) {
            $payload['response_format'] = ['type' => 'json_schema', 'json_schema' => [
                'name'   => $request->schema['name'],
                'schema' => $request->schema['schema'],
                'strict' => true,
            ]];
        }

        [$body, $sent] = $this->post('/chat/completions', $payload, function (array $payload) use ($request): ?array {
            if (! isset($payload['reasoning_effort']) && ! isset($payload['response_format'])) {
                return null;
            }

            unset($payload['reasoning_effort']);

            if (isset($payload['response_format'])) {
                unset($payload['response_format']);
                $payload['messages'][0]['content'] .= $this->schemaAsInstructions($request->schema);
            }

            return $payload;
        });

        $choice = (array) ($body['choices'][0] ?? []);

        if (($choice['finish_reason'] ?? 'stop') === 'length') {
            throw new AiProviderException("{$this->label()} no terminó la respuesta (se cortó por largo).", retryable: false);
        }

        $text = $this->contentText($choice['message']['content'] ?? '');
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
            model: (string) ($body['model'] ?? $sent['model']),
            inputTokens: (int) ($usage['prompt_tokens'] ?? 0),
            outputTokens: (int) ($usage['completion_tokens'] ?? 0),
            reasoningTokens: (int) ($usage['completion_tokens_details']['reasoning_tokens'] ?? 0),
            cachedTokens: (int) ($usage['prompt_tokens_details']['cached_tokens'] ?? 0),
        );
    }

    /** `content` es un texto o, en algunos proveedores, una lista de partes. */
    private function contentText(mixed $content): string
    {
        if (is_array($content)) {
            $content = implode('', array_map(fn ($part) => is_array($part) ? (string) ($part['text'] ?? '') : (string) $part, $content));
        }

        return trim((string) $content);
    }
}
