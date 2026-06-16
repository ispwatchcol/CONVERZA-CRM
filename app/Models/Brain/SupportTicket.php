<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $fillable = [
        'account_id', 'assigned_to', 'opened_by',
        'subject', 'status', 'priority', 'category', 'product', 'source',
        'first_response_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_response_at' => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    public function account(): BelongsTo    { return $this->belongsTo(Account::class); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function openedBy(): BelongsTo   { return $this->belongsTo(User::class, 'opened_by'); }
    public function events(): HasMany       { return $this->hasMany(TicketEvent::class); }
}
