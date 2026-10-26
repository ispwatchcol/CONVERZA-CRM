<?php

namespace App\Services\Ai;

/**
 * Lo que contestó la IA, con lo que costó.
 *
 * `$costUsd` es el costo que informa el proveedor; null si no lo informa (la
 * bitácora lo estima con los precios de config/ai.php).
 */
final class AiResponse
{
    /**
     * @param array<string, mixed>|null $json La salida ya decodificada cuando se pidió un schema.
     */
    public function __construct(
        public readonly string $text,
        public readonly ?array $json,
        public readonly string $provider,
        public readonly string $model,
        public readonly int $inputTokens = 0,
        public readonly int $outputTokens = 0,
        public readonly int $reasoningTokens = 0,
        public readonly int $cachedTokens = 0,
        public readonly ?float $costUsd = null,
    ) {}
}
