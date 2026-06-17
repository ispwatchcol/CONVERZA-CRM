<?php

namespace App\Services\Campaigns;

use App\Models\CampaignSend;
use App\Models\CampaignWarmup;

/**
 * Calcula el "presupuesto de calentamiento" de un número de WhatsApp: cuántos
 * destinatarios MÁS puede contactar hoy sin pasarse de la rampa configurada.
 *
 * Por qué existe (y en qué se diferencia de throttle_per_minute):
 *   - throttle_per_minute  → ritmo DENTRO del minuto (no enviar en ráfaga).
 *   - warm-up (esto)       → VOLUMEN TOTAL del día, que sube gradualmente.
 *
 * El tope es por NÚMERO (tenant), compartido entre todas sus campañas, porque
 * el messaging tier y el quality rating de Meta son por número, no por campaña.
 *
 * La ventana de uso es RODANTE de 24h (no día calendario): refleja el tier real
 * de Meta y evita depender del timezone del servidor (que está en UTC).
 */
class WarmupBudget
{
    /**
     * Mensajes enviados por el tenant en las últimas 24h. Se cuenta sobre
     * campaign_sends (cada envío real, incluidos los seguimientos de pasos 2+),
     * no sobre destinatarios. Incluye los `queued` (ya despachados a la cola,
     * aún sin sent_at) para no pasarnos del tope por la carrera entre el conteo
     * y el envío real del job.
     */
    public function usedLast24h(int $tenantId): int
    {
        return CampaignSend::withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where(function ($q) {
                $q->where('sent_at', '>=', now()->subDay())
                    ->orWhere('status', CampaignSend::STATUS_QUEUED);
            })
            ->count();
    }

    /**
     * Tope de envíos para hoy según la rampa. Si el warm-up está apagado no hay
     * tope (PHP_INT_MAX): el comportamiento es idéntico al de antes de esta feature.
     */
    public function allowance(CampaignWarmup $warmup): int
    {
        if (! $warmup->enabled) {
            return PHP_INT_MAX;
        }

        $dayIndex = $warmup->started_on
            ? max(0, (int) $warmup->started_on->startOfDay()->diffInDays(now()))
            : 0;

        $allow = $warmup->start_per_day + $warmup->daily_increment * $dayIndex;

        return (int) min($warmup->max_per_day, max($warmup->start_per_day, $allow));
    }

    /** Cuántos más puede contactar el tenant ahora mismo sin romper el warm-up. */
    public function remaining(int $tenantId): int
    {
        $warmup = CampaignWarmup::forTenant($tenantId);

        if (! $warmup->enabled) {
            return PHP_INT_MAX;
        }

        return max(0, $this->allowance($warmup) - $this->usedLast24h($tenantId));
    }
}
