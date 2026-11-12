<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

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
 *     comprobante (con nombres y cuentas) y no hay nada que retomar.
 *   - La imagen viaja como data URL en base64: los medios viven detrás de
 *     sesión, sin URL pública que el proveedor pueda bajar.
 *   - El costo sale de `usage.cost_in_usd_ticks` (10^10 ticks = 1 USD) cuando
 *     el proveedor lo informa (xAI). Si no (OpenAI), queda en null y AiGateway
 *     lo estima con los precios de config.
 */
abstract class ResponsesApiProvider implements AiProvider
{
    private const TICKS_PER_USD = 10_000_000_000;

    /** El nombre del proveedor y de su bloque en config/ai.php. */
    abstract public function name(): string;

    /** Para los mensajes de error. */
    abstract protected function label(): string;

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

    public function isConfigured(): bool
    {
        return filled(config("ai.{$this->name()}.api_key")) && filled(config("ai.{$this->name()}.base_url"));
    }

    public function respond(AiRequest $request): AiResponse
    {
        $config = (array) config("ai.{$this->name()}");
        $effort = trim((string) ($config['reasoning_effort'] ?? ''));

        $payload = [
            'model'             => (string) $config['model'],
            'instructions'      => $request->instructions,
            'input'             => array_map(fn (array $message) => $this->message($message), $request->messages),
            'store'             => false,
            'max_output_tokens' => $request->maxOutputTokens,
        ];

        if ($effort !== '') {
            $payload['reasoning'] = ['effort' => $effort];
        }

        if ($request->schema !== null) {
            $payload['text'] = ['format' => [
                'type'   => 'json_schema',
                'name'   => $request->schema['name'],
                'schema' => $this->schema($request->schema['schema']),
                'strict' => true,
            ]];
        }

        try {
            $response = Http::withToken((string) $config['api_key'])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(max(5, (int) ($config['timeout'] ?? 60)))
                ->post(rtrim((string) $config['base_url'], '/') . '/responses', $payload);
        } catch (ConnectionException $e) {
            throw new AiProviderException("Sin conexión con {$this->label()}: " . mb_substr($e->getMessage(), 0, 200), retryable: true);
        }

        if (! $response->successful()) {
            $status = $response->status();
            $detail = $response->json('error.message') ?? $response->json('error') ?? $response->body();

            throw new AiProviderException(
                "{$this->label()} respondió {$status}: " . mb_substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 200),
                retryable: $status === 429 || $status >= 500,
                status: $status,
            );
        }

        $body = (array) $response->json();

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
            $json = json_decode($text, true);

            if (! is_array($json)) {
                throw new AiProviderException("{$this->label()} devolvió algo que no es el JSON pedido.", retryable: true);
            }
        }

        $usage = (array) ($body['usage'] ?? []);

        return new AiResponse(
            text: $text,
            json: $json,
            provider: $this->name(),
            model: (string) ($body['model'] ?? $config['model']),
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
        if (is_string($message['content'])) {
            return ['role' => $message['role'], 'content' => $message['content']];
        }

        return [
            'role'    => $message['role'],
            'content' => array_map(fn (array $part) => ($part['type'] ?? 'text') === 'image'
                ? [
                    'type'      => 'input_image',
                    'image_url' => 'data:' . $part['mime'] . ';base64,' . $part['base64'],
                    // Un comprobante es letra chica: valores y referencias.
                    'detail'    => 'high',
                ]
                : ['type' => 'input_text', 'text' => (string) ($part['text'] ?? '')],
                $message['content']),
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
