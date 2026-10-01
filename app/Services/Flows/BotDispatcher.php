<?php

namespace App\Services\Flows;

use App\Jobs\HandleBotResponse;
use App\Jobs\RunBotFlow;
use App\Models\BotFlow;
use App\Models\BotFlowRun;
use App\Models\BotFlowVersion;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Carbon\Carbon;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Collection;

/**
 * Decide quién atiende un mensaje entrante: un flujo del Workspace o el bot
 * clásico. Lo llama ProcessIncomingWhatsAppMessage.
 *
 * Reglas, en orden:
 *
 *   1. Si la conversación tiene una ejecución viva, el mensaje es para ella.
 *   2. Si el tenant tiene flujos activos, el bot clásico queda EN PAUSA (nunca
 *      dos bots en el mismo número) y arranca el primer flujo cuyo disparador
 *      coincida. Nunca en una conversación con asesor asignado.
 *   3. Sin flujos activos, el bot clásico funciona exactamente como antes.
 *
 * El arranque de un flujo "reclama" la conversación en el acto (bot_active =
 * true), ANTES de que el webhook mire la auto-asignación. El job del flujo
 * corre después, en otro worker: si la reclamación esperara al job, la
 * auto-asignación ya le habría dado el chat a un asesor y el flujo nacería
 * muerto.
 */
final class BotDispatcher
{
    /** Tipos de entrante que no arrancan un flujo. */
    private const NOT_TRIGGERS = ['reaction', 'system'];

    /**
     * @return 'flow'|'legacy'|null Quién se hizo cargo del mensaje.
     */
    public function route(Tenant $tenant, Conversation $conversation, Message $message, bool $isNew, bool $wasReopened): ?string
    {
        $live = $this->liveRun($tenant, $conversation);

        if ($live && $this->abandoned($live)) {
            // Una pregunta de hace un día ya no espera respuesta: el mensaje de
            // hoy se evalúa como uno nuevo.
            app(FlowRunner::class)->close($live, BotFlowRun::STATUS_CANCELLED, $live->status === BotFlowRun::STATUS_PENDING ? 'stalled' : 'input_timeout');
            $conversation->bot_active = false;
            $conversation->syncOriginalAttribute('bot_active');
            $live = null;
        }

        if ($live) {
            RunBotFlow::dispatch($tenant->id, $live->id, RunBotFlow::REASON_MESSAGE);

            return 'flow';
        }

        $flows = $this->activeFlows($tenant);

        if ($flows->isEmpty()) {
            return $this->legacy($tenant, $conversation, $message, $isNew);
        }

        // Una conversación que quedó a medias con el bot clásico (o con una
        // marca vieja) no puede seguir "en manos del bot": con flujos activos
        // el clásico está en pausa y nadie la atendería, y bot_active = true
        // además le impide a la auto-asignación dársela a un asesor.
        if ($conversation->bot_active) {
            $conversation->updateQuietly(['bot_active' => false]);
        }

        if ($conversation->assigned_to !== null || in_array($message->type, self::NOT_TRIGGERS, true)) {
            return null;
        }

        $flow = $this->match($flows, $conversation, $message, $isNew, $wasReopened);

        if ($flow === null) {
            return null;
        }

        $run = $this->start($tenant, $flow, $conversation, $message);

        if ($run === null) {
            // Otro webhook del mismo cliente arrancó una ejecución en el mismo
            // instante (índice único de live_conversation_id): el mensaje es
            // para esa.
            $live = $this->liveRun($tenant, $conversation);
            if ($live) {
                RunBotFlow::dispatch($tenant->id, $live->id, RunBotFlow::REASON_MESSAGE);

                return 'flow';
            }

            return null;
        }

        return 'flow';
    }

    /**
     * ¿El mensaje trae alguna de las palabras clave del disparador? Pública
     * porque el simulador la usa para avisar "en la vida real esto no
     * arrancaría el flujo".
     *
     * @param array{keywords?: list<string>, match?: string} $config
     */
    public static function keywordMatches(array $config, ?string $text): bool
    {
        $text = TextMatcher::normalize($text);

        if ($text === '') {
            return false;
        }

        foreach (TextMatcher::keywordList($config['keywords'] ?? []) as $keyword) {
            $hit = ($config['match'] ?? 'contains') === 'exact'
                ? $text === $keyword
                : TextMatcher::containsPhrase($text, $keyword);

            if ($hit) {
                return true;
            }
        }

        return false;
    }

