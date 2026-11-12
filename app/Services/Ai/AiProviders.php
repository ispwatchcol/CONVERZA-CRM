<?php

namespace App\Services\Ai;

use App\Models\Tenant;

/**
 * Arma el proveedor de IA de UNA empresa con SU llave. No existe una llave de
 * plataforma: si la empresa no cargó la suya, no hay proveedor y la IA queda
 * "sin llave" para ella (nunca usa la de otra).
 */
final class AiProviders
{
    public const NAMES = ['xai', 'openai'];

    public function forTenant(Tenant $tenant): ?AiProvider
    {
        if (! $tenant->hasAiKey()) {
            return null;
        }

        return $this->make((string) $tenant->ai_provider, (string) $tenant->ai_api_key);
    }

    public function make(string $provider, ?string $apiKey): ?AiProvider
    {
        return match ($provider) {
            'xai'    => new XaiProvider($apiKey),
            'openai' => new OpenAiProvider($apiKey),
            default  => null,
        };
    }
}
