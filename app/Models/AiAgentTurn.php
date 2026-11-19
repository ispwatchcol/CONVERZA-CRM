<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una respuesta del agente que pidió un flujo: la tarea asíncrona del bloque
 * «Agente IA» (Step::waitTask). La crea el bloque con el prompt y el historial
 * ya armados, la contesta RunAiAgentTurn en la cola `ai` y el flujo la lee al
 * despertar.
 */
class AiAgentTurn extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING     = 'pending';
    public const STATUS_DONE        = 'done';
    public const STATUS_FAILED      = 'failed';
    public const STATUS_UNAVAILABLE = 'unavailable';

    protected $fillable = [
        'tenant_id',
        'ai_agent_id',
        'bot_flow_run_id',
        'conversation_id',
        'status',
        'instructions',
        'messages',
        'reply',
        'action',
        'reason',
    ];

    protected $casts = [
        'messages' => 'array',
    ];

    public function agent(): BelongsTo
    {
        return $this->belongsTo(AiAgent::class, 'ai_agent_id');
    }
}
