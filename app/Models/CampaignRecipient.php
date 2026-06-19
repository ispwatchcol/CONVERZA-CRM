<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CampaignRecipient extends Model
{
    use BelongsToTenant;

    // `status` refleja el ÚLTIMO envío (para mostrar en la UI). El control de la
    // secuencia lo lleva `enrollment_status` (ver constantes ENROLLMENT_*).
    public const STATUS_PENDING = 'pending';
    public const STATUS_QUEUED = 'queued';
    public const STATUS_SENT = 'sent';
    public const STATUS_DELIVERED = 'delivered';
    public const STATUS_READ = 'read';
    public const STATUS_FAILED = 'failed';
    public const STATUS_SKIPPED = 'skipped';
    public const STATUS_OPTED_OUT = 'opted_out';

    // Estado de la INSCRIPCIÓN en la secuencia.
    public const ENROLLMENT_ACTIVE = 'active';       // esperando/listo para el próximo paso
    public const ENROLLMENT_SENDING = 'sending';     // transitorio: un paso está en vuelo
    public const ENROLLMENT_COMPLETED = 'completed'; // terminó todos los pasos o la condición lo cerró
    public const ENROLLMENT_REPLIED = 'replied';     // respondió → secuencia detenida (conversión)
    public const ENROLLMENT_OPTED_OUT = 'opted_out';
    public const ENROLLMENT_FAILED = 'failed';       // un envío falló de forma terminal

    protected $fillable = [
        'tenant_id', 'campaign_id', 'contact_id', 'phone', 'name', 'variables',
        'status', 'skip_reason', 'wa_message_id', 'error',
        'current_step', 'enrollment_status', 'next_action_at',
        'queued_at', 'sent_at', 'delivered_at', 'read_at', 'failed_at', 'replied_at',
    ];

    protected function casts(): array
    {
        return [
            'variables' => 'array',
            'current_step' => 'integer',
            'next_action_at' => 'datetime',
            'queued_at' => 'datetime',
            'sent_at' => 'datetime',
            'delivered_at' => 'datetime',
            'read_at' => 'datetime',
            'failed_at' => 'datetime',
            'replied_at' => 'datetime',
        ];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(CampaignSend::class, 'recipient_id');
    }
}
