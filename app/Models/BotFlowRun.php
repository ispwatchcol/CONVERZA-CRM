<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Una ejecución de un flujo sobre una conversación.
 *
 * Reemplaza, para los flujos del Workspace, lo que el bot cableado guardaba en
 * `conversations.bot_step` y `bot_context`.
 */
class BotFlowRun extends Model
{
    use BelongsToTenant;

    // Vivas: la conversación sigue en manos del flujo.
    public const STATUS_PENDING       = 'pending';        // creada, el job aún no la arranca
    public const STATUS_WAITING_INPUT = 'waiting_input';  // esperando respuesta del cliente
    public const STATUS_WAITING_TIMER = 'waiting_timer';  // en un bloque Espera

    // Terminadas.
    public const STATUS_COMPLETED  = 'completed';   // llegó a un bloque Fin
    public const STATUS_HANDED_OFF = 'handed_off';  // entregó la conversación al equipo
    public const STATUS_CANCELLED  = 'cancelled';   // la cortó algo de afuera (un asesor, apagar el flujo…)
    public const STATUS_FAILED     = 'failed';      // la cortó el propio motor (error, tope de pasos)

    public const LIVE_STATUSES = [
        self::STATUS_PENDING,
        self::STATUS_WAITING_INPUT,
        self::STATUS_WAITING_TIMER,
    ];

    protected $fillable = [
        'tenant_id',
        'bot_flow_id',
        'bot_flow_version_id',
        'conversation_id',
        'contact_id',
        'live_conversation_id',
        'status',
        'current_node',
        'variables',
        'state',
        'trigger_message_id',
        'last_message_id',
        'resume_at',
        'resume_dispatched_at',
        'steps_count',
        'ended_reason',
        'last_activity_at',
        'ended_at',
    ];

    protected $casts = [
        'variables'            => 'array',
        'state'                => 'array',
        'resume_at'            => 'datetime',
        'resume_dispatched_at' => 'datetime',
        'last_activity_at'     => 'datetime',
        'ended_at'             => 'datetime',
        'steps_count'          => 'integer',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BotFlow::class, 'bot_flow_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(BotFlowVersion::class, 'bot_flow_version_id');
    }

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(BotFlowStep::class)->orderBy('id');
    }

    public function scopeLive(Builder $query): Builder
    {
        return $query->whereIn('status', self::LIVE_STATUSES);
    }

    public function isLive(): bool
    {
        return in_array($this->status, self::LIVE_STATUSES, true);
    }
}
