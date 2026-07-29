<?php

namespace App\Models\Brain;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
    ];

    protected function casts(): array
    {
        return [
            'onboarding_at' => 'date',
            'renewal_at'    => 'date',
            'billing_day'   => 'integer',
        ];
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
