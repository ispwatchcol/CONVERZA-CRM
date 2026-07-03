<?php

namespace App\Console\Commands;

use App\Models\BillingNotificationLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\IspwatchEventCursor;
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
 * Avisos por WhatsApp disparados por EVENTOS de ispwatch (no por fecha del ciclo):
 *
 *   - service_activated  → "bienvenida", al activarse el servicio de un cliente.
 *   - payment_registered → "pago registrado", al registrarse un pago.
 *
 * ispwatch es SOLO LECTURA: sin webhooks ni triggers. Detectamos lo nuevo con
 * polling incremental sobre el id de la tabla origen (user_services / payments),
 * guardando una marca de agua por (tenant, evento) en `ispwatch_event_cursors`.
 * Cada corrida trae solo `id > cursor`. Compuerta barata: si MAX(id) no supera el
 * cursor, la corrida corta sin tocar joins (el tic en reposo es una consulta).
 *
 * Opt-in y apagado por defecto: un evento solo se procesa si su ruta
 * (Settings → Avisos) está activa y con plantilla aprobada. Sin ruta ⇒ ni siquiera
 * se siembra cursor; nadie recibe nada.
 *
 * Salvaguardas (el usuario es muy cauto con baneos/calidad Meta):
 *   1. Cold-start: la primera corrida de un evento SIEMBRA el cursor en MAX(id) y
 *      NO envía — así solo reciben aviso las altas/pagos POSTERIORES a activarlo,
 *      nunca la base histórica completa.
 *   2. Frescura: no se envía por registros más viejos que N días (no soltar avisos
 *      rancios tras una caída larga); el cursor igual avanza sobre ellos.
 *   3. Carga masiva (solo bienvenida): una importación crea decenas/cientos de
 *      activaciones en el mismo minuto. Se detecta por densidad temporal
 *      (cluster_size) y NO se envía bienvenida a esas — solo a altas manuales.
 *   4. Idempotencia: bienvenida UNA vez por cliente; confirmación una vez por pago
 *      (fila única en billing_notification_logs por (tenant, kind, ref)).
 *   5. Pacing: tope de envíos por corrida + micro-espaciado; una ráfaga se drena
 *      sola en varios minutos (el cursor avanza solo sobre lo procesado).
 *
 * Uso:
 *   php artisan whatsapp:events-notify
 *   php artisan whatsapp:events-notify --tenant=2 --event=payment_registered
 *   php artisan whatsapp:events-notify --customer=123 --force   # prueba dirigida
 *   php artisan whatsapp:events-notify --dry-run
 *   php artisan whatsapp:events-notify --reset-cursor           # re-siembra sin enviar histórico
 */
class SendEventNotifications extends Command
{
    protected $signature = 'whatsapp:events-notify
        {--tenant= : Limitar a un tenant de Converza por ID}
        {--event= : Limitar a un evento (service_activated | payment_registered)}
        {--customer= : Limitar a un cliente de ispwatch por user_id (pruebas; no mueve el cursor)}
        {--dry-run : No envía; solo muestra lo que haría (no mueve el cursor)}
        {--force : Permite enviar aunque el interruptor maestro/el gate del tenant estén apagados}
        {--reset-cursor : Re-siembra el cursor al MAX(id) actual sin enviar histórico}
        {--limit= : Máximo de avisos a ENVIAR por tenant/evento}';

    protected $description = 'Envía bienvenidas (servicio activado) y confirmaciones de pago por WhatsApp según eventos de ispwatch.';

    /** Eventos que maneja este comando (por orden de proceso). */
    private const EVENTS = [
        BillingNotificationLog::KIND_WELCOME,
        BillingNotificationLog::KIND_PAYMENT,
    ];

    /** Techo de filas a examinar por corrida/evento (acota el query; el pacing lo da $limit). */
    private const FETCH_LIMIT = 1000;

    public function handle(IspwatchRepository $ispwatch, WhatsAppService $whatsapp): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');
        $customer = $this->option('customer') !== null ? (int) $this->option('customer') : null;

        $onlyEvent = $this->option('event');
        if ($onlyEvent !== null && ! in_array($onlyEvent, self::EVENTS, true)) {
            $this->error('Evento inválido. Usa: ' . implode(' | ', self::EVENTS));
            return self::FAILURE;
        }
        $events = $onlyEvent !== null ? [$onlyEvent] : self::EVENTS;

