<?php

namespace App\Console\Commands;

use App\Jobs\SendCampaignMessageJob;
use App\Models\Campaign;
use App\Models\CampaignRecipient;
use App\Models\Tenant;
use Illuminate\Console\Command;

/**
 * Corre cada minuto (registrado en routes/console.php). Promueve campañas
 * `scheduled` cuya hora ya llegó a `sending`, y para cada campaña `sending`
 * despacha hasta `throttle_per_minute` destinatarios `pending`, escalonados
 * dentro del minuto para no enviar en ráfaga (protege el quality rating).
 *
 * Mismo patrón de aislamiento de tenant que SendBillingNotifications/
 * ProcessIncomingWhatsAppMessage: el worker reutiliza el container entre
 * jobs, así que cada tenant se rebindea explícitamente y se limpia al salir.
 */
class RunCampaignTick extends Command
{
    protected $signature = 'campaigns:tick';

    protected $description = 'Promueve campañas agendadas y despacha el siguiente lote de mensajes de las campañas en envío';

    public function handle(): int
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
                $this->dispatchForTenant($tenant);
            } finally {
                app()->forgetInstance('tenant');
            }
        }

        return self::SUCCESS;
    }

    private function dispatchForTenant(Tenant $tenant): void
    {
        $campaigns = Campaign::where('tenant_id', $tenant->id)
            ->where('status', Campaign::STATUS_SENDING)
            ->get();

        foreach ($campaigns as $campaign) {
            $this->dispatchForCampaign($campaign);
        }
    }

    private function dispatchForCampaign(Campaign $campaign): void
    {
        $pending = $campaign->recipients()
            ->where('status', CampaignRecipient::STATUS_PENDING)
            ->orderBy('id')
            ->limit(max(1, $campaign->throttle_per_minute))
            ->get(['id']);

        if ($pending->isEmpty()) {
            $stillInFlight = $campaign->recipients()->where('status', CampaignRecipient::STATUS_QUEUED)->exists();
            if (! $stillInFlight) {
                $campaign->update(['status' => Campaign::STATUS_COMPLETED, 'completed_at' => now()]);
            }
            return;
        }

        $count = $pending->count();
        $intervalSeconds = $count > 1 ? intdiv(60, $count) : 0;

        foreach ($pending->values() as $i => $recipient) {
            CampaignRecipient::where('id', $recipient->id)->update([
                'status' => CampaignRecipient::STATUS_QUEUED,
                'queued_at' => now(),
            ]);

            SendCampaignMessageJob::dispatch($campaign->id, $recipient->id, $campaign->tenant_id)
                ->delay(now()->addSeconds($i * $intervalSeconds));
        }
    }
}
