<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fusiona los chats duplicados de un mismo contacto en UN solo hilo.
 *
 * Arrastre histórico: hasta ahora cada envío resolvía la conversación con
 * `firstOrCreate([... 'status' => 'open'])`, así que con el chat cerrado se abría
 * uno nuevo. Un cliente que escribió tres veces terminaba con tres chats en la
 * lista y el historial partido. La causa ya está corregida
 * (Conversation::resolveForContact); este comando limpia lo que quedó en la BD.
 *
 * Para cada (tenant, contacto) con más de una conversación:
 *   1. Se conserva la MÁS ANTIGUA (menor id): es el hilo original del cliente.
 *   2. Mensajes, notas de cierre y logs del bot se mueven a ese hilo. Como cada
 *      mensaje conserva su created_at, el chat queda en orden cronológico real.
 *   3. Los "leídos" por agente se consolidan quedándose con la marca más nueva.
 *   4. El hilo sobreviviente hereda estado/asignación/bot del más reciente, y su
 *      updated_at pasa a ser el más nuevo del grupo (no se hunde en la lista).
 *   5. Los duplicados vacíos se borran.
 *
 * Correr SIEMPRE primero con --dry-run y revisar el reporte.
 *
 * Uso:
 *   php artisan chats:merge-duplicates --dry-run
 *   php artisan chats:merge-duplicates --tenant=2 --dry-run
 *   php artisan chats:merge-duplicates --tenant=2
 */
class MergeDuplicateConversations extends Command
{
    protected $signature = 'chats:merge-duplicates
        {--tenant= : Limitar a un tenant de Converza por ID}
        {--contact= : Limitar a un contacto por ID (revisión puntual)}
        {--limit=0 : Máximo de contactos a fusionar (0 = sin tope)}
        {--dry-run : No modifica nada; solo muestra qué fusionaría}';

    protected $description = 'Une en un solo hilo los chats duplicados del mismo contacto (arrastre histórico).';

