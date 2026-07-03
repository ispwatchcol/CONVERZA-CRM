<?php

namespace App\Console\Commands;

use App\Models\BillingNotificationLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Template;
use App\Models\Tenant;
use App\Models\TenantNotificationRoute;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\Notifications\EventCatalog;
use App\Services\Templates\TemplateRenderer;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envíos automáticos por WhatsApp disparados por las fechas que cada router
 * define en ispwatch (tabla `billing`):
 *
 *   - kind=invoice_created  → "factura generada", el día `create_invoice`.
 *   - kind=payment_reminder → "recordatorio de pago", el día `payment_reminder`.
 *
 * Gate: solo routers con `billing.notificar_wpp = true` (el toggle de WhatsApp
 * del core). De las fechas importa el DÍA del mes y la HORA local
 * (`create_invoice_time` / `payment_reminder_time`).
 *
 * Zona horaria: esas horas son `time without time zone` que el admin del ISP
 * escribió en su hora local (Colombia por defecto). La app corre en UTC, así
 * que el comando convierte "ahora" a `services.whatsapp.billing_notify_timezone`
 * antes de comparar día y hora. Sin esto, todo saldría 5 horas tarde.
 *
 * Catch-up con ventana de N días (services.whatsapp.billing_notify_catchup_days,
 * default 2): la tarea corre cada minuto y dispara desde la hora configurada del
 * día agendado hasta el FIN del día (agendado + N), acotado a fin de mes. Si un
 * envío falla —o el sistema estuvo caído todo un día— reintenta los minutos/días
 * siguientes sin duplicar (idempotencia por ciclo en `billing_notification_logs`,
 * llaveada al MES, no a la fecha: cambiar la fecha/hora del router NO reenvía a
 * quien ya recibió). En los días de catch-up una compuerta LOCAL barata evita el
 * query pesado a ispwatch cuando ya no queda nada pendiente. Pasada la ventana,
 * el reenvío es solo manual.
 *
 * Pacing (anti-baneo): la corrida envía como máximo
 * `services.whatsapp.billing_notify_per_minute` (default 40) avisos por tenant.
 * Como la tarea corre cada minuto y es idempotente con catch-up, un lote grande
 * se drena solo en varios minutos sin salir en ráfaga ni perder a nadie (los no
 * enviados por el tope no se registran, así el siguiente minuto los reintenta).
 * Además un micro-espaciado (`billing_notify_gap_ms`, default 250ms) separa cada
 * envío real. Ambos protegen la calidad/tier del número en Meta. `--limit` manda
 * sobre el tope; el día agendado siempre drena (la compuerta de recursos solo
 * aplica en días de catch-up), así que con horario normal todo sale el mismo día.
 *
 * Todo intento queda registrado (sent / skipped+motivo / failed) para la
 * bitácora del sidebar.
 *
 * Uso:
 *   php artisan whatsapp:billing-notify
 *   php artisan whatsapp:billing-notify --tenant=2
 *   php artisan whatsapp:billing-notify --date="2026-06-06 14:30"  # simula fecha+hora (hora local ISP)
 *   php artisan whatsapp:billing-notify --dry-run
 *   php artisan whatsapp:billing-notify --limit=1                   # máx. envíos por tenant
 */
class SendBillingNotifications extends Command
{
    protected $signature = 'whatsapp:billing-notify
        {--tenant= : Limitar a un tenant de Converza por ID}
        {--customer= : Limitar a un cliente de ispwatch por user_id (pruebas)}
        {--date= : Simular la corrida en hora local del ISP (YYYY-MM-DD o "YYYY-MM-DD HH:MM"), default ahora}
        {--dry-run : No envía; solo muestra lo que haría}
        {--force : Permite el envío masivo aunque el interruptor maestro esté apagado}
        {--limit= : Máximo de avisos a ENVIAR por tenant}';

    protected $description = 'Envía avisos de factura generada y recordatorios de pago por WhatsApp según las fechas del router en ispwatch.';

