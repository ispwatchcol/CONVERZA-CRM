<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Copia inmutable del grafo de un flujo en el momento de publicarlo.
 *
 * Nunca se edita: publicar crea una fila nueva. Una ejecución en curso guarda
 * el id de su versión y termina con ella aunque se publique otra en el medio.
 */
class BotFlowVersion extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id',
        'bot_flow_id',
        'version',
        'graph',
        'note',
        'published_by',
        'published_at',
    ];

    protected $casts = [
        'graph'        => 'array',
        'version'      => 'integer',
        'published_at' => 'datetime',
    ];

    public function flow(): BelongsTo
    {
        return $this->belongsTo(BotFlow::class, 'bot_flow_id');
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }
}
