<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Configuración de "calentamiento" (warm-up) del número de WhatsApp de un
 * tenant: una rampa de volumen diario que arranca baja y sube cada día, para
 * que el quality rating se mantenga verde y Meta promueva el número de tier.
 *
 * Es por número (= por tenant hoy) y se comparte entre TODAS las campañas del
 * tenant — el tier de Meta es por número, no por campaña. Ver WarmupBudget.
 */
class CampaignWarmup extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'enabled', 'started_on',
        'start_per_day', 'daily_increment', 'max_per_day',
    ];

    protected function casts(): array
    {
        return [
            'enabled' => 'boolean',
            'started_on' => 'immutable_date',
            'start_per_day' => 'integer',
            'daily_increment' => 'integer',
            'max_per_day' => 'integer',
        ];
    }

    /**
     * El warm-up del tenant, o una instancia nueva (sin persistir) con los
     * defaults de config si todavía no se configuró. Bypassa el scope global
     * de tenant a propósito: se llama desde el worker (RunCampaignTick), donde
     * el tenant se rebindea por job, así que filtramos por el id explícito.
     */
    public static function forTenant(int $tenantId): self
    {
        return static::withoutGlobalScopes()
            ->firstOrNew(['tenant_id' => $tenantId], [
                'enabled' => (bool) config('campaigns.warmup.enabled'),
                'start_per_day' => (int) config('campaigns.warmup.start_per_day'),
                'daily_increment' => (int) config('campaigns.warmup.daily_increment'),
                'max_per_day' => (int) config('campaigns.warmup.max_per_day'),
            ]);
    }
}
