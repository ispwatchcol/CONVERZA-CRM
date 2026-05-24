<?php

namespace App\Models\Ispwatch;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Mirror read-only de ispwatch.public.invoices.
 * `customer_id` apunta a users.id (no a customer_profile.user_id directamente,
 * pero como customer_profile.PK ES user_id en la práctica es lo mismo).
 */
class IspwatchInvoice extends Model
{
    protected $connection = 'ispwatch';
    protected $table = 'invoices';
    public $timestamps = false;
    protected $guarded = [];

    protected $casts = [
        'issue_date'   => 'date',
        'due_date'     => 'date',
        'period_start' => 'date',
        'period_end'   => 'date',
        'subtotal'     => 'decimal:2',
        'tax'          => 'decimal:2',
        'total'        => 'decimal:2',
        'balance_due'  => 'decimal:2',
    ];

    public function customer(): BelongsTo
    {
        return $this->belongsTo(IspwatchUser::class, 'customer_id');
    }
}
