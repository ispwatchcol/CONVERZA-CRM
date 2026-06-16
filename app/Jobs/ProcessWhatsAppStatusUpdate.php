<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Procesa `value.statuses` del webhook de Meta (sent/delivered/read/failed):
 * actualiza el CampaignRecipient y el Message del chat que coincidan por
 * wa_message_id. Antes de esto el webhook ignoraba `statuses` por completo.
 *
 * Mismo aislamiento de tenant que el resto de jobs del webhook.
 */
class ProcessWhatsAppStatusUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 5;

    private const RANK = [
        CampaignRecipient::STATUS_SENT => 1,
        CampaignRecipient::STATUS_DELIVERED => 2,
        CampaignRecipient::STATUS_READ => 3,
    ];

    /**
     * @param  array<int, array<string, mixed>>  $statuses
     */
    public function __construct(
        private readonly array $statuses,
        private readonly ?int $tenantId,
    ) {}

    public function handle(): void
    {
        app()->forgetInstance('tenant');

        $tenant = $this->tenantId ? Tenant::find($this->tenantId) : null;

        if ($tenant) {
            app()->instance('tenant', $tenant);
        }

        try {
            if ($tenant) {
                foreach ($this->statuses as $status) {
                    $this->applyStatus($tenant, $status);
                }
            }
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    private function applyStatus(Tenant $tenant, array $status): void
    {
        $waMessageId = $status['id'] ?? null;
        $statusValue = $status['status'] ?? null;

        if (! $waMessageId || ! $statusValue) {
            return;
        }

        $this->updateMessage($tenant, $waMessageId, $statusValue);
        $this->updateCampaignRecipient($tenant, $waMessageId, $statusValue, $status);
    }

    private function updateMessage(Tenant $tenant, string $waMessageId, string $statusValue): void
    {
        if (! in_array($statusValue, ['delivered', 'read', 'failed'], true)) {
            return;
        }

        Message::where('tenant_id', $tenant->id)
            ->where('wa_message_id', $waMessageId)
            ->update(['status' => $statusValue]);
    }

    private function updateCampaignRecipient(Tenant $tenant, string $waMessageId, string $statusValue, array $status): void
    {
        $recipient = CampaignRecipient::where('tenant_id', $tenant->id)
            ->where('wa_message_id', $waMessageId)
            ->first();

        if (! $recipient) {
            return;
        }

        $campaign = Campaign::where('tenant_id', $tenant->id)->find($recipient->campaign_id);

        if ($statusValue === 'failed') {
            if ($recipient->status === CampaignRecipient::STATUS_FAILED) {
                return;
            }

            $error = $status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'Falló la entrega (reportado por Meta)';
            $recipient->update(['status' => CampaignRecipient::STATUS_FAILED, 'failed_at' => now(), 'error' => $error]);
            $campaign?->increment('failed_count');
            return;
        }

        $newRank = self::RANK[$statusValue] ?? null;
        $currentRank = self::RANK[$recipient->status] ?? 0;

        // No degradar (ej. un reintento del webhook que reenvía "delivered"
        // después de que ya marcamos "read") ni tocar estados terminales/no
        // relacionados (queued, pending, skipped, opted_out se ignoran aquí).
        if ($newRank === null || $newRank <= $currentRank) {
            return;
        }

        $recipient->update([
            'status' => $statusValue,
            $statusValue . '_at' => now(),
        ]);

        if ($statusValue === 'delivered') {
            $campaign?->increment('delivered_count');
        } elseif ($statusValue === 'read') {
            $campaign?->increment('read_count');
        }
    }
}
