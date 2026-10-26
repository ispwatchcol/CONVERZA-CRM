<?php

namespace App\Services\Flows;

use Carbon\CarbonInterface;

/**
 * Lo que un bloque le dice al motor después de ejecutarse.
 *
 *   next       → seguir por la salida `$handle`
 *   waitInput  → detenerse hasta que el cliente escriba
 *   waitTimer  → detenerse hasta `$until`
 *   waitTask   → detenerse hasta que termine un trabajo en otra cola (`$task`)
 *   end        → la ejecución termina con `$endStatus`
 */
final class Step
{
    public const NEXT       = 'next';
    public const WAIT_INPUT = 'wait_input';
    public const WAIT_TIMER = 'wait_timer';
    public const WAIT_TASK  = 'wait_task';
    public const END        = 'end';

    /**
     * @param array{kind: string, id: int, dispatch: bool}|null $task
     */
    private function __construct(
        public readonly string $kind,
        public readonly ?string $handle = null,
        public readonly ?CarbonInterface $until = null,
        public readonly ?string $endStatus = null,
        public readonly ?string $reason = null,
        public readonly bool $handoffDone = false,
        public readonly ?array $task = null,
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
     * Algo lento y de un tercero (leer un comprobante con IA) que no puede
     * correr dentro del job del flujo: ese job tiene el lock de la conversación
     * y no se reintenta. El runner encola la tarea al soltar el lock y la tarea,
     * al terminar, despierta a la ejecución, que sigue por resume() del bloque.
     *
     * Si la tarea no avisa antes de `$until`, `flows:tick` despierta igual a la
     * ejecución y el bloque decide qué hacer sin el resultado.
     *
     * @param string $kind     Tipo de tarea; FlowRunner sabe a qué cola mandarla.
     * @param int    $id       El registro que la tarea procesa (p. ej. el reporte de pago).
     * @param bool   $dispatch false = la tarea ya está en curso y el bloque solo
     *                         vuelve a esperarla (lo despertó un aviso viejo).
     */
    public static function waitTask(string $kind, int $id, CarbonInterface $until, bool $dispatch = true): self
    {
        return new self(self::WAIT_TASK, until: $until, task: ['kind' => $kind, 'id' => $id, 'dispatch' => $dispatch]);
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
            self::WAIT_TASK  => 'waiting_task',
            default          => (string) $this->reason,
        };
    }
}
