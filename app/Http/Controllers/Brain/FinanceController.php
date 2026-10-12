<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\Account;
use App\Models\Brain\AccountPayment;
use App\Models\Brain\Expense;
use App\Models\Brain\RecurringExpense;
use App\Services\Brain\CobroAutomatico;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Finanzas del negocio (`/brain/finanzas`): lo que entra por cobros a los ISP
 * contra lo que cuesta mantener las apps.
 *
 * Todo por moneda, sin convertir: el negocio cobra y paga en USD y COP, y una
 * tasa de cambio inventada en el reporte haría parecer exacto lo que no lo es.
 *
 * Ingreso = plata que entró de verdad (`account_payments` sin las aplicaciones de
 * saldo a favor, que son plata ya contada). No es lo facturado: una factura sin
 * pagar no paga el servidor.
 */
class FinanceController extends Controller
{
    public function index(Request $request)
    {
        $month = preg_match('/^\d{4}-\d{2}$/', (string) $request->query('month'))
            ? (string) $request->query('month')
            : CobroAutomatico::hoy()->format('Y-m');

        $start = Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay();
        $end   = $start->copy()->endOfMonth();

        $income = AccountPayment::realMoney()
            ->whereBetween('paid_at', [$start->toDateString(), $end->toDateString()])
            ->with(['account:id,name', 'invoice:id,number'])
            ->orderByDesc('paid_at')
            ->get();

        $expenses = Expense::whereBetween('spent_at', [$start->toDateString(), $end->toDateString()])
            ->orderByDesc('spent_at')
            ->orderByDesc('id')
            ->get();

        $recurring = RecurringExpense::orderByDesc('active')->orderBy('concept')->get();

        return Inertia::render('Brain/Finanzas', [
            'month'   => $month,
            'summary' => [
                'income'   => $this->sumBy($income, 'currency'),
                'expenses' => $this->sumBy($expenses, 'currency'),
                'fixed'    => $this->sumBy($expenses->where('kind', 'fixed'), 'currency'),
                'variable' => $this->sumBy($expenses->where('kind', 'variable'), 'currency'),
            ],
            'by_category' => $this->breakdown($expenses, 'category'),
            'by_app'      => $this->breakdown($expenses, 'app'),
            'trend'       => $this->trend($start),
            'unit'        => $this->unitEconomics($recurring),
            'income'      => $income->map(fn (AccountPayment $p) => [
                'id'             => $p->id,
                'account_id'     => $p->account_id,
                'account_name'   => $p->account?->name,
                'amount'         => (string) $p->amount,
                'currency'       => $p->currency,
                'method'         => $p->method,
                'reference'      => $p->reference,
                'paid_at'        => $p->paid_at?->toDateString(),
                'invoice_number' => $p->invoice?->number,
            ]),
            'expenses'  => $expenses->map(fn (Expense $e) => $this->mapExpense($e)),
            'recurring' => $recurring->map(fn (RecurringExpense $r) => [
                'id'            => $r->id,
                'concept'       => $r->concept,
                'vendor'        => $r->vendor,
                'category'      => $r->category,
                'app'           => $r->app,
                'amount'        => (string) $r->amount,
                'currency'      => $r->currency,
                'cycle'         => $r->cycle,
                'day_of_month'  => $r->day_of_month,
                'month_of_year' => $r->month_of_year,
                'starts_on'     => $r->starts_on?->toDateString(),
                'ends_on'       => $r->ends_on?->toDateString(),
                'active'        => $r->active,
                'notes'         => $r->notes,
                'monthly'       => number_format($r->monthlyAmount(), 2, '.', ''),
            ]),
            'categories' => Expense::CATEGORIES,
            'apps'       => collect(Expense::DEFAULT_APPS)
                ->merge(Expense::distinct()->pluck('app'))
                ->merge(RecurringExpense::distinct()->pluck('app'))
                ->unique()->values(),
        ]);
    }

    // ── Egresos ───────────────────────────────────────────────────────────────

    public function storeExpense(Request $request)
    {
        Expense::create([...$this->validateExpense($request), 'created_by' => Auth::id()]);

        return back()->with('success', 'Egreso registrado.');
    }

