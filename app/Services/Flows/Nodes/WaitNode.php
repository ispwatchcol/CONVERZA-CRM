<?php

namespace App\Services\Flows\Nodes;

use App\Services\Flows\Execution;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * Pausa N minutos y sigue. La reanuda `flows:tick`, que corre cada minuto.
 *
 * Los mensajes que el cliente mande durante la espera quedan en el chat pero
 * no los consume el flujo: una pregunta que viene DESPUÉS de la espera no se
 * puede contestar con algo escrito ANTES de hacerla.
 */
final class WaitNode extends NodeType
{
    public function type(): string
    {
        return 'wait';
    }

    public function label(): string
    {
        return 'Espera';
    }

    public function waitMinutes(array $data): int
    {
        return max(0, $this->int($data, 'minutes'));
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $minutes = $node['data']['minutes'] ?? null;
        $max     = $ctx->maxWait();

        if (! is_numeric($minutes) || (int) $minutes < 1 || (int) $minutes > $max) {
            return [Issue::error(sprintf(
                'Espera: entre 1 minuto y %d horas. Más que eso y la ventana de 24 h de WhatsApp se cerraría antes de que el bot vuelva a escribir.',
                intdiv($max, 60),
            ), $node['id'])];
        }

        return [];
    }

    public function enter(array $node, Execution $ex): Step
    {
        $until = $ex->io->now()->addMinutes(max(1, $this->waitMinutes($node['data'])));
        $ex->note(detail: ['hasta' => $until->toIso8601String()]);

        return Step::waitTimer($until);
    }

    public function resume(array $node, Execution $ex): Step
    {
        return Step::next();
    }
}
