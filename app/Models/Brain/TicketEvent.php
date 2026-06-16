<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    public $timestamps = false;

    protected $fillable = ['support_ticket_id', 'author_user_id', 'type', 'body', 'meta'];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_user_id'); }
}
