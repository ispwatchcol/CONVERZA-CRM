<?php

namespace App\Services\Flows;

use App\Models\BotFlowRun;
use Illuminate\Support\Facades\Log;

/**
 * El intérprete: dado un evento (arranque, respuesta del cliente o fin de una
 * espera), avanza la ejecución bloque a bloque hasta que toca esperar o el
 * flujo termina.
 *
 * No sabe de colas, locks ni base de datos: eso es del FlowRunner. Tampoco
 * sabe si está enviando de verdad o simulando: eso es del FlowIO. Por eso el
 * simulador ejecuta exactamente este mismo código.
 */
final class FlowEngine
{
    public const EVENT_START = 'start';
    public const EVENT_INPUT = 'input';
    public const EVENT_TIMER = 'timer';

    /** Notas internas del traspaso de resguardo, según por qué se cortó el flujo. */
    private const SAFETY_NOTES = [
        'window_closed' => '🤖 El bot no pudo seguir: la ventana de 24 h de WhatsApp está cerrada. Para escribirle al cliente usa una plantilla aprobada.',
        'send_failed'   => '🤖 El bot no pudo enviar su mensaje por WhatsApp y pasó la conversación al equipo.',
        'dead_end'      => '🤖 El flujo llegó a una salida sin conectar y pasó la conversación al equipo.',
        'step_limit'    => '🤖 El flujo se detuvo porque superó el tope de pasos (posible ciclo). Revisa el flujo en el Workspace.',
    ];

    public function __construct(private readonly NodeRegistry $registry) {}

    /**
     * @return list<array{node_id: string, node_type: string, outcome: string, input: ?string, output: ?string, message_id: ?int, detail: ?array}>
     */
    public function advance(
        Execution $ex,
        RunState $run,
        string $event,
        ?Inbound $input = null,
        ?int $maxSteps = null,
        ?int $maxRunSteps = null,
    ): array {
        $maxSteps    ??= (int) config('flows.max_steps_per_event', 25);
        $maxRunSteps ??= (int) config('flows.max_steps_per_run', 200);

        // Cada evento solo vale en su estado: una respuesta con la ejecución en
        // una Espera, o un fin de espera con la ejecución esperando al cliente,
        // no mueven nada.
        $expected = match ($event) {
            self::EVENT_START => BotFlowRun::STATUS_PENDING,
            self::EVENT_INPUT => BotFlowRun::STATUS_WAITING_INPUT,
            self::EVENT_TIMER => BotFlowRun::STATUS_WAITING_TIMER,
            default           => null,
        };
        if ($expected === null || $run->status !== $expected) {
            return [];
        }

        $records = [];
        $node    = $run->currentNode !== null ? $ex->graph->node($run->currentNode) : null;
        $handler = $node ? $this->registry->get($node['type']) : null;

        if ($node === null || $handler === null) {
            return $this->finish($ex, $run, $records, BotFlowRun::STATUS_FAILED, 'missing_node', false, $node, [
                'nodo' => $run->currentNode,
            ]);
        }

        $step = $this->call($ex, fn () => match ($event) {
            self::EVENT_INPUT => $handler->receive($node, $ex, $input ?? new Inbound('')),
            self::EVENT_TIMER => $handler->resume($node, $ex),
            default           => $handler->enter($node, $ex),
        });

        $executed = 0;

        while (true) {
            $executed++;
            $run->stepsCount++;
            $records[] = $this->record($node, $step, $ex);

            if ($step->kind === Step::WAIT_INPUT) {
                $run->status   = BotFlowRun::STATUS_WAITING_INPUT;
                $run->resumeAt = null;

                return $records;
            }

            if ($step->kind === Step::WAIT_TIMER) {
                $run->status   = BotFlowRun::STATUS_WAITING_TIMER;
                $run->resumeAt = $step->until;

                return $records;
            }

            if ($step->kind === Step::END) {
                return $this->finish($ex, $run, $records, (string) $step->endStatus, (string) $step->reason, $step->handoffDone);
            }

            // Step::NEXT
            $targetId = $ex->graph->target($node['id'], (string) $step->handle);
            $target   = $targetId !== null ? $ex->graph->node($targetId) : null;

            if ($target === null) {
                // El validador exige todas las salidas conectadas, así que esto
                // no debería pasar con un flujo publicado. Si pasa, nadie se
                // queda esperando: la conversación va al equipo.
                return $this->finish($ex, $run, $records, BotFlowRun::STATUS_HANDED_OFF, 'dead_end', false, $node, [
                    'salida' => $step->handle,
                ]);
            }

            if ($executed >= $maxSteps || $run->stepsCount >= $maxRunSteps) {
                return $this->finish($ex, $run, $records, BotFlowRun::STATUS_FAILED, 'step_limit', false, $node, [
                    'pasos_en_este_evento' => $executed,
                    'pasos_totales'        => $run->stepsCount,
                ]);
            }

            $handler = $this->registry->get($target['type']);
            if ($handler === null) {
                return $this->finish($ex, $run, $records, BotFlowRun::STATUS_FAILED, 'missing_node', false, $target, [
                    'tipo' => $target['type'],
                ]);
            }

            $node              = $target;
            $run->currentNode  = $node['id'];
            $step              = $this->call($ex, fn () => $handler->enter($node, $ex));
        }
    }