    public function updateExpense(Request $request, Expense $expense)
    {
        $expense->update($this->validateExpense($request));

        return back()->with('success', 'Egreso actualizado.');
    }

    public function destroyExpense(Expense $expense)
    {
        $expense->delete();

        return back()->with('success', 'Egreso eliminado.');
    }

    // ── Gastos fijos ──────────────────────────────────────────────────────────

    public function storeRecurring(Request $request)
    {
        $recurring = RecurringExpense::create([...$this->validateRecurring($request), 'created_by' => Auth::id()]);

        // Si el día de este mes ya pasó, la fila del mes sale ya: esperar al
        // comando de mañana dejaría el reporte de hoy sin el gasto que se acaba
        // de cargar.
        $generated = $recurring->generateDue(CobroAutomatico::hoy());

        return back()->with('success', $generated
            ? 'Gasto fijo creado. Ya se registró el de este período.'
            : 'Gasto fijo creado. Se registrará solo cuando llegue su día.');
    }

    public function updateRecurring(Request $request, RecurringExpense $recurring)
    {
        // Cambia lo que viene, no lo que ya pasó: las filas generadas conservan el
        // valor de su momento (el servidor costaba 6 USD hasta que subió a 12).
        $recurring->update($this->validateRecurring($request));

        return back()->with('success', 'Gasto fijo actualizado.');
    }

