<?php

namespace App\Models\Brain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountProduct extends Model
{
    protected $fillable = [
        'account_id',
        'product',
        'plan_key',
        'plan',
        'status',
        'billing_cycle',
        'amount',
        'currency',
        'started_at',
        'renews_at',
        'cancelled_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'       => 'decimal:2',
            'started_at'   => 'date',
            'renews_at'    => 'date',
            'cancelled_at' => 'date',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    /** ¿Este producto es parte de un combo (ambas apps a precio de paquete)? */
    public function isBundled(): bool
    {
        return $this->plan_key !== null && str_starts_with($this->plan_key, 'combo_');
    }

    /** Valor normalizado a mensual según el ciclo de facturación. */
    public function monthlyAmount(): float
    {
        return match ($this->billing_cycle) {
            'quarterly' => (float) $this->amount / 3,
            'yearly'    => (float) $this->amount / 12,
            default     => (float) $this->amount,
        };
    }
}
