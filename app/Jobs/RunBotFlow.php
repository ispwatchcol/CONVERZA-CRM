<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\Flows\FlowRunner;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

/**
 * Despierta al motor de flujos para una ejecución: arrancarla, leerle al
 * cliente lo que escribió, o seguir después de una Espera.
 */
class RunBotFlow implements ShouldQueue
{
    use Queueable;

    public const REASON_START   = 'start';
    public const REASON_MESSAGE = 'message';
    public const REASON_TIMER   = 'timer';

    // Cada release() por lock ocupado cuenta como intento: cinco dan unos diez
    // segundos para que termine el job que tiene el lock de la conversación.
    public int $tries = 5;

    // Pero una EXCEPCIÓN no se reintenta: el reintento volvería a mandarle al
    // cliente lo que ya salió antes del error. Mejor un traspaso al equipo
    // (ver failed()) que un mensaje duplicado.
    public int $maxExceptions = 1;

    public int $timeout = 60;

    public function __construct(
        public readonly int $tenantId,
        public readonly int $runId,
        public readonly string $reason = self::REASON_MESSAGE,
    ) {
        $this->onQueue(config('flows.queue', 'default'));
    }

    public function handle(FlowRunner $runner): void
    {
        // Mismo patrón que ProcessIncomingWhatsAppMessage: queue:work reusa el
        // container entre jobs, así que el tenant se rebindea SIEMPRE desde el
        // id explícito y se libera al terminar. Heredar el binding de un job
        // anterior ya causó una fuga de mensajes entre tenants.
        app()->forgetInstance('tenant');

        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        app()->instance('tenant', $tenant);

        try {
            if ($runner->process($tenant, $this->runId, $this->reason) === FlowRunner::LOCKED) {
                $this->release(2);
            }
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
            app(FlowRunner::class)->abort($tenant, $this->runId);
        } catch (Throwable $e) {
            report($e);
        } finally {
            app()->forgetInstance('tenant');
        }
    }
}