    /**
     * Un bloque que lanza una excepción no tumba el worker ni deja al cliente
     * en el aire: la ejecución falla, queda en la traza y pasa al equipo.
     */
    private function call(Execution $ex, callable $fn): Step
    {
        try {
            return $fn();
        } catch (\Throwable $e) {
            Log::error('Flujo: un bloque lanzó una excepción', [
                'error' => $e->getMessage(),
                'class' => $e::class,
            ]);
            report($e);
            $ex->note(detail: ['error' => class_basename($e) . ': ' . mb_substr($e->getMessage(), 0, 300)]);

            return Step::end(BotFlowRun::STATUS_FAILED, 'error');
        }
    }

    private function record(array $node, Step $step, Execution $ex): array
    {
        $notes = $ex->takeNotes();

        return [
            'node_id'    => $node['id'],
            'node_type'  => $node['type'],
            'outcome'    => $step->outcome(),
            'input'      => $notes['input'],
            'output'     => $notes['output'],
            'message_id' => $notes['message_id'],
            'detail'     => $notes['detail'] !== [] ? $notes['detail'] : null,
        ];
    }

    /**
     * Cierra la ejecución. Si termina en manos del equipo y ningún bloque hizo
     * el traspaso (ventana cerrada, error, tope de pasos), lo hace el motor: la
     * regla es que ningún cliente quede esperando una respuesta que no llega.
     *
     * @param array|null $atNode Bloque donde el MOTOR cortó (no el bloque): se
     *                           agrega a la traza para que se vea el motivo.
     */
    private function finish(
        Execution $ex,
        RunState $run,
        array $records,
        string $status,
        string $reason,
        bool $handoffDone,
        ?array $atNode = null,
        array $detail = [],
    ): array {
        $run->status      = $status;
        $run->endedReason = $reason;
        $run->resumeAt    = null;

        $needsHandoff = ! $handoffDone
            && in_array($status, [BotFlowRun::STATUS_HANDED_OFF, BotFlowRun::STATUS_FAILED], true);

        if ($needsHandoff) {
            try {
                $detail['traspaso'] = $ex->io->handoff(
                    null,
                    true,
                    self::SAFETY_NOTES[$reason] ?? '🤖 El flujo se detuvo por un error interno y pasó la conversación al equipo.',
                );
            } catch (\Throwable $e) {
                report($e);
                $detail['traspaso'] = ['error' => $e->getMessage()];
            }
        }

        if ($atNode !== null || $detail !== []) {
            $records[] = [
                'node_id'    => $atNode['id'] ?? (string) $run->currentNode,
                'node_type'  => 'engine',
                'outcome'    => $reason,
                'input'      => null,
                'output'     => null,
                'message_id' => null,
                'detail'     => $detail !== [] ? $detail : null,
            ];
        }

        return $records;
    }
}
