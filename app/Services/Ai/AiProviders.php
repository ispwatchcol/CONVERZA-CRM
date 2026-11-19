<?php

namespace App\Services\Ai;

use App\Models\Tenant;

/**
 * Arma el proveedor de IA de UNA empresa con SU llave, SU modelo y (si eligió
 * «otro compatible») SU URL. No existe una llave de plataforma: si la empresa no
 * cargó la suya, no hay proveedor y la IA queda "sin llave" para ella (nunca
 * usa la de otra).
 */
final class AiProviders
{
    /** @return list<string> Los proveedores que existen (config('ai.providers')). */
    public static function names(): array
    {
        return array_values((array) config('ai.providers', []));
    }

    public function forTenant(Tenant $tenant): ?AiProvider
    {
        if (! $tenant->hasAiKey()) {
            return null;
        }

        return $this->make(
            (string) $tenant->ai_provider,
            (string) $tenant->ai_api_key,
            $tenant->ai_model,
            $tenant->ai_provider === 'custom' ? $tenant->ai_base_url : null,
        );
    }

    public function make(string $provider, ?string $apiKey, ?string $model = null, ?string $baseUrl = null): ?AiProvider
    {
        if (! in_array($provider, self::names(), true)) {
            return null;
        }

        return match (config("ai.{$provider}.api")) {
            'responses' => match ($provider) {
                'xai'   => new XaiProvider($apiKey, $model),
                default => new OpenAiProvider($apiKey, $model),
            },
            'anthropic' => new AnthropicProvider($apiKey, $model),
            'chat'      => new ChatCompletionsProvider($provider, $apiKey, $model, $provider === 'custom' ? $baseUrl : null),
            default     => null,
        };
    }

    /**
     * Lo que la pantalla necesita saber de cada proveedor (nunca llaves).
     *
     * @return list<array{value: string, label: string, hint: string, placeholder: string, needs_base_url: bool, default_model: ?string, vision: bool}>
     */
    public static function catalog(): array
    {
        return array_map(fn (string $name) => [
            'value'          => $name,
            'label'          => (string) config("ai.{$name}.label", $name),
            'hint'           => (string) config("ai.{$name}.key_url", ''),
            'placeholder'    => (string) config("ai.{$name}.key_placeholder", ''),
            'needs_base_url' => $name === 'custom',
            'default_model'  => config("ai.{$name}.model"),
            'vision'         => (bool) config("ai.{$name}.vision", false),
        ], self::names());
    }
}
