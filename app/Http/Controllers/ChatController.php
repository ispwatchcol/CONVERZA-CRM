<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\QuickReply;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ChatController extends Controller
{
    public function __construct(
        protected WhatsAppService $whatsappService,
        protected IspwatchRepository $ispwatch,
    ) {}

    private function normalizePhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10 && str_starts_with($phone, '3')) {
            $phone = '57' . $phone;
        }
        return $phone;
    }

    public function index(Request $request)
    {
        $conversations = Conversation::with(['contact', 'latestMessage'])
            ->orderBy('updated_at', 'desc')
            ->get()
            ->map(fn($conv) => [
                'id' => $conv->id,
                'contact_id' => $conv->contact_id,
                'phone' => $conv->contact->phone,
                'name' => $conv->contact->name ?: $conv->contact->phone,
                'status' => $conv->status,
                'last_message' => $conv->latestMessage?->body,
                'last_message_status' => $conv->latestMessage?->status,
                'last_message_at' => $conv->latestMessage?->created_at?->toIso8601String(),
                'updated_at' => $conv->updated_at->toIso8601String(),
            ]);

        $activeConversationId = $request->query('conversation');
        $activeChat = [];
        $activeConversation = null;

        if ($activeConversationId) {
            $activeConversation = Conversation::with('contact')->find($activeConversationId);
            if ($activeConversation) {
                $activeChat = Message::where('conversation_id', $activeConversationId)
                    ->orderBy('created_at', 'asc')
                    ->get()
                    ->map(fn($msg) => [
                        'id' => $msg->id,
                        'body' => $msg->body,
                        'status' => $msg->status,
                        'type' => $msg->type ?? 'text',
                        'caption' => $msg->caption,
                        'media_url' => $msg->media_path ? route('media.serve', ['path' => $msg->media_path]) : null,
                        'media_mime' => $msg->media_mime,
                        'media_filename' => $msg->media_filename,
                        'created_at' => $msg->created_at->toIso8601String(),
                    ]);
            }
        } elseif ($conversations->isNotEmpty()) {
            $firstConv = $conversations->first();
            $activeConversationId = $firstConv['id'];
            $activeConversation = Conversation::with('contact')->find($activeConversationId);
            $activeChat = Message::where('conversation_id', $activeConversationId)
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($msg) => [
                    'id'             => $msg->id,
                    'body'           => $msg->body,
                    'status'         => $msg->status,
                    'type'           => $msg->type ?? 'text',
                    'caption'        => $msg->caption,
                    'media_url'      => $msg->media_path ? asset('storage/' . $msg->media_path) : null,
                    'media_mime'     => $msg->media_mime,
                    'media_filename' => $msg->media_filename,
                    'created_at'     => $msg->created_at->toIso8601String(),
                ]);
        }

        $quickReplies = QuickReply::where('is_active', true)->get(['id', 'title', 'body', 'shortcut']);

        [$ispwatchCustomer, $ispwatchInvoices] = $this->resolveIspwatchData(
            $activeConversation?->contact?->phone,
            $activeConversation?->contact?->name,
        );

        return Inertia::render('Chat/Index', [
            'conversations' => $conversations,
            'activeChat' => $activeChat,
            'activeConversationId' => $activeConversationId ? (int) $activeConversationId : null,
            'activePhone' => $activeConversation?->contact?->phone,
            'activeName' => $activeConversation?->contact?->name ?: $activeConversation?->contact?->phone,
            'activeStatus' => $activeConversation?->status,
            'quickReplies' => $quickReplies,
            'ispwatchCustomer' => $ispwatchCustomer,
            'ispwatchInvoices' => $ispwatchInvoices,
        ]);
    }

    /**
     * Resuelve [customer, invoices] desde ispwatch para el teléfono dado.
     * Devuelve [null, []] si no hay tenant activo, no está linkeado a ispwatch,
     * no hay teléfono o no hay match en ispwatch.
     *
     * $contactName se pasa al repo como tie-break cuando varios clientes en
     * ispwatch comparten el mismo teléfono (caso real: familiares).
     *
     * @return array{0: array<string, mixed>|null, 1: array<int, array<string, mixed>>}
     */
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

    public function sendMessage(Request $request)
    {
        $request->validate([
            'phone' => 'required|string',
            'message' => 'required|string',
            'conversation_id' => 'nullable|integer|exists:conversations,id',
        ]);

        $phone = $this->normalizePhone($request->input('phone'));
        $messageContent = $request->input('message');

        // Find or create contact
        $contact = Contact::firstOrCreate(['phone' => $phone]);

        // Find or create conversation
        $conversation = null;
        if ($request->conversation_id) {
            $conversation = Conversation::find($request->conversation_id);
        }
        if (!$conversation) {
            $conversation = Conversation::firstOrCreate(
                ['contact_id' => $contact->id, 'status' => 'open'],
                ['contact_id' => $contact->id]
            );
        }

        // Send via API
        $result = $this->whatsappService->sendMessage($phone, $messageContent);

        if ($result['success']) {
            Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'body' => $messageContent,
                'status' => 'sent',
                'wa_message_id' => $result['data']['messages'][0]['id'] ?? null,
            ]);

            $conversation->touch();

            return back()->with('success', 'Mensaje enviado correctamente.');
        }

        return back()->withErrors(['message' => 'Error al enviar: ' . ($result['error'] ?? 'Error desconocido')]);
    }
}
