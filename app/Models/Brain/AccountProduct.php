<?php

namespace App\Models\Brain;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountProduct extends Model
{
    protected $fillable = [
        'account_id',
        'product',
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
}
