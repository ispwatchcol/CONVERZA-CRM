<?php

namespace App\Services\Flows;

/**
 * Qué pasó con un envío del bot.
 *
 * `skipped` no es un error del número: significa que el motor decidió NO
 * mandar (p. ej. la ventana de 24 h estaba cerrada), y por eso tampoco llegó a
 * Meta a gastar quality rating.
 */
final class SendResult
{
    private function __construct(
        public readonly string $status,
        public readonly ?int $messageId = null,
        public readonly ?string $reason = null,
    ) {}

    public static function sent(?int $messageId): self
    {
        return new self('sent', $messageId);
    }

    public static function skipped(string $reason): self
    {
        return new self('skipped', null, $reason);
    }

    public static function failed(string $reason, ?int $messageId = null): self
    {
        return new self('failed', $messageId, $reason);
    }

    public function ok(): bool
    {
        return $this->status === 'sent';
    }
}
