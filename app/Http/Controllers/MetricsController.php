<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class MetricsController extends Controller
{
    /**
     * Todas las queries están scopeadas por tenant de DOS formas:
     *   1. Filtro explícito `->where('tenant_id', $tenantId)` (defensa en profundidad).
     *   2. Global scope del trait BelongsToTenant en cada modelo (lo aplica
     *      automáticamente el middleware ResolveTenant). El where explícito
     *      no rompe nada — es idempotente.
     */
    public function index()
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;

        return Inertia::render('Metrics/Index', [
            'tenant' => [
                'name' => $tenant->name,
            ],
            'stats'          => $this->stats($tenantId),
            'responseTime'   => $this->responseTime($tenantId, 30),
            'messagesChart'  => $this->messagesByDay($tenantId, 30),
            'contactsGrowth' => $this->contactsByWeek($tenantId, 12),
            'messagesByHour' => $this->messagesByHour($tenantId, 30),
            'messagesByType' => $this->messagesByType($tenantId, 30),
            'topContacts'    => $this->topContacts($tenantId, 30, 5),
        ]);
    }

    /**
     * Tiempo de respuesta del staff: por cada mensaje 'received' busca el
     * siguiente 'sent' dentro de la misma conversación y mide el delta.
     *
     * Procesamiento en PHP para portabilidad pgsql ↔ mysql (en local es
     * Postgres, en prod MySQL). Es liviano: incluso con 50k mensajes son
     * pocos ms. Si el dataset crece mucho, reemplazar por window function.
     *
     * Excluye:
     *   - Mensajes recibidos que aún no tienen respuesta (no contabilizan).
     *   - Deltas > 7 días (probablemente conversación abandonada y reabierta;
     *     skewearían el promedio).
     *
     * @return array{avg_seconds: int|null, sample_size: int, fastest_seconds: int|null, slowest_seconds: int|null, responded_rate: float|null, total_received: int}
     */
    private function responseTime(int $tenantId, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();
        $maxDeltaSeconds = 7 * 24 * 60 * 60; // 7 días

        $messages = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->whereIn('status', ['received', 'sent'])
            ->orderBy('conversation_id')
            ->orderBy('created_at')
            ->get(['conversation_id', 'status', 'created_at']);

        $deltas = [];
        $totalReceived = 0;
        $respondedReceived = 0;

        foreach ($messages->groupBy('conversation_id') as $convMessages) {
            $msgs = $convMessages->values();
            $count = $msgs->count();

            for ($i = 0; $i < $count; $i++) {
                if ($msgs[$i]->status !== 'received') {
                    continue;
                }
                $totalReceived++;

                // Buscar el siguiente mensaje 'sent' en esta misma conversación
                for ($j = $i + 1; $j < $count; $j++) {
                    if ($msgs[$j]->status === 'sent') {
                        $delta = $msgs[$j]->created_at->timestamp - $msgs[$i]->created_at->timestamp;
                        if ($delta >= 0 && $delta <= $maxDeltaSeconds) {
                            $deltas[] = $delta;
                            $respondedReceived++;
                        }
                        break;
                    }
                }
            }
        }

        $sampleSize = count($deltas);
        if ($sampleSize === 0) {
            return [
                'avg_seconds'     => null,
                'sample_size'     => 0,
                'fastest_seconds' => null,
                'slowest_seconds' => null,
                'responded_rate'  => $totalReceived > 0 ? 0.0 : null,
                'total_received'  => $totalReceived,
            ];
        }

        return [
            'avg_seconds'     => (int) round(array_sum($deltas) / $sampleSize),
            'sample_size'     => $sampleSize,
            'fastest_seconds' => (int) min($deltas),
            'slowest_seconds' => (int) max($deltas),
            'responded_rate'  => $totalReceived > 0 ? round(($respondedReceived / $totalReceived) * 100, 1) : null,
            'total_received'  => $totalReceived,
        ];
    }

    /**
     * @return array<string, int|float>
     */
    private function stats(int $tenantId): array
    {
        $startOfWeek = now()->startOfWeek();
        $startOfDay  = now()->startOfDay();

        // Una sola query para los conteos por status de conversation.
        $convoStatus = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw("status, count(*) as n")
            ->groupBy('status')
            ->pluck('n', 'status')
            ->all();

        $totalContacts      = Contact::where('tenant_id', $tenantId)->count();
        $totalConversations = array_sum($convoStatus);
        $totalMessages      = Message::where('tenant_id', $tenantId)->count();

        return [
            'totalContacts'              => $totalContacts,
            'totalConversations'         => $totalConversations,
            'totalMessages'              => $totalMessages,
            'openConversations'          => (int) ($convoStatus['open'] ?? 0),
            'closedConversations'        => (int) ($convoStatus['closed'] ?? 0),
            'avgMessagesPerConversation' => $totalConversations > 0
                ? round($totalMessages / $totalConversations, 1)
                : 0,
            'messagesToday'      => Message::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $startOfDay)
                ->count(),
            'contactsThisWeek'   => Contact::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $startOfWeek)
                ->count(),
        ];
    }

    /**
     * Mensajes por día de los últimos N días, separados por status.
     * UNA sola query agregada en vez de 2*N counts individuales.
     *
     * @return array<int, array{date: string, label: string, sent: int, received: int}>
     */
    private function messagesByDay(int $tenantId, int $days): array
    {
        $from = now()->subDays($days - 1)->startOfDay();

        $rows = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as date, status, count(*) as n')
            ->groupBy('date', 'status')
            ->get()
            ->groupBy('date')
            ->map(fn ($group) => [
                'sent'     => (int) ($group->firstWhere('status', 'sent')->n ?? 0),
                'received' => (int) ($group->firstWhere('status', 'received')->n ?? 0),
            ]);

        // Rellenar días sin actividad con 0 para que la gráfica no tenga huecos.
        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $key = $d->format('Y-m-d');
            $counts = $rows[$key] ?? ['sent' => 0, 'received' => 0];
            $out[] = [
                'date'     => $key,
                'label'    => $d->isoFormat('D MMM'),
                'sent'     => $counts['sent'],
                'received' => $counts['received'],
            ];
        }
        return $out;
    }

    /**
     * Nuevos contactos por semana de las últimas N semanas.
     *
     * @return array<int, array{week: string, label: string, count: int}>
     */
    private function contactsByWeek(int $tenantId, int $weeks): array
    {
        $from = now()->subWeeks($weeks - 1)->startOfWeek();

        // Postgres: DATE_TRUNC('week', ...) devuelve el lunes de cada semana.
        $rows = Contact::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->selectRaw("DATE_TRUNC('week', created_at) as week_start, count(*) as n")
            ->groupBy('week_start')
            ->get()
            ->mapWithKeys(fn ($r) => [Carbon::parse($r->week_start)->format('Y-m-d') => (int) $r->n])
            ->all();

        $out = [];
        for ($i = $weeks - 1; $i >= 0; $i--) {
            $w = now()->subWeeks($i)->startOfWeek();
            $key = $w->format('Y-m-d');
            $out[] = [
                'week'  => $key,
                'label' => $w->isoFormat('D MMM'),
                'count' => $rows[$key] ?? 0,
            ];
        }
        return $out;
    }

    /**
     * Distribución por hora del día (0-23) de los últimos N días.
     * Útil para planear staffing y horarios de atención.
     *
     * @return array<int, array{hour: int, count: int}>
     */
    private function messagesByHour(int $tenantId, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();

        $rows = Message::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->selectRaw('EXTRACT(HOUR FROM created_at)::int as hour, count(*) as n')
            ->groupBy('hour')
            ->pluck('n', 'hour')
            ->all();

        $out = [];
        for ($h = 0; $h < 24; $h++) {
            $out[] = ['hour' => $h, 'count' => (int) ($rows[$h] ?? 0)];
        }
        return $out;
    }

    /**
     * Distribución por tipo de mensaje (text, image, audio, ...) en los últimos N días.
     *
     * @return array<int, array{type: string, count: int}>
     */
    private function messagesByType(int $tenantId, int $days): array
    {
        $from = now()->subDays($days)->startOfDay();

        return Message::query()
            ->where('tenant_id', $tenantId)
            ->where('created_at', '>=', $from)
            ->selectRaw("COALESCE(type, 'text') as type, count(*) as n")
            ->groupBy('type')
            ->orderByDesc('n')
            ->get()
            ->map(fn ($r) => ['type' => $r->type, 'count' => (int) $r->n])
            ->all();
    }

    /**
     * Top N contactos por número de mensajes en los últimos D días.
     *
     * @return array<int, array{name: string, phone: string, message_count: int}>
     */
    private function topContacts(int $tenantId, int $days, int $limit): array
    {
        $from = now()->subDays($days)->startOfDay();

        return Contact::query()
            ->where('contacts.tenant_id', $tenantId)
            ->join('messages', 'messages.contact_id', '=', 'contacts.id')
            ->where('messages.tenant_id', $tenantId)
            ->where('messages.created_at', '>=', $from)
            ->selectRaw('contacts.id, contacts.name, contacts.phone, count(messages.id) as message_count')
            ->groupBy('contacts.id', 'contacts.name', 'contacts.phone')
            ->orderByDesc('message_count')
            ->limit($limit)
            ->get()
            ->map(fn ($c) => [
                'name'          => $c->name ?: $c->phone,
                'phone'         => $c->phone,
                'message_count' => (int) $c->message_count,
            ])
            ->all();
    }
}