    public function handle(): int
    {
        $dryRun    = (bool) $this->option('dry-run');
        $tenantId  = $this->option('tenant') !== null ? (int) $this->option('tenant') : null;
        $contactId = $this->option('contact') !== null ? (int) $this->option('contact') : null;
        $limit     = max(0, (int) $this->option('limit'));

        $groups = DB::table('conversations')
            ->select('tenant_id', 'contact_id', DB::raw('COUNT(*) as total'))
            ->when($tenantId, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($contactId, fn ($q) => $q->where('contact_id', $contactId))
            ->groupBy('tenant_id', 'contact_id')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('contact_id')
            ->get();

        if ($groups->isEmpty()) {
            $this->info('No hay contactos con chats duplicados. Nada que hacer.');
            return self::SUCCESS;
        }

        $this->info(($dryRun ? '[dry-run] ' : '') . "Contactos con chats duplicados: {$groups->count()}");

        $merged  = 0;
        $removed = 0;

        foreach ($groups as $group) {
            if ($limit > 0 && $merged >= $limit) {
                $this->warn("Tope de --limit={$limit} alcanzado; quedan grupos sin procesar.");
                break;
            }

            $removed += $this->mergeGroup($group, $dryRun);
            $merged++;
        }

        $this->newLine();
        $this->info(($dryRun ? '[dry-run] ' : '') . "Contactos fusionados: {$merged} · chats eliminados: {$removed}");

        if ($dryRun) {
            $this->comment('Nada se modificó. Repite el comando sin --dry-run para aplicar.');
        }

        return self::SUCCESS;
    }

    /** @return int chats duplicados eliminados en este grupo */
    private function mergeGroup(object $group, bool $dryRun): int
    {
        $conversations = DB::table('conversations')
            ->where('contact_id', $group->contact_id)
            ->when($group->tenant_id === null,
                fn ($q) => $q->whereNull('tenant_id'),
                fn ($q) => $q->where('tenant_id', $group->tenant_id),
            )
            ->orderBy('id')
            ->get();

        if ($conversations->count() < 2) {
            return 0;
        }

        $canonical = $conversations->first();
        $dupIds    = $conversations->skip(1)->pluck('id')->all();
        $allIds    = $conversations->pluck('id')->all();

        $messageCount = DB::table('messages')->whereIn('conversation_id', $dupIds)->count();

        $phone = DB::table('contacts')->where('id', $group->contact_id)->value('phone');

        $this->line(sprintf(
            '%s· contacto %s (tenant %s): #%d ← %s  [%d mensajes a mover]',
            $dryRun ? '[dry-run] ' : '',
            $phone ?: ('id ' . $group->contact_id),
            $group->tenant_id ?? '—',
            $canonical->id,
            '#' . implode(', #', $dupIds),
            $messageCount,
        ));

        if ($dryRun) {
            return count($dupIds);
        }

        DB::transaction(function () use ($canonical, $dupIds, $allIds, $conversations) {
            DB::table('messages')->whereIn('conversation_id', $dupIds)
                ->update(['conversation_id' => $canonical->id]);

            DB::table('closing_notes')->whereIn('conversation_id', $dupIds)
                ->update(['conversation_id' => $canonical->id]);

            DB::table('bot_logs')->whereIn('conversation_id', $dupIds)
                ->update(['conversation_id' => $canonical->id]);

            $this->mergeReads($canonical->id, $allIds);

            DB::table('conversations')->where('id', $canonical->id)
                ->update($this->survivorAttributes($conversations));

            DB::table('conversations')->whereIn('id', $dupIds)->delete();
        });

        return count($dupIds);
    }

    /**
     * Consolida los "leídos" por agente: una fila por staff con la marca más
     * nueva del grupo. La tabla tiene único (conversation_id, staff_member_id),
     * así que no se puede reapuntar a ciegas — se reescribe el conjunto.
     */
    private function mergeReads(int $canonicalId, array $allIds): void
    {
        $reads = DB::table('conversation_reads')->whereIn('conversation_id', $allIds)->get();

        if ($reads->isEmpty()) {
            return;
        }

        $now  = now();
        $rows = $reads
            ->groupBy('staff_member_id')
            ->map(fn ($group) => [
                'tenant_id'       => $group->first()->tenant_id,
                'conversation_id' => $canonicalId,
                'staff_member_id' => $group->first()->staff_member_id,
                'last_read_at'    => $group->max('last_read_at'),
                'created_at'      => $group->min('created_at') ?: $now,
                'updated_at'      => $now,
            ])
            ->values()
            ->all();

        DB::table('conversation_reads')->whereIn('conversation_id', $allIds)->delete();
        DB::table('conversation_reads')->insert($rows);
    }

    /**
     * Qué hereda el hilo sobreviviente: el estado "más vivo" del grupo (si
     * cualquiera estaba abierto, el resultado queda abierto) y la asignación,
     * equipo y estado del bot del hilo más reciente que los tuviera.
     *
     * @param  \Illuminate\Support\Collection<int, object>  $conversations
     * @return array<string, mixed>
     */
    private function survivorAttributes($conversations): array
    {
        $statuses = $conversations->pluck('status')->all();
        $status   = in_array('open', $statuses, true)
            ? 'open'
            : (in_array('pending', $statuses, true) ? 'pending' : 'closed');

        // Del más nuevo al más viejo: gana el primer valor no nulo.
        $newestFirst = $conversations->sortByDesc('id')->values();
        $newest      = $newestFirst->first();

        $firstNotNull = fn (string $column) => $newestFirst
            ->pluck($column)
            ->first(fn ($value) => ! is_null($value));

        return [
            'status'             => $status,
            'assigned_to'        => $firstNotNull('assigned_to'),
            'team_id'            => $firstNotNull('team_id'),
            'bot_active'         => $newest->bot_active,
            'bot_step'           => $newest->bot_step,
            'bot_failed_intents' => $newest->bot_failed_intents,
            'bot_context'        => $newest->bot_context,
            // El hilo debe ordenarse en la lista por su actividad más reciente.
            'updated_at'         => $conversations->max('updated_at'),
        ];
    }
}
