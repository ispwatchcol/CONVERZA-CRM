<?php

namespace App\Services\Flows\Nodes;

use App\Services\Flows\Execution;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\ValidationContext;

/** Envía un texto, con variables, y sigue. */
final class MessageNode extends NodeType
{
    public function type(): string
    {
        return 'message';
    }

    public function label(): string
    {
        return 'Mensaje';
    }

    public function sendsMessage(array $data): bool
    {
        return true;
    }

    public function texts(array $data): array
    {
        return [$this->str($data, 'text')];
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        return $this->requireText($node, 'text', 'el texto del mensaje');
    }

    public function enter(array $node, Execution $ex): Step
    {
        $text = trim($ex->render($this->str($node['data'], 'text')));

        // Todas sus variables vinieron vacías: mandar un mensaje en blanco no
        // aporta nada y WhatsApp lo rechaza.
        if ($text === '') {
            $ex->note(detail: ['omitido' => 'texto vacío tras reemplazar variables']);

            return Step::next();
        }

        return $this->afterSend($ex, $ex->io->sendText($text), $text) ?? Step::next();
    }
}
