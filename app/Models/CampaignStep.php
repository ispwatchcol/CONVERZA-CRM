<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un paso de la secuencia de una campaña. step_order 1 = envío inicial; los
 * siguientes son seguimientos que esperan `delay_hours` tras el paso anterior y
 * solo se mandan si se cumple `send_condition` (ver RunCampaignTick).
 */
class CampaignStep extends Model
{
    use BelongsToTenant;

    public const CONDITION_ALWAYS = 'always';
    public const CONDITION_IF_NOT_REPLIED = 'if_not_replied';
    public const CONDITION_IF_NOT_READ = 'if_not_read';
    public const CONDITION_IF_NOT_DELIVERED = 'if_not_delivered';

    public const CONDITIONS = [
        self::CONDITION_ALWAYS,
        self::CONDITION_IF_NOT_REPLIED,
        self::CONDITION_IF_NOT_READ,
        self::CONDITION_IF_NOT_DELIVERED,
    ];

    protected $fillable = [
        'tenant_id', 'campaign_id', 'step_order', 'template_id',
        'variable_mapping', 'delay_hours', 'send_condition',
    ];

    protected function casts(): array
    {
        return [
            'variable_mapping' => 'array',
            'step_order' => 'integer',
            'delay_hours' => 'integer',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class, 'step_id');
    }
}
