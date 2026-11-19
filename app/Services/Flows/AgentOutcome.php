<?php

namespace App\Services\Flows;

use App\Models\AiAgentTurn;

/** En qué va una respuesta del agente IA, como la ve el bloque «Agente IA». */
final class AgentOutcome
{
    public function __construct(
        public readonly int $turnId,
        public readonly string $status,
        public readonly ?string $reply = null,
        public readonly ?string $action = null,
        public readonly ?string $reason = null,
    ) {}

    public static function fromTurn(AiAgentTurn $turn): self
    {
        return new self((int) $turn->id, (string) $turn->status, $turn->reply, $turn->action, $turn->reason);
    }

    public static function unavailable(string $reason): self
    {
        return new self(0, AiAgentTurn::STATUS_UNAVAILABLE, reason: $reason);
    }

    public function pending(): bool
    {
        return $this->status === AiAgentTurn::STATUS_PENDING;
    }

    public function answered(): bool
    {
        return $this->status === AiAgentTurn::STATUS_DONE;
    }
}
