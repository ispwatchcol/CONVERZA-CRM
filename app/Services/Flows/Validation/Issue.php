<?php

namespace App\Services\Flows\Validation;

/**
 * Un problema que encontró el validador. `$nodeId` apunta al bloque exacto
 * para que el editor lo marque: "el error dice exactamente qué nodo falla".
 */
final class Issue
{
    public const ERROR   = 'error';
    public const WARNING = 'warning';

    public function __construct(
        public readonly string $severity,
        public readonly string $message,
        public readonly ?string $nodeId = null,
    ) {}

    public static function error(string $message, ?string $nodeId = null): self
    {
        return new self(self::ERROR, $message, $nodeId);
    }

    public static function warning(string $message, ?string $nodeId = null): self
    {
        return new self(self::WARNING, $message, $nodeId);
    }

    /** @return array{severity: string, message: string, node_id: ?string} */
    public function toArray(): array
    {
        return [
            'severity' => $this->severity,
            'message'  => $this->message,
            'node_id'  => $this->nodeId,
        ];
    }
}
