<?php

namespace App\Services\Flows\Validation;

use App\Http\Controllers\BotSettingsController;
use App\Models\Label;
use App\Models\Team;

/**
 * Lo que el validador necesita saber del tenant: qué etiquetas y equipos
 * existen de verdad (un bloque no puede apuntar a una etiqueta de otro ISP ni
 * a una borrada) y los límites de configuración.
 */
final class ValidationContext
{
    /**
     * @param array<int, string> $labels id => nombre
     * @param array<int, string> $teams  id => nombre
     * @param list<string>       $timezones
     */
    public function __construct(
        public readonly array $labels = [],
        public readonly array $teams = [],
        public readonly array $timezones = BotSettingsController::TIMEZONES,
        public readonly ?int $maxWaitMinutes = null,
        public readonly ?int $maxNodes = null,
    ) {}

    public static function forTenant(int $tenantId): self
    {
        return new self(
            labels: Label::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('name', 'id')->all(),
            teams: Team::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('name', 'id')->all(),
        );
    }

    public function maxWait(): int
    {
        return $this->maxWaitMinutes ?? (int) config('flows.max_wait_minutes', 23 * 60);
    }

    public function maxNodes(): int
    {
        return $this->maxNodes ?? (int) config('flows.max_nodes', 150);
    }
}
