<?php

namespace App\Services\Flows\Nodes;

use App\Services\Flows\Execution;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/** Pone o quita una etiqueta del contacto. */
final class TagNode extends NodeType
{
    public function type(): string
    {
        return 'tag';
    }

    public function label(): string
    {
        return 'Etiquetar';
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $issues = [];

        if (! array_key_exists((int) ($node['data']['label_id'] ?? 0), $ctx->labels)) {
            $issues[] = Issue::error('Etiquetar: elige una etiqueta que exista.', $node['id']);
        }

        if (! in_array($node['data']['action'] ?? 'add', ['add', 'remove'], true)) {
            $issues[] = Issue::error('Etiquetar: acción no válida.', $node['id']);
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        $labelId = (int) ($node['data']['label_id'] ?? 0);
        $remove  = ($node['data']['action'] ?? 'add') === 'remove';

        $name = $remove ? $ex->io->removeLabel($labelId) : $ex->io->addLabel($labelId);

        // Etiqueta borrada después de publicar: no es motivo para cortarle la
        // atención al cliente. Queda en la traza y el flujo sigue.
        $ex->note(detail: $name === null
            ? ['omitido' => 'la etiqueta ya no existe']
            : [$remove ? 'quitada' : 'agregada' => $name]);

        return Step::next();
    }
}
