<?php

namespace App\Console\Commands;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\PaymentReminderLog;
use App\Models\Template;
use App\Models\Tenant;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\WhatsAppService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Envía recordatorios de pago por WhatsApp a clientes con facturas VENCIDAS,
 * leyendo la lista (y el método de notificación) desde ispwatch en solo lectura
 * y enviando con la cuenta Meta de CADA tenant.
 *
 * Idempotente: una factura no se recuerda dos veces en el mismo ciclo
 * (tabla payment_reminder_logs), porque Converza no puede escribir el
 * last_reminder_sent en ispwatch.
 *
 * Uso:
 *   php artisan reminders:send                 # todos los tenants activos
 *   php artisan reminders:send --tenant=1      # solo el tenant Converza #1
 *   php artisan reminders:send --dry-run       # no envía nada, solo lista
 *   php artisan reminders:send --limit=5       # máx. 5 envíos por tenant (pruebas)
 */
class SendPaymentReminders extends Command
{
    protected $signature = 'reminders:send
        {--tenant= : Limitar a un tenant de Converza por ID}
        {--dry-run : No envía; solo muestra a quién se enviaría}
        {--limit= : Máximo de recordatorios a enviar por tenant}';

    protected $description = 'Envía recordatorios de pago por WhatsApp a clientes con facturas vencidas (según config del router en ispwatch).';

    public function handle(IspwatchRepository $ispwatch, WhatsAppService $whatsapp): int
    {
        $dryRun       = (bool) $this->option('dry-run');
        $limit        = $this->option('limit') !== null ? max(0, (int) $this->option('limit')) : null;
        $templateName = config('services.whatsapp.reminder_template', 'payment_reminder');

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
            $this->warn('— DRY RUN — no se enviará ningún mensaje.');
        }

        $grand = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($tenants as $tenant) {
            // Vincular el tenant al contenedor para que el WhatsAppService use sus
            // credenciales Meta y los modelos (Message, logs) se auto-scopeen.
            app()->instance('tenant', $tenant);

            $this->line("• Tenant <info>{$tenant->name}</info> (#{$tenant->id} → ispwatch #{$tenant->ispwatch_tenant_id})");

            if (! $tenant->hasWhatsAppConfigured()) {
                $this->warn('  ↳ WhatsApp no configurado para este tenant. Saltando.');
                continue;
            }

            // La plantilla DEBE existir aprobada en Meta (mirror local sincronizado).
            $template = Template::where('name', $templateName)
                ->where('status', 'approved')
                ->where('is_active', true)
                ->first();

            if (! $template && ! $dryRun) {
                $this->warn("  ↳ Plantilla '{$templateName}' no está aprobada/sincronizada. Sincroniza en Plantillas → Sync. Saltando.");
                continue;
            }

            $language = $template?->language ?? 'es_CO';

            $due = $ispwatch->overdueRemindersForTenant((int) $tenant->ispwatch_tenant_id);
            $this->line('  ↳ ' . count($due) . ' factura(s) vencida(s) con WhatsApp habilitado.');

            $stats = ['sent' => 0, 'skipped' => 0, 'failed' => 0];

            foreach ($due as $row) {
                if ($limit !== null && $stats['sent'] >= $limit) {
                    break;
                }

                $cycleKey = $this->cycleKey($row['period_start'], $row['due_date']);

                $alreadySent = PaymentReminderLog::where('ispwatch_invoice_id', $row['invoice_id'])
                    ->where('cycle_key', $cycleKey)
                    ->where('status', 'sent')
                    ->exists();

                if ($alreadySent) {
                    $stats['skipped']++;
                    continue;
                }

                $phone = Contact::normalizePhone($row['phone']);
                if (! $phone) {
                    $this->warn("    · Factura {$row['invoice_number']}: sin teléfono válido ({$row['phone']}). Saltando.");
                    $stats['skipped']++;
                    continue;
                }

                $params = [
                    $row['customer_name'],
                    (string) $row['invoice_number'],
                    $this->formatAmount($row['balance_due']),
                    $this->formatDate($row['due_date']),
                    $tenant->name,
                ];

                if ($dryRun) {
                    $this->line("    · [DRY] {$row['customer_name']} ({$phone}) — factura {$row['invoice_number']} por {$this->formatAmount($row['balance_due'])}");
                    continue;
                }

                $result = $whatsapp->forTenant($tenant)
                    ->sendTemplate($phone, $templateName, $language, $params);

                if ($result['success'] ?? false) {
                    $waId = $result['data']['messages'][0]['id'] ?? null;
                    $this->logReminder($tenant, $row, $cycleKey, $phone, $templateName, 'sent', $waId, null);
                    $this->recordInChat($tenant, $phone, $row, $template, $params, $waId);
                    $stats['sent']++;
                } else {
                    $err = $result['error'] ?? 'desconocido';
                    $this->logReminder($tenant, $row, $cycleKey, $phone, $templateName, 'failed', null, $err);
                    $this->warn("    · Falló factura {$row['invoice_number']} ({$phone}): {$err}");
                    $stats['failed']++;
                }
            }

            $this->info("  ↳ Enviados: {$stats['sent']}  ·  Omitidos: {$stats['skipped']}  ·  Fallidos: {$stats['failed']}");

            $grand['sent']    += $stats['sent'];
            $grand['skipped'] += $stats['skipped'];
            $grand['failed']  += $stats['failed'];
        }

