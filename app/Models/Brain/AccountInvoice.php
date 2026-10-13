<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AccountInvoice extends Model
{
    protected $fillable = [
        'account_id', 'account_product_id', 'created_by',
        'number', 'concept', 'amount', 'tax', 'total', 'currency',
        'status', 'issued_at', 'due_at', 'paid_at', 'period_start', 'period_end',
        'source', 'billing_period',
    ];

    /** La generó el cobro automático (`brain:billing-run`), no una persona. */
    public const SOURCE_AUTO = 'auto';

    /**
     * Siguiente número consecutivo `F-NNN`.
     *
     * El número es único en toda la tabla, no por cuenta: se busca el mayor de los
     * que siguen el patrón y se suma uno. Los que alguien escribió a mano con otro
     * formato no estorban.
     */
    public static function nextNumber(): string
    {
        $max = static::query()
            ->where('number', 'like', 'F-%')
            ->pluck('number')
            ->map(fn (string $n) => preg_match('/^F-(\d+)$/', $n, $m) ? (int) $m[1] : 0)
            ->max() ?? 0;

        return sprintf('F-%03d', $max + 1);
    }

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'tax'          => 'decimal:2',
            'total'        => 'decimal:2',
            'issued_at'    => 'date',
            'due_at'       => 'date',
            'paid_at'      => 'date',
            'period_start' => 'date',
            'period_end'   => 'date',
        ];
    }

    public function account(): BelongsTo   { return $this->belongsTo(Account::class); }
    public function createdBy(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function payments(): HasMany    { return $this->hasMany(AccountPayment::class); }

    /**
     * Saldo pendiente (total - suma de pagos). Una factura anulada no debe nada.
     *
     * Cuenta TODOS los pagos, incluidos los `is_credit_application`: para la factura
     * el saldo a favor aplicado sí la abona. El que los excluye es el saldo de la
     * cuenta (`Account::balanceByCurrency`), donde esa plata ya se contó al entrar.
     */
    public function getBalanceDueAttribute(): float
    {
        if ($this->status === 'void') {
            return 0;
        }

        $paid = $this->payments->sum('amount');

        return max(0, (float) $this->total - $paid);
    }

    /** Actualiza status según pagos. Llama tras registrar un pago. */
    public function recalculateStatus(): void
    {
        if ($this->status === 'void') return;

        $paid = (float) $this->payments()->sum('amount');
        $total = (float) $this->total;

        if ($paid >= $total) {
            $this->update(['status' => 'paid', 'paid_at' => $this->paid_at ?? now()]);
        } elseif ($paid > 0) {
            $this->update(['status' => 'partial', 'paid_at' => null]);
        } elseif ($this->due_at && $this->due_at->isPast()) {
            $this->update(['status' => 'overdue', 'paid_at' => null]);
        } else {
            // Al borrar el único pago, la factura se quedaba en `paid` para siempre:
            // ni el corte automático ni "por cobrar" la volvían a ver.
            $this->update(['status' => 'sent', 'paid_at' => null]);
        }
    }
}