        // Interruptor maestro global (compartido con billing-notify). Con esto en
        // false la tarea agendada no envía; pruebas dirigidas y --dry-run sí.
        $massEnabled = (bool) config('services.whatsapp.billing_notify_enabled', false);
        if (! $massEnabled && ! $dryRun && ! $force && $customer === null) {
            $this->warn('Envío automático DESHABILITADO (WHATSAPP_BILLING_NOTIFY_ENABLED=false). Usa --customer, --dry-run o --force para probar.');
            return self::SUCCESS;
        }

        $configCap = max(0, (int) config('services.whatsapp.events_notify_per_minute', 30));
        $limit = $this->option('limit') !== null
            ? max(0, (int) $this->option('limit'))
            : (($dryRun || $configCap === 0) ? null : $configCap);

        $gapMs         = max(0, (int) config('services.whatsapp.events_notify_gap_ms', 350));
        $freshnessDays = max(0, (int) config('services.whatsapp.events_notify_freshness_days', 2));
        $bulkThreshold = max(0, (int) config('services.whatsapp.events_notify_bulk_threshold', 5));
        $bulkWindow    = max(0, (int) config('services.whatsapp.events_notify_bulk_window_seconds', 120));

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

        if ($dryRun) {
            $this->warn('— DRY RUN — no se enviará ningún mensaje ni se moverá el cursor.');
        }

