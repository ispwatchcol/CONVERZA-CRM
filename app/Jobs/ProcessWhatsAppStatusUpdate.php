<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Procesa `value.statuses` del webhook de Meta (sent/delivered/read/failed):
 * actualiza el CampaignSend (el mensaje del paso) y el Message del chat que
 * coincidan por wa_message_id, y espeja el último estado en el destinatario.
 *
 * Mismo aislamiento de tenant que el resto de jobs del webhook.
 */
class ProcessWhatsAppStatusUpdate implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 5;

    private const RANK = [
        CampaignSend::STATUS_SENT => 1,
        CampaignSend::STATUS_DELIVERED => 2,
        CampaignSend::STATUS_READ => 3,
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

        $this->updateMessage($tenant, $waMessageId, $statusValue, $status);
        $this->updateCampaignSend($tenant, $waMessageId, $statusValue, $status);
    }

    private function updateMessage(Tenant $tenant, string $waMessageId, string $statusValue, array $status): void
    {
        if (! in_array($statusValue, ['delivered', 'read', 'failed'], true)) {
            return;
        }

        if ($statusValue !== 'failed') {
            // Solo se avanza en la escala sent → delivered → read, nunca se
            // retrocede. Sin esto, un webhook reintentado por Meta —o un replay
            // desde el log crudo— que reenvía "delivered" después de que ya
            // marcamos "read" degrada el mensaje en el chat. La rama de campañas
            // (updateCampaignSend) ya se protegía así; la del chat no.
            //
            // Filtrar por los estados de rango MENOR, en vez de leer y comparar
            // en PHP, resuelve además la carrera entre dos webhooks simultáneos:
            // la condición viaja dentro del UPDATE.
            //
            // Los estados fuera de la escala quedan intactos a propósito:
            // 'failed' es terminal (un rechazo de Meta no debe volver a verse
            // como entregado) y 'received' es de mensajes entrantes.
            $rango = self::RANK[$statusValue] ?? 0;
            $inferiores = array_keys(array_filter(self::RANK, fn ($r) => $r < $rango));

            Message::where('tenant_id', $tenant->id)
                ->where('wa_message_id', $waMessageId)
                ->where(fn ($q) => $q->whereIn('status', $inferiores)->orWhereNull('status'))
                ->update(['status' => $statusValue]);

            return;
        }

        // En 'failed' guardamos además el MOTIVO que reporta Meta. Sin él, el
        // chat solo podía decir "no se entregó" sin explicar que casi siempre
        // es la ventana de 24h y que la salida es mandar una plantilla. La
        // rama de campañas ya guardaba el error; la del chat lo tiraba.
        //
        // No se usa el update() masivo porque `raw_metadata` tiene cast a array
        // y el query builder lo escribiría sin serializar.
        $error = $status['errors'][0] ?? [];

        $failure = array_filter([
            'code'    => $error['code'] ?? null,
            'title'   => $error['title'] ?? $error['message'] ?? null,
            'details' => $error['error_data']['details'] ?? null,
            'at'      => now()->toIso8601String(),
        ], fn ($v) => $v !== null && $v !== '');

        $messages = Message::where('tenant_id', $tenant->id)
            ->where('wa_message_id', $waMessageId)
            ->get();

        foreach ($messages as $message) {
            $message->update([
                'status'       => 'failed',
                'raw_metadata' => array_merge((array) $message->raw_metadata, ['failure' => $failure]),
            ]);
        }
    }

    private function updateCampaignSend(Tenant $tenant, string $waMessageId, string $statusValue, array $status): void
    {
        $send = CampaignSend::where('tenant_id', $tenant->id)
            ->where('wa_message_id', $waMessageId)
            ->first();

        if (! $send) {
            return;
        }

        $campaign = Campaign::where('tenant_id', $tenant->id)->find($send->campaign_id);
        $recipient = CampaignRecipient::where('tenant_id', $tenant->id)->find($send->recipient_id);

        if ($statusValue === 'failed') {
            if ($send->status === CampaignSend::STATUS_FAILED) {
                return;
            }

            $error = $status['errors'][0]['title'] ?? $status['errors'][0]['message'] ?? 'Falló la entrega (reportado por Meta)';
            $send->update(['status' => CampaignSend::STATUS_FAILED, 'failed_at' => now(), 'error' => $error]);
            // Entrega fallida reportada por Meta = terminal: corta la secuencia.
            $recipient?->update([
                'status' => CampaignRecipient::STATUS_FAILED,
                'enrollment_status' => CampaignRecipient::ENROLLMENT_FAILED,
                'error' => $error,
            ]);
            $campaign?->increment('failed_count');
            return;
        }

        $newRank = self::RANK[$statusValue] ?? null;
        $currentRank = self::RANK[$send->status] ?? 0;

        // No degradar (ej. un reintento del webhook que reenvía "delivered"
        // después de que ya marcamos "read") ni tocar estados no relacionados
        // (queued, skipped se ignoran aquí).
        if ($newRank === null || $newRank <= $currentRank) {
            return;
        }

        $send->update([
            'status' => $statusValue,
            $statusValue . '_at' => now(),
        ]);

        // Espejo del último estado en el destinatario (para la UI), sin degradar.
        if ($recipient && $newRank > (self::RANK[$recipient->status] ?? 0)) {
            $recipient->update([
                'status' => $statusValue,
                $statusValue . '_at' => now(),
            ]);
        }

        if ($statusValue === 'delivered') {
            $campaign?->increment('delivered_count');
        } elseif ($statusValue === 'read') {
            $campaign?->increment('read_count');
        }
    }
}
