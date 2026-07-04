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

    /** Estados de `user_services` que cuentan como "servicio activo" (bienvenida). */
    public const ACTIVE_SERVICE_STATUSES = ['active', 'gratis'];

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
     * Versión batch de tenantInfo(): resuelve varios tenants de ispwatch en
     * 3 consultas agrupadas (en vez de 3 por tenant). Reutiliza y rellena la
     * misma caché por-tenant que tenantInfo(), así ambos métodos son
     * intercambiables y consistentes.
     *
     * @param  array<int, int|string>  $ispwatchTenantIds
     * @return array<int, array{name: string, customers_count: int, invoices_count: int}|null>
     *         Mapa [tenantId => info|null]. null = no existe en ispwatch.
     */
    public function tenantInfoBatch(array $ispwatchTenantIds): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ispwatchTenantIds))));

        $result  = [];
        $missing = [];

        // 1) Reutilizar lo que ya esté cacheado por tenantInfo()
        foreach ($ids as $id) {
            $cached = Cache::get("ispwatch:tenant_info:{$id}");
            if ($cached !== null) {
                $result[$id] = $cached;
            } else {
                $missing[] = $id;
            }
        }

        if ($missing === []) {
            return $result;
        }

        // 2) Una consulta agrupada por cada dato (3 en total, no 3 por tenant)
        $names = \App\Models\Ispwatch\IspwatchTenant::query()
            ->whereIn('id', $missing)
            ->pluck('name', 'id');

        $customers = \DB::connection('ispwatch')
            ->table('users as u')
            ->join('customer_profile as cp', 'cp.user_id', '=', 'u.id')
            ->whereIn('u.tenant_id', $missing)
            ->groupBy('u.tenant_id')
            ->selectRaw('u.tenant_id as tid, count(*) as c')
            ->pluck('c', 'tid');

        $invoices = \DB::connection('ispwatch')
            ->table('invoices')
            ->whereIn('tenant_id', $missing)
            ->groupBy('tenant_id')
            ->selectRaw('tenant_id as tid, count(*) as c')
            ->pluck('c', 'tid');

        // 3) Ensamblar y cachear bajo la misma clave que tenantInfo()
        foreach ($missing as $id) {
            if (! isset($names[$id])) {
                $result[$id] = null; // no existe en ispwatch
                continue;
            }

            $info = [
                'name'            => $names[$id],
                'customers_count' => (int) ($customers[$id] ?? 0),
                'invoices_count'  => (int) ($invoices[$id] ?? 0),
            ];

            $result[$id] = $info;
            Cache::put("ispwatch:tenant_info:{$id}", $info, self::CACHE_TTL_SECONDS);
        }

        return $result;
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
     * recordatorio (payment_reminder), día de CORTE (cut_day), la HORA local de
     * cada uno (create_invoice_time / payment_reminder_time / cut_time, formato
     * 'HH:MM:SS' o null), si el recordatorio está habilitado y los nombres de los
     * routers que la usan (para mostrar en la bitácora).
     *
     * Las horas son `time without time zone`: hora de pared que el admin del ISP
     * escribió en su zona local. Quien las consuma debe interpretarlas en esa
     * zona (ver services.whatsapp.billing_notify_timezone).
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
            ->groupBy(
                'b.id', 'b.create_invoice', 'b.payment_reminder', 'b.cut_day',
                'b.create_invoice_time', 'b.payment_reminder_time', 'b.cut_time',
                'b.payment_reminder_enabled',
            )
            ->selectRaw("b.id as billing_id,
                         extract(day from b.create_invoice)::int   as create_day,
                         extract(day from b.payment_reminder)::int as reminder_day,
                         extract(day from b.cut_day)::int          as cut_day,
                         to_char(b.create_invoice_time,   'HH24:MI:SS') as create_time,
                         to_char(b.payment_reminder_time, 'HH24:MI:SS') as reminder_time,
                         to_char(b.cut_time,              'HH24:MI:SS') as cut_time,
                         b.payment_reminder_enabled,
                         string_agg(distinct r.name, ', ') as router_names")
            ->orderBy('b.id')
            ->get();

        return $rows->map(fn ($r) => [
            'billing_id'               => (int) $r->billing_id,
            'create_day'               => $r->create_day !== null ? (int) $r->create_day : null,
            'reminder_day'             => $r->reminder_day !== null ? (int) $r->reminder_day : null,
            'cut_day'                  => $r->cut_day !== null ? (int) $r->cut_day : null,
            'create_time'              => $r->create_time,   // 'HH:MM:SS' (hora local ISP) o null
            'reminder_time'            => $r->reminder_time, // 'HH:MM:SS' (hora local ISP) o null
            'cut_time'                 => $r->cut_time,      // 'HH:MM:SS' (hora local ISP) o null
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
                i.due_date    as due_date,
                i.issue_date  as issue_date
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
            'issue_date'       => $r->issue_date,
        ], $rows);
    }

    /**
     * Salud agregada de un tenant de ispwatch para el Core Brain.
     * Devuelve: clientes activos/suspendidos, facturas vencidas.
     * Sin caché: se usa en la ficha de cuenta (una sola llamada).
     *
     * @return array{active_customers: int, suspended_customers: int, overdue_invoices: int}|null
     */
    public function tenantHealth(int $ispwatchTenantId): ?array
    {
        try {
            $today = now()->toDateString();

            $activeCustomers = \DB::connection('ispwatch')
                ->table('customer_profile as cp')
                ->join('users as u', 'u.id', '=', 'cp.user_id')
                ->where('u.tenant_id', $ispwatchTenantId)
                ->where('cp.status', true)
                ->count();

            $suspendedCustomers = \DB::connection('ispwatch')
                ->table('customer_profile as cp')
                ->join('users as u', 'u.id', '=', 'cp.user_id')
                ->where('u.tenant_id', $ispwatchTenantId)
                ->where('cp.status', false)
                ->count();

            $overdueInvoices = IspwatchInvoice::query()
                ->where('tenant_id', $ispwatchTenantId)
                ->where('balance_due', '>', 0)
                ->whereNotIn('status', ['paid', 'void', 'cancelled'])
                ->whereDate('due_date', '<=', $today)
                ->count();

            return [
                'active_customers'    => (int) $activeCustomers,
                'suspended_customers' => (int) $suspendedCustomers,
                'overdue_invoices'    => (int) $overdueInvoices,
            ];
        } catch (\Throwable) {
            return null;
        }
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  Avisos por EVENTO (polling incremental para whatsapp:events-notify).
    //  ispwatch es solo lectura: no hay webhooks/triggers. Detectamos "lo nuevo"
    //  con una marca de agua sobre el id de la tabla origen (user_services / payments).
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mayor `user_services.id` con servicio activo del tenant. Compuerta barata:
     * si no supera el cursor guardado, la corrida no hace el query pesado. Sirve
     * también para sembrar el cursor en el arranque (cold-start) sin enviar nada.
     */
    public function maxActivationId(int $ispwatchTenantId): int
    {
        $in  = implode(',', array_fill(0, count(self::ACTIVE_SERVICE_STATUSES), '?'));
        $row = \DB::connection('ispwatch')->selectOne(
            "select coalesce(max(us.id), 0) as max_id
               from user_services us
               join users u on u.id = us.user_id
              where u.tenant_id = ? and us.status in ({$in})",
            [$ispwatchTenantId, ...self::ACTIVE_SERVICE_STATUSES],
        );

        return (int) ($row->max_id ?? 0);
    }

    /**
     * Activaciones de servicio NUEVAS (user_services.id > $afterId) del tenant,
     * con datos del cliente y su plan. Cada fila trae `cluster_size`: cuántas
     * activaciones del MISMO tenant cayeron dentro de ±$clusterWindowSeconds de su
     * `created_at`. Sirve para distinguir alta MANUAL (1-4/min) de CARGA MASIVA
     * (decenas/cientos en el mismo minuto) — a estas NO se les manda bienvenida.
     *
     * El count over() se calcula sobre TODAS las activaciones del tenant (no solo
     * las nuevas), así la densidad es real aunque el vecino ya se haya procesado.
     *
     * Sin caché: es un job batch y la frescura importa.
     *
     * @return array<int, array<string, mixed>>
     */
    public function newActivationsSince(int $ispwatchTenantId, int $afterId, int $limit, int $clusterWindowSeconds): array
    {
        $window   = max(0, $clusterWindowSeconds);
        $limitInt = max(1, $limit);
        $in       = implode(',', array_fill(0, count(self::ACTIVE_SERVICE_STATUSES), '?'));

        $sql = <<<SQL
            select x.* from (
                select
                    us.id             as service_id,
                    us.user_id        as customer_user_id,
                    us.created_at     as activated_at,
                    u.tel             as phone,
                    u.name            as user_name,
                    cp.name           as cp_name,
                    cp.last_name      as cp_last_name,
                    cp.pppoe_username as pppoe_username,
                    cp.address        as address,
                    cp.status         as cp_status,
                    sp.name           as plan_name,
                    count(*) over (
                        partition by u.tenant_id
                        order by us.created_at
                        range between interval '{$window} seconds' preceding
                                  and interval '{$window} seconds' following
                    ) as cluster_size
                from user_services us
                join users u on u.id = us.user_id
                left join customer_profile cp on cp.user_id = u.id
                left join service_plan sp on sp.id = us.service_plan_id
                where u.tenant_id = ?
                  and us.status in ({$in})
            ) x
            where x.service_id > ?
            order by x.service_id asc
            limit {$limitInt}
        SQL;

        $rows = \DB::connection('ispwatch')->select(
            $sql,
            [$ispwatchTenantId, ...self::ACTIVE_SERVICE_STATUSES, $afterId],
        );

        return array_map(fn ($r) => [
            'service_id'       => (int) $r->service_id,
            'customer_user_id' => (int) $r->customer_user_id,
            'activated_at'     => $r->activated_at,
            'phone'            => $r->phone,
            'customer_name'    => trim(($r->cp_name ?: $r->user_name) . ' ' . ($r->cp_last_name ?? '')) ?: 'Cliente',
            'pppoe_username'   => $r->pppoe_username,
            'address'          => $r->address,
            'plan_name'        => $r->plan_name,
            'cp_status'        => (bool) $r->cp_status,
            'cluster_size'     => (int) $r->cluster_size,
        ], $rows);
    }

    /**
     * Mayor `payments.id` (completado) del tenant. Compuerta barata / semilla de
     * cursor, igual que maxActivationId().
     */
    public function maxPaymentId(int $ispwatchTenantId): int
    {
        $row = \DB::connection('ispwatch')->selectOne(
            "select coalesce(max(id), 0) as max_id
               from payments where tenant_id = ? and status = 'completed'",
            [$ispwatchTenantId],
        );

        return (int) ($row->max_id ?? 0);
    }

    /**
     * Pagos NUEVOS (payments.id > $afterId, status completed) del tenant, con el
     * cliente y su saldo pendiente TRAS el pago (suma de balance_due de facturas
     * abiertas). Sin caché: batch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function newPaymentsSince(int $ispwatchTenantId, int $afterId, int $limit): array
    {
        $limitInt = max(1, $limit);

        $sql = <<<SQL
            select
                p.id           as payment_id,
                p.customer_id  as customer_user_id,
                p.amount       as amount,
                p.payment_date as payment_date,
                p.method       as method,
                p.reference    as reference,
                p.created_at   as created_at,
                u.tel          as phone,
                u.name         as user_name,
                cp.name        as cp_name,
                cp.last_name   as cp_last_name,
                (select coalesce(sum(i.balance_due), 0)
                   from invoices i
                  where i.customer_id = p.customer_id
                    and i.tenant_id   = ?
                    and i.balance_due > 0) as pending_balance
            from payments p
            join users u on u.id = p.customer_id
            left join customer_profile cp on cp.user_id = u.id
            where p.tenant_id = ?
              and p.status = 'completed'
              and p.id > ?
            order by p.id asc
            limit {$limitInt}
        SQL;

        $rows = \DB::connection('ispwatch')->select($sql, [$ispwatchTenantId, $ispwatchTenantId, $afterId]);

        return array_map(fn ($r) => [
            'payment_id'       => (int) $r->payment_id,
            'customer_user_id' => (int) $r->customer_user_id,
            'amount'           => (string) $r->amount,
            'payment_date'     => $r->payment_date,
            'method'           => $r->method,
            'reference'        => $r->reference,
            'created_at'       => $r->created_at,
            'phone'            => $r->phone,
            'customer_name'    => trim(($r->cp_name ?: $r->user_name) . ' ' . ($r->cp_last_name ?? '')) ?: 'Cliente',
            'pending_balance'  => (string) $r->pending_balance,
        ], $rows);
    }

    // ─────────────────────────────────────────────────────────────────────────
    //  FALLA MASIVA por core/router (router_outage_events → fan-out a clientes).
    //  ispwatch inserta una fila cuando un core cae (type=inicio) o se restablece
    //  (type=fin). El "core" es un router; los clientes dependen de él vía
    //  customer_profile.router_id. Un outage afecta a TODOS sus clientes activos.
    // ─────────────────────────────────────────────────────────────────────────

    /**
     * Mayor `router_outage_events.id` del tenant cuyo `type` esté en $types
     * (lista lowercase). Compuerta barata / semilla de cursor.
     *
     * @param  array<int, string>  $types
     */
    public function maxOutageId(int $ispwatchTenantId, array $types): int
    {
        if ($types === []) {
            return 0;
        }
        $in = implode(',', array_fill(0, count($types), '?'));

        $row = \DB::connection('ispwatch')->selectOne(
            "select coalesce(max(id), 0) as max_id
               from router_outage_events
              where tenant_id = ? and lower(type) in ({$in})",
            [$ispwatchTenantId, ...$types],
        );

        return (int) ($row->max_id ?? 0);
    }

    /**
     * Eventos de outage NUEVOS (id > $afterId) del tenant cuyo `type` esté en
     * $types, con el nombre del router. `is_latest` = este evento es el MÁS
     * RECIENTE de su router (cualquier tipo): si es false, ya hay un evento
     * posterior (p.ej. la falla ya se resolvió) y el aviso está superado → no
     * se debe enviar. Sin caché: batch.
     *
     * @param  array<int, string>  $types
     * @return array<int, array<string, mixed>>
     */
    public function newOutagesSince(int $ispwatchTenantId, array $types, int $afterId, int $limit): array
    {
        if ($types === []) {
            return [];
        }
        $limitInt = max(1, $limit);
        $in       = implode(',', array_fill(0, count($types), '?'));

        $sql = <<<SQL
            select
                e.id             as outage_id,
                e.router_id      as router_id,
                e.type           as type,
                e.affected_count as affected_count,
                e.created_at     as created_at,
                r.name           as router_name,
                (e.id = (select max(e2.id) from router_outage_events e2
                          where e2.tenant_id = e.tenant_id
                            and e2.router_id = e.router_id)) as is_latest
            from router_outage_events e
            left join router r on r.id = e.router_id
            where e.tenant_id = ?
              and lower(e.type) in ({$in})
              and e.id > ?
            order by e.id asc
            limit {$limitInt}
        SQL;

        $rows = \DB::connection('ispwatch')->select($sql, [$ispwatchTenantId, ...$types, $afterId]);

        return array_map(fn ($r) => [
            'outage_id'      => (int) $r->outage_id,
            'router_id'      => (int) $r->router_id,
            'type'           => $r->type,
            'affected_count' => $r->affected_count !== null ? (int) $r->affected_count : null,
            'created_at'     => $r->created_at,
            'router_name'    => $r->router_name,
            'is_latest'      => (bool) $r->is_latest,
        ], $rows);
    }

    /**
     * Clientes ACTIVOS (cp.status = true) que dependen del router dado, con
     * teléfono. Destinatarios del aviso de falla masiva. Sin caché: batch.
     *
     * @return array<int, array<string, mixed>>
     */
    public function activeCustomersForRouter(int $ispwatchTenantId, int $routerId): array
    {
        $sql = <<<'SQL'
            select
                u.id         as customer_user_id,
                u.tel        as phone,
                u.name       as user_name,
                cp.name      as cp_name,
                cp.last_name as cp_last_name
            from customer_profile cp
            join users u on u.id = cp.user_id
            where u.tenant_id = ?
              and cp.router_id = ?
              and cp.status = true
            order by u.id asc
        SQL;

        $rows = \DB::connection('ispwatch')->select($sql, [$ispwatchTenantId, $routerId]);

        return array_map(fn ($r) => [
            'customer_user_id' => (int) $r->customer_user_id,
            'phone'            => $r->phone,
            'customer_name'    => trim(($r->cp_name ?: $r->user_name) . ' ' . ($r->cp_last_name ?? '')) ?: 'Cliente',
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