    /** Valores en el orden que las plantillas esperan sus variables {{1}}, {{2}}, … */
    private function templateValues(string $kind, array $row, Tenant $tenant): array
    {
        $amount = in_array($kind, [BillingNotificationLog::KIND_REMINDER, BillingNotificationLog::KIND_SUSPENSION], true)
            ? ($row['balance_due'] ?? $row['total'])
            : ($row['total'] ?? $row['balance_due']);

        // Convención documentada (ver plan): nombre, monto, n° factura,
        // vencimiento, empresa, … Las plantillas actuales usan las primeras 1-2.
        return [
            $row['customer_name'],
            $this->formatAmount($amount),
            (string) ($row['invoice_number'] ?? ''),
            $this->formatDate($row['due_date'] ?? null),
            $tenant->name,
        ];
    }

    public function handle(IspwatchRepository $ispwatch, WhatsAppService $whatsapp): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $customer = $this->option('customer') !== null ? (int) $this->option('customer') : null;

        // Pacing: tope de envíos por tenant POR CORRIDA. --limit lo sobreescribe.
        // Sin --limit usamos el default de config (40); en dry-run no capamos para
        // mostrar el plan completo. config=0 ⇒ sin tope (ráfaga, opt-out explícito).
        $configCap = max(0, (int) config('services.whatsapp.billing_notify_per_minute', 40));
        $limit = $this->option('limit') !== null
            ? max(0, (int) $this->option('limit'))
            : (($dryRun || $configCap === 0) ? null : $configCap);

        // Micro-espaciado entre cada envío real, en milisegundos.
        $gapMs = max(0, (int) config('services.whatsapp.billing_notify_gap_ms', 250));

        // Hora local del ISP: los días/horas de ispwatch están en esta zona, y la
        // app corre en UTC. Comparamos día y hora SIEMPRE en esta zona.
        $tz = config('services.whatsapp.billing_notify_timezone', 'America/Bogota');

        // Catch-up: cuántos días tras el agendado se sigue reintentando. La
        // compuerta de recursos (hasPendingWork) se salta en pruebas dirigidas
        // (--customer) y en --force para que el envío sea predecible.
        $catchupDays = max(0, (int) config('services.whatsapp.billing_notify_catchup_days', 2));
        $bypassGate  = $customer !== null || (bool) $this->option('force');

        try {
            $now = $this->option('date')
                ? Carbon::parse($this->option('date'), $tz)
                : Carbon::now($tz);
        } catch (\Throwable $e) {
            $this->error("Fecha inválida en --date: {$this->option('date')}");
            return self::FAILURE;
        }

        $cycleKey   = $now->format('Y-m');
        $cycleStart = $now->copy()->startOfMonth()->toDateString();
        $cycleEnd   = $now->copy()->endOfMonth()->toDateString();

        // Interruptor maestro: hasta que el usuario lo active, la tarea agendada NO
        // hace envíos masivos. Se permiten igual las pruebas dirigidas a un cliente
        // (--customer), los --dry-run y un --force explícito.
        $massEnabled = (bool) config('services.whatsapp.billing_notify_enabled', false);
        if (! $massEnabled && ! $dryRun && ! $this->option('force') && $customer === null) {
            $this->warn('Envío automático masivo DESHABILITADO (WHATSAPP_BILLING_NOTIFY_ENABLED=false).');
            $this->line('Para activarlo pon WHATSAPP_BILLING_NOTIFY_ENABLED=true. Para pruebas usa --customer=<id>, --dry-run o --force.');
            return self::SUCCESS;
        }

        $this->line("Corrida para <info>{$now->toDateTimeString()}</info> {$tz} (ciclo {$cycleKey}, día {$now->day}).");
        if ($dryRun) {
            $this->warn('— DRY RUN — no se enviará ningún mensaje (sí se registra el plan).');
        }

        $tenants = Tenant::query()
            ->where('is_active', true)
            ->whereNotNull('ispwatch_tenant_id')
            ->when($this->option('tenant'), fn ($q) => $q->where('id', (int) $this->option('tenant')))
            ->orderBy('id')
            ->get();

        if ($tenants->isEmpty()) {
            $this->warn('No hay tenants activos vinculados a ispwatch.');
            return self::SUCCESS;
        }

