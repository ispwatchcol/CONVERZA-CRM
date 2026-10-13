<?php

namespace App\Models\Brain;

use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;

/**
 * Un gasto fijo: lo que se paga todos los meses (o todos los años) sin que nadie
 * tenga que acordarse. No es plata gastada todavía: cada período, cuando llega
 * su día, `brain:expenses-recurring` le crea la fila en `brain_expenses`.
 */
class RecurringExpense extends Model
{
    protected $table = 'brain_recurring_expenses';

    protected $fillable = [
        'concept', 'vendor', 'category', 'app', 'amount', 'currency', 'cycle',
        'day_of_month', 'month_of_year', 'starts_on', 'ends_on', 'active', 'notes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount'        => 'decimal:2',
            'day_of_month'  => 'integer',
            'month_of_year' => 'integer',
            'starts_on'     => 'date',
            'ends_on'       => 'date',
            'active'        => 'boolean',
        ];
    }

    public function expenses(): HasMany  { return $this->hasMany(Expense::class, 'recurring_expense_id'); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }

    /** Valor normalizado a mensual: lo que pesa cada mes contra el MRR. */
    public function monthlyAmount(): float
    {
        return $this->cycle === 'yearly' ? (float) $this->amount / 12 : (float) $this->amount;
    }

    /**
     * Fecha de cobro del período vigente a `$today`, o null si este período no le
     * toca (un anual fuera de su mes, o antes de empezar / después de terminar).
     *
     * Solo el período vigente: si el comando estuvo apagado tres meses, al volver
     * NO aparecen tres meses de servidor de golpe. Un gasto que falte se carga a
     * mano, y queda a la vista en vez de inventado.
     *
     * @return array{date: Carbon, period: string}|null
     */
    public function dueFor(CarbonInterface $today): ?array
    {
        $today = Carbon::parse($today->toDateString());

        if ($this->cycle === 'yearly') {
            $month = $this->month_of_year ?? 1;
            $base  = $today->copy()->setDate($today->year, $month, 1);
            $date  = $base->copy()->addDays(min($this->day_of_month, $base->daysInMonth) - 1);
            $period = (string) $today->year;
        } else {
            $base  = $today->copy()->startOfMonth();
            $date  = $base->copy()->addDays(min($this->day_of_month, $base->daysInMonth) - 1);
            $period = $today->format('Y-m');
        }

        if ($date->gt($today)) {
            return null;
        }

        if ($this->starts_on && $date->lt($this->starts_on)) {
            return null;
        }

        if ($this->ends_on && $date->gt($this->ends_on)) {
            return null;
        }

        return ['date' => $date, 'period' => $period];
    }

    /**
     * Crea la fila del período vigente si ya le llegó el día y no existe.
     *
     * Idempotente por (gasto fijo, período), con índice único en la BD: correrlo
     * dos veces, o desde dos servidores a la vez, deja una sola fila.
     */
    public function generateDue(CarbonInterface $today): ?Expense
    {
        if (! $this->active) {
            return null;
        }

        $due = $this->dueFor($today);

        if ($due === null) {
            return null;
        }

        if ($this->expenses()->where('period', $due['period'])->exists()) {
            return null;
        }

        try {
            return $this->expenses()->create([
                'concept'    => $this->concept,
                'vendor'     => $this->vendor,
                'category'   => $this->category,
                'app'        => $this->app,
                'kind'       => 'fixed',
                'amount'     => $this->amount,
                'currency'   => $this->currency,
                'spent_at'   => $due['date']->toDateString(),
                'period'     => $due['period'],
                'created_by' => $this->created_by,
            ]);
        } catch (QueryException) {
            // Otra corrida ganó la carrera: la fila ya existe.
            return null;
        }
    }
}
