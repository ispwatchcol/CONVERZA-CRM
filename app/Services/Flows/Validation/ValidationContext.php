<?php

namespace App\Services\Flows\Validation;

use App\Http\Controllers\BotSettingsController;
use App\Models\Label;
use App\Models\PaymentChannel;
use App\Models\Team;
use App\Models\Tenant;

/**
 * Lo que el validador necesita saber del tenant: qué etiquetas y equipos
 * existen de verdad (un bloque no puede apuntar a una etiqueta de otro ISP ni
 * a una borrada), qué tiene configurado (la IA, la conexión de pagos) y los
 * límites de configuración.
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
        // Lo que usa «Comprobante de pago» para advertir (no para impedir).
        public readonly bool $aiEnabled = true,
        public readonly bool $canRegisterPayments = true,
        public readonly bool $hasPaymentAccounts = true,
    ) {}

    public static function forTenant(int $tenantId): self
    {
        $tenant = Tenant::find($tenantId);

        return new self(
            labels: Label::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('name', 'id')->all(),
            teams: Team::withoutGlobalScopes()->where('tenant_id', $tenantId)->pluck('name', 'id')->all(),
            aiEnabled: (bool) config('ai.enabled') && (bool) $tenant?->ai_enabled,
            canRegisterPayments: (bool) $tenant?->canRegisterPayments(),
            hasPaymentAccounts: PaymentChannel::withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->whereNotNull('account_number')
                ->exists(),
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
