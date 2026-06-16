<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\Account;
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

        $stats = [
            'total'         => (int) $agg->total,
            'active'        => (int) $agg->active,
            'with_converza' => (int) $agg->with_converza,
            'with_ispwatch' => (int) $agg->with_ispwatch,
            'at_risk'       => (int) $agg->at_risk,
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
                'products'   => $a->products->map(fn ($p) => [
                    'product'  => $p->product,
                    'amount'   => (string) $p->amount,
                    'currency' => $p->currency,
                ]),
            ]);

        $recentAccounts = Account::with(['products' => fn ($q) => $q->where('status', 'active')])
            ->withCount('products')
            ->latest('updated_at')
            ->limit(8)
            ->get()
            ->map(fn ($a) => $this->mapAccount($a));

        return Inertia::render('Brain/Cockpit', [
            'stats'          => $stats,
            'renewing_soon'  => $renewingSoon,
            'recent_accounts' => $recentAccounts,
        ]);
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
                'plan'     => $p->plan,
                'amount'   => (string) $p->amount,
                'currency' => $p->currency,
                'status'   => $p->status,
            ]),
        ];
    }
}
