<?php

namespace App\Jobs;

use App\Models\AiAgentTurn;
use App\Models\Tenant;
use App\Services\Ai\Agents\AgentResponder;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiUnavailable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Le pide al agente IA la respuesta que espera un flujo (bloque «Agente IA») y
 * despierta al flujo para que la envíe. Corre en la cola de IA, nunca en la de
 * mensajes: una respuesta tarda segundos.
 *
 * Este job NO le escribe al cliente (eso lo hace el flujo al despertar), así que
 * reintentar un error pasajero del proveedor no duplica nada: a lo sumo cuesta
 * otra llamada.
 */
class RunAiAgentTurn implements ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    /** @var list<int> */
    public array $backoff = [5];

    // La llamada al proveedor tiene ≈60 s de tope. Por debajo del --timeout=150 del worker de IA.
    public int $timeout = 100;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $turnId,
    ) {
        $this->onQueue(config('ai.queue', 'ai'));
    }

    public function handle(AgentResponder $responder): void
    {
        // queue:work reusa el container entre jobs: el tenant se rebindea desde
        // el id explícito y se libera al terminar (ver RunBotFlow).
        app()->forgetInstance('tenant');

        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        app()->instance('tenant', $tenant);

        try {
            $turn = $this->turn();

            if (! $turn || $turn->status !== AiAgentTurn::STATUS_PENDING) {
                // Ya contestada o vencida (el flujo se cansó de esperar).
                return;
            }

            try {
                $reply = $responder->reply(
                    $tenant,
                    (string) $turn->instructions,
                    (array) $turn->messages,
                    $turn->agent?->model,
                    $turn,
                );

                $turn->forceFill([
                    'status' => AiAgentTurn::STATUS_DONE,
                    'reply'  => $reply->text,
                    'action' => $reply->action,
                    'reason' => null,
                ])->save();
            } catch (AiUnavailable $e) {
                $turn->forceFill(['status' => AiAgentTurn::STATUS_UNAVAILABLE, 'reason' => $e->reason])->save();
            } catch (AiProviderException $e) {
                if ($e->retryable && $this->attempts() < $this->tries) {
                    throw $e;
                }

                $turn->forceFill(['status' => AiAgentTurn::STATUS_FAILED, 'reason' => mb_substr($e->getMessage(), 0, 300)])->save();
            }

            $this->wakeFlow($turn);
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app()->forgetInstance('tenant');

        try {
            $turn = $this->turn();

            if ($turn && $turn->status === AiAgentTurn::STATUS_PENDING) {
                $turn->forceFill([
                    'status' => AiAgentTurn::STATUS_FAILED,
                    'reason' => mb_substr($exception?->getMessage() ?? 'El job se rindió.', 0, 300),
                ])->save();

                $this->wakeFlow($turn);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }

    private function turn(): ?AiAgentTurn
    {
        return AiAgentTurn::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->with(['agent' => fn ($q) => $q->withoutGlobalScopes()])
            ->find($this->turnId);
    }

    /** El flujo esperaba esta respuesta: ya hay algo que decirle al cliente. */
    private function wakeFlow(AiAgentTurn $turn): void
    {
        if ($turn->bot_flow_run_id) {
            RunBotFlow::dispatch($turn->tenant_id, $turn->bot_flow_run_id, RunBotFlow::REASON_TASK);
        }
    }
}
