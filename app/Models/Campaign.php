<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Campaign extends Model
{
    use BelongsToTenant, SoftDeletes;

    public const STATUS_DRAFT = 'draft';
    public const STATUS_SCHEDULED = 'scheduled';
    public const STATUS_SENDING = 'sending';
    public const STATUS_PAUSED = 'paused';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'tenant_id', 'template_id', 'created_by', 'name', 'status',
        'source_type', 'source_meta', 'variable_mapping',
        'throttle_per_minute', 'scheduled_at', 'started_at', 'completed_at',
        'total_recipients', 'sent_count', 'delivered_count', 'read_count',
        'failed_count', 'skipped_count', 'replied_count',
    ];

    protected function casts(): array
    {
        return [
            'source_meta' => 'array',
            'variable_mapping' => 'array',
            'scheduled_at' => 'datetime',
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(Template::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function recipients(): HasMany
    {
        return $this->hasMany(CampaignRecipient::class);
    }

    public function steps(): HasMany
    {
        return $this->hasMany(CampaignStep::class)->orderBy('step_order');
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class);
    }

    /** ¿Quedan inscripciones activas o en vuelo dentro de la secuencia? */
    public function hasPendingWork(): bool
    {
        return $this->recipients()
            ->whereIn('enrollment_status', [
                CampaignRecipient::ENROLLMENT_ACTIVE,
                CampaignRecipient::ENROLLMENT_SENDING,
            ])
            ->exists();
    }
}
