<?php

namespace App\Console\Commands;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Cierra solos los chats que el cliente dejó colgados.
 *
 * Regla (deliberadamente conservadora): se cierra SOLO si el último mensaje real
 * del hilo es SALIENTE —o sea, el equipo (o el bot/un aviso) ya respondió— y el
 * cliente no volvió a escribir en las últimas N horas. Si el último mensaje es
 * del cliente NO se toca: ese chat está esperando respuesta nuestra y cerrarlo
 * escondería un pendiente real. Tampoco se cierra si el último saliente quedó en
 * `failed`: el cliente nunca lo recibió y alguien tiene que revisarlo.
 *
 * El cierre es SILENCIOSO: no se le manda nada al cliente por WhatsApp (cero
 * ruido y cero riesgo de calidad/baneo). Queda una nota de sistema en el hilo
 * para que el agente sepa por qué se cerró.
 *
 * Opt-in por tenant: `auto_close_enabled` + `auto_close_hours` (default 2 h),
 * en Configuración → Cierre automático. Apagado ⇒ este comando lo saltea.
 *
 * Como ahora hay UN solo hilo por contacto (Conversation::resolveForContact),
 * cerrar no pierde nada: el próximo mensaje del cliente reabre ESTE mismo chat
 * con todo el historial.
 *
 * Uso:
 *   php artisan chats:auto-close
 *   php artisan chats:auto-close --dry-run
 *   php artisan chats:auto-close --tenant=2 --hours=1 --dry-run
 */
class AutoCloseIdleConversations extends Command
{
    protected $signature = 'chats:auto-close
        {--tenant= : Limitar a un tenant de Converza por ID}
        {--hours= : Sobrescribe las horas configuradas del tenant (pruebas)}
        {--limit=500 : Máximo de chats a cerrar por tenant y corrida}
        {--dry-run : No cierra nada; solo lista lo que cerraría}
        {--force : Corre aunque el tenant tenga el cierre automático apagado}';

    protected $description = 'Cierra los chats abiertos donde el cliente no respondió tras N horas (opt-in por tenant).';

    /** Filas por lote: acota la memoria sin multiplicar queries. */
    private const CHUNK = 200;

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $force    = (bool) $this->option('force');
        $tenantId = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $limit    = max(1, (int) $this->option('limit'));

        // --force existe para probar en UN tenant, no para cerrarle los chats a
        // todos los que nunca activaron la función.
        if ($force && ! $tenantId && ! $dryRun) {
            $this->error('--force necesita --tenant= (o --dry-run): saltarse el interruptor en todos los tenants no es intencional.');
            return self::FAILURE;
        }

        $tenants = Tenant::query()
            ->when($tenantId, fn ($q) => $q->where('id', $tenantId))
            ->when(! $tenantId, fn ($q) => $q->where('is_active', true))
            ->when(! $force, fn ($q) => $q->where('auto_close_enabled', true))
            ->get();

        if ($tenants->isEmpty()) {
            // Caso normal cuando ningún tenant lo activó: corrida barata, sin ruido.
            return self::SUCCESS;
        }

        $total = 0;

        foreach ($tenants as $tenant) {
            $total += $this->processTenant($tenant, $dryRun, $limit);
        }

        if ($total > 0) {
            $this->info(($dryRun ? '[dry-run] ' : '') . "Chats cerrados por inactividad: {$total}");
        }

        return self::SUCCESS;
    }

    private function processTenant(Tenant $tenant, bool $dryRun, int $limit): int
    {
        $hours     = (int) ($this->option('hours') ?: $tenant->auto_close_hours ?: 2);
        $hours     = max(1, min(720, $hours));
        $threshold = now()->subHours($hours);

        $closed = 0;

        Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            // Prefiltro barato: cada mensaje toca la conversación, así que un hilo
            // con actividad reciente ni se mira. El veredicto lo da igual el último
            // mensaje real de abajo.
            ->where('updated_at', '<=', $threshold)
            ->with(['latestMessage', 'contact:id,phone,name'])
            ->chunkById(self::CHUNK, function ($conversations) use ($tenant, $threshold, $hours, $dryRun, $limit, &$closed) {
                foreach ($conversations as $conversation) {
                    if ($closed >= $limit) {
                        return false;
                    }

                    $last = $conversation->latestMessage;

                    // Hilo sin mensajes reales (solo notas internas o eventos de
                    // sistema): no hay conversación que cerrar.
                    if (! $last || ! $last->created_at) {
                        continue;
                    }

                    // El cliente escribió último ⇒ la pelota es nuestra.
                    if ($last->status === 'received') {
                        continue;
                    }

                    // El último saliente no le llegó: dejarlo visible.
                    if ($last->status === 'failed') {
                        continue;
                    }

                    if ($last->created_at->gt($threshold)) {
                        continue;
                    }

                    $closed++;

                    if ($dryRun) {
                        $this->line(sprintf(
                            '[dry-run] tenant %d · chat #%d · %s · sin respuesta desde %s',
                            $tenant->id,
                            $conversation->id,
                            $conversation->contact?->phone ?? 's/n',
                            $last->created_at->diffForHumans(),
                        ));
                        continue;
                    }

                    $this->close($tenant, $conversation, $hours);
                }

                return true;
            });

        return $closed;
    }

    private function close(Tenant $tenant, Conversation $conversation, int $hours): void
    {
        try {
            $conversation->update(['status' => 'closed']);

            // Nota de sistema (no viaja a WhatsApp): explica el cierre en el hilo.
            // type=system queda fuera de latestMessage, así que no altera el
            // "último mensaje" de la lista de chats.
            Message::create([
                'tenant_id'       => $tenant->id,
                'conversation_id' => $conversation->id,
                'contact_id'      => $conversation->contact_id,
                'body'            => "🕒 Cerrado automáticamente: el cliente no respondió en {$hours} h.",
                'status'          => 'sent',
                'type'            => 'system',
            ]);
        } catch (\Throwable $e) {
            // Un chat que falla no debe tumbar la corrida del resto.
            Log::warning('chats:auto-close: no se pudo cerrar la conversación', [
                'tenant_id'       => $tenant->id,
                'conversation_id' => $conversation->id,
                'error'           => $e->getMessage(),
            ]);
        }
    }
}
