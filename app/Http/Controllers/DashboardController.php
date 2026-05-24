<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;

class DashboardController extends Controller
{
    public function __construct(protected IspwatchRepository $ispwatch) {}

    public function index()
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;
        $ispwatchTenantId = $tenant->ispwatch_tenant_id ? (int) $tenant->ispwatch_tenant_id : null;

        return Inertia::render('Dashboard', [
            'user' => [
                'name' => request()->user()?->name,
            ],
            'tenant' => [
                'name' => $tenant->name,
            ],
            'stats'              => $this->stats($tenantId, $ispwatchTenantId),
            'messagesChart'      => $this->messagesByDay($tenantId, 7),
            'needsAttention'     => $this->conversationsNeedingAttention($tenantId, $ispwatchTenantId),
            'recentConversations'=> $this->recentConversations($tenantId, $ispwatchTenantId),
            'health'             => $this->systemHealth($tenant, $ispwatchTenantId),
        ]);
    }

    /**
     * @return array<string, int|float>
     */
    private function stats(int $tenantId, ?int $ispwatchTenantId): array
    {
        $convoStatus = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw("status, count(*) as n")
            ->groupBy('status')
            ->pluck('n', 'status');

        // Cuentas pendientes en ISPWatch: solo se calcula si hay link
        $overdueAmount = 0;
        $overdueInvoices = 0;
        if ($ispwatchTenantId !== null) {
            $row = DB::connection('ispwatch')
                ->table('invoices')
                ->where('tenant_id', $ispwatchTenantId)
                ->where('balance_due', '>', 0)
                ->selectRaw('COALESCE(SUM(balance_due), 0) as total, COUNT(*) as cnt')
                ->first();
            if ($row) {
                $overdueAmount = (float) $row->total;
                $overdueInvoices = (int) $row->cnt;
            }
        }

        return [
            'totalContacts'       => Contact::where('tenant_id', $tenantId)->count(),
            'openConversations'   => (int) ($convoStatus['open'] ?? 0),
            'closedConversations' => (int) ($convoStatus['closed'] ?? 0),
            'messagesToday'       => Message::where('tenant_id', $tenantId)
                ->whereDate('created_at', today())
                ->count(),
            'overdueAmount'       => $overdueAmount,
            'overdueInvoices'     => $overdueInvoices,
        ];
    }

    /**
     * Mensajes por día — última semana, en UNA sola query.
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
            ->map(fn ($g) => [
                'sent'     => (int) ($g->firstWhere('status', 'sent')->n ?? 0),
                'received' => (int) ($g->firstWhere('status', 'received')->n ?? 0),
            ]);

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = now()->subDays($i);
            $key = $d->format('Y-m-d');
            $c = $rows[$key] ?? ['sent' => 0, 'received' => 0];
            $out[] = [
                'date'     => $key,
                'label'    => $d->isoFormat('ddd'), // Lun, Mar, ...
                'sent'     => $c['sent'],
                'received' => $c['received'],
            ];
        }
        return $out;
    }

    /**
     * Conversaciones abiertas donde el último mensaje es `received` (cliente
     * escribió y nadie le ha contestado) y tiene más de 30 min sin respuesta.
     * Ordenadas por la más vieja sin atender primero.
     *
     * @return array<int, array<string, mixed>>
     */
    private function conversationsNeedingAttention(int $tenantId, ?int $ispwatchTenantId): array
    {
        $threshold = now()->subMinutes(30);

        $convs = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->with(['contact', 'latestMessage'])
            ->get()
            ->filter(fn ($c) =>
                $c->latestMessage
                && $c->latestMessage->status === 'received'
                && $c->latestMessage->created_at->lt($threshold)
            )
            ->sortBy(fn ($c) => $c->latestMessage->created_at)
            ->take(5)
            ->values();

        return $convs->map(function (Conversation $c) use ($ispwatchTenantId) {
            $ispwatchCustomer = $this->maybeIspwatchCustomer($ispwatchTenantId, $c->contact);
            $waitingMinutes = (int) now()->diffInMinutes($c->latestMessage->created_at, true);

            return [
                'id'                  => $c->id,
                'contact_name'        => $c->contact?->name ?: $c->contact?->phone,
                'contact_phone'       => $c->contact?->phone,
                'last_message'        => $c->latestMessage?->body,
                'last_message_type'   => $c->latestMessage?->type,
                'waiting_minutes'     => $waitingMinutes,
                'is_ispwatch_customer'=> $ispwatchCustomer !== null,
                'service_status'      => $ispwatchCustomer['service_status'] ?? null,
            ];
        })->all();
    }

    /**
     * Conversaciones recientes — top 8 por última actividad, con info ISPWatch.
     *
     * @return array<int, array<string, mixed>>
     */
    private function recentConversations(int $tenantId, ?int $ispwatchTenantId): array
    {
        return Conversation::query()
            ->where('tenant_id', $tenantId)
            ->with(['contact', 'latestMessage'])
            ->orderByDesc('updated_at')
            ->limit(8)
            ->get()
            ->map(function (Conversation $c) use ($ispwatchTenantId) {
                $ispwatchCustomer = $this->maybeIspwatchCustomer($ispwatchTenantId, $c->contact);
                return [
                    'id'                   => $c->id,
                    'contact_name'         => $c->contact?->name ?: $c->contact?->phone,
                    'contact_phone'        => $c->contact?->phone,
                    'status'               => $c->status,
                    'last_message'         => $c->latestMessage?->body,
                    'last_message_status'  => $c->latestMessage?->status,
                    'last_message_type'    => $c->latestMessage?->type,
                    'last_message_at'      => $c->latestMessage?->created_at?->toIso8601String(),
                    'is_ispwatch_customer' => $ispwatchCustomer !== null,
                ];
            })
            ->all();
    }

    /**
     * Health check de las integraciones externas.
     *
     * @return array<string, mixed>
     */
    private function systemHealth($tenant, ?int $ispwatchTenantId): array
    {
        $waConfigured = filled($tenant->wa_phone_number_id) && filled($tenant->getRawOriginal('wa_access_token'));

        $ispwatchConnected = false;
        $ispwatchName = null;
        if ($ispwatchTenantId !== null) {
            $info = $this->ispwatch->tenantInfo($ispwatchTenantId);
            if ($info) {
                $ispwatchConnected = true;
                $ispwatchName = $info['name'];
            }
        }

        return [
            'whatsapp_configured' => $waConfigured,
            'whatsapp_status'     => $tenant->wa_status,
            'ispwatch_linked'     => $ispwatchTenantId !== null,
            'ispwatch_connected'  => $ispwatchConnected,
            'ispwatch_name'       => $ispwatchName,
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function maybeIspwatchCustomer(?int $ispwatchTenantId, $contact): ?array
    {
        if (! $ispwatchTenantId || ! $contact?->phone) {
            return null;
        }
        return $this->ispwatch->customerByPhone($ispwatchTenantId, $contact->phone, $contact->name);
    }
}
