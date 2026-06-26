<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BotLog extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'conversation_id',
        'incoming_body',
        'intent_detected',
        'bot_response',
        'escalated',
        'context_data',
    ];

    protected $casts = [
        'escalated' => 'boolean',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
}
