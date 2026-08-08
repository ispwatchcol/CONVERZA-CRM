<?php

namespace App\Http\Controllers;

use App\Models\ClosingNote;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Message;
use App\Models\User;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ClosingNoteController extends Controller
{
    public function __construct(
        protected IspwatchRepository $ispwatch,
        protected WhatsAppService $whatsappService,
    ) {}

    public function index(Request $request)
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;

        // ── Filtros ──────────────────────────────────────────────────────────
        $search   = trim((string) $request->query('search', ''));
        $agentId  = $request->query('agent_id');
        $range    = $request->query('range', 'all'); // all | 7 | 30 | 90

        $query = ClosingNote::query()
            ->where('tenant_id', $tenantId)
            ->with(['conversation.contact', 'user']);

        if ($search !== '') {
            $query->where('note', 'like', "%{$search}%");
        }

        if ($agentId) {
            $query->where('user_id', $agentId);
        }

        if (in_array($range, ['7', '30', '90'], true)) {
            $query->where('created_at', '>=', now()->subDays((int) $range));
        }

        $notes = $query->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        $ispwatchTenantId = $tenant->ispwatch_tenant_id ? (int) $tenant->ispwatch_tenant_id : null;

        $notes->getCollection()->transform(function (ClosingNote $note) use ($ispwatchTenantId) {
            $contact = $note->conversation?->contact;
            $contactName = $contact?->name ?: $contact?->phone;

            // Enriquecimiento ISPWatch (cacheado en el repo, no martillea)
            $ispwatchCustomer = null;
            if ($ispwatchTenantId && $contact?->phone) {
                $cust = $this->ispwatch->customerByPhone($ispwatchTenantId, $contact->phone, $contact->name);
                if ($cust) {
                    $ispwatchCustomer = [
                        'name'           => $cust['name'],
                        'service_status' => $cust['service_status'],
                        'pppoe_username' => $cust['pppoe_username'],
                    ];
                }
            }

            return [
                'id'                  => $note->id,
                'note'                => $note->note,
                'conversation_id'     => $note->conversation_id,
                'conversation_status' => $note->conversation?->status,
                'contact_name'        => $contactName,
                'contact_phone'       => $contact?->phone,
                'user_id'             => $note->user_id,
                'user_name'           => $note->user?->name ?? '—',
                'created_at'          => $note->created_at?->toIso8601String(),
                'updated_at'          => $note->updated_at?->toIso8601String(),
                'was_edited'          => $note->updated_at && $note->updated_at->gt($note->created_at->addSeconds(2)),
                'ispwatch_customer'   => $ispwatchCustomer,
            ];
        });

        return Inertia::render('ClosingNotes/Index', [
            'notes'        => $notes,
            'filters'      => ['search' => $search, 'agent_id' => $agentId, 'range' => $range],
            'agents'       => $this->agentsForTenant($tenantId),
            'openConversations' => $this->openConversationsForTenant($tenantId),
            'stats'        => $this->stats($tenantId),
            'currentUserId' => $request->user()?->id,
        ]);
    }

    public function store(Request $request)
    {
        $tenant = app('tenant');

        $validated = $request->validate([
            'conversation_id'    => [
                'required',
                Rule::exists('conversations', 'id')->where('tenant_id', $tenant->id),
            ],
            'note'               => ['required', 'string', 'min:3', 'max:2000'],
            'close_conversation' => ['nullable', 'boolean'],
        ]);

        // Solo el asesor asignado puede cerrar el chat. Los admins siempre pueden.
        if ($validated['close_conversation'] ?? true) {
            $conversation = Conversation::where('id', $validated['conversation_id'])
                ->where('tenant_id', $tenant->id)
                ->first();

            if ($conversation) {
                $user    = $request->user();
                $isAdmin = $user->staffRole() === 'admin';

                if (! $isAdmin) {
                    $staffMember = $user->staffMember;
                    $isAssigned  = $staffMember && $conversation->assigned_to === $staffMember->id;
                    abort_if(! $isAssigned, 403, 'Solo el asesor asignado puede cerrar este chat.');
                }
            }
        }

        ClosingNote::create([
            'tenant_id'       => $tenant->id,
            'conversation_id' => $validated['conversation_id'],
            'user_id'         => $request->user()->id,
            'note'            => $validated['note'],
        ]);

        if ($validated['close_conversation'] ?? true) {
            Conversation::where('id', $validated['conversation_id'])
                ->where('tenant_id', $tenant->id)
                ->update(['status' => 'closed']);

            // Enviar mensaje de cierre al cliente solo si la conversación estaba abierta.
            // Si ya estaba cerrada (doble cierre), $conversation->status !== 'open' y no se envía.
            // El try-catch protege el cierre: si WA falla, la nota y el cambio de status ya están
            // persistidos — no revertimos por eso.
            if (isset($conversation) && $conversation->status === 'open') {
                $conversation->loadMissing('contact');
                $phone = $conversation->contact?->phone;

                if ($phone) {
                    try {
                        $closeMsg = 'La conversación ha finalizado. Gracias por comunicarse con nosotros.';
                        $waResult = $this->whatsappService->sendMessage($phone, $closeMsg);

                        Message::create([
                            'tenant_id'       => $tenant->id,
                            'conversation_id' => $conversation->id,
                            'contact_id'      => $conversation->contact_id,
                            'body'            => $closeMsg,
                            'status'          => 'sent',
                            'sent_by_user_id' => $request->user()->id,
                            'wa_message_id'   => $waResult['data']['messages'][0]['id'] ?? null,
                        ]);

                        // Cerrar con nota es atenderla: nadie del equipo debería
                        // seguir viéndola en verde como si estuviera pendiente.
                        ConversationRead::markAnsweredForTeam($tenant->id, $conversation->id);
                    } catch (\Throwable $e) {
                        \Log::warning('ClosingNoteController@store: WA closing message failed', [
                            'conversation_id' => $conversation->id,
                            'phone'           => $phone,
                            'error'           => $e->getMessage(),
                        ]);
                    }
                }
            }
        }

        return back()->with('success', 'Nota de cierre guardada.');
    }

    public function update(Request $request, ClosingNote $closingNote)
    {
        $this->authorizeTenantOwnership($closingNote);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:3', 'max:2000'],
        ]);

        $closingNote->update($validated);

        return back()->with('success', 'Nota actualizada.');
    }

    public function destroy(ClosingNote $closingNote)
    {
        $this->authorizeTenantOwnership($closingNote);
        $closingNote->delete();

        return back()->with('success', 'Nota eliminada.');
    }

    /**
     * Reabre la conversación asociada a la nota (la nota queda como histórico).
     */
    public function reopen(ClosingNote $closingNote)
    {
        $this->authorizeTenantOwnership($closingNote);

        if ($closingNote->conversation) {
            $closingNote->conversation->update(['status' => 'open']);
        }

        return back()->with('success', 'Conversación reabierta.');
    }

    // ─────────────────────────────────────────────────────────────────────────
    // Helpers
    // ─────────────────────────────────────────────────────────────────────────

    private function authorizeTenantOwnership(ClosingNote $note): void
    {
        $tenantId = app('tenant')->id;
        abort_if($note->tenant_id !== $tenantId, 403, 'No autorizado para este tenant.');
    }

    /**
     * Lista de agentes (users) que TIENEN al menos 1 nota en este tenant.
     * Útil para el dropdown del filtro.
     *
     * @return array<int, array{id: int, name: string}>
     */
    private function agentsForTenant(int $tenantId): array
    {
        $userIds = ClosingNote::where('tenant_id', $tenantId)
            ->distinct()
            ->pluck('user_id')
            ->all();

        if (empty($userIds)) {
            return [];
        }

        return User::whereIn('id', $userIds)
            ->orderBy('name')
            ->get(['id', 'name'])
            ->map(fn ($u) => ['id' => (int) $u->id, 'name' => $u->name])
            ->all();
    }

    /**
     * Conversaciones abiertas del tenant — para el picker del modal "Nueva nota".
     *
     * @return array<int, array{id: int, contact_name: string, contact_phone: string}>
     */
    private function openConversationsForTenant(int $tenantId): array
    {
        return Conversation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->with('contact:id,name,phone')
            ->orderByDesc('updated_at')
            ->limit(100)
            ->get()
            ->map(fn ($conv) => [
                'id'            => (int) $conv->id,
                'contact_name'  => $conv->contact?->name ?: $conv->contact?->phone ?: 'Sin contacto',
                'contact_phone' => $conv->contact?->phone ?? '',
            ])
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId): array
    {
        $startOfMonth = Carbon::now()->startOfMonth();
        $currentUserId = request()->user()?->id;

        return [
            'total'         => ClosingNote::where('tenant_id', $tenantId)->count(),
            'this_month'    => ClosingNote::where('tenant_id', $tenantId)
                ->where('created_at', '>=', $startOfMonth)
                ->count(),
            'my_notes'      => $currentUserId
                ? ClosingNote::where('tenant_id', $tenantId)->where('user_id', $currentUserId)->count()
                : 0,
            // Conversaciones "reabiertas" = tienen al menos 1 nota Y están en estado 'open'
            'reopened'      => ClosingNote::where('tenant_id', $tenantId)
                ->whereHas('conversation', fn ($q) => $q->where('status', 'open'))
                ->distinct('conversation_id')
                ->count('conversation_id'),
        ];
    }
}
