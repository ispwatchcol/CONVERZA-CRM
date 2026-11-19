<?php

namespace App\Services\Ai\Agents;

/** Lo que contestó el agente: el mensaje para el cliente y si pidió salir. */
final class AgentReply
{
    public const CONTINUE = 'continue';
    public const HUMAN    = 'human';
    public const END      = 'end';

    public function __construct(
        public readonly string $text,
        public readonly string $action = self::CONTINUE,
        public readonly ?string $model = null,
    ) {}
}
