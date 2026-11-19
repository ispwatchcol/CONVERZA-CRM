<?php

namespace App\Services\Ai;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

/**
 * Lo que comparten todos los proveedores: la llave, el modelo y la URL de LA
 * EMPRESA, el manejo de errores y la lista de modelos.
 *
 * Cada proveedor es una subclase que dice su nombre (= su bloque en
 * config/ai.php) y cómo se arma y se lee una llamada.
 *
 * Una idea que atraviesa a todos: la empresa puede elegir CUALQUIER modelo de su
 * proveedor, y no todos aceptan todo (un modelo que no razona rechaza
 * `reasoning`; uno viejo no conoce el JSON schema estricto). Por eso cada
 * proveedor manda primero la petición completa y, si recibe un 400, la repite
 * UNA vez sin lo opcional (simplify()). Un 400 no se cobra.
 */
abstract class HttpAiProvider implements AiProvider
{
    /** Ids que no son modelos de texto: embeddings, audio, imágenes, moderación… */
    private const NOT_CHAT = '/(embed|whisper|tts|dall-e|davinci|babbage|moderation|transcribe|realtime|audio|image|imagen|veo|aqa|sora|rerank|guard|ocr|computer-use)/i';

    /**
     * @param string|null $apiKey  La llave de LA EMPRESA. No hay llave de plataforma.
     * @param string|null $model   El modelo que eligió la empresa (null = el de config).
     * @param string|null $baseUrl La URL de la API, solo para «otro compatible» (null = la de config).
     */
    public function __construct(
        protected readonly ?string $apiKey = null,
        protected readonly ?string $chosenModel = null,
        protected readonly ?string $chosenBaseUrl = null,
    ) {}

    abstract public function name(): string;

    /** Para los mensajes de error. */
    protected function label(): string
    {
        return (string) (config("ai.{$this->name()}.label") ?: $this->name());
    }

    public function isConfigured(): bool
    {
        return filled($this->apiKey) && filled($this->baseUrl()) && filled($this->model());
    }

    public function model(): ?string
    {
        $model = trim((string) $this->chosenModel);

        return $model !== '' ? $model : (config("ai.{$this->name()}.model") ?: null);
    }

    protected function baseUrl(): string
    {
        return rtrim((string) ($this->chosenBaseUrl ?: config("ai.{$this->name()}.base_url")), '/');
    }

    protected function config(string $key, mixed $default = null): mixed
    {
        return config("ai.{$this->name()}.{$key}", $default);
    }

    protected function effort(): string
    {
        return trim((string) $this->config('reasoning_effort', ''));
    }

    /**
     * La petición autenticada. Bearer es lo estándar; Anthropic usa su propio
     * encabezado. Sin seguir redirecciones: una API no las necesita, y con la URL
     * de «otro compatible» un servidor público podría redirigir a la red interna
     * (ver PublicUrl).
     */
    protected function http(): PendingRequest
    {
        return Http::withToken((string) $this->apiKey)->acceptJson()->asJson()->withoutRedirecting();
    }

    /**
     * POST con el plazo del proveedor, repetido una vez sin lo opcional si el
     * modelo lo rechaza con 400.
     *
     * @param callable(array): ?array $simplify Devuelve la petición sin lo opcional, o null si no había nada que quitar.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>} [cuerpo de la respuesta, petición que funcionó]
     *
     * @throws AiProviderException
     */
    protected function post(string $path, array $payload, ?callable $simplify = null): array
    {
        $response = $this->send($path, $payload);

        if ($response->status() === 400 && $simplify !== null && ($simpler = $simplify($payload)) !== null) {
            $payload  = $simpler;
            $response = $this->send($path, $payload);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return [(array) $response->json(), $payload];
    }

    private function send(string $path, array $payload): Response
    {
        try {
            return $this->http()
                ->connectTimeout(10)
                ->timeout(max(5, (int) $this->config('timeout', 60)))
                ->post($this->baseUrl() . $path, $payload);
        } catch (ConnectionException $e) {
            throw new AiProviderException("Sin conexión con {$this->label()}: " . mb_substr($e->getMessage(), 0, 200), retryable: true);
        }
    }

    protected function failure(Response $response): AiProviderException
    {
        $status = $response->status();
        $detail = $response->json('error.message') ?? $response->json('error') ?? $response->json('message') ?? $response->body();

        return new AiProviderException(
            "{$this->label()} respondió {$status}: " . mb_substr(is_string($detail) ? $detail : (string) json_encode($detail), 0, 200),
            retryable: $status === 429 || $status >= 500,
            status: $status,
        );
    }

    public function models(): array
    {
        try {
            $response = $this->http()->connectTimeout(10)->timeout(20)->get($this->baseUrl() . '/models');
        } catch (ConnectionException $e) {
            throw new AiProviderException("Sin conexión con {$this->label()}.", retryable: true);
        }

        if (! $response->successful()) {
            throw $this->failure($response);
        }

        return $this->normalizeModels((array) $response->json('data', []));
    }

    /**
     * @param list<array<string, mixed>> $data
     * @return list<array{id: string, label: string}>
     */
    protected function normalizeModels(array $data): array
    {
        $models = [];

        foreach ($data as $item) {
            // Gemini los nombra «models/gemini-…»; para llamarlos basta el nombre.
            $id = preg_replace('#^models/#', '', trim((string) ($item['id'] ?? '')));

            if ($id === '' || preg_match(self::NOT_CHAT, $id) || isset($models[$id])) {
                continue;
            }

            $name = trim((string) ($item['display_name'] ?? $item['name'] ?? ''));
            $models[$id] = ['id' => $id, 'label' => $name !== '' && $name !== $id ? "{$name} ({$id})" : $id];
        }

        ksort($models, SORT_NATURAL | SORT_FLAG_CASE);

        return array_values($models);
    }

    /**
     * El JSON de la salida. Con schema estricto viene limpio; un modelo que no lo
     * soporta (o la petición simplificada) a veces lo envuelve en ```json o le
     * agrega una frase: se rescata el objeto.
     */
    protected function decodeJson(string $text): ?array
    {
        $json = json_decode($text, true);

        if (is_array($json)) {
            return $json;
        }

        if (preg_match('/```(?:json)?\s*(\{.*\})\s*```/s', $text, $m)) {
            $json = json_decode($m[1], true);
        } elseif (($start = strpos($text, '{')) !== false && ($end = strrpos($text, '}')) > $start) {
            $json = json_decode(substr($text, $start, $end - $start + 1), true);
        }

        return is_array($json) ? $json : null;
    }

    /** La instrucción que reemplaza al schema estricto cuando el modelo no lo soporta. */
    protected function schemaAsInstructions(array $schema): string
    {
        return "\n\nResponde ÚNICAMENTE con un objeto JSON válido (sin texto antes ni después, sin ```) que cumpla este JSON schema:\n"
            . json_encode($schema['schema'] ?? $schema, JSON_UNESCAPED_UNICODE);
    }

    /** El mensaje en partes (texto e imágenes) con el formato de cada API. */
    protected function parts(array $message, callable $text, callable $image): array|string
    {
        if (is_string($message['content'])) {
            return $message['content'];
        }

        return array_map(
            fn (array $part) => ($part['type'] ?? 'text') === 'image' ? $image($part) : $text((string) ($part['text'] ?? '')),
            $message['content'],
        );
    }
}
