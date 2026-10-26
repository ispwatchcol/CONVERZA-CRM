<?php

namespace App\Services\Flows;

use App\Models\BotFlowRun;
use Carbon\CarbonInterface;

/**
 * La parte de una ejecución que el motor mueve: en qué bloque está, en qué
 * estado, cuántos pasos lleva. El runner la carga de/guarda en BotFlowRun; el
 * simulador la guarda en el navegador.
 */
final class RunState
{
    /**
     * La tarea que un bloque acaba de pedir (Step::waitTask) y que todavía nadie
     * encoló. El runner la toma, la encola al soltar el lock y la limpia.
     *
     * @var array{kind: string, id: int}|null
     */
    public ?array $task = null;

    public function __construct(
        public string $status,
        public ?string $currentNode,
        public int $stepsCount = 0,
        public ?CarbonInterface $resumeAt = null,
        public ?string $endedReason = null,
    ) {}

    public function isLive(): bool
    {
        return in_array($this->status, BotFlowRun::LIVE_STATUSES, true);
    }
}
