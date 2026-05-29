<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\StaffMember;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function __construct(
        protected WhatsAppService $whatsappService,
        protected IspwatchRepository $ispwatch,
    ) {}

    public function index(Request $request)
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;
        $userId = $request->user()->id;

        // ── Auto-crear StaffMember para el usuario actual ────────────────────
        // Permite asignarse conversaciones sin pasar primero por la UI de Staff.
        $myStaffMember = $this->getOrCreateStaffForUser($tenantId, $userId, $request->user()->name);

        // ── Filtro de la lista de conversaciones ─────────────────────────────
        $filter = $request->query('filter', 'all'); // all | open | mine | unassigned | closed

        $conversationsQuery = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->with(['contact', 'latestMessage', 'assignee.user']);

        if ($filter === 'open') {
            $conversationsQuery->where('status', 'open');
        } elseif ($filter === 'closed') {
            $conversationsQuery->where('status', 'closed');
        } elseif ($filter === 'mine') {
            $conversationsQuery->where('assigned_to', $myStaffMember->id)->where('status', 'open');
        } elseif ($filter === 'unassigned') {
            $conversationsQuery->whereNull('assigned_to')->where('status', 'open');
        }

        $conversations = $conversationsQuery
            ->orderByDesc('updated_at')
            ->get()
            ->map(fn (Conversation $conv) => [
                'id'                  => $conv->id,
                'contact_id'          => $conv->contact_id,
                'phone'               => $conv->contact?->phone,
                'name'                => $conv->contact?->name ?: $conv->contact?->phone,
                'status'              => $conv->status,
                'last_message'        => $conv->latestMessage?->body,
                'last_message_status' => $conv->latestMessage?->status,
                'last_message_at'     => $conv->latestMessage?->created_at?->toIso8601String(),
                'updated_at'          => $conv->updated_at?->toIso8601String(),
                'assigned_to'         => $conv->assignee ? [
                    'id'      => $conv->assignee->id,
                    'name'    => $conv->assignee->user?->name ?? 'Agente',
                    'initial' => mb_substr($conv->assignee->user?->name ?? '?', 0, 1),
                ] : null,
            ]);

        // ── Conversación activa ──────────────────────────────────────────────
        $activeConversationId = $request->query('conversation');
        $activeChat = [];
        $activeConversation = null;
        $activeAssignedTo = null;

        if ($activeConversationId) {
            $activeConversation = Conversation::with(['contact', 'assignee.user'])
                ->where('tenant_id', $tenantId)
                ->find($activeConversationId);
        } elseif ($conversations->isNotEmpty()) {
            $firstId = $conversations->first()['id'];
            $activeConversation = Conversation::with(['contact', 'assignee.user'])
                ->where('tenant_id', $tenantId)
                ->find($firstId);
            $activeConversationId = $firstId;
        }

        if ($activeConversation) {
            $activeChat = Message::where('conversation_id', $activeConversation->id)
                ->where('tenant_id', $tenantId)
                ->with('sender:id,name')
                ->orderBy('created_at')
                ->get()
                ->map(fn (Message $msg) => [
                    'id'             => $msg->id,
                    'body'           => $msg->body,
                    'status'         => $msg->status,
                    'type'           => $msg->type ?? 'text',
                    'caption'        => $msg->caption,
                    'media_url'      => $msg->media_path ? route('media.serve', ['path' => $msg->media_path]) : null,
                    'media_mime'     => $msg->media_mime,
                    'media_filename' => $msg->media_filename,
                    'sender_name'    => $msg->sender?->name,
                    'created_at'     => $msg->created_at->toIso8601String(),
                ])
                ->all();

            if ($activeConversation->assignee) {
                $activeAssignedTo = [
                    'id'      => $activeConversation->assignee->id,
                    'name'    => $activeConversation->assignee->user?->name ?? 'Agente',
                    'initial' => mb_substr($activeConversation->assignee->user?->name ?? '?', 0, 1),
                ];
            }
        }

        // Resolución de ispwatch memoizada: una sola vez por petición aunque
        // la consuman dos props (customer + invoices).
        $ispwatchData = null;
        $resolveIspwatch = function () use (&$ispwatchData, $activeConversation) {
            if ($ispwatchData === null) {
                $ispwatchData = $this->resolveIspwatchData(
                    $activeConversation?->contact?->phone,
                    $activeConversation?->contact?->name,
                );
            }
            return $ispwatchData;
        };

        // Las props pesadas se entregan como closures: Inertia solo las evalúa
        // cuando se incluyen en la respuesta. En el polling cada 5s
        // (only: conversations, activeChat) NO se ejecutan estas consultas
        // —incluidas las de ispwatch a la BD externa—, solo en la carga completa.
        return Inertia::render('Chat/Index', [
            'conversations'        => $conversations,
            'activeChat'           => $activeChat,
            'activeConversationId' => $activeConversationId ? (int) $activeConversationId : null,
            'activePhone'          => $activeConversation?->contact?->phone,
            'activeName'           => $activeConversation?->contact?->name ?: $activeConversation?->contact?->phone,
            'activeStatus'         => $activeConversation?->status,
            'activeAssignedTo'     => $activeAssignedTo,
            'myStaffMemberId'      => $myStaffMember->id,
            'filter'               => $filter,

            'quickReplies'         => fn () => QuickReply::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('title')
                ->get(['id', 'title', 'body', 'shortcut', 'category']),

            'ispwatchCustomer'     => fn () => $resolveIspwatch()[0],
            'ispwatchInvoices'     => fn () => $resolveIspwatch()[1],

            'staffMembers'         => fn () => StaffMember::query()
                ->where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->with('user:id,name')
                ->orderBy('id')
                ->get()
                ->map(fn (StaffMember $s) => [
                    'id'      => $s->id,
                    'name'    => $s->user?->name ?? 'Agente',
                    'initial' => mb_substr($s->user?->name ?? '?', 0, 1),
                    'is_me'   => $s->id === $myStaffMember->id,
                ])
                ->all(),

            'filterCounts'         => fn () => $this->filterCounts($tenantId, $myStaffMember->id),
        ]);
    }

    public function sendMessage(Request $request)
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;

        $request->validate([
            'phone'           => 'required|string',
            'message'         => 'required|string|max:4096',
            'conversation_id' => [
                'nullable', 'integer',
                Rule::exists('conversations', 'id')->where('tenant_id', $tenantId),
            ],
        ]);

        $phone = Contact::normalizePhone($request->input('phone'));
        $messageContent = $request->input('message');

        $contact = Contact::firstOrCreate(
            ['phone' => $phone, 'tenant_id' => $tenantId],
            ['phone' => $phone, 'tenant_id' => $tenantId],
        );

        $conversation = null;
        if ($request->conversation_id) {
            $conversation = Conversation::where('tenant_id', $tenantId)->find($request->conversation_id);
        }
        if (! $conversation) {
            $conversation = Conversation::firstOrCreate(
                ['contact_id' => $contact->id, 'status' => 'open', 'tenant_id' => $tenantId],
                ['contact_id' => $contact->id, 'tenant_id' => $tenantId],
            );
        }

        // Reabrir si estaba cerrada — enviar un mensaje implica que retomamos la conversación
        if ($conversation->status === 'closed') {
            $conversation->update(['status' => 'open']);
        }

        $result = $this->whatsappService->sendMessage($phone, $messageContent);

        if ($result['success']) {
            Message::create([
                'tenant_id'       => $tenantId,
                'conversation_id' => $conversation->id,
                'contact_id'      => $contact->id,
                'body'            => $messageContent,
                'status'          => 'sent',
                'sent_by_user_id' => $request->user()->id,
                'wa_message_id'   => $result['data']['messages'][0]['id'] ?? null,
            ]);

            $conversation->touch();

            return back()->with('success', 'Mensaje enviado.');
        }

        return back()->withErrors(['message' => 'Error al enviar: ' . ($result['error'] ?? 'Error desconocido')]);
    }

    /**
     * Envía una imagen o audio al cliente vía WhatsApp Cloud API.
     * Funciona dentro de la ventana de 24h sin costo adicional por mensaje.
     */
    public function sendMedia(Request $request)
    {
        $tenant   = app('tenant');
        $tenantId = $tenant->id;

        $request->validate([
            'phone'           => 'required|string',
            // Imagen: jpeg/png/webp hasta 5 MB. Audio: ogg/mp3/m4a/aac hasta 16 MB.
            'file'            => 'required|file|max:16384|mimes:jpeg,jpg,png,webp,ogg,oga,mp3,m4a,aac,amr',
            'caption'         => 'nullable|string|max:1024',
            'conversation_id' => [
                'nullable', 'integer',
                Rule::exists('conversations', 'id')->where('tenant_id', $tenantId),
            ],
        ]);

        $phone     = Contact::normalizePhone($request->input('phone'));
        $uploaded  = $request->file('file');
        $mimeType  = $uploaded->getMimeType();
        $origName  = $uploaded->getClientOriginalName();
        $caption   = $request->input('caption');
        $type      = str_starts_with($mimeType, 'image/') ? 'image' : 'audio';
        $content   = file_get_contents($uploaded->getRealPath());

        $contact = Contact::firstOrCreate(
            ['phone' => $phone, 'tenant_id' => $tenantId],
            ['phone' => $phone, 'tenant_id' => $tenantId],
        );

        $conversation = null;
        if ($request->conversation_id) {
            $conversation = Conversation::where('tenant_id', $tenantId)->find($request->conversation_id);
        }
        if (! $conversation) {
            $conversation = Conversation::firstOrCreate(
                ['contact_id' => $contact->id, 'status' => 'open', 'tenant_id' => $tenantId],
                ['contact_id' => $contact->id, 'tenant_id' => $tenantId],
            );
        }
        if ($conversation->status === 'closed') {
            $conversation->update(['status' => 'open']);
        }

        // Subir el archivo a WhatsApp para obtener un media_id
        $waMediaId = $this->whatsappService->uploadMedia($content, $origName, $mimeType);

        // En modo mock (sin credenciales), generamos un ID ficticio
        if (! $waMediaId && empty(config('services.whatsapp.url'))) {
            $waMediaId = 'mock-' . Str::uuid();
        }

        if (! $waMediaId) {
            return back()->withErrors(['file' => 'Error al subir el archivo a WhatsApp.']);
        }

        $result = $this->whatsappService->sendMedia($phone, $type, $waMediaId, $caption);

        if (! $result['success']) {
            return back()->withErrors(['file' => 'Error al enviar: ' . ($result['error'] ?? 'Error desconocido')]);
        }

        // Guardar el archivo en el disco configurado (local o Supabase)
        $mediaDisk = config('filesystems.media_disk', 'public');
        $ext       = $uploaded->getClientOriginalExtension();
        $storedName = Str::uuid() . ($ext ? '.' . $ext : '');
        $path       = 'whatsapp-media/' . $storedName;

        // Intentar disco remoto (Supabase/S3) si está configurado
        if ($mediaDisk !== 'public') {
            try {
                Storage::disk($mediaDisk)->put($path, $content);
            } catch (\Throwable $e) {
                \Log::warning('ChatController sendMedia: remote disk save failed', [
                    'disk'  => $mediaDisk,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        // Siempre guardar copia local como fallback
        Storage::disk('public')->put($path, $content);

        Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'contact_id'      => $contact->id,
            'body'            => $caption ?? ($type === 'image' ? '[Imagen]' : '[Audio]'),
            'status'          => 'sent',
            'type'            => $type,
            'media_path'      => $path,
            'media_mime'      => $mimeType,
            'media_filename'  => $origName,
            'caption'         => $caption,
            'sent_by_user_id' => $request->user()->id,
            'wa_message_id'   => $result['data']['messages'][0]['id'] ?? null,
        ]);

        $conversation->touch();

        return back()->with('success', ($type === 'image' ? 'Imagen' : 'Audio') . ' enviado.');
    }

    /**
     * Asigna una conversación a un StaffMember (o desasigna si se pasa null).
     */
    public function assign(Request $request, Conversation $conversation)
    {
        $tenantId = app('tenant')->id;
        abort_if($conversation->tenant_id !== $tenantId, 403);

        $validated = $request->validate([
            'staff_member_id' => [
                'nullable', 'integer',
                Rule::exists('staff_members', 'id')->where('tenant_id', $tenantId)->where('is_active', true),
            ],
        ]);

        $newStaffId = $validated['staff_member_id'] ?? null;
        $previousStaffId = $conversation->assigned_to;

        // Sin cambios reales: no registramos nada.
        if ($newStaffId === $previousStaffId) {
            return back();
        }

        $conversation->update(['assigned_to' => $newStaffId]);

        // Registrar la transferencia como mensaje de sistema dentro del chat,
        // para que quede el rastro de quién pasó la conversación a quién.
        $actorName = $request->user()->name;
        $previousName = $previousStaffId
            ? StaffMember::with('user:id,name')->find($previousStaffId)?->user?->name
            : null;
        $newName = $newStaffId
            ? StaffMember::with('user:id,name')->find($newStaffId)?->user?->name
            : null;

        if ($newStaffId === null) {
            $body = "🔄 {$actorName} quitó la asignación de " . ($previousName ?? 'la conversación') . '.';
        } elseif ($previousName) {
            $body = "🔄 {$actorName} transfirió la conversación de {$previousName} a {$newName}.";
        } else {
            $body = "🔄 {$actorName} asignó la conversación a {$newName}.";
        }

        Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'contact_id'      => $conversation->contact_id,
            'body'            => $body,
            'status'          => 'sent',
            'type'            => 'system',
            'sent_by_user_id' => $request->user()->id,
        ]);

        $conversation->touch();

        return back()->with('success', $newStaffId ? 'Conversación asignada.' : 'Asignación quitada.');
    }

    /**
     * Elimina una conversación y todos sus mensajes.
     */
    public function destroy(Conversation $conversation)
    {
        $tenantId = app('tenant')->id;
        abort_if($conversation->tenant_id !== $tenantId, 403);

        Message::where('conversation_id', $conversation->id)->delete();
        $conversation->delete();

        return redirect()->route('chat.index')->with('success', 'Conversación eliminada.');
    }

    /**
     * Reabre una conversación cerrada (solo cambia status, no toca closing notes).
     */
    public function reopen(Conversation $conversation)
    {
        $tenantId = app('tenant')->id;
        abort_if($conversation->tenant_id !== $tenantId, 403);

        $conversation->update(['status' => 'open']);

        return back()->with('success', 'Conversación reabierta.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function resolveIspwatchData(?string $phone, ?string $contactName): array
    {
        if (! app()->bound('tenant') || ! $phone) {
            return [null, []];
        }

        $tenant = app('tenant');
        if (! $tenant->ispwatch_tenant_id) {
            return [null, []];
        }

        $customer = $this->ispwatch->customerByPhone(
            (int) $tenant->ispwatch_tenant_id,
            $phone,
            $contactName,
        );
        if (! $customer) {
            return [null, []];
        }

        $invoices = $this->ispwatch->pendingInvoicesForCustomer(
            (int) $tenant->ispwatch_tenant_id,
            (int) $customer['user_id'],
        );

        return [$customer, $invoices];
    }

    /**
     * Devuelve el StaffMember del user actual en este tenant, creándolo
     * si no existe. Así el agente puede asignarse conversaciones sin
     * pasar primero por la UI de Staff.
     */
    private function getOrCreateStaffForUser(int $tenantId, int $userId, ?string $userName): StaffMember
    {
        return StaffMember::firstOrCreate(
            ['tenant_id' => $tenantId, 'user_id' => $userId],
            ['tenant_id' => $tenantId, 'user_id' => $userId, 'role' => 'agent', 'is_active' => true],
        );
    }

    /**
     * Conteos por filtro para mostrar en los chips de la lista.
     *
     * @return array<string, int>
     */
    private function filterCounts(int $tenantId, int $myStaffMemberId): array
    {
        // Una sola consulta con agregación condicional en lugar de 5 COUNT
        // separados (clave cuando la BD es remota: 1 ida/vuelta en vez de 5).
        $row = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->selectRaw('COUNT(*) AS all_count')
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'open') AS open_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'open' AND assigned_to = ?) AS mine_count", [$myStaffMemberId])
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'open' AND assigned_to IS NULL) AS unassigned_count")
            ->selectRaw("COUNT(*) FILTER (WHERE status = 'closed') AS closed_count")
            ->first();

        return [
            'all'        => (int) $row->all_count,
            'open'       => (int) $row->open_count,
            'mine'       => (int) $row->mine_count,
            'unassigned' => (int) $row->unassigned_count,
            'closed'     => (int) $row->closed_count,
        ];
    }
}
