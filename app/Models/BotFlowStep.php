<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un bloque ejecutado dentro de una ejecución: la traza que permite responder
 * "¿por qué el bot le dijo eso a este cliente?" sin adivinar.
 */
class BotFlowStep extends Model
{
    use BelongsToTenant;

    // Solo se inserta: una traza no se edita.
    public const UPDATED_AT = null;

    protected $fillable = [
        'tenant_id',
        'bot_flow_run_id',
        'node_id',
        'node_type',
        'outcome',
        'input',
        'output',
        'message_id',
        'detail',
        'created_at',
    ];

    protected $casts = [
        'detail'     => 'array',
        'created_at' => 'datetime',
    ];

    public function run(): BelongsTo
    {
        return $this->belongsTo(BotFlowRun::class, 'bot_flow_run_id');
    }

    public function message(): BelongsTo
    {
        return $this->belongsTo(Message::class);
    }
}
