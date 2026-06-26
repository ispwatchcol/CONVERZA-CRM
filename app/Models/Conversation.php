<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Conversation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'contact_id', 'status', 'assigned_to', 'team_id',
        'bot_active', 'bot_step', 'bot_failed_intents', 'bot_context',
    ];

    protected $casts = [
        'bot_active'         => 'boolean',
        'bot_context'        => 'array',
        'bot_failed_intents' => 'integer',
    ];

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'assigned_to');
    }

    public function closingNotes(): HasMany
    {
        return $this->hasMany(ClosingNote::class);
    }

    /**
     * El "último mensaje" de la lista de chats y el cálculo de "cliente
     * esperando" del dashboard deben reflejar la conversación real con el
     * cliente, NO las notas internas ni los eventos de sistema (transferencias).
     * type NULL = mensaje de texto saliente antiguo, por eso se incluye.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where(function ($q) {
                $q->whereNull('type')->orWhereNotIn('type', ['system', 'note']);
            }),
        );
    }
}