        $grand = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'already' => 0, 'no_due' => 0];

        foreach ($tenants as $tenant) {
            app()->instance('tenant', $tenant);

            $this->line("• Tenant <info>{$tenant->name}</info> (#{$tenant->id} → ispwatch #{$tenant->ispwatch_tenant_id})");

            // Gate a nivel tenant: sin credenciales Meta no se puede enviar nada.
            if (! $tenant->hasWhatsAppConfigured()) {
                $this->warn('  ↳ WhatsApp del tenant no configurado. Saltando.');
                continue;
            }

            // Interruptor MAESTRO del tenant (botón de pausa en Configuración →
            // Avisos). --customer (prueba dirigida) y --force lo saltan para testear.
            if (! $tenant->billing_notify_enabled && $customer === null && ! $this->option('force')) {
                $this->warn('  ↳ Avisos automáticos PAUSADOS para este tenant (interruptor en Configuración). Saltando.');
                continue;
            }

            // Enrutamiento dinámico: evento → plantilla, definido por el cliente en
            // Settings (tabla tenant_notification_routes). Solo rutas activas.
            $routes = $tenant->notificationRoutes()
                ->where('enabled', true)
                ->with('template')
                ->get()
                ->keyBy('event_key');

            $invoiceRoute    = $routes->get(BillingNotificationLog::KIND_INVOICE);
            $reminderRoute   = $routes->get(BillingNotificationLog::KIND_REMINDER);
            $suspensionRoute = $routes->get(BillingNotificationLog::KIND_SUSPENSION);

            // $tpl = plantilla LISTA para enviar (aprobada+activa) o null.
            $invoiceTpl    = $this->routeTemplate($invoiceRoute);
            $reminderTpl   = $this->routeTemplate($reminderRoute);
            $suspensionTpl = $this->routeTemplate($suspensionRoute);

            $billings = $ispwatch->whatsappBillingConfigsForTenant((int) $tenant->ispwatch_tenant_id);
            if ($billings === []) {
                $this->warn('  ↳ Ningún router con notificar_wpp activo. Saltando.');
                continue;
            }

            $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'already' => 0, 'no_due' => 0];

            // Precarga de idempotencia: todos los avisos ya ENVIADOS este ciclo,
            // en memoria, para no hacer un SELECT por cliente (cientos de viajes).
            $sentKeys = BillingNotificationLog::query()
                ->where('cycle_key', $cycleKey)
                ->where('status', 'sent')
                ->get(['kind', 'ispwatch_customer_id'])
                ->mapWithKeys(fn ($l) => [$l->kind . ':' . $l->ispwatch_customer_id => true])
                ->all();

            foreach ($billings as $billing) {
                $runInvoice  = $this->timeReached($billing['create_day'], $billing['create_time'], $now, $catchupDays);
                $runReminder = $billing['payment_reminder_enabled']
                    && $this->timeReached($billing['reminder_day'], $billing['reminder_time'], $now, $catchupDays);
                // Aviso de suspensión: el día de corte del router (cut_day). No hay
                // toggle propio en ispwatch; lo gobierna notificar_wpp + la ruta en
                // Settings. Solo a clientes con saldo (el filtro se aplica por cliente).
                $runSuspension = $this->timeReached($billing['cut_day'], $billing['cut_time'], $now, $catchupDays);

                // Compuerta de recursos en días de CATCH-UP (posteriores al día
                // agendado): el query a ispwatch es caro y sin caché. En un día de
                // recuperación solo vale la pena jalar la lista de clientes si de
                // verdad quedó algo pendiente (nada enviado aún, o hay fallidos por
                // reintentar). El día agendado original SIEMPRE pasa. Chequeo LOCAL
                // barato; no toca ispwatch. Se salta con --customer/--force.
                if (! $bypassGate) {
                    if ($runInvoice && $this->isCatchupDay($billing['create_day'], $now)
                        && ! $this->hasPendingWork($tenant->id, (int) $billing['billing_id'], BillingNotificationLog::KIND_INVOICE, $cycleKey)) {
                        $runInvoice = false;
                    }
                    if ($runReminder && $this->isCatchupDay($billing['reminder_day'], $now)
                        && ! $this->hasPendingWork($tenant->id, (int) $billing['billing_id'], BillingNotificationLog::KIND_REMINDER, $cycleKey)) {
                        $runReminder = false;
                    }
                    if ($runSuspension && $this->isCatchupDay($billing['cut_day'], $now)
                        && ! $this->hasPendingWork($tenant->id, (int) $billing['billing_id'], BillingNotificationLog::KIND_SUSPENSION, $cycleKey)) {
                        $runSuspension = false;
                    }
                }

                $this->line("  ↳ billing #{$billing['billing_id']} ({$billing['router_names']}) — "
                    . 'factura(' . $this->describeWhen($billing['create_day'], $billing['create_time']) . ($runInvoice ? ' ✓' : ' ·') . ') '
                    . 'recordatorio(' . $this->describeWhen($billing['reminder_day'], $billing['reminder_time']) . ($runReminder ? ' ✓' : ' ·') . ') '
                    . 'corte(' . $this->describeWhen($billing['cut_day'], $billing['cut_time']) . ($runSuspension ? ' ✓' : ' ·') . ')');

                // Nada que enviar (ni a su hora, ni catch-up pendiente): NO traemos
                // la lista de clientes. La tarea corre cada minuto; este
                // corto-circuito evita el query pesado a ispwatch cuando no hay nada.
                if (! $runInvoice && ! $runReminder && ! $runSuspension) {
                    continue;
                }

                $rows = $ispwatch->cycleCustomersForBilling(
                    (int) $tenant->ispwatch_tenant_id,
                    $billing['billing_id'],
                    $cycleStart,
                    $cycleEnd,
                );

                // Filtro de pruebas: limitar a un cliente específico de ispwatch.
                if ($customer !== null) {
                    $rows = array_values(array_filter($rows, fn ($r) => $r['customer_user_id'] === $customer));
                }

                foreach (
                    [
                        [BillingNotificationLog::KIND_INVOICE,    $runInvoice,    $invoiceTpl,    $invoiceRoute?->template?->name],
                        [BillingNotificationLog::KIND_REMINDER,   $runReminder,   $reminderTpl,   $reminderRoute?->template?->name],
                        [BillingNotificationLog::KIND_SUSPENSION, $runSuspension, $suspensionTpl, $suspensionRoute?->template?->name],
                    ] as [$kind, $run, $tpl, $tplName]
                ) {
                    if (! $run) {
                        continue;
                    }

                    // Gate de plantilla a nivel tenant (una sola advertencia, no
                    // una fila de log por cada uno de los cientos de clientes).
                    if (blank($tplName)) {
                        $this->warn("    · {$kind}: sin plantilla configurada en Settings. Omitido.");
                        continue;
                    }
                    if (! $tpl) {
                        $this->warn("    · {$kind}: plantilla '{$tplName}' no está aprobada/activa. Omitido.");
                        continue;
                    }

                    foreach ($rows as $row) {
                        $atSendLimit = $limit !== null && $stats['sent'] >= $limit;

                        $result = $this->processRow(
                            $whatsapp, $tenant, $billing, $row, $kind, $tpl, $tplName,
                            $cycleKey, $dryRun, $atSendLimit, $sentKeys,
                        );

                        $stats[$result]++;
                        $grand[$result]++;

                        // Micro-espaciado tras un envío REAL (no dry-run, no
                        // omitidos/ya enviados): evita salir en ráfaga dentro del
                        // mismo segundo y protege la calidad del número.
                        if (! $dryRun && $result === 'sent' && $gapMs > 0) {
                            usleep($gapMs * 1000);
                        }
                    }
                }
            }

            $this->info("  ↳ Enviados: {$stats['sent']} · Omitidos: {$stats['skipped']} · Fallidos: {$stats['failed']} · Ya enviados: {$stats['already']} · Sin factura: {$stats['no_due']}");
        }

        $this->newLine();
        $this->info("Total — Enviados: {$grand['sent']} · Omitidos: {$grand['skipped']} · Fallidos: {$grand['failed']} · Ya enviados: {$grand['already']} · Sin factura: {$grand['no_due']}");

        // Corre cada minuto: solo dejamos rastro en laravel.log cuando hubo
        // actividad real (envío, fallo u omisión significativa), para no inundar
        // el log con miles de líneas vacías en los minutos sin nada que hacer.
        if ($grand['sent'] || $grand['failed'] || $grand['skipped']) {
            Log::info('whatsapp:billing-notify', $grand);
        }

        return self::SUCCESS;
    }

    /**
     * Procesa un (cliente, kind) — el tenant ya tiene WhatsApp y plantilla
     * válidos a este punto. Decide enviabilidad, envía (o no) y registra solo lo
     * significativo en la bitácora. Devuelve: sent | skipped | failed | already | no_due.
     *
     * `no_due`: el cliente simplemente no tiene factura de este ciclo → no es un
     * envío fallido, no ensucia la bitácora (no se escribe fila).
     */
    private function processRow(
        WhatsAppService $whatsapp,
        Tenant $tenant,
        array $billing,
        array $row,
        string $kind,
        Template $tpl,
        string $tplName,
        string $cycleKey,
        bool $dryRun,
        bool $atSendLimit,
        array $sentKeys,
    ): string {
        // Idempotencia: si ya salió este ciclo, no se reenvía (lookup en memoria).
        if (isset($sentKeys[$kind . ':' . $row['customer_user_id']])) {
            return 'already';
        }

        // Sin factura de este ciclo: nada que avisar (no es una falla). Silencioso.
        if ($row['invoice_id'] === null) {
            return 'no_due';
        }

        $phone = Contact::normalizePhone($row['phone']);

        // Skips SIGNIFICATIVOS (el cliente sí tenía factura pero no se le envió):
        // se registran con motivo para que el ISP lo vea en la bitácora.
        $skipReason = match (true) {
            in_array($kind, [BillingNotificationLog::KIND_REMINDER, BillingNotificationLog::KIND_SUSPENSION], true)
                && (float) ($row['balance_due'] ?? 0) <= 0
                            => 'La factura ya está pagada',
            ! $phone        => "Teléfono inválido ({$row['phone']})",
            default         => null,
        };

        if ($skipReason !== null) {
            if (! $dryRun) {
                $this->logRow($tenant, $billing, $row, $kind, $tplName, 'skipped', $skipReason, $phone, $cycleKey, null);
            }
            return 'skipped';
        }

        // Límite de envíos del tenant alcanzado: lo dejamos pendiente sin
        // registrar (mañana el catch-up lo reintenta).
        if ($atSendLimit) {
            return 'skipped';
        }

        $params = $this->buildParams($kind, $tpl, $row, $tenant);
        $body   = $this->renderBody($tpl, $params);

        if ($dryRun) {
            $this->line("    · [DRY] {$kind} → {$row['customer_name']} ({$phone}) factura {$row['invoice_number']} — se enviaría");
            return 'sent';
        }

        $result = $whatsapp->forTenant($tenant)
            ->sendTemplate($phone, $tpl->name, $tpl->language ?? 'es_CO', $params);

        if (($result['success'] ?? false) && empty($result['mock'])) {
            $waId = $result['data']['messages'][0]['id'] ?? null;
            $this->logRow($tenant, $billing, $row, $kind, $tplName, 'sent', null, $phone, $cycleKey, $waId);
            $this->recordInChat($tenant, $phone, $row, $body, $waId);
            return 'sent';
        }

        $err = $result['error'] ?? ($result['mock'] ?? false ? 'WhatsApp en modo mock (sin credenciales)' : 'desconocido');
        $this->warn("    · Falló {$kind} {$row['invoice_number']} ({$phone}): {$err}");
        $this->logRow($tenant, $billing, $row, $kind, $tplName, 'failed', $err, $phone, $cycleKey, null);
        return 'failed';
    }

    /**
     * ¿Estamos DENTRO de la ventana de envío del aviso para ESTE ciclo? La ventana
     * es [día agendado @ hora configurada → fin del día (agendado + $catchupDays)],
     * en la zona local de `$now` y SIEMPRE acotada a fin de mes (nunca cruza al
     * ciclo siguiente):
     *
     *   - Antes de la hora del día agendado      → false (aún no toca).
     *   - El día agendado, pasada la hora         → true (envía).
     *   - Días de catch-up (hasta agendado + N)   → true (recupera caídas largas;
     *     la idempotencia por ciclo impide reenviar a quien ya recibió).
     *   - Después de la ventana                   → false (ya no arrastra; solo manual).
     *
     * Con $catchupDays = 0 equivale al comportamiento anterior (solo el día
     * agendado). Día agendado acotado a fin de mes (día 31 en un mes de 30 ⇒ 30);
     * un agendado el último día del mes no tiene días de catch-up disponibles.
     * Hora ausente/ilegible ⇒ medianoche.
     */
    private function timeReached(?int $day, ?string $time, Carbon $now, int $catchupDays = 0): bool
    {
        if ($day === null) {
            return false;
        }

        $scheduledDay = min($day, $now->daysInMonth);
        [$h, $m, $s]  = $this->parseTime($time);

        // Inicio: el día agendado, a la hora configurada.
        $windowStart = $now->copy()
            ->setDate($now->year, $now->month, $scheduledDay)
            ->setTime($h, $m, $s);

        // Fin: N días después, al cierre de ESE día, sin pasar de fin de mes.
        $lastDay   = min($scheduledDay + max(0, $catchupDays), $now->daysInMonth);
        $windowEnd = $now->copy()
            ->setDate($now->year, $now->month, $lastDay)
            ->endOfDay();

        return $now->greaterThanOrEqualTo($windowStart) && $now->lessThanOrEqualTo($windowEnd);
    }

    /**
     * ¿Hoy es un día de CATCH-UP (posterior al día agendado), no el día original?
     * Se usa para decidir si aplica la compuerta de recursos. El día agendado se
     * acota a fin de mes igual que en timeReached.
     */
    private function isCatchupDay(?int $day, Carbon $now): bool
    {
        if ($day === null) {
            return false;
        }

        return $now->day > min($day, $now->daysInMonth);
    }

    /**
     * ¿Queda trabajo pendiente para este (billing, kind) en el ciclo? Chequeo
     * LOCAL barato sobre billing_notification_logs (NO toca ispwatch): hay
     * pendiente si todavía no se ha enviado nada (caída total el día agendado) o
     * si hay envíos FALLIDOS por reintentar. Si ya hubo envíos y ninguno falló se
     * asume completo y se evita el query pesado de clientes en los días de
     * catch-up. (Clientes nuevos a mitad de ciclo no se recuperan por esta vía:
     * el aviso de un cliente que aparece tras completarse el billing es manual.)
     */
    private function hasPendingWork(int $tenantId, int $billingId, string $kind, string $cycleKey): bool
    {
        $row = BillingNotificationLog::query()
            ->where('tenant_id', $tenantId)
            ->where('billing_id', $billingId)
            ->where('kind', $kind)
            ->where('cycle_key', $cycleKey)
            ->selectRaw("count(*) filter (where status = 'sent') as sent_count,
                         count(*) filter (where status = 'failed') as failed_count")
            ->first();

        $sent   = (int) ($row->sent_count ?? 0);
        $failed = (int) ($row->failed_count ?? 0);

        return $failed > 0 || $sent === 0;
    }

    /**
     * Parsea 'HH:MM' o 'HH:MM:SS' (o null) a [hora, min, seg], acotado a un reloj
     * válido. Cualquier valor no parseable cae a 00:00:00 — nunca lanza, para no
     * tumbar una corrida masiva por un dato sucio en ispwatch.
     *
     * @return array{0:int,1:int,2:int}
     */
    private function parseTime(?string $time): array
    {
        if (! $time || ! preg_match('/^\s*(\d{1,2}):(\d{2})(?::(\d{2}))?\s*$/', $time, $mch)) {
            return [0, 0, 0];
        }

        return [
            min(23, max(0, (int) $mch[1])),
            min(59, max(0, (int) $mch[2])),
            min(59, max(0, (int) ($mch[3] ?? 0))),
        ];
    }

    /** "día 6 09:00" para la línea de bitácora de consola (· nunca lanza). */
    private function describeWhen(?int $day, ?string $time): string
    {
        if ($day === null) {
            return 'día –';
        }

        [$h, $m] = $this->parseTime($time);

        return sprintf('día %d %02d:%02d', $day, $h, $m);
    }

    /**
     * Plantilla LISTA para enviar a partir de una ruta: la asignada, pero solo si
     * sigue aprobada y activa (pudo desactivarse después de configurarla).
     */
    private function routeTemplate(?TenantNotificationRoute $route): ?Template
    {
        $tpl = $route?->template;

        if ($tpl && $tpl->status === 'approved' && $tpl->is_active) {
            return $tpl;
        }

        return null;
    }

    /**
     * Parámetros del BODY. Dos formatos según la plantilla:
     *   - Nombrada ({{nombre_cliente}}) → mapa { nombre => valor } (named params).
     *     Los valores salen del catálogo de eventos (EventCatalog::resolveValues).
     *   - Posicional legada ({{1}}) → lista ordenada con la convención clásica.
     *
     * @return array<int|string, string>
     */
    private function buildParams(string $kind, Template $tpl, array $row, Tenant $tenant): array
    {
        if ($tpl->usesNamedVariables()) {
            $values = EventCatalog::resolveValues($kind, $row, $tenant);
            $params = [];
            foreach (Template::namedVariablesIn($tpl->body) as $name) {
                $params[$name] = $values[$name] ?? '';
            }
            return $params;
        }

        // Legado posicional.
        $positional = Template::positionalVariablesIn($tpl->body);
        $varCount   = $positional ? max($positional) : 0;
        $values     = $this->templateValues($kind, $row, $tenant);

        $params = [];
        for ($i = 0; $i < $varCount; $i++) {
            $params[] = $values[$i] ?? '';
        }

        if ($varCount > count($values)) {
            Log::warning('billing-notify: plantilla con más variables que la convención', [
                'template' => $tpl->name,
                'vars'     => $varCount,
            ]);
        }

        return $params;
    }

    /**
     * Reconstruye el texto final (para el eco en el chat). Soporta variables
     * nombradas (mapa) y posicionales (lista). Lógica compartida con las
     * campañas masivas, ver {@see TemplateRenderer::renderBody()}.
     *
     * @param  array<int|string, string>  $params
     */
    private function renderBody(Template $tpl, array $params): string
    {
        return TemplateRenderer::renderBody($tpl, $params);
    }

    private function formatAmount($amount): string
    {
        return '$' . number_format((float) $amount, 0, ',', '.');
    }

    private function formatDate(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '';
    }

    private function logRow(
        Tenant $tenant,
        array $billing,
        array $row,
        string $kind,
        ?string $template,
        string $status,
        ?string $reason,
        ?string $phone,
        string $cycleKey,
        ?string $waId,
    ): void {
        BillingNotificationLog::updateOrCreate(
            [
                'tenant_id'            => $tenant->id,
                'kind'                 => $kind,
                'ispwatch_customer_id' => $row['customer_user_id'],
                'cycle_key'            => $cycleKey,
            ],
            [
                'ispwatch_tenant_id'  => $tenant->ispwatch_tenant_id,
                'customer_name'       => $row['customer_name'],
                'phone'               => $phone,
                'ispwatch_invoice_id' => $row['invoice_id'],
                'invoice_number'      => $row['invoice_number'],
                'billing_id'          => $billing['billing_id'],
                'router_name'         => $billing['router_names'],
                'channel'             => 'whatsapp',
                'template'            => $template,
                'status'              => $status,
                'reason'              => $reason,
                'wa_message_id'       => $waId,
                'sent_at'             => $status === 'sent' ? now() : null,
            ],
        );
    }

    /**
     * Deja constancia del aviso en el hilo de chat del cliente, para que el
     * agente vea el contexto cuando responda. (Igual que SendPaymentReminders.)
     */
    private function recordInChat(Tenant $tenant, string $phone, array $row, string $body, ?string $waId): void
    {
        $contact = Contact::firstOrCreate(
            ['phone' => $phone, 'tenant_id' => $tenant->id],
            ['name' => $row['customer_name'], 'tenant_id' => $tenant->id],
        );

        $conversation = Conversation::firstOrCreate(
            ['contact_id' => $contact->id, 'status' => 'open', 'tenant_id' => $tenant->id],
            ['contact_id' => $contact->id, 'tenant_id' => $tenant->id],
        );

        Message::create([
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conversation->id,
            'contact_id'      => $contact->id,
            'body'            => $body,
            'status'          => 'sent',
            'type'            => 'template',
            'wa_message_id'   => $waId,
        ]);

        $conversation->touch();
    }
}
