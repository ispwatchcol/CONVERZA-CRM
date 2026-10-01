<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un flujo del Workspace: un grafo de bloques que el ISP arma en el editor.
 *
 * Tiene dos caras que nunca se mezclan: el BORRADOR (`draft`), que es lo que el
 * admin edita, y la VERSIÓN PUBLICADA (`published_version_id`), que es lo único
 * que ejecuta el motor. Ver docs/workflows-bot.md.
 */
class BotFlow extends Model
{
    use BelongsToTenant;

    public const TRIGGER_CONVERSATION_START = 'conversation_start';
    public const TRIGGER_KEYWORD            = 'keyword';

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'draft',
        'draft_updated_at',
        'draft_source_version',
        'published_version_id',
        'is_active',
        'trigger_type',
        'trigger_config',
        'priority',
    ];

    protected $casts = [
        'draft'                => 'array',
        'trigger_config'       => 'array',
        'is_active'            => 'boolean',
        'draft_updated_at'     => 'datetime',
        'draft_source_version' => 'integer',
        'priority'             => 'integer',
    ];

    public function versions(): HasMany
    {
        return $this->hasMany(BotFlowVersion::class);
    }

    public function publishedVersion(): BelongsTo
    {
        return $this->belongsTo(BotFlowVersion::class, 'published_version_id');
    }

    public function runs(): HasMany
    {
        return $this->hasMany(BotFlowRun::class);
    }

    /**
     * ¿El borrador difiere de lo publicado?
     *
     * Ambos grafos se guardan normalizados (FlowGraph::normalize), así que la
     * comparación del JSON es estable: mover un bloque cuenta como cambio, igual
     * que en el editor.
     */
    public function hasUnpublishedChanges(): bool
    {
        $published = $this->publishedVersion?->graph;

        if ($published === null) {
            return true;
        }

        return json_encode($this->draft) !== json_encode($published);
    }
}
