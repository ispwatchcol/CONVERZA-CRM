<?php

namespace App\Services\Flows\Nodes;

use App\Models\BotFlowRun;
use App\Services\Flows\Execution;
use App\Services\Flows\Step;

/**
 * Termina el flujo sin traspasar: el bot resolvió. La conversación queda en la
 * bandeja sin asignar, y si el cliente vuelve a escribir la toma el equipo (o
 * la auto-asignación, si está activa).
 */
final class EndNode extends NodeType
{
    public function type(): string
    {
        return 'end';
    }

    public function label(): string
    {
        return 'Fin';
    }

    public function outputs(array $data): array
    {
        return [];
    }

    public function enter(array $node, Execution $ex): Step
    {
        return Step::end(BotFlowRun::STATUS_COMPLETED, 'completed');
    }
}
