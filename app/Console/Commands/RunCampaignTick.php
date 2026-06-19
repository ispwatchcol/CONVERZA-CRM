<?php

namespace App\Console\Commands;

use App\Jobs\SendCampaignMessageJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\CampaignStep;
use App\Models\Tenant;
use App\Services\Campaigns\WarmupBudget;
use Illuminate\Console\Command;

/**
 * Corre cada minuto (registrado en routes/console.php). Motor de las secuencias
 * multi-paso: promueve campañas `scheduled` cuya hora llegó a `sending`, y para
 * cada campaña en envío toma las inscripciones (campaign_recipients) cuyo próximo
 * paso ya vence, valida la CONDICIÓN del paso, y despacha un SendCampaignMessageJob
 * por cada una, escalonados dentro del minuto (no enviar en ráfaga).
 *
 * Respeta el "calentamiento" (warm-up): un tope de volumen DIARIO por número
 * (tenant), compartido por todas sus campañas (ver WarmupBudget). Solo los envíos
 * que pasan la condición consumen presupuesto.
 *
 * Mismo patrón de aislamiento de tenant que SendBillingNotifications/
 * ProcessIncomingWhatsAppMessage: el worker reutiliza el container entre jobs,
 * así que cada tenant se rebindea explícitamente y se limpia al salir.
 */
class RunCampaignTick extends Command
{
    protected $signature = 'campaigns:tick';

    protected $description = 'Promueve campañas agendadas y despacha el siguiente paso de las inscripciones que ya vencen';

    /** Tras este tiempo, una inscripción atascada en `sending` (job perdido) se reintenta. */
    private const STALE_SENDING_MINUTES = 15;

    public function handle(WarmupBudget $budget): int
    {
        Campaign::where('status', Campaign::STATUS_SCHEDULED)
            ->where('scheduled_at', '<=', now())
            ->update(['status' => Campaign::STATUS_SENDING, 'started_at' => now()]);

        $tenantIds = Campaign::where('status', Campaign::STATUS_SENDING)->distinct()->pluck('tenant_id');

        foreach ($tenantIds as $tenantId) {
            app()->forgetInstance('tenant');
            $tenant = Tenant::find($tenantId);

            if (! $tenant) {
                continue;
            }

            app()->instance('tenant', $tenant);

            try {
                $this->dispatchForTenant($tenant, $budget);
            } finally {
                app()->forgetInstance('tenant');
            }
        }

        return self::SUCCESS;
    }

    private function dispatchForTenant(Tenant $tenant, WarmupBudget $budget): void
    {
        // Tope de warm-up del NÚMERO: presupuesto compartido por todas las
        // campañas del tenant. PHP_INT_MAX si el warm-up está apagado.
        $remaining = $budget->remaining($tenant->id);

        $campaigns = Campaign::where('tenant_id', $tenant->id)
            ->where('status', Campaign::STATUS_SENDING)
            ->orderBy('id')
            ->get();

        foreach ($campaigns as $campaign) {
            if ($remaining <= 0) {
                break;
            }
            $remaining -= $this->dispatchForCampaign($campaign, $remaining);
        }
    }

    /**
     * Despacha el siguiente lote de UNA campaña sin exceder ni su
     * throttle_per_minute ni el $budget de warm-up que le queda al número.
     * Devuelve cuántos mensajes despachó, para descontarlos del presupuesto.
     */
    private function dispatchForCampaign(Campaign $campaign, int $budget): int
    {
        $this->reclaimStaleSending($campaign);

        $limit = max(1, min($campaign->throttle_per_minute, $budget));

        $candidates = $campaign->recipients()
            ->where('enrollment_status', CampaignRecipient::ENROLLMENT_ACTIVE)
            ->where(function ($q) {
                $q->whereNull('next_action_at')->orWhere('next_action_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->get();

        // Decide qué se envía: resuelve el paso y evalúa su condición. Las
        // inscripciones que no pasan la condición se cierran sin gastar presupuesto.
        $toDispatch = [];

        foreach ($candidates as $recipient) {
            $step = CampaignStep::where('campaign_id', $campaign->id)
                ->where('step_order', $recipient->current_step)
                ->first();

            if (! $step) {
                $recipient->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_COMPLETED, 'next_action_at' => null]);
                continue;
            }

            if (! $this->conditionHolds($recipient, $step)) {
                // p.ej. ya leyó/respondió y el seguimiento era condicional: meta cumplida.
                $recipient->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_COMPLETED, 'next_action_at' => null]);
                continue;
            }

            $toDispatch[] = [$recipient, $step];
        }

