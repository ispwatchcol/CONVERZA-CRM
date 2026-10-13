<?php

namespace App\Models\Brain;

use App\Models\Tenant;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;

/**
 * Ficha central del ISP que le paga al founder.
 * NO usa BelongsToTenant — es cross-tenant por diseño.
 * El founder crea una Cuenta por cada ISP cliente.
 */
class Account extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'name',
        'legal_name',
        'slug',
        'tenant_id',
        'ispwatch_tenant_id',
        'status',
        'health',
        'owner_user_id',
        'contact_name',
        'contact_email',
        'contact_phone',
        'country',
        'onboarding_at',
        'renewal_at',
        'billing_day',
        'notes',
        'support_notify_enabled',
        'support_notify_phone',
        'auto_billing_enabled',
        'auto_billing_since',
        'billing_grace_days',
        'suspended_at',
        'suspension_reason',
    ];

    /** Lo cortó el cobro automático: el pago lo reactiva solo. */
    public const SUSPENSION_BILLING = 'billing';

    /** Lo cortó una persona: solo una persona lo levanta. */
    public const SUSPENSION_MANUAL = 'manual';

    protected function casts(): array
    {
        return [
            'onboarding_at' => 'date',
            'renewal_at'    => 'date',
            'billing_day'   => 'integer',
            'support_notify_enabled' => 'boolean',
            'auto_billing_enabled'   => 'boolean',
            'auto_billing_since'     => 'date',
            'billing_grace_days'     => 'integer',
            'suspended_at'           => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        // El muro del panel (EnsureServicioActivo) cachea si el tenant está
        // suspendido. Al cambiar el estado se olvida en el acto: un ISP que pagó
        // no tiene por qué esperar un minuto mirando "servicio suspendido".
        static::saved(function (Account $account) {
            foreach (array_filter([$account->tenant_id, $account->getOriginal('tenant_id')]) as $tenantId) {
                Cache::forget(self::suspendedCacheKey((int) $tenantId));
            }
        });
    }

    public static function suspendedCacheKey(int $tenantId): string
    {
        return "brain.tenant_suspendido.{$tenantId}";
    }

    public function isSuspended(): bool
    {
        return $this->status === 'suspended';
    }

    /**
     * Corta el servicio. No pisa un corte que ya existe: si una persona lo cortó a
     * mano, el cobro automático no lo convierte en un corte "por factura" que el
     * siguiente pago levantaría solo.
     */
    public function suspend(string $reason): bool
    {
        if ($this->isSuspended()) {
            return false;
        }

        $this->update([
            'status'            => 'suspended',
            'suspended_at'      => now(),
            'suspension_reason' => $reason,
        ]);

        return true;
    }

    public function reactivate(): void
    {
        $this->update([
            'status'            => 'active',
            'suspended_at'      => null,
            'suspension_reason' => null,
        ]);
    }

    public function billingNotices(): HasMany
    {
        return $this->hasMany(AccountBillingNotice::class);
    }

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function ownerUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_user_id');
    }

    public function products(): HasMany
    {
        return $this->hasMany(AccountProduct::class);
    }

    /**
     * MRR (ingreso mensual recurrente) por moneda, sumando los productos activos.
     *
     * Combo-safe: un combo guarda el precio del paquete en la fila de Converza y 0
     * en la de ISPWatch (ambas comparten el mismo `plan_key` combo_*), por lo que la
     * suma simple no lo cuenta dos veces.
     *
     * @return array<string, float>
     */
    public function getMrrAttribute(): array
    {
        $mrr = [];
        foreach ($this->products->where('status', 'active') as $product) {
            $mrr[$product->currency] = ($mrr[$product->currency] ?? 0) + $product->monthlyAmount();
        }
        return $mrr;
    }

    public function invoices(): HasMany
    {
        return $this->hasMany(AccountInvoice::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(AccountPayment::class);
    }

    /**
     * Saldo de la cuenta por moneda: **positivo = saldo a favor** (pagó de más),
     * negativo = debe.
     *
     *     saldo = Σ pagos reales − Σ facturas no anuladas
     *
     * Se excluyen los pagos marcados `is_credit_application`: ese dinero ya entró y
     * ya se contó cuando se registró el pago real; la fila solo mueve el saldo a
     * favor hacia una factura. Contarla otra vez inflaría el saldo.
     *
     * Es un valor DERIVADO — no hay tabla de saldos que se pueda desincronizar.
     *
     * @return array<string, float>
     */
    public function balanceByCurrency(?int $excludeInvoiceId = null): array
    {
        $balance = [];

        foreach ($this->payments->where('is_credit_application', false) as $payment) {
            $balance[$payment->currency] = ($balance[$payment->currency] ?? 0) + (float) $payment->amount;
        }

        foreach ($this->invoices->where('status', '!=', 'void') as $invoice) {
            if ($invoice->id === $excludeInvoiceId) {
                continue;
            }
            $balance[$invoice->currency] = ($balance[$invoice->currency] ?? 0) - (float) $invoice->total;
        }

        return array_map(fn ($v) => round($v, 2), $balance);
    }

    /**
     * Saldo a favor disponible en una moneda (0 si no hay o si está en deuda).
     *
     * `$excludeInvoiceId` sirve para preguntar "¿cuánto saldo tenía ANTES de esta
     * factura?" al momento de aplicarle el descuento: si no se excluye, la factura
     * recién creada ya está restando y el saldo sale disminuido justo en su propio
     * total, aplicando de menos.
     */
    public function availableCredit(string $currency, ?int $excludeInvoiceId = null): float
    {
        return max(0, $this->balanceByCurrency($excludeInvoiceId)[$currency] ?? 0);
    }

    /**
     * Plata que entró y todavía no cubre ninguna factura, por moneda.
     *
     *     libre = Σ pagos reales − Σ (lo cubierto de cada factura no anulada)
     *
     * Distinto de `balanceByCurrency()`, que resta el TOTAL de cada factura: con
     * una factura abierta de 69 y un pago suelto de 69 el saldo da 0 —correcto
     * como resumen: no debe nada—, pero para decidir cuánto aplicar esos 69 están
     * libres y le tocan justo a esa factura. Lo cubierto se topa en el total: lo
     * que alguien pagó de más sobre una factura vuelve a quedar libre.
     *
     * @return array<string, float>
     */
    public function freeCreditByCurrency(): array
    {
        $this->loadMissing(['payments', 'invoices.payments']);

        $free = [];

        foreach ($this->payments->where('is_credit_application', false) as $payment) {
            $free[$payment->currency] = ($free[$payment->currency] ?? 0) + (float) $payment->amount;
        }

        foreach ($this->invoices->where('status', '!=', 'void') as $invoice) {
            $covered = min((float) $invoice->total, (float) $invoice->payments->sum('amount'));
            $free[$invoice->currency] = ($free[$invoice->currency] ?? 0) - $covered;
        }

        return array_map(fn ($v) => max(0, round($v, 2)), $free);
    }

    /**
     * Aplica la plata libre a las facturas abiertas, de la más vieja a la más nueva.
     *
     * Se materializa como pagos marcados `is_credit_application`, igual que el
     * descuento al crear una factura: así cada factura muestra su saldo real y el
     * saldo de la cuenta no cuenta la plata dos veces.
     *
     * De la más vieja primero porque es la que vence antes: un pago suelto que se
     * fuera a la factura nueva dejaría la vieja vencida y al cliente cortado
     * habiendo pagado.
     *
     * @return float Monto aplicado en total.
     */
    public function allocateCredit(?int $recordedBy = null): float
    {
        $applied = 0.0;

        $open = $this->invoices()
            ->whereNotIn('status', ['paid', 'void'])
            ->orderBy('issued_at')
            ->orderBy('id')
            ->get();

        foreach ($open as $invoice) {
            // Fresco en cada vuelta: la aplicación anterior ya movió la plata.
            $this->unsetRelation('payments')->unsetRelation('invoices');
            $free = $this->freeCreditByCurrency()[$invoice->currency] ?? 0;

            if ($free <= 0) {
                continue;
            }

            $toApply = round(min($free, $invoice->load('payments')->balance_due), 2);

            if ($toApply <= 0) {
                continue;
            }

            AccountPayment::create([
                'account_id'            => $this->id,
                'account_invoice_id'    => $invoice->id,
                'recorded_by'           => $recordedBy,
                'amount'                => $toApply,
                'currency'              => $invoice->currency,
                'method'                => 'other',
                'is_credit_application' => true,
                'reference'             => 'Saldo a favor aplicado',
                'paid_at'               => now()->toDateString(),
            ]);

            $invoice->load('payments')->recalculateStatus();
            $applied += $toApply;
        }

        $this->unsetRelation('payments')->unsetRelation('invoices');

        return $applied;
    }

    /**
     * Facturas con saldo cuyo vencimiento ya pasó a la fecha `$today`.
     *
     * Se decide por fecha y saldo, no por `status`: una factura con abono parcial
     * queda en `partial` y vencida sigue debiendo.
     *
     * @return \Illuminate\Support\Collection<int, AccountInvoice>
     */
    public function overdueInvoices(CarbonInterface $today)
    {
        return $this->invoices()
            ->whereNotIn('status', ['paid', 'void'])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '<', $today->toDateString())
            ->with('payments')
            ->orderBy('due_at')
            ->get()
            ->filter(fn (AccountInvoice $i) => $i->balance_due > 0)
            ->values();
    }

    /**
     * Inicio del período de cobro vigente: la última fecha de facturación que ya
     * llegó a `$today`. Con día 1, el 12 de octubre está en el período que empezó
     * el 1 de octubre; con día 27, en el que empezó el 27 de septiembre.
     */
    public function currentPeriodStart(CarbonInterface $today): ?Carbon
    {
        if ($this->billing_day === null) {
            return null;
        }

        $today     = Carbon::parse($today->toDateString());
        $thisMonth = $this->clampToMonth($today, $this->billing_day);

        return $thisMonth->lte($today)
            ? $thisMonth
            : $this->clampToMonth($today->copy()->startOfMonth()->subMonthNoOverflow(), $this->billing_day);
    }

    /** Último día del período que arranca en `$start`: la víspera de la siguiente fecha de cobro. */
    public function periodEnd(CarbonInterface $start): Carbon
    {
        $next = $this->clampToMonth(Carbon::parse($start->toDateString())->startOfMonth()->addMonthNoOverflow(), $this->billing_day);

        return $next->subDay();
    }

    /**
     * Lo que se le cobra cada mes, por moneda: los productos activos, MENSUALES y
     * con valor.
     *
     * Los trimestrales y anuales quedan afuera del cobro automático: cobrarlos
     * cada mes sería cobrar de más, y la regla de "cada cuántos meses" no existe
     * todavía. La fila ispwatch de un combo (en 0) cae sola por el valor.
     *
     * @return array<string, \Illuminate\Support\Collection<int, AccountProduct>>
     */
    public function autoBillableProducts(): array
    {
        return $this->products
            ->where('status', 'active')
            ->where('billing_cycle', 'monthly')
            ->filter(fn (AccountProduct $p) => (float) $p->amount > 0)
            ->groupBy('currency')
            ->all();
    }

    /**
     * Próxima fecha de facturación según `billing_day`, contando desde `$from`.
     *
     * Si el día ya pasó este mes se va al siguiente. Si el mes destino no tiene ese
     * día (31 en un mes de 30, o 29-31 en febrero) se recorta al último día del mes:
     * facturar "el 31" en abril significa el 30.
     *
     * Devuelve null si la cuenta no tiene día de facturación configurado.
     */
    public function nextBillingDate(?\Carbon\CarbonInterface $from = null): ?\Illuminate\Support\Carbon
    {
        if ($this->billing_day === null) {
            return null;
        }

        $from   = $from ? \Illuminate\Support\Carbon::parse($from) : now();
        $anchor = $from->copy()->startOfDay();

        $candidate = $this->clampToMonth($anchor, $this->billing_day);

        return $candidate->gte($anchor)
            ? $candidate
            : $this->clampToMonth($anchor->copy()->addMonthNoOverflow()->startOfMonth(), $this->billing_day);
    }

    /** El día `$day` dentro del mes de `$date`, recortado al último día si no existe. */
    private function clampToMonth(\Illuminate\Support\Carbon $date, int $day): \Illuminate\Support\Carbon
    {
        return $date->copy()->startOfMonth()->addDays(min($day, $date->daysInMonth) - 1);
    }
}
