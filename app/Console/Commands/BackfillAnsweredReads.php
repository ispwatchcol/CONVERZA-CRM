<?php

namespace App\Console\Commands;

use App\Models\StaffMember;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Backfill único para la regla "respondida = leída para todo el equipo"
 * (ConversationRead::markAnsweredForTeam).
 *
 * El "no leído" siempre fue por agente y solo bajaba cuando ESE agente abría el
 * chat, así que las conversaciones que un compañero ya respondió quedaron con
 * el badge verde pegado para el resto del equipo. La regla nueva solo aplica a
 * envíos futuros; este comando arrastra el historial, dejando las cosas como si
 * la regla hubiera existido siempre.
 *
 * Por cada conversación toma la ÚLTIMA respuesta humana enviada desde la UI
 * (sent_by_user_id NOT NULL — los avisos de facturación, el bot y las campañas
 * lo dejan en null, así que quedan afuera como corresponde) y marca esa fecha
 * como last_read_at para todo el equipo activo del tenant.
 *
 * Nunca mueve a un agente hacia atrás: si ya tenía un last_read_at posterior,
 * se respeta. Es idempotente — se puede correr las veces que haga falta.
 */
class BackfillAnsweredReads extends Command
{
    protected $signature = 'chat:backfill-answered-reads
        {--tenant= : Limitar a un tenant_id}
        {--dry-run : Mostrar qué haría, sin escribir nada}';

    protected $description = 'Marca como leídas para todo el equipo las conversaciones que ya fueron respondidas por un agente.';

    public function handle(): int
    {
        $dryRun   = (bool) $this->option('dry-run');
        $tenantId = $this->option('tenant') ? (int) $this->option('tenant') : null;

        $answered = DB::table('messages')
            ->whereNotNull('sent_by_user_id')
            ->whereNotIn('status', ['received', 'failed'])
            ->where(fn ($q) => $q->whereNull('type')->orWhereNotIn('type', ['system', 'note']))
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->groupBy('conversation_id', 'tenant_id')
            ->selectRaw('conversation_id, tenant_id, MAX(created_at) AS answered_at')
            ->get();

        if ($answered->isEmpty()) {
            $this->info('✅ No hay conversaciones respondidas para procesar.');

            return self::SUCCESS;
        }

        // Staff activo por tenant, resuelto una sola vez.
        $staffByTenant = StaffMember::withoutGlobalScopes()
            ->where('is_active', true)
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->get(['id', 'tenant_id'])
            ->groupBy('tenant_id')
            ->map(fn ($rows) => $rows->pluck('id')->all());

        $this->info(($dryRun ? '🔍 [DRY RUN] ' : '✍️  ') . "Procesando {$answered->count()} conversaciones respondidas...");
        $bar = $this->output->createProgressBar($answered->count());
        $bar->start();

        $inserted = 0;
        $updated  = 0;
        $now      = now();

        foreach ($answered as $row) {
            $staffIds = $staffByTenant[$row->tenant_id] ?? [];

            if ($staffIds === []) {
                $bar->advance();
                continue;
            }

            if ($dryRun) {
                $existing = DB::table('conversation_reads')
                    ->where('conversation_id', $row->conversation_id)
                    ->whereIn('staff_member_id', $staffIds);

                $inserted += count($staffIds) - (clone $existing)->count();
                $updated  += (clone $existing)->where($this->staleRead($row->answered_at))->count();

                $bar->advance();
                continue;
            }

            // 1) Los agentes que nunca abrieron el chat: fila nueva. insertOrIgnore
            //    se apoya en el índice único (conversation_id, staff_member_id).
            $inserted += DB::table('conversation_reads')->insertOrIgnore(
                array_map(fn (int $staffId) => [
                    'tenant_id'       => $row->tenant_id,
                    'conversation_id' => $row->conversation_id,
                    'staff_member_id' => $staffId,
                    'last_read_at'    => $row->answered_at,
                    'created_at'      => $now,
                    'updated_at'      => $now,
                ], $staffIds),
            );

            // 2) Los que ya tenían fila, pero anterior a la respuesta.
            $updated += DB::table('conversation_reads')
                ->where('conversation_id', $row->conversation_id)
                ->whereIn('staff_member_id', $staffIds)
                ->where($this->staleRead($row->answered_at))
                ->update(['last_read_at' => $row->answered_at, 'updated_at' => $now]);

            $bar->advance();
        }

        $bar->finish();
        $this->newLine(2);

        $this->info(($dryRun ? '[DRY RUN] ' : '') . "Filas nuevas: {$inserted} · Filas adelantadas: {$updated}");

        if ($dryRun) {
            $this->warn('Nada se escribió. Corré sin --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }

    /**
     * Filas que hay que adelantar: las que quedaron ANTES de la respuesta. El
     * '<' es lo que evita pisar a quien leyó DESPUÉS de responder (le
     * reaparecerían como no leídos los entrantes del medio). last_read_at NULL
     * es "nunca leyó", así que también entra.
     */
    private function staleRead(string $answeredAt): callable
    {
        return fn ($q) => $q->whereNull('last_read_at')->orWhere('last_read_at', '<', $answeredAt);
    }
}
