<?php

namespace App\Services\Flows;

use App\Models\BotFlowRun;
use App\Models\BotFlowStep;
use App\Models\BotFlowVersion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Assignment\ConversationAssigner;
use App\Services\Flows\IO\LiveFlowIO;
use App\Services\Flows\Nodes\StartNode;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\WhatsAppService;
use Illuminate\Support\Facades\Cache;

/**
 * Corre el motor sobre una conversación real. Es lo que hace RunBotFlow.
 *
 * Lo que agrega sobre el FlowEngine es todo lo operativo:
 *
 *   - Lock por conversación, el MISMO que usa el bot clásico: los dos nunca
 *     corren a la vez sobre el mismo hilo, y dos mensajes en ráfaga no
 *     ejecutan el flujo dos veces.
 *   - La bandeja se consume en orden, a partir del último mensaje ya leído
 *     (`last_message_id`). Un job no procesa "su" mensaje sino todo lo que
 *     quede pendiente; así el orden no depende de qué worker tome qué job.
 *   - Los humanos mandan: si un asesor tomó la conversación o le escribió al
 *     cliente, la ejecución se corta sin decir nada más.
 *   - Versión inmutable: la ejecución usa la versión con la que arrancó.
 */
final class FlowRunner
{
    public const DONE   = 'done';
    public const LOCKED = 'locked';

    /** Entrantes que no son una respuesta: se consumen sin mostrárselos al flujo. */
    private const IGNORED_TYPES = ['reaction', 'system'];

    public function __construct(
        private readonly FlowEngine $engine,
        private readonly WhatsAppService $whatsapp,
        private readonly IspwatchRepository $ispwatch,
        private readonly ConversationAssigner $assigner,
    ) {}

    /** @param 'start'|'message'|'timer' $reason Por qué se despertó el job (solo informativo). */
    public function process(Tenant $tenant, int $runId, string $reason): string
    {
        $run = BotFlowRun::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($runId);

        if (! $run || ! $run->isLive()) {
            return self::DONE;
        }

        $lock = Cache::lock("bot:conv:{$run->conversation_id}", 30);

        if (! $lock->get()) {
            return self::LOCKED;
        }

        try {
            $run->refresh();

            if ($run->isLive()) {
                $this->processLocked($tenant, $run);
            }
        } finally {
            $lock->release();
        }

        return self::DONE;
    }

    /**
     * Cierra una ejecución viva desde afuera (job que falló, flujo apagado,
     * pregunta caducada) y libera la conversación.
     *
     * @param string|null $note Si viene, la conversación además pasa al equipo
     *                          con esa nota interna: alguien la estaba
     *                          atendiendo el bot y ahora nadie.
     */
    public function close(BotFlowRun $run, string $status, string $reason, ?string $note = null): void
    {
        if (! $run->isLive()) {
            return;
        }

        $run->forceFill([
            'status'               => $status,
            'ended_reason'         => $reason,
            'ended_at'             => now(),
            'live_conversation_id' => null,
            'resume_at'            => null,
        ])->save();

        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $run->tenant_id)
            ->with('contact')
            ->find($run->conversation_id);

        if (! $conversation) {
            return;
        }

        $conversation->updateQuietly(['bot_active' => false]);

        $tenant = Tenant::find($run->tenant_id);

