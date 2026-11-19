<?php

namespace App\Services\Ai;

/**
 * Lo que una función de Converza le pide a la IA, sin saber qué proveedor la
 * atiende.
 *
 * `$messages` es una lista de mensajes `{role, content}` donde `content` es un
 * texto o una lista de partes:
 *
 *   ['type' => 'text',  'text' => '…']
 *   ['type' => 'image', 'mime' => 'image/jpeg', 'base64' => '…']
 */
final class AiRequest
{
    /**
     * @param string                                          $purpose      Para qué es (receipt, agent…): va a la bitácora de uso.
     * @param list<array{role: string, content: string|list<array<string, string>>}> $messages
     * @param array{name: string, schema: array<string, mixed>}|null $schema Salida JSON estricta.
     * @param string|null                                     $model        Otro modelo que el de la empresa (el de un agente).
     */
    public function __construct(
        public readonly string $purpose,
        public readonly string $instructions,
        public readonly array $messages,
        public readonly ?array $schema = null,
        public readonly int $maxOutputTokens = 800,
        public readonly ?string $model = null,
    ) {}
}
