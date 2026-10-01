<?php

namespace App\Services\Flows;

use Carbon\CarbonInterface;

/**
 * Lo que un bloque le dice al motor después de ejecutarse.
 *
 *   next       → seguir por la salida `$handle`
 *   waitInput  → detenerse hasta que el cliente escriba
 *   waitTimer  → detenerse hasta `$until`
 *   end        → la ejecución termina con `$endStatus`
 */
final class Step
{
    public const NEXT       = 'next';
    public const WAIT_INPUT = 'wait_input';
    public const WAIT_TIMER = 'wait_timer';
    public const END        = 'end';

    private function __construct(
        public readonly string $kind,
        public readonly ?string $handle = null,
        public readonly ?CarbonInterface $until = null,
        public readonly ?string $endStatus = null,
        public readonly ?string $reason = null,
        public readonly bool $handoffDone = false,
    ) {}

    public static function next(string $handle = 'next'): self
    {
        return new self(self::NEXT, handle: $handle);
    }

    public static function waitInput(): self
    {
        return new self(self::WAIT_INPUT);
    }

    public static function waitTimer(CarbonInterface $until): self
    {
        return new self(self::WAIT_TIMER, until: $until);
    }

    /**
     * @param bool $handoffDone El bloque ya entregó la conversación (bloque
     *                          Traspaso). Si no, y la ejecución termina en
     *                          manos del equipo, el motor hace el traspaso de
     *                          resguardo para que nadie quede esperando.
     */
    public static function end(string $status, string $reason, bool $handoffDone = false): self
    {
        return new self(self::END, endStatus: $status, reason: $reason, handoffDone: $handoffDone);
    }

    /** La salida o el estado, tal como queda en la traza. */
    public function outcome(): string
    {
        return match ($this->kind) {
            self::NEXT       => (string) $this->handle,
            self::WAIT_INPUT => 'waiting_input',
            self::WAIT_TIMER => 'waiting_timer',
            default          => (string) $this->reason,
        };
    }
}
