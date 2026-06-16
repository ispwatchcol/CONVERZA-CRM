<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AccountPayment extends Model
{
    protected $fillable = [
        'account_id', 'account_invoice_id', 'recorded_by',
        'amount', 'currency', 'method', 'reference', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'amount'  => 'decimal:2',
            'paid_at' => 'date',
        ];
    }

    public function account(): BelongsTo  { return $this->belongsTo(Account::class); }
    public function invoice(): BelongsTo  { return $this->belongsTo(AccountInvoice::class, 'account_invoice_id'); }
    public function recordedBy(): BelongsTo { return $this->belongsTo(User::class, 'recorded_by'); }
}
