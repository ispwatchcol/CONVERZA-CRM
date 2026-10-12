<?php

namespace App\Console\Commands;

use App\Models\Brain\RecurringExpense;
use App\Services\Brain\CobroAutomatico;
use Illuminate\Console\Command;

/**
 * Registra los gastos fijos del Brain cuando les llega el día: el servidor del
 * mes, el dominio del año. Una fila por período y gasto fijo (índice único), así
 * que correrlo de más no duplica nada.
 *
 * Solo el período vigente: si estuvo apagado un tiempo, al volver no aparecen
 * meses viejos de golpe. Ver RecurringExpense::dueFor().
 */
class BrainExpensesRecurring extends Command
{
    protected $signature = 'brain:expenses-recurring';

    protected $description = 'Registra los gastos fijos del negocio a los que ya les llegó el día';

    public function handle(): int
    {
        $hoy     = CobroAutomatico::hoy();
        $created = 0;

        RecurringExpense::where('active', true)->each(function (RecurringExpense $r) use ($hoy, &$created) {
            if ($r->generateDue($hoy)) {
                $created++;
            }
        });

        $this->info("Gastos fijos registrados: {$created}");

        return self::SUCCESS;
    }
}
