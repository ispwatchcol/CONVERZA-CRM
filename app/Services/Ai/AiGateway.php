<?php

namespace App\Services\Ai;

use App\Models\AiUsageLog;
use App\Models\Tenant;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * La única puerta hacia la IA. Antes de cada llamada mira, en este orden:
 *
 *   1. El interruptor general (config ai.enabled): apagarlo frena todo sin
 *      desplegar.
 *   2. Que la empresa la haya encendido (tenants.ai_enabled): es opt-in,
 *      porque los comprobantes salen a un proveedor externo.
 *   3. Que el proveedor esté configurado (llave).
 *   4. El presupuesto del mes de la empresa.
 *
 * Y deja cada llamada en ai_usage_logs, con éxito o error, que es de donde sale
 * el gasto del mes. La bitácora nunca tumba la llamada: si no se puede
 * escribir, se reporta y se sigue.
 */
final class AiGateway
{
    public function __construct(private readonly AiProviders $providers) {}

    /** null = se puede usar; si no, el motivo (una llave de PaymentReport::REASONS). */
    public function unavailableReason(Tenant $tenant): ?string
    {
        return match (true) {
            ! config('ai.enabled')                                              => 'ia_apagada_global',
            ! $tenant->ai_enabled                                               => 'ia_apagada',
            ! $this->providers->forTenant($tenant)?->isConfigured()             => 'sin_llave',
            $this->overBudget($tenant)                                          => 'presupuesto_agotado',
            default                                                             => null,
        };
    }

    /**
     * @param Model|null $subject A qué se le cargó la llamada (el reporte de pago…).
     *
     * @throws AiUnavailable       La IA no está disponible para la empresa: no es un error.
     * @throws AiProviderException El proveedor falló.
     */
    public function respond(Tenant $tenant, AiRequest $request, ?Model $subject = null): AiResponse
    {
        if (($reason = $this->unavailableReason($tenant)) !== null) {
            throw new AiUnavailable($reason);
        }

        $started = hrtime(true);

        try {
            $response = $this->providers->forTenant($tenant)->respond($request);
        } catch (AiProviderException $e) {
            $this->log($tenant, $request, $subject, $started, null, $e->getMessage());

            throw $e;
        }

        $this->log($tenant, $request, $subject, $started, $response);

        return $response;
    }

    /** Lo gastado en el mes calendario en curso, en la hora del negocio. */
    public function spentThisMonth(Tenant $tenant): float
    {
        $since = CarbonImmutable::now((string) config('flows.default_timezone', 'America/Bogota'))
            ->startOfMonth()
            ->utc();

        return (float) AiUsageLog::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('created_at', '>=', $since)
            ->sum('cost_usd');
    }

    /**
     * El tope mensual de la empresa en USD, o null si no tiene. Es OPCIONAL: la
     * llave y el gasto son de la empresa, así que el tope es un freno que ella
     * (o el superadmin) se pone, no un límite de Converza.
     */
    public function monthlyBudget(Tenant $tenant): ?float
    {
        $budget = (float) ($tenant->ai_monthly_budget_usd ?? config('ai.default_monthly_budget_usd', 0));

        return $budget > 0 ? $budget : null;
    }

    private function overBudget(Tenant $tenant): bool
    {
        $budget = $this->monthlyBudget($tenant);

        return $budget !== null && $this->spentThisMonth($tenant) >= $budget;
    }

    private function log(Tenant $tenant, AiRequest $request, ?Model $subject, int $started, ?AiResponse $response, ?string $error = null): void
    {
        try {
            $cost      = $response?->costUsd;
            $estimated = false;

            if ($response !== null && $cost === null) {
                $cost = $response->inputTokens / 1_000_000 * (float) config("ai.{$response->provider}.price_input_per_million", 0)
                    + $response->outputTokens / 1_000_000 * (float) config("ai.{$response->provider}.price_output_per_million", 0);
                $estimated = true;
            }

            AiUsageLog::create([
                'tenant_id'        => $tenant->id,
                'purpose'          => mb_substr($request->purpose, 0, 20),
                'provider'         => $response?->provider ?? (string) $tenant->ai_provider,
                'model'            => mb_substr($response?->model ?? (string) config("ai.{$tenant->ai_provider}.model"), 0, 60),
                'status'           => $response !== null ? 'ok' : 'error',
                'input_tokens'     => $response?->inputTokens ?? 0,
                'output_tokens'    => $response?->outputTokens ?? 0,
                'reasoning_tokens' => $response?->reasoningTokens ?? 0,
                'cached_tokens'    => $response?->cachedTokens ?? 0,
                'cost_usd'         => round((float) $cost, 6),
                'cost_estimated'   => $estimated,
                'latency_ms'       => (int) ((hrtime(true) - $started) / 1_000_000),
                'error'            => $error !== null ? mb_substr($error, 0, 300) : null,
                'subject_type'     => $subject ? class_basename($subject) : null,
                'subject_id'       => $subject?->getKey(),
                'created_at'       => now(),
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
