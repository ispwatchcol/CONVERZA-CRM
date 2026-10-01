<?php

namespace App\Console\Commands;

use App\Jobs\RunBotFlow;
use App\Models\BotFlowRun;
use App\Services\Flows\FlowRunner;
use Illuminate\Console\Command;

/**
 * El reloj del motor de flujos. Corre cada minuto (routes/console.php):
 *
 *   1. Reanuda las Esperas vencidas.
 *   2. Cierra las preguntas que nadie contestó en `flows.input_timeout_hours`:
 *      sin esto la conversación seguiría "en manos del bot" para siempre, y
 *      bot_active = true le impide a la auto-asignación dársela a un asesor.
 *   3. Cierra los arranques cuyo job nunca corrió (cola caída, job perdido).
 *
 * En reposo son tres consultas cortas por índice.
 */
class FlowsTick extends Command
{
    protected $signature = 'flows:tick';

    protected $description = 'Reanuda las esperas vencidas de los flujos del bot y cierra las ejecuciones abandonadas';

    public function handle(FlowRunner $runner): int
    {
        $resumed = 0;

        $due = BotFlowRun::withoutGlobalScopes()
            ->where('status', BotFlowRun::STATUS_WAITING_TIMER)
            ->where('resume_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('resume_dispatched_at')
                ->orWhere('resume_dispatched_at', '<', now()->subMinutes(5)))
            ->orderBy('resume_at')
            ->limit(200)
            ->get(['id', 'tenant_id']);

        foreach ($due as $run) {
            // Se marca ANTES de encolar y de forma condicional: el siguiente tic
            // no lo vuelve a encolar mientras el job espera turno, y si otro tic
            // ganó la carrera este no despacha nada.
            $claimed = BotFlowRun::withoutGlobalScopes()
                ->whereKey($run->id)
                ->where('status', BotFlowRun::STATUS_WAITING_TIMER)
                ->where(fn ($q) => $q->whereNull('resume_dispatched_at')
                    ->orWhere('resume_dispatched_at', '<', now()->subMinutes(5)))
                ->update(['resume_dispatched_at' => now()]);

            if ($claimed) {
                RunBotFlow::dispatch($run->tenant_id, $run->id, RunBotFlow::REASON_TIMER);
                $resumed++;
            }
        }

        $expired = 0;

        $stale = BotFlowRun::withoutGlobalScopes()
            ->where('status', BotFlowRun::STATUS_WAITING_INPUT)
            ->where('last_activity_at', '<', now()->subHours((int) config('flows.input_timeout_hours', 24)))
            ->limit(500)
            ->get();

        foreach ($stale as $run) {
            // Sin nota ni traspaso: el cliente dejó de contestar, no está esperando nada.
            $runner->close($run, BotFlowRun::STATUS_CANCELLED, 'input_timeout');
            $expired++;
        }

        $stalled = BotFlowRun::withoutGlobalScopes()
            ->where('status', BotFlowRun::STATUS_PENDING)
            ->where('created_at', '<', now()->subMinutes(15))
            ->limit(500)
            ->get();

        foreach ($stalled as $run) {
            // El cliente escribió y el bot nunca le contestó: esa conversación sí
            // va al equipo, con nota.
            $runner->close($run, BotFlowRun::STATUS_FAILED, 'stalled',
                '🤖 El flujo no alcanzó a responderle a este cliente (la cola de trabajos no lo procesó). Atiéndelo tú.');
            $expired++;
        }

        if ($resumed > 0 || $expired > 0) {
            $this->info("Esperas reanudadas: {$resumed} · ejecuciones cerradas: {$expired}");
        }

        return self::SUCCESS;
    }
}