        $count = count($toDispatch);

        if ($count > 0) {
            $intervalSeconds = $count > 1 ? intdiv(60, $count) : 0;

            foreach (array_values($toDispatch) as $i => [$recipient, $step]) {
                $send = CampaignSend::updateOrCreate(
                    ['recipient_id' => $recipient->id, 'step_order' => $step->step_order],
                    [
                        'tenant_id' => $campaign->tenant_id,
                        'campaign_id' => $campaign->id,
                        'step_id' => $step->id,
                        'status' => CampaignSend::STATUS_QUEUED,
                        'queued_at' => now(),
                        'wa_message_id' => null,
                        'error' => null,
                        'sent_at' => null,
                        'delivered_at' => null,
                        'read_at' => null,
                        'failed_at' => null,
                    ],
                );

                $recipient->update([
                    'enrollment_status' => CampaignRecipient::ENROLLMENT_SENDING,
                    'status' => CampaignRecipient::STATUS_QUEUED,
                    'queued_at' => now(),
                ]);

                SendCampaignMessageJob::dispatch($campaign->id, $recipient->id, $send->id, $campaign->tenant_id)
                    ->delay(now()->addSeconds($i * $intervalSeconds));
            }
        }

        // Sin trabajo pendiente (ni activos esperando seguimiento ni en vuelo) → completada.
        $hasWork = $campaign->recipients()
            ->whereIn('enrollment_status', [CampaignRecipient::ENROLLMENT_ACTIVE, CampaignRecipient::ENROLLMENT_SENDING])
            ->exists();

        if (! $hasWork) {
            $campaign->update(['status' => Campaign::STATUS_COMPLETED, 'completed_at' => now()]);
        }

        return $count;
    }

    /**
     * ¿Se cumple la condición del paso para este destinatario? El paso 1 (envío
     * inicial) siempre se manda; los seguimientos miran el envío del paso previo.
     */
    private function conditionHolds(CampaignRecipient $recipient, CampaignStep $step): bool
    {
        if ($step->step_order <= 1 || $step->send_condition === CampaignStep::CONDITION_ALWAYS) {
            return true;
        }

        $previous = CampaignSend::where('recipient_id', $recipient->id)
            ->where('step_order', $step->step_order - 1)
            ->first();

        return match ($step->send_condition) {
            // null-safe: si no hay envío previo, la condición se considera cumplida.
            CampaignStep::CONDITION_IF_NOT_REPLIED => $previous?->replied_at === null,
            CampaignStep::CONDITION_IF_NOT_READ => $previous?->read_at === null,
            CampaignStep::CONDITION_IF_NOT_DELIVERED => $previous?->delivered_at === null,
            default => true,
        };
    }

    /**
     * Reintenta inscripciones atascadas en `sending` (su job se perdió): borra el
     * envío `queued` viejo y las devuelve a `active` para re-despacharlas.
     */
    private function reclaimStaleSending(Campaign $campaign): void
    {
        $stale = CampaignSend::where('campaign_id', $campaign->id)
            ->where('status', CampaignSend::STATUS_QUEUED)
            ->where('queued_at', '<', now()->subMinutes(self::STALE_SENDING_MINUTES))
            ->get(['id', 'recipient_id']);

        if ($stale->isEmpty()) {
            return;
        }

        CampaignRecipient::whereIn('id', $stale->pluck('recipient_id'))
            ->where('enrollment_status', CampaignRecipient::ENROLLMENT_SENDING)
            ->update(['enrollment_status' => CampaignRecipient::ENROLLMENT_ACTIVE]);

        CampaignSend::whereIn('id', $stale->pluck('id'))->delete();
    }
}