        $this->newLine();
        $this->info("Total — Enviados: {$grand['sent']}  ·  Omitidos: {$grand['skipped']}  ·  Fallidos: {$grand['failed']}");
        Log::info('reminders:send complete', $grand);

        return self::SUCCESS;
    }

    /**
     * Clave de ciclo: usa period_start si existe, si no el YYYY-MM del vencimiento.
     */
    private function cycleKey(?string $periodStart, ?string $dueDate): string
    {
        $source = $periodStart ?: $dueDate;
        return $source ? Carbon::parse($source)->format('Y-m') : 'na';
    }

    private function formatAmount(string $amount): string
    {
        return number_format((float) $amount, 0, ',', '.');
    }

    private function formatDate(?string $date): string
    {
        return $date ? Carbon::parse($date)->format('d/m/Y') : '';
    }

    private function logReminder(
        Tenant $tenant,
        array $row,
        string $cycleKey,
        string $phone,
        string $template,
        string $status,
        ?string $waId,
        ?string $error,
    ): void {
        PaymentReminderLog::updateOrCreate(
            [
                'tenant_id'           => $tenant->id,
                'ispwatch_invoice_id' => $row['invoice_id'],
                'cycle_key'           => $cycleKey,
            ],
            [
                'ispwatch_customer_id' => $row['customer_user_id'],
                'channel'              => 'whatsapp',
                'phone'                => $phone,
                'template'             => $template,
                'status'               => $status,
                'wa_message_id'        => $waId,
                'error'                => $error,
            ],
        );
    }

    /**
     * Deja constancia del recordatorio en el hilo de chat del cliente, para que
     * el agente vea el contexto cuando el cliente responda.
     */
    private function recordInChat(
        Tenant $tenant,
        string $phone,
        array $row,
        ?Template $template,
        array $params,
        ?string $waId,
    ): void {
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
            'body'            => $this->renderBody($template, $params, $row),
            'status'          => 'sent',
            'type'            => 'template',
            'wa_message_id'   => $waId,
        ]);

        $conversation->touch();
    }

    /**
     * Reconstruye el texto que vio el cliente reemplazando {{1}}.. en el body
     * de la plantilla. Si no hay mirror del body, arma una línea genérica.
     */
    private function renderBody(?Template $template, array $params, array $row): string
    {
        $body = $template?->body;

        if ($body) {
            foreach ($params as $i => $value) {
                $body = str_replace('{{' . ($i + 1) . '}}', $value, $body);
            }
            return $body;
        }

        return "Recordatorio de pago: factura {$row['invoice_number']} por {$this->formatAmount($row['balance_due'])} (vence {$this->formatDate($row['due_date'])}).";
    }
}
