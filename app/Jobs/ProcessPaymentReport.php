<?php

namespace App\Jobs;

use App\Models\PaymentReport;
use App\Models\Tenant;
use App\Services\Payments\PaymentReportProcessor;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Lee un comprobante con IA, lo verifica y, si corresponde, le pide a ispwatch
 * que registre el pago. Corre en la cola de IA (config ai.queue), nunca en la
 * de mensajes: una lectura tarda segundos.
 *
 * A diferencia de RunBotFlow, este job SÍ se reintenta tras una excepción: no
 * le escribe al cliente, y el procesador retoma donde quedó (la lectura no se
 * repite si ya está; el registro se repite con la misma llave de idempotencia).
 *
 * Al terminar despierta al flujo que esperaba el resultado, si hay uno.
 */
class ProcessPaymentReport implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [10, 30];

    // La lectura (≈60 s de tope) más el registro en ispwatch (≈60 s, reconecta
    // en el router). Por debajo del --timeout=150 del worker de IA.
    public int $timeout = 140;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $reportId,
    ) {
        $this->onQueue(config('ai.queue', 'ai'));
    }

    public function handle(PaymentReportProcessor $processor): void
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
            $report = $this->report();
            if (! $report) {
                return;
            }

            $processor->process($tenant, $report);

            $this->wakeFlow($report);
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    public function failed(?Throwable $exception): void
    {
        app()->forgetInstance('tenant');

        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        app()->instance('tenant', $tenant);

        try {
            $report = $this->report();

            if ($report) {
                app(PaymentReportProcessor::class)->giveUp($report, $exception ?? new \RuntimeException('El job se rindió.'));
                $this->wakeFlow($report);
            }
        } catch (Throwable $e) {
            report($e);
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    private function report(): ?PaymentReport
    {
        return PaymentReport::withoutGlobalScopes()
            ->where('tenant_id', $this->tenantId)
            ->find($this->reportId);
    }

    /** El flujo esperaba este comprobante: ya hay algo que decirle al cliente. */
    private function wakeFlow(PaymentReport $report): void
    {
        $report->refresh();

        if ($report->bot_flow_run_id && ! in_array($report->status, [PaymentReport::STATUS_READING, PaymentReport::STATUS_REGISTERING], true)) {
            RunBotFlow::dispatch($report->tenant_id, $report->bot_flow_run_id, RunBotFlow::REASON_TASK);
        }
    }
}
