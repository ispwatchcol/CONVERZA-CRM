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
    ];

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
            $this->update(['status' => 'paid', 'paid_at' => now()]);
        } elseif ($paid > 0) {
            $this->update(['status' => 'partial']);
        } elseif ($this->due_at && $this->due_at->isPast()) {
            $this->update(['status' => 'overdue']);
        }
    }
}
