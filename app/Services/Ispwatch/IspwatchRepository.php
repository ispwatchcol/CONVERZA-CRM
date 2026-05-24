<?php

namespace App\Services\Ispwatch;

use App\Models\Ispwatch\IspwatchInvoice;
use App\Models\Ispwatch\IspwatchUser;
use Illuminate\Support\Facades\Cache;

/**
 * Capa única de acceso a ispwatch desde Converza. SOLO LECTURA.
 * Si el schema de ispwatch cambia, este es el único archivo que se debería tocar.
 */
class IspwatchRepository
{
    public const CACHE_TTL_SECONDS = 60;

    /**
     * Devuelve el cliente (user + customer_profile) cuyo `tel` coincida con el
     * teléfono dado, dentro del tenant ispwatch indicado.
     *
     * Converza guarda teléfonos como `57XXXXXXXXXX`; ispwatch como `XXXXXXXXXX`.
     * Normalizamos ambos lados a 10 dígitos antes de comparar.
     *
     * Tie-break cuando varios clientes comparten teléfono (caso real: familiares):
     *   1. Si `$contactName` viene, se prefiere el candidato cuyo `name + last_name`
     *      tenga más tokens en común con el nombre del contacto de WhatsApp.
     *   2. Si no hay ganador claro (sin nombre o todos empatados en 0), se devuelve
     *      el más reciente por `created_at`.
     *
     * @return array<string, mixed>|null
     */
    public function customerByPhone(int $ispwatchTenantId, ?string $phone, ?string $contactName = null): ?array
    {
        $normalized = $this->normalizeToLocal($phone);
        if ($normalized === null) {
            return null;
        }

        $nameKey = $contactName ? substr(md5(mb_strtolower(trim($contactName))), 0, 8) : 'none';
        $cacheKey = "ispwatch:customer:t{$ispwatchTenantId}:p{$normalized}:n{$nameKey}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($ispwatchTenantId, $normalized, $contactName) {
            $candidates = IspwatchUser::query()
                ->with('customerProfile')
                ->where('tenant_id', $ispwatchTenantId)
                ->whereHas('customerProfile')
                ->whereRaw("regexp_replace(coalesce(tel, ''), '\\D', '', 'g') = ?", [$normalized])
                ->get();

            if ($candidates->isEmpty()) {
                return null;
            }

            $user = $this->pickBestMatch($candidates, $contactName);
            $cp = $user->customerProfile;

            return [
                'user_id'         => (int) $user->id,
                'tenant_id'       => (int) $user->tenant_id,
                'name'            => trim(($cp->name ?: $user->name) . ' ' . ($cp->last_name ?? '')),
                'email'           => $user->email,
                'tel'             => $user->tel,
                'cedula'          => $cp->cedula,
                'document_number' => $cp->document_number,
                'pppoe_username'  => $cp->pppoe_username,
                'service_status'  => $cp->service_status,
                'credit_balance'  => $cp->credit_balance,
                'ip_user'         => $cp->ip_user,
                'address'         => $cp->address,
                'ambiguous'       => $candidates->count() > 1,
            ];
        });
    }

    /**
     * Elige el mejor candidato entre varios `IspwatchUser` que comparten teléfono.
     *
     * Score = nº de tokens del nombre de contacto que aparecen en `name + last_name`
     * del candidato (ambos normalizados: lowercase, sin acentos, tokens >= 2 chars).
     * Si el mejor score es 0 (no hay match de nombre), se elige el más reciente.
     *
     * @param \Illuminate\Database\Eloquent\Collection<int, IspwatchUser> $candidates
     */
    private function pickBestMatch($candidates, ?string $contactName): IspwatchUser
    {
        if ($candidates->count() === 1) {
            return $candidates->first();
        }

        $contactTokens = $this->nameTokens($contactName);

        if ($contactTokens !== []) {
            $scored = $candidates->map(function (IspwatchUser $u) use ($contactTokens) {
                $candidateTokens = $this->nameTokens(
                    trim(($u->customerProfile->name ?: $u->name) . ' ' . ($u->customerProfile->last_name ?? ''))
                );
                $score = count(array_intersect($contactTokens, $candidateTokens));
                return ['user' => $u, 'score' => $score];
            });

            $maxScore = $scored->max('score');
            if ($maxScore > 0) {
                // empate -> el más reciente entre los ganadores
                return $scored->where('score', $maxScore)
                    ->pluck('user')
                    ->sortByDesc(fn ($u) => $u->created_at ?? $u->id)
                    ->first();
            }
        }

        // sin nombre o sin coincidencia: el más reciente
        return $candidates->sortByDesc(fn ($u) => $u->created_at ?? $u->id)->first();
    }

    /**
     * Normaliza un nombre a tokens comparables: lowercase, sin acentos,
     * solo tokens alfanuméricos de 2+ caracteres.
     *
     * @return array<int, string>
     */
    private function nameTokens(?string $name): array
    {
        if (! $name) {
            return [];
        }

        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name) ?: $name;
        $lower = mb_strtolower($ascii);
        $clean = preg_replace('/[^a-z0-9\s]/', ' ', $lower) ?? '';

        return array_values(array_filter(
            preg_split('/\s+/', $clean) ?: [],
            fn ($t) => mb_strlen($t) >= 2,
        ));
    }

    /**
     * Info básica de un tenant de ispwatch (para verificar el link desde Settings).
     * Devuelve [name, customers_count, invoices_count] o null si no existe.
     *
     * @return array{name: string, customers_count: int, invoices_count: int}|null
     */
    public function tenantInfo(int $ispwatchTenantId): ?array
    {
        $cacheKey = "ispwatch:tenant_info:{$ispwatchTenantId}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($ispwatchTenantId) {
            $tenant = \App\Models\Ispwatch\IspwatchTenant::query()
                ->where('id', $ispwatchTenantId)
                ->first(['id', 'name']);

            if (! $tenant) {
                return null;
            }

            $customersCount = \DB::connection('ispwatch')
                ->table('users as u')
                ->join('customer_profile as cp', 'cp.user_id', '=', 'u.id')
                ->where('u.tenant_id', $ispwatchTenantId)
                ->count();

            $invoicesCount = IspwatchInvoice::query()
                ->where('tenant_id', $ispwatchTenantId)
                ->count();

            return [
                'name'            => $tenant->name,
                'customers_count' => (int) $customersCount,
                'invoices_count'  => (int) $invoicesCount,
            ];
        });
    }

    /**
     * Facturas con saldo pendiente del cliente dado dentro del tenant ispwatch.
     * Ordenadas por `due_date` ascendente (más vencidas primero).
     *
     * @return array<int, array<string, mixed>>
     */
    public function pendingInvoicesForCustomer(int $ispwatchTenantId, int $customerUserId): array
    {
        $cacheKey = "ispwatch:invoices:pending:t{$ispwatchTenantId}:c{$customerUserId}";

        return Cache::remember($cacheKey, self::CACHE_TTL_SECONDS, function () use ($ispwatchTenantId, $customerUserId) {
            return IspwatchInvoice::query()
                ->where('tenant_id', $ispwatchTenantId)
                ->where('customer_id', $customerUserId)
                ->where('balance_due', '>', 0)
                ->orderBy('due_date')
                ->get(['id', 'number', 'total', 'balance_due', 'status', 'due_date', 'currency'])
                ->map(fn ($inv) => [
                    'id'          => (int) $inv->id,
                    'number'      => $inv->number,
                    'total'       => (string) $inv->total,
                    'balance_due' => (string) $inv->balance_due,
                    'status'      => $inv->status,
                    'due_date'    => optional($inv->due_date)->toIso8601String(),
                    'currency'    => $inv->currency,
                ])
                ->all();
        });
    }

    /**
     * Quita todo lo que no sea dígito y trimea el prefijo país `57` si está
     * presente, devolviendo el número en formato local de 10 dígitos.
     * Retorna null si el resultado no es un teléfono creíble (<10 dígitos).
     */
    private function normalizeToLocal(?string $phone): ?string
    {
        if ($phone === null) {
            return null;
        }

        $digits = preg_replace('/\D/', '', $phone) ?? '';

        if (strlen($digits) === 12 && str_starts_with($digits, '57')) {
            $digits = substr($digits, 2);
        }

        return strlen($digits) >= 10 ? substr($digits, -10) : null;
    }
}
