<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignOptOut;
use App\Models\CampaignRecipient;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Campaigns\CampaignMessageBuilder;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Envía UN mensaje de campaña a UN destinatario. Despachado por
 * RunCampaignTick con un delay escalonado para respetar throttle_per_minute.
 *
 * Aislamiento de tenant idéntico a ProcessIncomingWhatsAppMessage: el worker
 * reutiliza el container entre jobs, así que se rebindea explícitamente por
 * tenant_id y se limpia en `finally` para no filtrar datos entre tenants.
 */
class SendCampaignMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        private readonly int $campaignId,
        private readonly int $recipientId,
        private readonly ?int $tenantId,
    ) {}

    public function handle(WhatsAppService $whatsapp, CampaignMessageBuilder $messageBuilder): void
    {
        app()->forgetInstance('tenant');

        $tenant = $this->tenantId ? Tenant::find($this->tenantId) : null;

        if ($tenant) {
            app()->instance('tenant', $tenant);
        }

        try {
            if ($tenant) {
                $this->process($whatsapp->forTenant($tenant), $messageBuilder, $tenant);
            }
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    private function process(WhatsAppService $whatsapp, CampaignMessageBuilder $messageBuilder, Tenant $tenant): void
    {
        $campaign = Campaign::where('tenant_id', $tenant->id)->find($this->campaignId);
        $recipient = CampaignRecipient::where('tenant_id', $tenant->id)
            ->where('campaign_id', $this->campaignId)
            ->find($this->recipientId);

        if (! $campaign || ! $recipient || $recipient->status !== CampaignRecipient::STATUS_QUEUED) {
            return;
        }

        if ($campaign->status === Campaign::STATUS_CANCELLED) {
            $recipient->update(['status' => CampaignRecipient::STATUS_SKIPPED, 'skip_reason' => 'Campaña cancelada']);
            return;
        }

        if ($campaign->status === Campaign::STATUS_PAUSED) {
            $recipient->update(['status' => CampaignRecipient::STATUS_PENDING, 'queued_at' => null]);
            return;
        }

        if (CampaignOptOut::where('tenant_id', $tenant->id)->where('phone', $recipient->phone)->exists()) {
            $recipient->update(['status' => CampaignRecipient::STATUS_OPTED_OUT, 'skip_reason' => 'El contacto se dio de baja (opt-out)']);
            $campaign->increment('skipped_count');
            return;
        }

        $template = $campaign->template;

        if (! $template) {
            $recipient->update(['status' => CampaignRecipient::STATUS_FAILED, 'failed_at' => now(), 'error' => 'La campaña no tiene plantilla asociada.']);
            $campaign->increment('failed_count');
            return;
        }

        $params = $messageBuilder->buildParams($template, $recipient, $campaign->variable_mapping ?? []);

        $result = $whatsapp->sendTemplate($recipient->phone, $template->name, $template->language ?? 'es_CO', $params);

        if ($result['success'] ?? false) {
            $waId = $result['data']['messages'][0]['id'] ?? null;

            $recipient->update([
                'status' => CampaignRecipient::STATUS_SENT,
                'sent_at' => now(),
                'wa_message_id' => $waId,
                'error' => null,
            ]);

            $campaign->increment('sent_count');

            $this->recordInChat($tenant, $recipient, $messageBuilder->renderBody($template, $params), $waId);

            return;
        }

        $error = $result['error'] ?? 'desconocido';
        Log::warning('Campaign message send failed', ['campaign_id' => $campaign->id, 'recipient_id' => $recipient->id, 'error' => $error]);

        $recipient->update(['status' => CampaignRecipient::STATUS_FAILED, 'failed_at' => now(), 'error' => $error]);
        $campaign->increment('failed_count');
    }

    /**
     * Eco del envío en el chat (mismo patrón que SendBillingNotifications::
     * recordInChat) para que la respuesta del prospecto caiga en el inbox y
     * la auto-asignación existente, cerrando el loop con el resto del CRM.
     */
    private function recordInChat(Tenant $tenant, CampaignRecipient $recipient, string $body, ?string $waId): void
    {
        $contact = Contact::firstOrCreate(
            ['phone' => $recipient->phone, 'tenant_id' => $tenant->id],
            ['name' => $recipient->name, 'tenant_id' => $tenant->id],
        );

        if (! $recipient->contact_id) {
            $recipient->update(['contact_id' => $contact->id]);
        }

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'status' => 'open', 'tenant_id' => $tenant->id],
            ['contact_id' => $contact->id, 'tenant_id' => $tenant->id],
        );

        Message::create([
            'tenant_id' => $tenant->id,
            'conversation_id' => $conversation->id,
            'contact_id' => $contact->id,
            'body' => $body,
            'status' => 'sent',
            'type' => 'template',
            'wa_message_id' => $waId,
        ]);

        $conversation->touch();
    }
}
