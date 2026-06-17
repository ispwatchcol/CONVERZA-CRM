<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Un mensaje real enviado a un destinatario por un paso de la secuencia. Es el
 * registro que casa el webhook de Meta por `wa_message_id` (sent/delivered/read/
 * failed) y sobre el que se cuenta el warm-up y el embudo por paso.
 */
class CampaignSend extends Model
{
    use BelongsToTenant;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';

    protected $fillable = [
        'tenant_id', 'campaign_id', 'recipient_id', 'step_id', 'step_order',
        'status', 'wa_message_id', 'error',
        'queued_at', 'sent_at', 'delivered_at', 'read_at', 'replied_at', 'failed_at',
    ];

    protected function casts(): array
    {
        return [
            'step_order' => 'integer',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'replied_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(CampaignRecipient::class, 'recipient_id');
    }

    public function step(): BelongsTo
    {
        return $this->belongsTo(CampaignStep::class, 'step_id');
    }
}
