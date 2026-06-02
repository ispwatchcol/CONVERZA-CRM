<?php

namespace App\Console\Commands;

use App\Models\BillingNotificationLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Template;
use App\Models\Tenant;
use App\Services\Ispwatch\IspwatchRepository;
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
 * del core). De las fechas solo importa el DÍA del mes.
 *
 * Catch-up: corre a diario y dispara si hoy es >= el día configurado y el aviso
 * aún no salió este ciclo, así un fallo o una caída se recuperan el día
 * siguiente sin duplicar (idempotencia en `billing_notification_logs`).
 *
 * Todo intento queda registrado (sent / skipped+motivo / failed) para la
 * bitácora del sidebar.
 *
 * Uso:
 *   php artisan whatsapp:billing-notify
 *   php artisan whatsapp:billing-notify --tenant=2
 *   php artisan whatsapp:billing-notify --date=2026-06-05   # simula un día
 *   php artisan whatsapp:billing-notify --dry-run
 *   php artisan whatsapp:billing-notify --limit=1            # máx. envíos por tenant
 */
class SendBillingNotifications extends Command
{
    protected $signature = 'whatsapp:billing-notify
        {--tenant= : Limitar a un tenant de Converza por ID}
        {--customer= : Limitar a un cliente de ispwatch por user_id (pruebas)}
        {--date= : Simular la fecha de corrida (YYYY-MM-DD), default hoy}
        {--dry-run : No envía; solo muestra lo que haría}
        {--force : Permite el envío masivo aunque el interruptor maestro esté apagado}
        {--limit= : Máximo de avisos a ENVIAR por tenant}';

    protected $description = 'Envía avisos de factura generada y recordatorios de pago por WhatsApp según las fechas del router en ispwatch.';

    /** Valores en el orden que las plantillas esperan sus variables {{1}}, {{2}}, … */
    private function templateValues(string $kind, array $row, Tenant $tenant): array
    {
        $amount = $kind === BillingNotificationLog::KIND_REMINDER
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
        $limit    = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $customer = $this->option('customer') !== null ? (int) $this->option('customer') : null;

        try {
            $today = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::now();
        } catch (\Throwable $e) {
            $this->error("Fecha inválida en --date: {$this->option('date')}");
            return self::FAILURE;
        }

        $cycleKey   = $today->format('Y-m');
        $cycleStart = $today->copy()->startOfMonth()->toDateString();
        $cycleEnd   = $today->copy()->endOfMonth()->toDateString();
        $daysInMon  = $today->daysInMonth;

        // Interruptor maestro: hasta que el usuario lo active, la tarea agendada NO
        // hace envíos masivos. Se permiten igual las pruebas dirigidas a un cliente
        // (--customer), los --dry-run y un --force explícito.
        $massEnabled = (bool) config('services.whatsapp.billing_notify_enabled', false);
        if (! $massEnabled && ! $dryRun && ! $this->option('force') && $customer === null) {
            $this->warn('Envío automático masivo DESHABILITADO (WHATSAPP_BILLING_NOTIFY_ENABLED=false).');
            $this->line('Para activarlo pon WHATSAPP_BILLING_NOTIFY_ENABLED=true. Para pruebas usa --customer=<id>, --dry-run o --force.');
            return self::SUCCESS;
        }

        $this->line("Corrida para <info>{$today->toDateString()}</info> (ciclo {$cycleKey}, día {$today->day}).");
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

            $invoiceTpl  = $this->resolveTemplate($tenant->wa_invoice_template);
            $reminderTpl = $this->resolveTemplate($tenant->wa_reminder_template);

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

                $runInvoice  = $this->dayReached($billing['create_day'], $today->day, $daysInMon);
                $runReminder = $billing['payment_reminder_enabled']
                    && $this->dayReached($billing['reminder_day'], $today->day, $daysInMon);

                $this->line("  ↳ billing #{$billing['billing_id']} ({$billing['router_names']}) — "
                    . count($rows) . ' cliente(s) · '
                    . 'factura(día ' . ($billing['create_day'] ?? '–') . ($runInvoice ? '✓' : '·') . ') '
                    . 'recordatorio(día ' . ($billing['reminder_day'] ?? '–') . ($runReminder ? '✓' : '·') . ')');

                foreach (
                    [
                        [BillingNotificationLog::KIND_INVOICE,  $runInvoice,  $invoiceTpl,  $tenant->wa_invoice_template],
                        [BillingNotificationLog::KIND_REMINDER, $runReminder, $reminderTpl, $tenant->wa_reminder_template],
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
                    }
                }
            }

            $this->info("  ↳ Enviados: {$stats['sent']} · Omitidos: {$stats['skipped']} · Fallidos: {$stats['failed']} · Ya enviados: {$stats['already']} · Sin factura: {$stats['no_due']}");
        }

        $this->newLine();
        $this->info("Total — Enviados: {$grand['sent']} · Omitidos: {$grand['skipped']} · Fallidos: {$grand['failed']} · Ya enviados: {$grand['already']} · Sin factura: {$grand['no_due']}");
        Log::info('whatsapp:billing-notify complete', $grand);

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
            $kind === BillingNotificationLog::KIND_REMINDER && (float) ($row['balance_due'] ?? 0) <= 0
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

    /** Día configurado alcanzado (catch-up: hoy >= día, con clamp a fin de mes). */
    private function dayReached(?int $day, int $todayDay, int $daysInMonth): bool
    {
        if ($day === null) {
            return false;
        }
        return $todayDay >= min($day, $daysInMonth);
    }

    private function resolveTemplate(?string $name): ?Template
    {
        if (blank($name)) {
            return null;
        }

        return Template::where('name', $name)
            ->where('status', 'approved')
            ->where('is_active', true)
            ->first();
    }

    /**
     * Construye los parámetros del BODY recortando la convención de valores al
     * número de variables {{n}} que tenga la plantilla.
     *
     * @return array<int, string>
     */
    private function buildParams(string $kind, Template $tpl, array $row, Tenant $tenant): array
    {
        preg_match_all('/\{\{(\d+)\}\}/', (string) $tpl->body, $m);
        $varCount = $m[1] ? max(array_map('intval', $m[1])) : 0;

        $values = $this->templateValues($kind, $row, $tenant);

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

    private function renderBody(Template $tpl, array $params): string
    {
        $body = (string) $tpl->body;
        foreach ($params as $i => $value) {
            $body = str_replace('{{' . ($i + 1) . '}}', $value, $body);
        }
        return $body;
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