        if ($note !== null && $tenant && $conversation->contact && $conversation->assigned_to === null) {
            $this->io($tenant, $conversation)->handoff(null, true, $note);
        }
    }

    /** El job falló con una excepción: la ejecución termina y la conversación va al equipo. */
    public function abort(Tenant $tenant, int $runId, string $reason = 'error'): void
    {
        $run = BotFlowRun::withoutGlobalScopes()->where('tenant_id', $tenant->id)->find($runId);

        if ($run) {
            $this->close($run, BotFlowRun::STATUS_FAILED, $reason,
                '🤖 El flujo se detuvo por un error interno y pasó la conversación al equipo.');
        }
    }

    private function processLocked(Tenant $tenant, BotFlowRun $run): void
    {
        $conversation = Conversation::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->with('contact')
            ->find($run->conversation_id);

        if (! $conversation || ! $conversation->contact) {
            $this->close($run, BotFlowRun::STATUS_CANCELLED, 'conversation_missing');
            return;
        }

        // Los humanos mandan. Sin mensaje al cliente: el asesor ya está ahí.
        if ($conversation->assigned_to !== null) {
            $this->close($run, BotFlowRun::STATUS_CANCELLED, 'assigned');
            return;
        }
        if ($this->humanReplied($run)) {
            $this->close($run, BotFlowRun::STATUS_CANCELLED, 'human_replied');
            return;
        }

        $version = BotFlowVersion::withoutGlobalScopes()->find($run->bot_flow_version_id);
        if (! $version) {
            $this->close($run, BotFlowRun::STATUS_FAILED, 'missing_version');
            return;
        }

        $graph = FlowGraph::fromArray($version->graph);
        $ex    = new Execution(
            $graph,
            $this->io($tenant, $conversation),
            StartNode::timezoneOf($graph->start()['data'] ?? []),
            (array) ($run->variables ?? []),
            (array) ($run->state ?? []),
        );
        $state = new RunState(
            $run->status,
            $run->current_node,
            (int) $run->steps_count,
            $run->resume_at,
            $run->ended_reason,
        );

        // 1. El evento propio del estado: arrancar, o seguir tras una Espera vencida.
        if ($state->status === BotFlowRun::STATUS_PENDING) {
            $this->step($run, $ex, $state, FlowEngine::EVENT_START);
        } elseif ($state->status === BotFlowRun::STATUS_WAITING_TIMER && $run->resume_at?->lte(now())) {
            $this->step($run, $ex, $state, FlowEngine::EVENT_TIMER);
        }

        // 2. Lo que el cliente escribió y el flujo aún no leyó, de a uno y en orden.
        while ($state->isLive()) {
            $message = Message::withoutGlobalScopes()
                ->where('tenant_id', $tenant->id)
                ->where('conversation_id', $run->conversation_id)
                ->where('status', 'received')
                ->where('id', '>', (int) $run->last_message_id)
                ->orderBy('id')
                ->first();

            if (! $message) {
                break;
            }

            $run->last_message_id = $message->id;

            // Durante una Espera, o si no es una respuesta (reacción, aviso de
            // sistema), el mensaje se da por leído sin mostrárselo al flujo.
            if ($state->status !== BotFlowRun::STATUS_WAITING_INPUT || in_array($message->type, self::IGNORED_TYPES, true)) {
                $this->persist($run, $ex, $state);
                continue;
            }

            $this->step($run, $ex, $state, FlowEngine::EVENT_INPUT, new Inbound(
                (string) $message->body,
                $message->raw_metadata['reply_id'] ?? null,
                $message->id,
            ));
        }
    }

    private function step(BotFlowRun $run, Execution $ex, RunState $state, string $event, ?Inbound $input = null): void
    {
        $records = $this->engine->advance($ex, $state, $event, $input);

        // El bot acaba de preguntar algo: lo que el cliente escribió ANTES no
        // es la respuesta a esta pregunta (p. ej. "hola" + "buenas" en ráfaga,
        // antes de que saliera el menú). Se da por leído.
        if ($state->status === BotFlowRun::STATUS_WAITING_INPUT) {
            $latest = (int) Message::withoutGlobalScopes()
                ->where('tenant_id', $run->tenant_id)
                ->where('conversation_id', $run->conversation_id)
                ->where('status', 'received')
                ->max('id');

            $run->last_message_id = max((int) $run->last_message_id, $latest);
        }

        foreach ($records as $record) {
            BotFlowStep::create([
                'tenant_id'       => $run->tenant_id,
                'bot_flow_run_id' => $run->id,
                'node_id'         => mb_substr($record['node_id'], 0, 64),
                'node_type'       => mb_substr($record['node_type'], 0, 30),
                'outcome'         => mb_substr((string) $record['outcome'], 0, 60),
                'input'           => $record['input'] !== null ? mb_substr($record['input'], 0, 2000) : null,
                'output'          => $record['output'] !== null ? mb_substr($record['output'], 0, 5000) : null,
                'message_id'      => $record['message_id'],
                'detail'          => $record['detail'],
                'created_at'      => now(),
            ]);
        }

        $this->persist($run, $ex, $state);
    }

    private function persist(BotFlowRun $run, Execution $ex, RunState $state): void
    {
        $run->forceFill([
            'status'           => $state->status,
            'current_node'     => $state->currentNode,
            'steps_count'      => $state->stepsCount,
            'variables'        => $ex->variables,
            'state'            => $ex->state,
            'resume_at'        => $state->resumeAt,
            'last_activity_at' => now(),
        ]);

        if ($state->status === BotFlowRun::STATUS_WAITING_TIMER && $run->isDirty('resume_at')) {
            $run->resume_dispatched_at = null;
        }

        if (! $state->isLive()) {
            $run->forceFill([
                'ended_reason'         => $state->endedReason,
                'ended_at'             => now(),
                'live_conversation_id' => null,
                'resume_at'            => null,
            ]);
        }

        $run->save();

        if (! $state->isLive()) {
            Conversation::withoutGlobalScopes()
                ->where('tenant_id', $run->tenant_id)
                ->whereKey($run->conversation_id)
                ->update(['bot_active' => false]);
        }
    }

    /**
     * ¿Un asesor le escribió al cliente desde que arrancó el flujo? Las notas
     * internas no cuentan: no le llegan al cliente.
     */
    private function humanReplied(BotFlowRun $run): bool
    {
        return Message::withoutGlobalScopes()
            ->where('tenant_id', $run->tenant_id)
            ->where('conversation_id', $run->conversation_id)
            ->where('id', '>', (int) $run->trigger_message_id)
            ->whereNotNull('sent_by_user_id')
            ->where(fn ($q) => $q->whereNull('type')->orWhere('type', '!=', 'note'))
            ->exists();
    }

    private function io(Tenant $tenant, Conversation $conversation): LiveFlowIO
    {
        return new LiveFlowIO(
            $this->whatsapp->forTenant($tenant),
            $tenant,
            $conversation,
            $conversation->contact,
            $this->ispwatch,
            $this->assigner,
        );
    }
}
