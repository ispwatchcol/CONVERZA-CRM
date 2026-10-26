<?php

namespace App\Services\Flows;

use App\Models\BotFlow;
use App\Models\BotFlowRun;
use App\Models\Contact;
use App\Models\Tenant;
use App\Services\Flows\IO\SimulatedFlowIO;
use App\Services\Flows\Nodes\StartNode;
use App\Services\Flows\Validation\ValidationContext;
use App\Services\Ispwatch\IspwatchRepository;
use Carbon\CarbonImmutable;

/**
 * El simulador del editor: el MISMO motor, con un FlowIO que no envía nada.
 *
 * No guarda estado en el servidor. Cada respuesta devuelve el estado de la
 * ejecución simulada y el navegador lo manda de vuelta con el siguiente
 * mensaje. Ese estado lo controla el cliente, y está bien: el simulador no
 * escribe en ninguna tabla y solo lee datos del propio tenant.
 */
final class FlowSimulator
{
    public function __construct(
        private readonly FlowEngine $engine,
        private readonly IspwatchRepository $ispwatch,
    ) {}

    /**
     * @param array<string, mixed>|null $state Lo que devolvió la llamada anterior; null = arrancar.
     * @param array{text?: ?string, reply_id?: ?string, skip_wait?: bool, phone?: ?string, name?: ?string, window_open?: bool, labels?: list<int>, receipt?: ?string} $input
     *        `receipt`: el mensaje es un comprobante simulado y este es el resultado a probar
     *        (valid | review | not_receipt). No se lee ninguna imagen.
     */
    public function run(Tenant $tenant, ?array $graphArray, ?array $state, array $input, ValidationContext $ctx): array
    {
        $graph = FlowGraph::fromArray($graphArray);
        $start = $graph->start();

        if ($start === null) {
            return ['error' => 'El flujo no tiene bloque Inicio.'];
        }

        $starting = $state === null;

        $contact = $starting
            ? ['name' => $this->clean($input['name'] ?? null, 120), 'phone' => Contact::normalizePhone($input['phone'] ?? null)]
            : [
                'name'  => $this->clean($state['contact']['name'] ?? null, 120),
                'phone' => Contact::normalizePhone($state['contact']['phone'] ?? null),
            ];
        $labels  = array_values(array_map('intval', (array) ($starting ? ($input['labels'] ?? []) : ($state['labels'] ?? []))));
        $window  = (bool) ($input['window_open'] ?? ($state['window_open'] ?? true));
        $receipt = in_array($input['receipt'] ?? null, SimulatedFlowIO::RECEIPT_OUTCOMES, true) ? $input['receipt'] : null;

        $io = new SimulatedFlowIO($tenant, $contact, $labels, $window, $ctx->labels, $ctx->teams, $this->ispwatch, $receipt);

        $notices = [];
        $text    = (string) ($input['text'] ?? '');

        if ($starting) {
            $run       = new RunState(BotFlowRun::STATUS_PENDING, $start['id']);
            $variables = ['mensaje_inicial' => $text];
            $engine    = [];
            $event     = FlowEngine::EVENT_START;
            $inbound   = null;

            $trigger = StartNode::triggerOf($start['data']);
            if ($trigger['type'] === BotFlow::TRIGGER_KEYWORD && ! BotDispatcher::keywordMatches($trigger['config'], $text)) {
                $notices[] = 'En una conversación real este mensaje NO arrancaría el flujo: no trae ninguna de sus palabras clave. Aquí lo arrancamos igual para que puedas probarlo.';
            }
        } else {
            $run = new RunState(
                (string) ($state['status'] ?? BotFlowRun::STATUS_COMPLETED),
                isset($state['current_node']) ? (string) $state['current_node'] : null,
                (int) ($state['steps'] ?? 0),
                ! empty($state['resume_at']) ? CarbonImmutable::parse($state['resume_at']) : null,
                $state['ended_reason'] ?? null,
            );
            $variables = array_map(fn ($v) => $v === null ? null : (string) $v, (array) ($state['variables'] ?? []));
            $engine    = (array) ($state['engine'] ?? []);

            if (! empty($input['skip_wait'])) {
                $event   = FlowEngine::EVENT_TIMER;
                $inbound = null;
            } else {
                $event   = FlowEngine::EVENT_INPUT;
                $inbound = $receipt !== null
                    ? new Inbound($text, null, -1, 'image')
                    : new Inbound($text, $this->clean($input['reply_id'] ?? null, 64));

                if ($run->status === BotFlowRun::STATUS_WAITING_TIMER) {
                    $notices[] = 'El flujo está en una Espera. En una conversación real este mensaje queda en el chat, pero el bot no lo lee: usa «Saltar espera».';
                }
            }

            if (! $run->isLive()) {
                $notices[] = 'El flujo ya terminó. Reinicia la simulación para probar otra vez.';
            }
        }

        $ex    = new Execution($graph, $io, StartNode::timezoneOf($start['data']), $variables, $engine);
        $trace = $this->engine->advance($ex, $run, $event, $inbound);

        // Las tareas simuladas terminan al instante (no hay cola ni IA): si un
        // bloque quedó esperando una, se le entrega el resultado de una vez.
        if ($run->status === BotFlowRun::STATUS_WAITING_TASK) {
            $run->task = null;
            $trace     = array_merge($trace, $this->engine->advance($ex, $run, FlowEngine::EVENT_TASK));
        }

        return [
            'state' => [
                'status'       => $run->status,
                'current_node' => $run->currentNode,
                'steps'        => $run->stepsCount,
                'resume_at'    => $run->resumeAt?->toIso8601String(),
                'ended_reason' => $run->endedReason,
                'variables'    => $ex->variables,
                'engine'       => $ex->state,
                'contact'      => $io->contactState(),
                'labels'       => $io->labels(),
                'window_open'  => $window,
            ],
            'outbox'  => $io->outbox,
            'effects' => $io->effects,
            'trace'   => $trace,
            'notices' => $notices,
        ];
    }

    private function clean(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : mb_substr($value, 0, $max);
    }
}