        $grand = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'already' => 0, 'bulk' => 0, 'stale' => 0];

        foreach ($tenants as $tenant) {
            app()->instance('tenant', $tenant);

            if (! $tenant->hasWhatsAppConfigured()) {
                continue;
            }

            // Gate del tenant (mismo botón de pausa que billing). --force / --customer lo saltan.
            if (! $tenant->billing_notify_enabled && $customer === null && ! $force) {
                continue;
            }

            $routes = $tenant->notificationRoutes()
                ->whereIn('event_key', $events)
                ->where('enabled', true)
                ->with('template')
                ->get()
                ->keyBy('event_key');

            foreach ($events as $event) {
                $route = $routes->get($event);
                if (! $route) {
                    // Evento apagado para este tenant: no se procesa ni se siembra cursor.
                    continue;
                }

                $tplName = $route->template?->name;
                $tpl     = $this->routeTemplate($route);

                if (blank($tplName)) {
                    $this->warn("• {$tenant->name} / {$event}: activo pero sin plantilla configurada. Omitido.");
                    continue;
                }
                if (! $tpl) {
                    $this->warn("• {$tenant->name} / {$event}: plantilla '{$tplName}' no está aprobada/activa. Omitido.");
                    continue;
                }

                $stats = $this->processEvent(
                    $ispwatch, $whatsapp, $tenant, $event, $tpl, $tplName,
                    $customer, $dryRun, $limit, $gapMs, $freshnessDays, $bulkThreshold, $bulkWindow,
                );

                foreach ($stats as $k => $v) {
                    $grand[$k] += $v;
                }
            }
        }

        if (array_sum($grand) > 0) {
            $this->info("Total — Enviados: {$grand['sent']} · Omitidos: {$grand['skipped']} · Fallidos: {$grand['failed']} · Ya avisados: {$grand['already']} · Carga masiva: {$grand['bulk']} · Rancios: {$grand['stale']}");
        }

        if ($grand['sent'] || $grand['failed'] || $grand['skipped']) {
            Log::info('whatsapp:events-notify', $grand);
        }

        return self::SUCCESS;
    }

    /**
     * Procesa un (tenant, evento): cold-start, compuerta barata, traída incremental,
     * salvaguardas y envío. Devuelve el desglose de resultados. Avanza el cursor solo
     * en corrida normal (no en --dry-run ni --customer).
     *
     * @return array{sent:int, skipped:int, failed:int, already:int, bulk:int, stale:int}
     */
    private function processEvent(
        IspwatchRepository $ispwatch,
        WhatsAppService $whatsapp,
        Tenant $tenant,
        string $event,
        Template $tpl,
        string $tplName,
        ?int $customer,
        bool $dryRun,
        ?int $limit,
        int $gapMs,
        int $freshnessDays,
        int $bulkThreshold,
        int $bulkWindow,
    ): array {
        $stats  = ['sent' => 0, 'skipped' => 0, 'failed' => 0, 'already' => 0, 'bulk' => 0, 'stale' => 0];
        $ispTid = (int) $tenant->ispwatch_tenant_id;
        $welcome = $event === BillingNotificationLog::KIND_WELCOME;

        $cursor = IspwatchEventCursor::firstOrNew(['tenant_id' => $tenant->id, 'event_key' => $event]);
        $maxId  = $welcome ? $ispwatch->maxActivationId($ispTid) : $ispwatch->maxPaymentId($ispTid);

        // Cold-start / reset: sembrar en MAX(id), sin enviar histórico.
        if ($cursor->last_id === null || $this->option('reset-cursor')) {
            $cursor->last_id      = $maxId;
            $cursor->last_seen_at = now();
            $cursor->save();
            $this->line("• {$tenant->name} / {$event}: cursor sembrado en {$maxId} (sin envíos).");
            return $stats;
        }

        // Compuerta barata: nada nuevo.
        if ($maxId <= $cursor->last_id) {
            return $stats;
        }

        $rows = $welcome
            ? $ispwatch->newActivationsSince($ispTid, $cursor->last_id, self::FETCH_LIMIT, $bulkWindow)
            : $ispwatch->newPaymentsSince($ispTid, $cursor->last_id, self::FETCH_LIMIT);

        // Prueba dirigida: un solo cliente. No mueve el cursor (ver $persist).
        if ($customer !== null) {
            $rows = array_values(array_filter($rows, fn ($r) => $r['customer_user_id'] === $customer));
        }

        if ($rows === []) {
            return $stats;
        }

        // Idempotencia: refs ya ENVIADOS (bienvenida→customer_id, pago→payment_id).
        $refOf    = fn (array $r): int => $welcome ? $r['customer_user_id'] : $r['payment_id'];
        $candRefs = array_map($refOf, $rows);
        $sentRefs = BillingNotificationLog::query()
            ->where('tenant_id', $tenant->id)
            ->where('kind', $event)
            ->where('status', 'sent')
            ->whereIn('ispwatch_ref_id', $candRefs)
            ->pluck('ispwatch_ref_id')
            ->flip()
            ->all();

        $freshCutoff  = $freshnessDays > 0 ? now()->subDays($freshnessDays) : null;
        $cursorTarget = (int) $cursor->last_id;
        $persist      = ! $dryRun && $customer === null;

        foreach ($rows as $row) {
            $rowId     = $welcome ? $row['service_id'] : $row['payment_id'];
            $ref       = $refOf($row);
            $createdAt = $welcome ? ($row['activated_at'] ?? null) : ($row['created_at'] ?? null);

            // (2) Frescura: registro rancio → no enviar, pero avanzar el cursor.
            if ($freshCutoff && $createdAt && Carbon::parse($createdAt)->lt($freshCutoff)) {
                $stats['stale']++;
                $cursorTarget = $rowId;
                continue;
            }

            if ($welcome) {
                // Cliente inactivo: no dar bienvenida (avanzar).
                if (! ($row['cp_status'] ?? true)) {
                    $stats['skipped']++;
                    $cursorTarget = $rowId;
                    continue;
                }
                // (3) Carga masiva: densidad alta ⇒ importación, no bienvenida.
                //     En prueba dirigida (--customer) no aplica, para poder testear.
                if ($customer === null && $bulkThreshold > 0 && ($row['cluster_size'] ?? 1) >= $bulkThreshold) {
                    $stats['bulk']++;
                    $cursorTarget = $rowId;
                    continue;
                }
            }

            // (4) Idempotencia: ya avisado.
            if (isset($sentRefs[$ref])) {
                $stats['already']++;
                $cursorTarget = $rowId;
                continue;
            }

            $phone = Contact::normalizePhone($row['phone']);
            if (! $phone) {
                if (! $dryRun) {
                    $this->logRow($tenant, $event, $row, $ref, $tplName, 'skipped', "Teléfono inválido ({$row['phone']})", null, null);
                }
                $stats['skipped']++;
                $cursorTarget = $rowId;
                continue;
            }

            // (5) Pacing: tope alcanzado ⇒ parar SIN avanzar (el resto va al próximo tic).
            if ($limit !== null && $stats['sent'] >= $limit) {
                break;
            }

            $params = $this->buildParams($event, $tpl, $row, $tenant);
            $body   = TemplateRenderer::renderBody($tpl, $params);

            if ($dryRun) {
                $this->line("  · [DRY] {$event} → {$row['customer_name']} ({$phone})");
                $stats['sent']++;
                $cursorTarget = $rowId;
                continue;
            }

            $result = $whatsapp->forTenant($tenant)
                ->sendTemplate($phone, $tpl->name, $tpl->language ?? 'es_CO', $params);

            if (($result['success'] ?? false) && empty($result['mock'])) {
                $waId = $result['data']['messages'][0]['id'] ?? null;
                $this->logRow($tenant, $event, $row, $ref, $tplName, 'sent', null, $phone, $waId);
                $this->recordInChat($tenant, $phone, $row, $body, $waId);
                $sentRefs[$ref] = true; // evita duplicado intra-lote (mismo cliente 2 activaciones).
                $stats['sent']++;
                $cursorTarget = $rowId;

                if ($gapMs > 0) {
                    usleep($gapMs * 1000);
                }
            } else {
                $err = $result['error'] ?? (($result['mock'] ?? false) ? 'WhatsApp en modo mock (sin credenciales)' : 'desconocido');
                $this->logRow($tenant, $event, $row, $ref, $tplName, 'failed', $err, $phone, null);
                $stats['failed']++;
                $cursorTarget = $rowId; // avanzar: evita bloquear la cola por un fallo (queda en bitácora).
            }
        }

        if ($persist && $cursorTarget > (int) $cursor->last_id) {
            $cursor->last_id      = $cursorTarget;
            $cursor->last_seen_at = now();
            $cursor->save();
        }

        $touched = array_sum($stats);
        if ($touched > 0) {
            $this->info("• {$tenant->name} / {$event} — Env: {$stats['sent']} · Omit: {$stats['skipped']} · Fall: {$stats['failed']} · Ya: {$stats['already']} · Masiva: {$stats['bulk']} · Rancios: {$stats['stale']}");
        }

        return $stats;
    }

    /** Plantilla LISTA para enviar (aprobada + activa) o null. */
    private function routeTemplate(?TenantNotificationRoute $route): ?Template
    {
        $tpl = $route?->template;

        if ($tpl && $tpl->status === 'approved' && $tpl->is_active) {
            return $tpl;
        }

        return null;
    }

    /**
     * Parámetros del BODY. Named ({{nombre_cliente}}) → mapa del catálogo; posicional
     * legado ({{1}}) → valores del catálogo en su orden. Igual que billing-notify.
     *
     * @return array<int|string, string>
     */
    private function buildParams(string $event, Template $tpl, array $row, Tenant $tenant): array
    {
        $values = EventCatalog::resolveValues($event, $row, $tenant);

        if ($tpl->usesNamedVariables()) {
            $params = [];
            foreach (Template::namedVariablesIn($tpl->body) as $name) {
                $params[$name] = $values[$name] ?? '';
            }
            return $params;
        }

        $ordered    = array_values($values);
        $positional = Template::positionalVariablesIn($tpl->body);
        $varCount   = $positional ? max($positional) : 0;

        $params = [];
        for ($i = 0; $i < $varCount; $i++) {
            $params[] = $ordered[$i] ?? '';
        }

        return $params;
    }

    private function logRow(
        Tenant $tenant,
        string $event,
        array $row,
        int $ref,
        ?string $tplName,
        string $status,
        ?string $reason,
        ?string $phone,
        ?string $waId,
    ): void {
        BillingNotificationLog::updateOrCreate(
            [
                'tenant_id'       => $tenant->id,
                'kind'            => $event,
                'ispwatch_ref_id' => $ref,
            ],
            [
                'ispwatch_tenant_id'   => $tenant->ispwatch_tenant_id,
                'ispwatch_customer_id' => $row['customer_user_id'],
                'customer_name'        => $row['customer_name'],
                'phone'                => $phone,
                'cycle_key'            => null,
                'channel'              => 'whatsapp',
                'template'             => $tplName,
                'status'               => $status,
                'reason'               => $reason,
                'wa_message_id'        => $waId,
                'sent_at'              => $status === 'sent' ? now() : null,
            ],
        );
    }

    /**
     * Deja constancia del aviso en el hilo de chat del cliente (igual que
     * billing-notify / SendPaymentReminders), para que el agente vea el contexto.
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
