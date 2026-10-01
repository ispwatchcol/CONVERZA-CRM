<?php

namespace App\Services\Flows\Nodes;

use App\Models\BotFlowRun;
use App\Services\Flows\Execution;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * Apaga el bot y entrega la conversación al equipo. Es el `handed_off` del bot
 * cableado, con tres extras: puede mandarla a un equipo, respeta la
 * auto-asignación del tenant y le deja al asesor una nota interna con todo lo
 * que el cliente respondió, para que no tenga que volver a preguntarlo.
 */
final class HandoffNode extends NodeType
{
    /** Variables que no aportan al asesor en la nota de traspaso. */
    private const NOT_IN_SUMMARY = ['respuesta', 'mensaje_inicial', 'cliente.encontrado'];

    public function type(): string
    {
        return 'handoff';
    }

    public function label(): string
    {
        return 'Pasar a un asesor';
    }

    public function outputs(array $data): array
    {
        return [];
    }

    public function sendsMessage(array $data): bool
    {
        return $this->str($data, 'text') !== '';
    }

    public function texts(array $data): array
    {
        return [$this->str($data, 'text')];
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $data   = $node['data'];
        $issues = [];

        if (mb_strlen($this->str($data, 'text')) > self::MAX_TEXT) {
            $issues[] = Issue::error('Pasar a un asesor: el mensaje es demasiado largo.', $node['id']);
        }

        $teamId = $data['team_id'] ?? null;
        if ($teamId !== null && $teamId !== '' && ! array_key_exists((int) $teamId, $ctx->teams)) {
            $issues[] = Issue::error('Pasar a un asesor: el equipo elegido no existe (¿lo borraron?).', $node['id']);
        }

        if (! in_array($data['assign'] ?? 'auto', ['auto', 'none'], true)) {
            $issues[] = Issue::error('Pasar a un asesor: opción de asignación no válida.', $node['id']);
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        $data = $node['data'];
        $text = trim($ex->render($this->str($data, 'text')));

        // El aviso al cliente es cortesía; el traspaso es lo importante. Si el
        // aviso no sale (ventana cerrada, error), el traspaso se hace igual.
        if ($text !== '') {
            $result = $ex->io->sendText($text);
            $ex->note(
                output: $text,
                messageId: $result->messageId,
                detail: $result->ok() ? [] : ['envio' => $result->status, 'motivo' => $result->reason],
            );
        }

        $teamId = is_numeric($data['team_id'] ?? null) ? (int) $data['team_id'] : null;
        $note   = $this->bool($data, 'note', true) ? $this->summary($ex) : null;

        $ex->note(detail: $ex->io->handoff($teamId, ($data['assign'] ?? 'auto') === 'auto', $note));

        return Step::end(BotFlowRun::STATUS_HANDED_OFF, 'handoff', handoffDone: true);
    }

    /** Nota interna con lo que el bot recogió, o null si no recogió nada. */
    private function summary(Execution $ex): ?string
    {
        $lines = [];

        foreach ($ex->variables as $name => $value) {
            $value = trim((string) $value);
            if ($value === '' || in_array($name, self::NOT_IN_SUMMARY, true)) {
                continue;
            }
            $lines[] = '• ' . str_replace(['cliente.', '_'], ['cliente ', ' '], $name) . ': ' . mb_substr($value, 0, 300);
        }

        if ($lines === []) {
            return null;
        }

        return mb_substr("🤖 Lo que respondió el cliente al bot:\n" . implode("\n", $lines), 0, 3000);
    }
}
