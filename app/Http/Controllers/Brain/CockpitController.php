<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\Account;
use App\Models\Brain\AccountInvoice;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class CockpitController extends Controller
{
    public function index()
    {
        $today    = now()->toDateString();
        $in30days = now()->addDays(30)->toDateString();

        // Un solo viaje a la BD para los 5 contadores (agregados condicionales).
        // El scope de SoftDeletes sigue aplicando, igual que con ->count().
        $agg = Account::query()
            ->selectRaw('count(*) as total')
            ->selectRaw("count(*) filter (where status = 'active') as active")
            ->selectRaw('count(*) filter (where tenant_id is not null) as with_converza')
            ->selectRaw('count(*) filter (where ispwatch_tenant_id is not null) as with_ispwatch')
            ->selectRaw("count(*) filter (where status in ('past_due', 'suspended')) as at_risk")
            ->first();

        // MRR (ingreso mensual recurrente) por moneda: suma los productos activos de
        // las cuentas activas. Combo-safe porque la fila ispwatch del combo va en 0.
        $mrrByCurrency = [];
        Account::where('status', 'active')
            ->with(['products' => fn ($q) => $q->where('status', 'active')])
            ->get()
            ->each(function (Account $a) use (&$mrrByCurrency) {
                foreach ($a->mrr as $cur => $amt) {
                    $mrrByCurrency[$cur] = ($mrrByCurrency[$cur] ?? 0) + $amt;
                }
            });

        // Por cobrar: saldo pendiente de las facturas no pagadas/anuladas, por moneda.
        $pendingByCurrency = [];
        $overdueCount = 0;
        AccountInvoice::whereNotIn('status', ['paid', 'void'])
            ->with('payments:id,account_invoice_id,amount')
            ->get()
            ->each(function (AccountInvoice $inv) use (&$pendingByCurrency, &$overdueCount) {
                $balance = $inv->balance_due;
                if ($balance <= 0) {
                    return;
                }
                $pendingByCurrency[$inv->currency] = ($pendingByCurrency[$inv->currency] ?? 0) + $balance;
                if ($inv->status === 'overdue' || ($inv->due_at && $inv->due_at->isPast())) {
                    $overdueCount++;
                }
            });

        $stats = [
            'total'          => (int) $agg->total,
            'active'         => (int) $agg->active,
            'with_converza'  => (int) $agg->with_converza,
            'with_ispwatch'  => (int) $agg->with_ispwatch,
            'at_risk'        => (int) $agg->at_risk,
            'mrr'            => $this->stringifyMoney($mrrByCurrency),
            'pending'        => $this->stringifyMoney($pendingByCurrency),
            'overdue_count'  => $overdueCount,
        ];

        $renewingSoon = Account::where('status', 'active')
            ->whereBetween('renewal_at', [$today, $in30days])
            ->with(['products' => fn ($q) => $q->where('status', 'active')])
            ->orderBy('renewal_at')
            ->get()
            ->map(fn ($a) => [
                'id'         => $a->id,
                'name'       => $a->name,
                'renewal_at' => $a->renewal_at?->toDateString(),
                'days_left'  => (int) now()->startOfDay()->diffInDays($a->renewal_at, false),
                'products'   => $a->products->map(fn ($p) => [
                    'product'  => $p->product,
                    'amount'   => (string) $p->amount,
                    'currency' => $p->currency,
                ]),
            ]);

        // Semáforo de servicio: productos activos ya vencidos (renews_at < hoy) — el
        // founder decide si corta. Nada se corta solo (corte manual).
        $overdueServices = Account::whereIn('status', ['active', 'past_due'])
            ->whereHas('products', fn ($q) => $q->where('status', 'active')->whereDate('renews_at', '<', $today))
            ->with(['products' => fn ($q) => $q->where('status', 'active')->whereDate('renews_at', '<', $today)])
            ->get()
            ->map(fn ($a) => [
                'id'       => $a->id,
                'name'     => $a->name,
                'status'   => $a->status,
                'products' => $a->products->map(fn ($p) => [
                    'product'   => $p->product,
                    'plan'      => $p->plan,
                    'renews_at' => $p->renews_at?->toDateString(),
                    'days_over' => (int) now()->startOfDay()->diffInDays($p->renews_at, false) * -1,
                ]),
            ]);

        $recentAccounts = Account::with(['products' => fn ($q) => $q->where('status', 'active')])
            ->withCount('products')
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->map(fn ($a) => $this->mapAccount($a));

        return Inertia::render('Brain/Cockpit', [
            'stats'            => $stats,
            'renewing_soon'    => $renewingSoon,
            'overdue_services' => $overdueServices,
            'recent_accounts'  => $recentAccounts,
        ]);
    }

    /**
     * Convierte un mapa moneda=>float a moneda=>string para no perder precisión al
     * serializar a JSON (los montos se formatean en el frontend con Intl).
     *
     * @param  array<string, float>  $byCurrency
     * @return array<string, string>
     */
    private function stringifyMoney(array $byCurrency): array
    {
        return array_map(fn ($v) => number_format($v, 2, '.', ''), $byCurrency);
    }

    private function mapAccount(Account $a): array
    {
        return [
            'id'         => $a->id,
            'name'       => $a->name,
            'slug'       => $a->slug,
            'status'     => $a->status,
            'health'     => $a->health,
            'renewal_at' => $a->renewal_at?->toDateString(),
            'products'   => $a->products->map(fn ($p) => [
                'product'  => $p->product,
                'plan_key' => $p->plan_key,
                'plan'     => $p->plan,
                'amount'   => (string) $p->amount,
                'currency' => $p->currency,
                'status'   => $p->status,
            ]),
        ];
    }
}
