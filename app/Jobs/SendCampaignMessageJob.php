<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignOptOut;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\CampaignStep;
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
 * Envía UN paso de la secuencia a UN destinatario, contra un registro concreto
 * de campaign_sends (creado por RunCampaignTick, que ya validó la condición del
 * paso y descontó el presupuesto de warm-up). Al terminar, AVANZA la inscripción
 * al siguiente paso (o la marca completada).
 *
 * Aislamiento de tenant idéntico a ProcessIncomingWhatsAppMessage: el worker
 * reutiliza el container entre jobs, así que se rebindea por tenant_id y se
 * limpia en `finally` para no filtrar datos entre tenants.
 */
class SendCampaignMessageJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 10;

    public function __construct(
        private readonly int $campaignId,
        private readonly int $recipientId,
        private readonly int $sendId,
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
        $send = CampaignSend::where('tenant_id', $tenant->id)
            ->where('recipient_id', $this->recipientId)
            ->find($this->sendId);

        if (! $campaign || ! $recipient || ! $send || $send->status !== CampaignSend::STATUS_QUEUED) {
            return;
        }

        if ($campaign->status === Campaign::STATUS_CANCELLED) {
            $send->update(['status' => CampaignSend::STATUS_SKIPPED, 'error' => 'Campaña cancelada']);
            $recipient->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_COMPLETED]);
            return;
        }

        // Pausada en pleno vuelo: descarto este envío (aún no salió) y devuelvo la
        // inscripción a `active` para que se reintente al reanudar. Borrar el send
        // evita chocar con unique(recipient_id, step_order) al re-despachar.
        if ($campaign->status === Campaign::STATUS_PAUSED) {
            $send->delete();
            $recipient->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_ACTIVE]);
            return;
        }

        if (CampaignOptOut::where('tenant_id', $tenant->id)->where('phone', $recipient->phone)->exists()) {
            $send->update(['status' => CampaignSend::STATUS_SKIPPED, 'error' => 'El contacto se dio de baja (opt-out)']);
            $recipient->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_OPTED_OUT, 'status' => CampaignRecipient::STATUS_OPTED_OUT]);
            $campaign->increment('skipped_count');
            return;
        }

        $step = CampaignStep::where('tenant_id', $tenant->id)
            ->where('campaign_id', $this->campaignId)
            ->where('step_order', $send->step_order)
            ->first();

        $template = $step?->template;

        if (! $step || ! $template) {
            $send->update(['status' => CampaignSend::STATUS_FAILED, 'failed_at' => now(), 'error' => 'El paso no tiene plantilla asociada.']);
            $recipient->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_FAILED, 'status' => CampaignRecipient::STATUS_FAILED]);
            $campaign->increment('failed_count');
            return;
        }

        $params = $messageBuilder->buildParams($template, $recipient, $step->variable_mapping ?? []);

        $result = $whatsapp->sendTemplate($recipient->phone, $template->name, $template->language ?? 'es_CO', $params);

        if ($result['success'] ?? false) {
            $waId = $result['data']['messages'][0]['id'] ?? null;

            $send->update([
                'status' => CampaignSend::STATUS_SENT,
                'sent_at' => now(),
                'wa_message_id' => $waId,
                'error' => null,
            ]);

            $campaign->increment('sent_count');

            // Espejo en el destinatario para la UI (último envío) + legacy.
            $recipient->update([
                'status' => CampaignRecipient::STATUS_SENT,
                'sent_at' => now(),
                'wa_message_id' => $waId,
                'error' => null,
            ]);

            $this->recordInChat($tenant, $recipient, $messageBuilder->renderBody($template, $params), $waId);

            $this->advanceEnrollment($campaign, $recipient, $send->step_order);

            return;
        }

        $error = $result['error'] ?? 'desconocido';
        Log::warning('Campaign message send failed', [
            'campaign_id' => $campaign->id, 'recipient_id' => $recipient->id, 'step' => $send->step_order, 'error' => $error,
        ]);

        $send->update(['status' => CampaignSend::STATUS_FAILED, 'failed_at' => now(), 'error' => $error]);
        $recipient->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_FAILED, 'status' => CampaignRecipient::STATUS_FAILED, 'error' => $error]);
        $campaign->increment('failed_count');
    }

    /**
     * Mueve la inscripción al siguiente paso (programando next_action_at según
     * su `delay_hours`), o la marca completada si no hay más pasos.
     */
    private function advanceEnrollment(Campaign $campaign, CampaignRecipient $recipient, int $currentStepOrder): void
    {
        // Si mientras enviábamos entró una respuesta/baja (que detiene la
        // secuencia), respetarla: no reactivar la inscripción.
        $recipient->refresh();
        if (in_array($recipient->enrollment_status, [
            CampaignRecipient::ENROLLMENT_REPLIED,
            CampaignRecipient::ENROLLMENT_OPTED_OUT,
            CampaignRecipient::ENROLLMENT_FAILED,
        ], true)) {
            return;
        }

        $nextStep = CampaignStep::where('tenant_id', $campaign->tenant_id)
            ->where('campaign_id', $campaign->id)
            ->where('step_order', $currentStepOrder + 1)
            ->first();

        if ($nextStep) {
            $recipient->update([
                'current_step' => $nextStep->step_order,
                'enrollment_status' => CampaignRecipient::ENROLLMENT_ACTIVE,
                'next_action_at' => now()->addHours(max(0, $nextStep->delay_hours)),
            ]);

            return;
        }

        $recipient->update([
            'enrollment_status' => CampaignRecipient::ENROLLMENT_COMPLETED,
            'next_action_at' => null,
        ]);
    }

    /**
     * Eco del envío en el chat (mismo patrón que SendBillingNotifications::
     * recordInChat) para que la respuesta del prospecto caiga en el inbox y la
     * auto-asignación existente, cerrando el loop con el resto del CRM.
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

        $conversation = Conversation::resolveForContact($tenant->id, $contact->id);

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
