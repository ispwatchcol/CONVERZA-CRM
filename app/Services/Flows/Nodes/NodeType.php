<?php

namespace App\Services\Flows\Nodes;

use App\Models\BotFlowRun;
use App\Services\Flows\Execution;
use App\Services\Flows\Inbound;
use App\Services\Flows\SendResult;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * Un tipo de bloque del Workspace.
 *
 * Cada bloque declara sus salidas (para el lienzo y el validador), valida su
 * propia configuración y sabe ejecutarse. Agregar un bloque nuevo es escribir
 * una subclase, registrarla en NodeRegistry y darle su tarjeta en el editor
 * (resources/js/Components/Flows/nodeCatalog.js).
 */
abstract class NodeType
{
    public const MAX_TEXT = 4096;

    /** Clave del tipo, tal como viaja en el grafo. */
    abstract public function type(): string;

    /** Nombre para humanos, en los mensajes del validador. */
    abstract public function label(): string;

    /**
     * Qué hace el bloque al llegar la ejecución a él.
     *
     * @param array{id: string, type: string, data: array} $node
     */
    abstract public function enter(array $node, Execution $ex): Step;

    /** El cliente respondió mientras la ejecución esperaba en este bloque. */
    public function receive(array $node, Execution $ex, Inbound $input): Step
    {
        return Step::next();
    }

    /** Terminó la espera de este bloque. */
    public function resume(array $node, Execution $ex): Step
    {
        return Step::next();
    }

    /** @return list<string> Salidas, en el orden en que se dibujan. */
    public function outputs(array $data): array
    {
        return ['next'];
    }

    /** Nombre legible de una salida, para los mensajes del validador. */
    public function outputLabel(string $handle, array $data): string
    {
        return 'Siguiente';
    }

    /** ¿Se detiene a esperar al cliente? Corta ciclos y reinicia la cuenta de la ventana de 24 h. */
    public function waitsForInput(): bool
    {
        return false;
    }

    /** Minutos que el bloque retiene la ejecución sin que el cliente escriba. */
    public function waitMinutes(array $data): int
    {
        return 0;
    }

    /** ¿Le manda algo al cliente al ejecutarse? Lo mira la regla de la ventana de 24 h. */
    public function sendsMessage(array $data): bool
    {
        return false;
    }

    /** @return list<string> Variables que el bloque deja definidas. */
    public function definesVariables(array $data): array
    {
        return [];
    }

    /** @return list<string> Textos con {{variables}} que el bloque renderiza. */
    public function texts(array $data): array
    {
        return [];
    }

    /** @return list<Issue> */
    public function validate(array $node, ValidationContext $ctx): array
    {
        return [];
    }

    // ── Ayudas para las subclases ────────────────────────────────────────────

    protected function str(array $data, string $key, string $default = ''): string
    {
        $value = $data[$key] ?? $default;

        return is_scalar($value) ? trim((string) $value) : $default;
    }

    protected function int(array $data, string $key, int $default = 0): int
    {
        $value = $data[$key] ?? null;

        return is_numeric($value) ? (int) $value : $default;
    }

    protected function bool(array $data, string $key, bool $default = false): bool
    {
        $value = $data[$key] ?? null;

        return $value === null ? $default : filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * Registra un envío en la traza y decide qué hacer si no salió.
     *
     * - Ventana de 24 h cerrada: el motor NO manda (Meta lo aceptaría con 200 y
     *   lo rechazaría después, gastando calidad del número). La conversación
     *   pasa al equipo, que puede escribir con una plantilla aprobada.
     * - Falla de envío: seguir hablando sin saber si el cliente recibió lo
     *   anterior es peor que ceder; también pasa al equipo.
     *
     * @return Step|null null = el envío salió, el bloque sigue normal.
     */
    protected function afterSend(Execution $ex, SendResult $result, string $shown): ?Step
    {
        $ex->note(
            output: $shown,
            messageId: $result->messageId,
            detail: $result->ok() ? [] : ['envio' => $result->status, 'motivo' => $result->reason],
        );

        return match ($result->status) {
            'skipped' => Step::end(BotFlowRun::STATUS_HANDED_OFF, 'window_closed'),
            'failed'  => Step::end(BotFlowRun::STATUS_FAILED, 'send_failed'),
            default   => null,
        };
    }

    /** Valida un texto obligatorio con largo máximo. */
    protected function requireText(array $node, string $key, string $what, int $max = self::MAX_TEXT): array
    {
        $text = $this->str($node['data'], $key);

        if ($text === '') {
            return [Issue::error("{$this->label()}: escribe {$what}.", $node['id'])];
        }

        if (mb_strlen($text) > $max) {
            return [Issue::error("{$this->label()}: {$what} supera los {$max} caracteres que permite WhatsApp.", $node['id'])];
        }

        return [];
    }
}