    public function destroyRecurring(RecurringExpense $recurring)
    {
        // Las filas ya generadas se quedan (la FK las deja en null): esa plata se
        // gastó aunque el gasto fijo ya no exista.
        $recurring->delete();

        return back()->with('success', 'Gasto fijo eliminado. Los egresos ya registrados se conservan.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validateExpense(Request $request): array
    {
        $validated = $request->validate([
            'concept'   => ['required', 'string', 'max:120'],
            'vendor'    => ['nullable', 'string', 'max:80'],
            'category'  => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'app'       => ['required', 'string', 'max:40', 'regex:/^[\pL\pN _.-]+$/u'],
            'kind'      => ['required', Rule::in(['fixed', 'variable'])],
            'amount'    => ['required', 'numeric', 'min:0.01'],
            'currency'  => ['required', Rule::in(['USD', 'COP'])],
            'spent_at'  => ['required', 'date'],
            'method'    => ['nullable', Rule::in(Expense::METHODS)],
            'reference' => ['nullable', 'string', 'max:120'],
            'notes'     => ['nullable', 'string', 'max:1000'],
        ]);

        return ['app' => $this->normalizeApp($validated['app'])] + $validated;
    }

    /** "Converza" y "converza " son la misma app: si no, el reporte por app se parte en dos. */
    private function normalizeApp(string $app): string
    {
        return mb_strtolower(trim($app));
    }

    /** @return array<string, mixed> */
    private function validateRecurring(Request $request): array
    {
        $validated = $request->validate([
            'concept'       => ['required', 'string', 'max:120'],
            'vendor'        => ['nullable', 'string', 'max:80'],
            'category'      => ['required', Rule::in(array_keys(Expense::CATEGORIES))],
            'app'           => ['required', 'string', 'max:40', 'regex:/^[\pL\pN _.-]+$/u'],
            'amount'        => ['required', 'numeric', 'min:0.01'],
            'currency'      => ['required', Rule::in(['USD', 'COP'])],
            'cycle'         => ['required', Rule::in(['monthly', 'yearly'])],
            'day_of_month'  => ['required', 'integer', 'between:1,31'],
            'month_of_year' => ['required_if:cycle,yearly', 'nullable', 'integer', 'between:1,12'],
            'starts_on'     => ['required', 'date'],
            'ends_on'       => ['nullable', 'date', 'after_or_equal:starts_on'],
            'active'        => ['boolean'],
            'notes'         => ['nullable', 'string', 'max:1000'],
        ]);

        $validated['active'] = $request->boolean('active', true);
        $validated['app']    = $this->normalizeApp($validated['app']);

        if ($validated['cycle'] === 'monthly') {
            $validated['month_of_year'] = null;
        }

        return $validated;
    }

    /**
     * @param  iterable<object>  $rows
     * @return array<string, string>
     */
    private function sumBy(iterable $rows, string $currencyField): array
    {
        $sum = [];
        foreach ($rows as $row) {
            $sum[$row->{$currencyField}] = ($sum[$row->{$currencyField}] ?? 0) + (float) $row->amount;
        }
        ksort($sum);

        return array_map(fn ($v) => number_format($v, 2, '.', ''), $sum);
    }

    /**
     * Egresos agrupados por un campo, y dentro por moneda.
     *
     * @return list<array{key: string, amounts: array<string, string>}>
     */
    private function breakdown($expenses, string $field): array
    {
        return $expenses->groupBy($field)
            ->map(fn ($rows, $key) => ['key' => (string) $key, 'amounts' => $this->sumBy($rows, 'currency')])
            ->sortByDesc(fn ($r) => array_sum(array_map('floatval', $r['amounts'])))
            ->values()
            ->all();
    }

    /**
     * Los últimos seis meses hasta el elegido: ingresos, egresos y resultado.
     *
     * Dos consultas agregadas en total, no dos por mes. El agrupado por mes se
     * hace en PHP: `to_char` es de Postgres y las pruebas corren en SQLite.
     *
     * @return list<array{month: string, income: array<string, string>, expenses: array<string, string>, result: array<string, string>}>
     */
    private function trend(Carbon $selected): array
    {
        $from = $selected->copy()->subMonths(5)->startOfMonth();
        $to   = $selected->copy()->endOfMonth();

        $income = AccountPayment::realMoney()
            ->whereBetween('paid_at', [$from->toDateString(), $to->toDateString()])
            ->get(['amount', 'currency', 'paid_at'])
            ->groupBy(fn ($p) => $p->paid_at->format('Y-m'));

        $expenses = Expense::whereBetween('spent_at', [$from->toDateString(), $to->toDateString()])
            ->get(['amount', 'currency', 'spent_at'])
            ->groupBy(fn ($e) => $e->spent_at->format('Y-m'));

        $rows = [];
        for ($m = $from->copy(); $m->lte($to); $m->addMonthNoOverflow()) {
            $key = $m->format('Y-m');
            $in  = $this->sumBy($income[$key] ?? [], 'currency');
            $out = $this->sumBy($expenses[$key] ?? [], 'currency');

            $result = [];
            foreach (array_unique([...array_keys($in), ...array_keys($out)]) as $cur) {
                $result[$cur] = number_format((float) ($in[$cur] ?? 0) - (float) ($out[$cur] ?? 0), 2, '.', '');
            }
            ksort($result);

            $rows[] = ['month' => $key, 'income' => $in, 'expenses' => $out, 'result' => $result];
        }

        return $rows;
    }

    /**
     * La foto del mes "normal": MRR de las cuentas activas contra los gastos fijos
     * activos llevados a mensual. Dice si el negocio se paga solo antes de mirar
     * cuánto entró este mes en particular (que depende de quién pagó cuándo).
     *
     * @return array{mrr: array<string, string>, fixed_monthly: array<string, string>}
     */
    private function unitEconomics($recurring): array
    {
        $mrr = [];
        Account::where('status', 'active')
            ->with(['products' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->each(function (Account $a) use (&$mrr) {
                foreach ($a->mrr as $cur => $amt) {
                    $mrr[$cur] = ($mrr[$cur] ?? 0) + $amt;
                }
            });

        $fixed = [];
        foreach ($recurring->where('active', true) as $r) {
            $fixed[$r->currency] = ($fixed[$r->currency] ?? 0) + $r->monthlyAmount();
        }

        $fmt = fn ($a) => array_map(fn ($v) => number_format($v, 2, '.', ''), $a);

        return ['mrr' => $fmt($mrr), 'fixed_monthly' => $fmt($fixed)];
    }

    /** @return array<string, mixed> */
    private function mapExpense(Expense $e): array
    {
        return [
            'id'                   => $e->id,
            'recurring_expense_id' => $e->recurring_expense_id,
            'concept'              => $e->concept,
            'vendor'               => $e->vendor,
            'category'             => $e->category,
            'app'                  => $e->app,
            'kind'                 => $e->kind,
            'amount'               => (string) $e->amount,
            'currency'             => $e->currency,
            'spent_at'             => $e->spent_at?->toDateString(),
            'method'               => $e->method,
            'reference'            => $e->reference,
            'notes'                => $e->notes,
        ];
    }
}
