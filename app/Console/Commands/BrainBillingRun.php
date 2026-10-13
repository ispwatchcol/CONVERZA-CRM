<?php

namespace App\Console\Commands;

use App\Services\Brain\CobroAutomatico;
use Illuminate\Console\Command;

/**
 * El cobro automático del SaaS a los ISP. Corre cada hora en horario de oficina
 * (routes/console.php) y solo toca las cuentas con el cobro encendido.
 *
 * Idempotente: la segunda pasada del día no crea nada nuevo. Si el servidor
 * estuvo caído, la siguiente pasada factura el período vigente con vencimiento
 * contado desde ESE día, así que nadie recibe una factura que nace vencida.
 *
 * Ver CobroAutomatico para el detalle de cada paso.
 */
class BrainBillingRun extends Command
{
    protected $signature = 'brain:billing-run
                            {--account= : Procesar solo esta cuenta (id del Brain)}';

    protected $description = 'Cobro automático del SaaS: factura el mes, recuerda, corta lo vencido y reactiva lo pagado';

    public function handle(CobroAutomatico $cobro): int
    {
        $account = $this->option('account');

        $stats = $cobro->run(null, $account !== null ? (int) $account : null);

        $this->info(sprintf(
            'Cuentas: %d · facturas: %d · recordatorios: %d · cortes: %d · reactivaciones: %d · errores: %d',
            $stats['cuentas'], $stats['facturas'], $stats['recordatorios'],
            $stats['cortes'], $stats['reactivaciones'], $stats['errores'],
        ));

        return $stats['errores'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