    /** @return Collection<int, BotFlow> */
    private function activeFlows(Tenant $tenant): Collection
    {
        return BotFlow::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereNotNull('published_version_id')
            ->orderBy('priority')
            ->orderBy('id')
            ->get(['id', 'tenant_id', 'trigger_type', 'trigger_config', 'published_version_id']);
    }

    /**
     * Primero los flujos por palabra clave (son más específicos: el cliente
     * pidió algo concreto), después los de inicio de conversación.
     *
     * @param Collection<int, BotFlow> $flows
     */
    private function match(Collection $flows, Conversation $conversation, Message $message, bool $isNew, bool $wasReopened): ?BotFlow
    {
        foreach ($flows as $flow) {
            if ($flow->trigger_type === BotFlow::TRIGGER_KEYWORD
                && self::keywordMatches((array) $flow->trigger_config, $message->body)) {
                return $flow;
            }
        }

        $previous = false;

        foreach ($flows as $flow) {
            if ($flow->trigger_type !== BotFlow::TRIGGER_CONVERSATION_START) {
                continue;
            }

            if ($isNew || $wasReopened) {
                return $flow;
            }

            $idleHours = (int) ($flow->trigger_config['idle_hours'] ?? 0);
            if ($idleHours <= 0) {
                continue;
            }

            if ($previous === false) {
                $previous = $this->previousActivity($conversation, $message);
            }

            if ($previous === null || $previous->lt(now()->subHours($idleHours))) {
                return $flow;
            }
        }

        return null;
    }

    private function start(Tenant $tenant, BotFlow $flow, Conversation $conversation, Message $message): ?BotFlowRun
    {
        $version = BotFlowVersion::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->find($flow->published_version_id);
        $start = $version ? FlowGraph::fromArray($version->graph)->start() : null;

        if ($start === null) {
            return null;
        }

        try {
            $run = BotFlowRun::create([
                'tenant_id'            => $tenant->id,
                'bot_flow_id'          => $flow->id,
                'bot_flow_version_id'  => $version->id,
                'conversation_id'      => $conversation->id,
                'contact_id'           => $conversation->contact_id,
                'live_conversation_id' => $conversation->id,
                'status'               => BotFlowRun::STATUS_PENDING,
                'current_node'         => $start['id'],
                'variables'            => ['mensaje_inicial' => mb_substr((string) $message->body, 0, 1000)],
                'state'                => [],
                'trigger_message_id'   => $message->id,
                'last_message_id'      => $message->id,
                'last_activity_at'     => now(),
            ]);
        } catch (UniqueConstraintViolationException) {
            return null;
        }

        $conversation->updateQuietly(['bot_active' => true, 'bot_step' => 'flow']);

        RunBotFlow::dispatch($tenant->id, $run->id, RunBotFlow::REASON_START);

        return $run;
    }

    /** El bot clásico, sin un solo cambio de comportamiento. */
    private function legacy(Tenant $tenant, Conversation $conversation, Message $message, bool $isNew): ?string
    {
        if (! $isNew && ! $conversation->bot_active) {
            return null;
        }

        HandleBotResponse::dispatch(
            conversationId:    $conversation->id,
            messageId:         $message->id,
            tenantId:          $tenant->id,
            isNewConversation: $isNew,
        );

        return 'legacy';
    }

    private function liveRun(Tenant $tenant, Conversation $conversation): ?BotFlowRun
    {
        return BotFlowRun::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('live_conversation_id', $conversation->id)
            ->first();
    }

    /**
     * Una ejecución que ya no está esperando de verdad: una pregunta que nadie
     * contestó en N horas, o un arranque cuyo job nunca corrió.
     */
    private function abandoned(BotFlowRun $run): bool
    {
        return match ($run->status) {
            BotFlowRun::STATUS_WAITING_INPUT => $run->last_activity_at?->lt(now()->subHours((int) config('flows.input_timeout_hours', 24))) ?? false,
            BotFlowRun::STATUS_PENDING       => $run->created_at?->lt(now()->subMinutes(15)) ?? false,
            default                          => false,
        };
    }

    /** Último mensaje del hilo antes de este (sin notas internas ni avisos de sistema). */
    private function previousActivity(Conversation $conversation, Message $message): ?Carbon
    {
        $at = Message::withoutGlobalScopes()
            ->where('tenant_id', $conversation->tenant_id)
            ->where('conversation_id', $conversation->id)
            ->where('id', '<', $message->id)
            ->where(fn ($q) => $q->whereNull('type')->orWhereNotIn('type', ['note', 'system']))
            ->max('created_at');

        return $at ? Carbon::parse($at) : null;
    }
}
