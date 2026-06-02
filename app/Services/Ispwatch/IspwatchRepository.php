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
     * Clientes con facturas VENCIDAS de un tenant cuyo router tenga el envío
     * por WhatsApp habilitado. Pensado para el batch de recordatorios.
     *
     * Respeta la config del router en ispwatch (tabla `billing` vía
     * router.billing_router_id → customer_profile.router_id):
     *   - `payment_reminder_enabled` = true   (toggle "Recordatorio de pago")
     *   - `notification_type` ∈ {whatsapp, both}  (método de envío)
     *   - `customer_profile.status` = true     (cliente activo)
     *
     * "Vencida" = `balance_due > 0`, `due_date <= hoy`, status no liquidado.
     * NO aplica el día-del-mes de `billing.payment_reminder` a propósito: ese
     * campo sirve para recordatorios PRE-vencimiento; aquí el filtro es la
     * fecha de vencimiento. La idempotencia (no reenviar) la maneja Converza
     * en `payment_reminder_logs`, porque NO podemos escribir en ispwatch.
     *
     * Sin caché: es un job batch y la frescura importa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function overdueRemindersForTenant(int $ispwatchTenantId): array
    {
        $today = now()->toDateString();

        $rows = \DB::connection('ispwatch')
            ->table('invoices as i')
            ->join('customer_profile as cp', 'cp.user_id', '=', 'i.customer_id')
            ->join('users as u', 'u.id', '=', 'i.customer_id')
            ->join('router as r', 'r.id', '=', 'cp.router_id')
            ->join('billing as b', 'b.id', '=', 'r.billing_router_id')
            ->where('i.tenant_id', $ispwatchTenantId)
            ->where('i.balance_due', '>', 0)
            ->whereNotIn('i.status', ['paid', 'void', 'cancelled'])
            ->whereDate('i.due_date', '<=', $today)
            ->where('b.payment_reminder_enabled', true)
            ->whereIn('b.notification_type', ['whatsapp', 'both'])
            ->where('cp.status', true)
            ->orderBy('i.due_date')
            ->get([
                'i.id as invoice_id',
                'i.number as invoice_number',
                'i.total',
                'i.balance_due',
                'i.currency',
                'i.due_date',
                'i.period_start',
                'i.status',
                'i.customer_id as customer_user_id',
                'u.tel as phone',
                'u.name as user_name',
                'cp.name as cp_name',
                'cp.last_name as cp_last_name',
                'b.notification_type',
            ]);

        return $rows->map(fn ($r) => [
            'invoice_id'        => (int) $r->invoice_id,
            'invoice_number'    => $r->invoice_number,
            'total'             => (string) $r->total,
            'balance_due'       => (string) $r->balance_due,
            'currency'          => $r->currency,
            'due_date'          => $r->due_date,
            'period_start'      => $r->period_start,
            'status'            => $r->status,
            'customer_user_id'  => (int) $r->customer_user_id,
            'phone'             => $r->phone,
            'customer_name'     => trim(($r->cp_name ?: $r->user_name) . ' ' . ($r->cp_last_name ?? '')) ?: 'Cliente',
            'notification_type' => $r->notification_type,
        ])->all();
    }

    /**
     * Configuraciones de `billing` de un tenant con el envío por WhatsApp
     * habilitado (`notificar_wpp = true`). Cada billing define las fechas que
     * disparan los avisos automáticos; de las fechas SOLO importa el día del
     * mes (las fechas guardadas pueden ser de meses pasados).
     *
     * Devuelve por cada billing: id, día de generación (create_invoice), día de
     * recordatorio (payment_reminder), si el recordatorio está habilitado y los
     * nombres de los routers que la usan (para mostrar en la bitácora).
     *
     * Sin caché: es un job batch y la frescura importa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function whatsappBillingConfigsForTenant(int $ispwatchTenantId): array
    {
        $rows = \DB::connection('ispwatch')
            ->table('billing as b')
            ->leftJoin('router as r', 'r.billing_router_id', '=', 'b.id')
            ->where('b.tenant_id', $ispwatchTenantId)
            ->where('b.notificar_wpp', true)
            ->groupBy('b.id', 'b.create_invoice', 'b.payment_reminder', 'b.payment_reminder_enabled')
            ->selectRaw("b.id as billing_id,
                         extract(day from b.create_invoice)::int   as create_day,
                         extract(day from b.payment_reminder)::int as reminder_day,
                         b.payment_reminder_enabled,
                         string_agg(distinct r.name, ', ') as router_names")
            ->orderBy('b.id')
            ->get();

        return $rows->map(fn ($r) => [
            'billing_id'               => (int) $r->billing_id,
            'create_day'               => $r->create_day !== null ? (int) $r->create_day : null,
            'reminder_day'             => $r->reminder_day !== null ? (int) $r->reminder_day : null,
            'payment_reminder_enabled' => (bool) $r->payment_reminder_enabled,
            'router_names'             => $r->router_names,
        ])->all();
    }

    /**
     * Clientes ACTIVOS (`cp.status = true`) cuyos routers usan la billing dada,
     * con LEFT JOIN a su factura del ciclo actual (la más reciente cuyo
     * `issue_date` cae entre $cycleStart y $cycleEnd). `invoice_id` viene null
     * si el cliente no tiene factura este ciclo (motivo de skip en el aviso).
     *
     * Una fila por cliente (DISTINCT ON). Sin caché: batch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function cycleCustomersForBilling(
        int $ispwatchTenantId,
        int $billingId,
        string $cycleStart,
        string $cycleEnd,
    ): array {
        $sql = <<<'SQL'
            select distinct on (u.id)
                u.id   as customer_user_id,
                u.tel  as phone,
                u.name as user_name,
                cp.name      as cp_name,
                cp.last_name as cp_last_name,
                i.id          as invoice_id,
                i.number      as invoice_number,
                i.total       as total,
                i.balance_due as balance_due,
                i.status      as invoice_status,
                i.due_date    as due_date
            from customer_profile cp
            join users u  on u.id = cp.user_id
            join router r on r.id = cp.router_id
            left join invoices i
                   on i.customer_id = u.id
                  and i.tenant_id   = ?
                  and i.issue_date between ? and ?
            where r.billing_router_id = ?
              and u.tenant_id = ?
              and cp.status = true
            order by u.id, i.issue_date desc nulls last
        SQL;

        $rows = \DB::connection('ispwatch')->select($sql, [
            $ispwatchTenantId,
            $cycleStart,
            $cycleEnd,
            $billingId,
            $ispwatchTenantId,
        ]);

        return array_map(fn ($r) => [
            'customer_user_id' => (int) $r->customer_user_id,
            'phone'            => $r->phone,
            'customer_name'    => trim(($r->cp_name ?: $r->user_name) . ' ' . ($r->cp_last_name ?? '')) ?: 'Cliente',
            'invoice_id'       => $r->invoice_id !== null ? (int) $r->invoice_id : null,
            'invoice_number'   => $r->invoice_number,
            'total'            => $r->total !== null ? (string) $r->total : null,
            'balance_due'      => $r->balance_due !== null ? (string) $r->balance_due : null,
            'invoice_status'   => $r->invoice_status,
            'due_date'         => $r->due_date,
        ], $rows);
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
