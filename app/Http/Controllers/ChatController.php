<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Label;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\StaffMember;
use App\Models\Template;
use App\Models\Tenant;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\Notifications\EventCatalog;
use App\Services\Presence\PresenceService;
use App\Services\Templates\TemplateRenderer;
use App\Services\WhatsAppService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ChatController extends Controller
{
    /**
     * Cuántas conversaciones se mandan al navegador de entrada.
     *
     * La lista se cargaba COMPLETA: en el tenant más grande son ~820 filas, y
     * como `conversations` está en el `only` del poll, el servidor las volvía a
     * armar —con el cruce de nombres de ispwatch— y el navegador a re-renderizar
     * las 820 cada 5 s. Con la lista así de pesada, un clic que caía sobre un
     * re-render se perdía, y el asesor tenía que insistir para cambiar de chat.
     * Ver CON-73.
     */
    private const CONVERSATIONS_PER_PAGE = 50;

    public function __construct(
        protected WhatsAppService $whatsappService,
        protected IspwatchRepository $ispwatch,
        protected PresenceService $presence,
    ) {}

    public function index(Request $request)
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;
        $userId = $request->user()->id;

        // ── Auto-crear StaffMember para el usuario actual ────────────────────
        // Permite asignarse conversaciones sin pasar primero por la UI de Staff.
        $myStaffMember = $this->getOrCreateStaffForUser($tenantId, $userId, $request->user()->name);

        // ── Restricción de visibilidad por rol ───────────────────────────────
        // Los agentes solo ven conversaciones asignadas a ellos. Los admins ven todo.
        // El check es en backend para que no se pueda eludir manipulando el query string.
        $userRole = $request->user()->staffRole();
        $isAgent  = $userRole === 'agent';

        // ── Filtro de la lista de conversaciones ─────────────────────────────
        $filter = $request->query('filter', $isAgent ? 'mine' : 'all');

        $conversationsQuery = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->with(['contact', 'latestMessage', 'assignee.user']);

        if ($isAgent) {
            // Agentes: siempre solo sus conversaciones asignadas, cualquier filtro que pidan.
            $conversationsQuery->where('assigned_to', $myStaffMember->id);
            if ($filter === 'closed') {
                $conversationsQuery->where('status', 'closed');
            } elseif ($filter === 'unread') {
                $conversationsQuery->where('status', 'open')
                    ->whereIn('id', fn (QueryBuilder $q) => $this->applyUnreadJoin($q, $tenantId, $myStaffMember->id)->select('m.conversation_id'));
            } else {
                $conversationsQuery->where('status', 'open');
            }
        } else {
            // Admin: filtro completo
            if ($filter === 'open') {
                $conversationsQuery->where('status', 'open');
            } elseif ($filter === 'closed') {
                $conversationsQuery->where('status', 'closed');
            } elseif ($filter === 'mine') {
                $conversationsQuery->where('assigned_to', $myStaffMember->id)->where('status', 'open');
            } elseif ($filter === 'unassigned') {
                $conversationsQuery->whereNull('assigned_to')->where('status', 'open');
            } elseif ($filter === 'unread') {
                $conversationsQuery->where('status', 'open')
                    ->whereIn('id', fn (QueryBuilder $q) => $this->applyUnreadJoin($q, $tenantId, $myStaffMember->id)->select('m.conversation_id'));
            }
        }

        // ── Búsqueda ─────────────────────────────────────────────────────────
        // Se hace en el servidor porque la lista ya no viaja entera: filtrar solo
        // lo cargado dejaría fuera los chats viejos. Cubre lo mismo que cubría el
        // filtro del navegador —nombre guardado, teléfono, username de WhatsApp y
        // el nombre del TITULAR en ispwatch—, que no está en esta base de datos.
        $search = trim((string) $request->query('q', ''));
        $ispwatchTenantId = $tenant->ispwatch_tenant_id ? (int) $tenant->ispwatch_tenant_id : null;

        if ($search !== '') {
            $digits = preg_replace('/\D/', '', $search) ?? '';
            $titularPhones = $ispwatchTenantId
                ? $this->ispwatch->phonesMatchingName($ispwatchTenantId, $search)
                : [];

            $conversationsQuery->whereHas('contact', function ($q) use ($search, $digits, $titularPhones) {
                $q->where(function ($q) use ($search, $digits, $titularPhones) {
                    $q->where('name', 'ilike', '%' . $search . '%')
                      ->orWhere('wa_username', 'ilike', '%' . $search . '%');

                    if ($digits !== '') {
                        $q->orWhere('phone', 'like', '%' . $digits . '%');
                    }

                    // Los teléfonos de ispwatch son locales (10 dígitos) y acá se
                    // guardan con indicativo, así que se comparan por el final.
                    if ($titularPhones !== []) {
                        $q->orWhereIn(DB::raw('right(phone, 10)'), $titularPhones);
                    }
                });
            });
        }

        // ── Paginado ─────────────────────────────────────────────────────────
        // `list_limit` crece con "Cargar más" y viaja en la URL, así que el poll
        // lo conserva y la lista no se encoge sola al refrescarse.
        $listLimit = (int) $request->query('list_limit', self::CONVERSATIONS_PER_PAGE);
        $listLimit = max(self::CONVERSATIONS_PER_PAGE, min($listLimit, 2000));

        // Una fila de más: es cómo se sabe que quedan chats sin traer, sin pagar
        // un COUNT(*) extra en cada poll.
        $conversationModels = $conversationsQuery->orderByDesc('updated_at')->limit($listLimit + 1)->get();

        $hasMoreConversations = $conversationModels->count() > $listLimit;
        $conversationModels   = $conversationModels->take($listLimit);

        // ── Nombre a mostrar: manda el TITULAR de ispwatch ───────────────────
        // El webhook de WhatsApp solo trae el nombre del PERFIL de quien escribe
        // (p. ej. la esposa que usa el teléfono), NUNCA el que el ISP tiene
        // guardado —ese vive en la agenda del celular y Meta no lo manda—. Así
        // que un chat del titular podía aparecer con un nombre que el ISP no
        // reconocía. Orden: titular en ispwatch → perfil de WhatsApp → teléfono.
        // Una sola query batch cacheada 60 s, apta para el poll de 5 s.
        $ispwatchNames    = $ispwatchTenantId
            ? $this->ispwatch->customerNamesForContacts(
                $ispwatchTenantId,
                $conversationModels
                    ->filter(fn (Conversation $c) => (bool) $c->contact)
                    ->mapWithKeys(fn (Conversation $c) => [
                        $c->contact->id => ['phone' => $c->contact->phone, 'name' => $c->contact->name],
                    ])
                    ->all(),
            )
            : [];

        // Conteo de mensajes entrantes no leídos por conversación, para ESTE
        // agente (conversation_reads.staff_member_id). Una sola query agrupada
        // en vez de N+1 por fila.
        $unreadCounts = $this->applyUnreadJoin(DB::table('messages as m'), $tenantId, $myStaffMember->id)
            ->whereIn('m.conversation_id', $conversationModels->pluck('id'))
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id, COUNT(*) AS unread_count')
            ->pluck('unread_count', 'm.conversation_id');

        $conversations = $conversationModels->map(function (Conversation $conv) use ($unreadCounts, $ispwatchNames) {
            $unread = (int) ($unreadCounts[$conv->id] ?? 0);

            return [
                'id'                  => $conv->id,
                'contact_id'          => $conv->contact_id,
                'phone'               => $conv->contact?->phone,
                'name'                => $this->displayName($conv->contact, $ispwatchNames),
                // Solo cuando difiere del nombre mostrado: el buscador de la
                // lista sigue encontrando el chat por el nombre de WhatsApp.
                'whatsapp_name'       => $this->whatsappAliasFor($conv->contact, $ispwatchNames),
                'status'              => $conv->status,
                'last_message'        => $conv->latestMessage?->body,
                'last_message_status' => $conv->latestMessage?->status,
                'last_message_at'     => $conv->latestMessage?->created_at?->toIso8601String(),
                'updated_at'          => $conv->updated_at?->toIso8601String(),
                'unread_count'        => $unread,
                'is_unread'           => $unread > 0,
                'assigned_to'         => $conv->assignee ? [
                    'id'      => $conv->assignee->id,
                    'name'    => $conv->assignee->user?->name ?? 'Agente',
                    'initial' => mb_substr($conv->assignee->user?->name ?? '?', 0, 1),
                ] : null,
            ];
        });

        // ── Conversación activa ──────────────────────────────────────────────
        $activeConversationId = $request->query('conversation');
        $activeChat = [];
        $activeConversation = null;
        $activeAssignedTo = null;
        $serviceWindowExpiresAt = null;

        // En full page loads el elseif auto-selecciona la primera conversación cuando
        // no hay ?conversation= en la URL. En partial reloads (polling) ese auto-select
        // debe bloquearse: sin el param la respuesta devolvería mensajes de otra
        // conversación (cross-contamination). El frontend ahora siempre incluye el param
        // explícitamente en cada poll (construido desde props.activeConversationId),
        // por lo que en condiciones normales el bloque if siempre se ejecuta y el elseif
        // nunca se alcanza en polls — pero el guard queda como defensa en profundidad.
        $isPartialReload = $request->hasHeader('X-Inertia-Partial-Data');
        // El poll se marca con su propia cabecera. Antes bastaba con "es partial"
        // para aflojar el alcance del agente más abajo, pero desde que abrir un
        // chat también es un partial (CON-73) eso habría ampliado el acceso a
        // cualquier hilo del tenant en una navegación normal.
        $isPoll = $request->hasHeader('X-Converza-Poll');

        if ($activeConversationId) {
            $convQuery = Conversation::with(['contact', 'assignee.user'])
                ->where('tenant_id', $tenantId);
            if ($isAgent) {
                // Evita que el agente acceda a conversaciones ajenas por URL.
                $convQuery->where('assigned_to', $myStaffMember->id);
            }
            $activeConversation = $convQuery->find($activeConversationId);

            // Agente reasignado en mitad de la sesión: el scope assigned_to ya no
            // resuelve la conversación. En el poll no blanqueamos el hilo que ya
            // tiene abierto — caemos a scope de tenant para mantener los mensajes
            // visibles (solo lectura; las acciones de escritura siguen bloqueadas
            // en sus propios endpoints). Abrir OTRO chat sigue sujeto al scope.
            if (!$activeConversation && $isPoll && $isAgent) {
                $activeConversation = Conversation::with(['contact', 'assignee.user'])
                    ->where('tenant_id', $tenantId)
                    ->find($activeConversationId);
            }
        } elseif ($conversations->isNotEmpty() && !$isPartialReload) {
            // Auto-select solo en carga completa de página, nunca en polling.
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
                    // URL separada que fuerza Content-Disposition: attachment con el
                    // nombre original (en disco el archivo se llama por su UUID).
                    'media_download_url' => $msg->media_path
                        ? route('media.serve', array_filter([
                            'path'     => $msg->media_path,
                            'download' => 1,
                            'name'     => $msg->media_filename,
                        ]))
                        : null,
                    'media_mime'     => $msg->media_mime,
                    'media_filename' => $msg->media_filename,
                    'sender_name'    => $msg->sender?->name,
                    // Solo viaja en los mensajes fallidos: explica POR QUÉ no llegó.
                    'status_reason'  => $this->failureReason($msg),
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

            // Marca la conversación como leída por ESTE agente. Corre en cada
            // carga (incluido el poll de 5s mientras el agente la tiene abierta),
            // así un mensaje nuevo que llegue mientras la está viendo se marca
            // leído automáticamente en el siguiente poll — sin esperar un clic.
            // El "unread" de $conversations ya se calculó arriba, con el estado
            // ANTERIOR a este upsert: la propia conversación abierta puede verse
            // como no leída una vez más en esta misma respuesta y corregirse recién
            // en el próximo poll (~5s); el frontend la marca leída al instante de
            // forma optimista mientras tanto.
            ConversationRead::updateOrCreate(
                ['conversation_id' => $activeConversation->id, 'staff_member_id' => $myStaffMember->id],
                ['tenant_id' => $tenantId, 'last_read_at' => now()],
            );

            // Ventana de servicio de 24 h: WhatsApp solo permite texto libre
            // dentro de las 24 h siguientes al último mensaje DEL CLIENTE. Fuera
            // de ella Meta acepta el envío y lo rechaza después, así que el
            // agente creía haber respondido. Se expone al front para avisarlo
            // ANTES de escribir. null = nunca escribió (ventana cerrada).
            $lastInboundAt = Message::where('tenant_id', $tenantId)
                ->where('conversation_id', $activeConversation->id)
                ->where('status', 'received')
                ->max('created_at');

            $serviceWindowExpiresAt = $lastInboundAt
                ? Carbon::parse($lastInboundAt)->addDay()->toIso8601String()
                : null;
        }

        // Heartbeat de presencia: registra que este usuario está en línea y, si
        // hay conversación activa, que la está viendo (base de la colisión). Corre
        // también en cada poll de 5s, porque el partial reload ejecuta el método
        // completo aunque solo serialice algunas props.
        $this->presence->heartbeat($tenantId, $userId, $request->user()->name, $activeConversation?->id);

        // La conversación activa puede no estar en la lista (filtro/rol la dejan
        // fuera): si su contacto no quedó en el batch de arriba, se resuelve
        // aparte. El mapa por teléfono ya está cacheado, así que no hay query extra.
        $activeContact = $activeConversation?->contact;
        if ($activeContact && $ispwatchTenantId && ! array_key_exists($activeContact->id, $ispwatchNames)) {
            $ispwatchNames += $this->ispwatch->customerNamesForContacts($ispwatchTenantId, [
                $activeContact->id => ['phone' => $activeContact->phone, 'name' => $activeContact->name],
            ]);
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
            'activePhone'          => $activeContact?->phone,
            // Cuando el cliente ocultó su número con un username de WhatsApp no
            // hay teléfono que mostrar ni con qué cruzarlo contra ispwatch. Se
            // dice explícitamente, para que el asesor no concluya que no es cliente.
            'activeUsername'       => $activeContact?->wa_username,
            'activeSinTelefono'    => (bool) $activeContact?->sinTelefono(),
            'activeName'           => $this->displayName($activeContact, $ispwatchNames),
            // Nombre del perfil de WhatsApp cuando NO es el que se muestra: el
            // encabezado lo aclara para que el agente sepa quién está escribiendo
            // realmente desde el número del titular.
            'activeWhatsappName'   => $this->whatsappAliasFor($activeContact, $ispwatchNames),
            'activeStatus'         => $activeConversation?->status,
            'activeAssignedTo'     => $activeAssignedTo,
            'myStaffMemberId'      => $myStaffMember->id,
            'filter'               => $filter,
            // Estado del paginado y la búsqueda: el front los reenvía en cada
            // poll para que la lista no cambie de tamaño ni pierda el término.
            'search'               => $search,
            'listLimit'            => $listLimit,
            'hasMoreConversations' => $hasMoreConversations,
            'activeContactId'      => $activeConversation?->contact?->id,
            // Cuándo se cierra la ventana de 24 h. null = cerrada (o el contacto
            // nunca escribió). El front calcula el tiempo restante en vivo.
            'serviceWindowExpiresAt' => $serviceWindowExpiresAt,

            // Plantillas aprobadas para reabrir la conversación fuera de la
            // ventana. Closure: no se evalúa en el polling de 5s.
            'templates'            => fn () => Template::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->where('status', 'approved')
                ->orderBy('name')
                ->get(['id', 'name', 'body', 'language', 'event_key'])
                ->map(function (Template $t) {
                    [$sendable, $reason] = $this->templateSendability($t);

                    return [
                        'id'        => $t->id,
                        'name'      => $t->name,
                        'body'      => $t->body,
                        'language'  => $t->language,
                        'variables' => Template::namedVariablesIn($t->body),
                        'sendable'  => $sendable,
                        'reason'    => $reason,
                    ];
                })
                ->all(),

            // Precarga del modal "Nuevo chat" cuando se llega desde Contactos y el
            // contacto todavía no tiene ninguna conversación (ver ContactController@chat).
            'newChatPhone'         => $request->query('new_phone'),
            'newChatName'          => $request->query('new_name'),

            // Etiquetas del contacto activo (refrescable solo en partial reload tras
            // asignar/quitar) y catálogo de etiquetas tipo 'contact' del tenant.
            'contactLabels'        => fn () => $activeConversation?->contact
                ? $activeConversation->contact->labels()
                    ->orderBy('name')
                    ->get(['labels.id', 'labels.name', 'labels.color'])
                : [],
            'labels'               => fn () => Label::where('tenant_id', $tenantId)
                ->where('type', 'contact')
                ->orderBy('name')
                ->get(['id', 'name', 'color']),

            'quickReplies'         => fn () => QuickReply::where('tenant_id', $tenantId)
                ->where('is_active', true)
                ->orderBy('category')
                ->orderBy('title')
                ->get(['id', 'title', 'body', 'shortcut', 'category']),

            'ispwatchCustomer'     => fn () => $resolveIspwatch()[0],
            'ispwatchInvoices'     => fn () => $resolveIspwatch()[1],

            'staffMembers'         => function () use ($tenantId, $myStaffMember) {
                $onlineUserIds = $this->presence->onlineUserIds($tenantId);

                return StaffMember::query()
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->with('user:id,name')
                    ->orderBy('id')
                    ->get()
                    ->map(fn (StaffMember $s) => [
                        'id'        => $s->id,
                        'user_id'   => $s->user_id,
                        'name'      => $s->user?->name ?? 'Agente',
                        'initial'   => mb_substr($s->user?->name ?? '?', 0, 1),
                        'is_me'     => $s->id === $myStaffMember->id,
                        'is_online' => in_array((int) $s->user_id, $onlineUserIds, true),
                    ])
                    ->all();
            },

            // Presencia para detección de colisión: otros usuarios viendo la
            // conversación activa AHORA. Refresca en cada poll de 5s.
            'presence'             => fn () => [
                'viewers' => $activeConversation
                    ? $this->presence->viewersOf($activeConversation->id, $userId)
                    : [],
            ],

            'filterCounts'         => fn () => $this->filterCounts($tenantId, $myStaffMember->id, $isAgent),
        ]);
    }

    public function sendMessage(Request $request)
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;

        $request->validate([
            // El teléfono deja de ser obligatorio: un cliente con username de
            // WhatsApp no tiene número visible, y a su chat solo se llega por el
            // hilo. Sigue haciendo falta uno de los dos para saber a quién escribir.
            'phone'           => 'nullable|string|required_without:conversation_id',
            'message'         => 'required|string|max:4096',
            'conversation_id' => [
                'nullable', 'integer',
                Rule::exists('conversations', 'id')->where('tenant_id', $tenantId),
            ],
        ]);

        $phone = Contact::normalizePhone($request->input('phone'));
        $messageContent = $request->input('message');

        // Con conversation_id (chat abierto en pantalla) se respeta ese hilo y el
        // cierre se valida acá. Sin él (escribirle a un contacto desde otra
        // pantalla) se reutiliza —y reabre— el hilo existente del contacto: el
        // agente ya decidió escribir, y abrir un chat paralelo partiría el historial.
        //
        // Se resuelve ANTES que el contacto porque, sin teléfono, el hilo es lo
        // único que lleva hasta la ficha.
        $conversation = null;
        if ($request->conversation_id) {
            $conversation = Conversation::where('tenant_id', $tenantId)->find($request->conversation_id);

            // Bloquear envío a conversaciones cerradas. La reapertura debe ser explícita.
            if ($conversation && $conversation->status === 'closed') {
                return back()->withErrors(['message' => 'La conversación está cerrada. Reabrela primero para enviar mensajes.']);
            }
        }

        $contact = $phone
            ? Contact::firstOrCreate(
                ['phone' => $phone, 'tenant_id' => $tenantId],
                ['phone' => $phone, 'tenant_id' => $tenantId],
            )
            : $conversation?->contact;

        if (! $contact) {
            return back()->withErrors(['message' => 'No se pudo identificar al destinatario.']);
        }

        if (! $conversation) {
            $conversation = Conversation::resolveForContact($tenantId, $contact->id);
        }

        // Teléfono si lo hay; si no, el BSUID. WhatsAppService distingue cuál es
        // y arma el payload que corresponde.
        $destino = $contact->waDestino();

        if (! $destino) {
            return back()->withErrors(['message' => 'Este contacto no tiene teléfono ni identidad de WhatsApp: no se le puede escribir.']);
        }

        // Prefijo del asesor en el texto enviado a WhatsApp: "Nombre: mensaje".
        // Se aplica solo al payload de WhatsApp; en BD guardamos el cuerpo limpio
        // para no duplicar el nombre en la UI (sent_by_user_id ya identifica al agente).
        $agentName    = $request->user()->name;
        $prefix       = '*' . $agentName . ':* ';
        $messageToWA  = str_starts_with($messageContent, $prefix) ? $messageContent : $prefix . $messageContent;

        $result = $this->whatsappService->sendMessage($destino, $messageToWA);

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
            ConversationRead::markAnsweredForTeam($tenantId, $conversation->id);

            return back()->with('success', 'Mensaje enviado.');
        }

        return back()->withErrors(['message' => 'Error al enviar: ' . ($result['error'] ?? 'Error desconocido')]);
    }

    /**
     * Envía una PLANTILLA aprobada desde el chat.
     *
     * Es la ÚNICA forma de escribirle al cliente fuera de la ventana de 24 h, y
     * hasta ahora el chat no la tenía: cuando la ventana se cerraba, el agente
     * solo podía mandar texto libre, Meta lo rechazaba en silencio y la
     * conversación quedaba muerta sin que nadie se enterara.
     *
     * Las variables se rellenan solas con el propósito "general" del catálogo de
     * eventos (datos del cliente en ispwatch + empresa + fecha/hora), que existía
     * justamente para este envío manual y nunca se había cableado.
     */
    public function sendTemplate(Request $request)
    {
        $tenant   = app('tenant');
        $tenantId = $tenant->id;

        $validated = $request->validate([
            // Nulable por lo mismo que en sendMessage y sendMedia: un cliente que
            // ocultó su número (username de WhatsApp) no tiene teléfono, y a su
            // chat solo se llega por el hilo. Ver CON-68.
            'phone'           => 'nullable|string|required_without:conversation_id',
            'template_id'     => [
                'required', 'integer',
                Rule::exists('templates', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('is_active', true)
                    ->where('status', 'approved'),
            ],
            'conversation_id' => [
                'nullable', 'integer',
                Rule::exists('conversations', 'id')->where('tenant_id', $tenantId),
            ],
        ], [
            'template_id.exists' => 'Esa plantilla no está aprobada por Meta o está desactivada.',
        ]);

        $template = Template::where('tenant_id', $tenantId)->findOrFail($validated['template_id']);

        // Defensa en profundidad: la UI ya deshabilita estas plantillas, pero
        // enviarla igual le llegaría al cliente con los huecos vacíos.
        [$sendable, $reason] = $this->templateSendability($template);
        if (! $sendable) {
            return back()->withErrors(['template' => 'Esta plantilla no se puede enviar desde el chat. ' . $reason]);
        }

        $phone = Contact::normalizePhone($validated['phone'] ?? null);

        // El hilo se resuelve ANTES que el contacto: sin teléfono es lo único que
        // lleva hasta la ficha. Mismo orden que sendMessage/sendMedia.
        $conversation = null;
        if ($request->conversation_id) {
            $conversation = Conversation::where('tenant_id', $tenantId)->find($request->conversation_id);

            if ($conversation && $conversation->status === 'closed') {
                return back()->withErrors(['template' => 'La conversación está cerrada. Reabrela primero para enviar la plantilla.']);
            }
        }

        $contact = $phone
            ? Contact::firstOrCreate(
                ['phone' => $phone, 'tenant_id' => $tenantId],
                ['phone' => $phone, 'tenant_id' => $tenantId],
            )
            : $conversation?->contact;

        if (! $contact) {
            return back()->withErrors(['template' => 'No se pudo identificar al destinatario.']);
        }

        if (! $conversation) {
            $conversation = Conversation::resolveForContact($tenantId, $contact->id);
        }

        // Teléfono si lo hay; si no, el BSUID. WhatsAppService arma `to` o
        // `recipient` según la forma del valor.
        $destino = $contact->waDestino();

        if (! $destino) {
            return back()->withErrors(['template' => 'Este contacto no tiene teléfono ni identidad de WhatsApp: no se le puede escribir.']);
        }

        $params = $this->templateParams($template, $contact, $tenant, $request->user()->name);

        $result = $this->whatsappService->sendTemplate(
            $destino,
            $template->name,
            $template->language ?: 'es_CO',
            $params,
        );

        if (! $result['success']) {
            return back()->withErrors([
                'template' => 'Error al enviar la plantilla: ' . ($result['error'] ?? 'Error desconocido'),
            ]);
        }

        Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'contact_id'      => $contact->id,
            // Se guarda el texto YA renderizado: en el chat el agente tiene que
            // leer lo que el cliente recibió, no la plantilla con {{variables}}.
            'body'            => TemplateRenderer::renderBody($template, $params),
            'status'          => 'sent',
            'type'            => 'template',
            'sent_by_user_id' => $request->user()->id,
            'wa_message_id'   => $result['data']['messages'][0]['id'] ?? null,
        ]);

        $conversation->touch();
        ConversationRead::markAnsweredForTeam($tenantId, $conversation->id);

        return back()->with('success', 'Plantilla enviada. La ventana de 24 h se reabre cuando el cliente responda.');
    }

    /**
     * ¿Se puede enviar esta plantilla a mano desde el chat, y si no, por qué?
     *
     * El envío manual solo sabe rellenar las variables del propósito "general"
     * del catálogo (datos del cliente, empresa, fecha). Una plantilla de
     * facturación pide `monto`, `mes_facturado`… que solo existen en el contexto
     * de un ciclo concreto: enviarla desde aquí le llegaría al cliente con los
     * huecos VACÍOS ("por un valor de: **"), que es peor que no ofrecerla.
     *
     * Las posicionales legadas ({{1}}) quedan fuera porque no hay forma de saber
     * qué significa cada posición en un envío manual.
     *
     * @return array{0: bool, 1: ?string}
     */
    private function templateSendability(Template $template): array
    {
        if (Template::positionalVariablesIn($template->body) !== []) {
            return [false, 'Usa variables numeradas ({{1}}); solo puede enviarse desde una campaña o un aviso automático.'];
        }

        $missing = array_diff(
            Template::namedVariablesIn($template->body),
            EventCatalog::variableNames('general'),
        );

        if ($missing !== []) {
            return [false, 'Necesita datos que solo existen en un aviso automático: ' . implode(', ', $missing) . '.'];
        }

        return [true, null];
    }

    /**
     * Valores de las variables de una plantilla enviada a mano desde el chat.
     *
     * Si el contacto está en ispwatch se usan sus datos reales; si no —o el
     * tenant no está vinculado— se cae al nombre que tengamos del contacto, para
     * que al menos el saludo salga bien en vez de quedar vacío.
     *
     * @return array<int|string, string>
     */
    private function templateParams(Template $template, Contact $contact, Tenant $tenant, string $agentName): array
    {
        $row = ['name' => (string) ($contact->name ?? '')];

        if ($tenant->ispwatch_tenant_id) {
            $customer = $this->ispwatch->customerByPhone(
                (int) $tenant->ispwatch_tenant_id,
                $contact->phone,
                $contact->name,
            );
            if ($customer) {
                $row = $customer;
            }
        }

        $values = EventCatalog::resolveValues('general', $row, $tenant, $agentName);

        // Solo variables NOMBRADAS: templateSendability() ya descartó las
        // posicionales y las que piden datos fuera del propósito "general".
        $params = [];
        foreach (Template::namedVariablesIn($template->body) as $name) {
            $params[$name] = (string) ($values[$name] ?? '');
        }

        return $params;
    }

    /**
     * Envía una imagen, audio, video o documento al cliente vía WhatsApp Cloud API.
     * Funciona dentro de la ventana de 24h sin costo adicional por mensaje.
     */
    public function sendMedia(Request $request)
    {
        $tenant   = app('tenant');
        $tenantId = $tenant->id;

        $request->validate([
            // Nulable por lo mismo que en sendMessage: un cliente con username de
            // WhatsApp no tiene teléfono y solo se alcanza por el hilo.
            'phone'           => 'nullable|string|required_without:conversation_id',
            // Imagen: jpeg/png/webp/gif. Audio: ogg/mp3/m4a/aac/amr y webm (grabado
            // desde el navegador, se transcodifica abajo). Video: mp4/3gp.
            // Documento: pdf, Word, Excel, PowerPoint, txt y csv.
            'file'            => 'required|file|max:16384|mimes:jpeg,jpg,png,webp,gif,ogg,oga,mp3,m4a,aac,amr,webm,weba,mp4,3gp,pdf,doc,docx,xls,xlsx,ppt,pptx,txt,csv',
            'caption'         => 'nullable|string|max:1024',
            'conversation_id' => [
                'nullable', 'integer',
                Rule::exists('conversations', 'id')->where('tenant_id', $tenantId),
            ],
        ], [
            'file.required' => 'No llegó ningún archivo. Si el archivo es grande puede estar excediendo el límite de subida del servidor (upload_max_filesize / post_max_size en PHP).',
            'file.max'      => 'El archivo supera el límite de 16 MB.',
            'file.mimes'    => 'Tipo de archivo no permitido. Se aceptan imágenes, audio, video y documentos (PDF, Word, Excel, PowerPoint, TXT, CSV).',
        ]);

        $phone     = Contact::normalizePhone($request->input('phone'));
        $uploaded  = $request->file('file');
        $mimeType  = $uploaded->getMimeType();
        $origName  = $uploaded->getClientOriginalName();
        $caption   = $request->input('caption');
        $content   = file_get_contents($uploaded->getRealPath());
        // getClientOriginalExtension() viene vacío en archivos pegados desde el
        // portapapeles; extension() la deduce del MIME real detectado.
        $ext       = $uploaded->getClientOriginalExtension() ?: $uploaded->extension();
        $baseMime  = strtolower(Str::before($mimeType, ';'));

        // El tipo de mensaje de WhatsApp se deriva del MIME. Todo lo que no sea
        // imagen/audio/video (pdf, Word, Excel, txt…) viaja como 'document'.
        // Antes se asumía audio para cualquier cosa que no fuera imagen, así que
        // un PDF entraba al transcodificador de ffmpeg y el envío fallaba.
        $type = match (true) {
            str_starts_with($baseMime, 'image/') => 'image',
            str_starts_with($baseMime, 'video/') => 'video',
            str_starts_with($baseMime, 'audio/') => 'audio',
            default                              => 'document',
        };

        // WhatsApp no acepta webm para audio. Las grabaciones del navegador
        // (Chrome/Edge → webm/opus) se transcodifican a OGG/opus con ffmpeg.
        if ($type === 'audio') {
            $accepted = ['audio/ogg', 'audio/mpeg', 'audio/mp3', 'audio/amr', 'audio/aac', 'audio/mp4'];
            if (! in_array($baseMime, $accepted, true)) {
                $converted = $this->transcodeToOggOpus($content, $ext ?: 'webm');
                if (! $converted) {
                    return back()->withErrors(['file' => 'No se pudo procesar el audio grabado. ¿Está ffmpeg instalado en el servidor?']);
                }
                $content  = $converted;
                $mimeType = 'audio/ogg';
                $ext      = 'ogg';
                $origName = pathinfo($origName, PATHINFO_FILENAME) . '.ogg';
            }
        }

        // Igual que en sendMessage: el hilo se resuelve primero porque un cliente
        // sin teléfono visible (username de WhatsApp) solo se alcanza por ahí.
        $conversation = null;
        if ($request->conversation_id) {
            $conversation = Conversation::where('tenant_id', $tenantId)->find($request->conversation_id);

            if ($conversation && $conversation->status === 'closed') {
                return back()->withErrors(['file' => 'La conversación está cerrada. Reabrela primero para enviar archivos.']);
            }
        }

        $contact = $phone
            ? Contact::firstOrCreate(
                ['phone' => $phone, 'tenant_id' => $tenantId],
                ['phone' => $phone, 'tenant_id' => $tenantId],
            )
            : $conversation?->contact;

        if (! $contact) {
            return back()->withErrors(['file' => 'No se pudo identificar al destinatario.']);
        }

        if (! $conversation) {
            $conversation = Conversation::resolveForContact($tenantId, $contact->id);
        }

        $destino = $contact->waDestino();

        if (! $destino) {
            return back()->withErrors(['file' => 'Este contacto no tiene teléfono ni identidad de WhatsApp: no se le puede escribir.']);
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

        // El filename solo aplica a documentos: es el nombre que ve el destinatario
        // en su WhatsApp. Sin él, Meta muestra el media_id como título del archivo.
        $result = $this->whatsappService->sendMedia($destino, $type, $waMediaId, $caption, $origName);

        if (! $result['success']) {
            return back()->withErrors(['file' => 'Error al enviar: ' . ($result['error'] ?? 'Error desconocido')]);
        }

        // Guardar el archivo en el disco configurado (local o Supabase)
        // $ext ya está definido arriba (puede haber cambiado a 'ogg' al transcodificar).
        $mediaDisk = config('filesystems.media_disk', 'public');
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

        // Siempre intentar guardar copia local como fallback. Envuelto en try/catch:
        // si storage/app/public no es escribible por el usuario de PHP-FPM (www-data)
        // en el servidor, esto NO debe tirar 500 — el archivo ya se envió a WhatsApp y
        // el mensaje debe quedar registrado en la conversación. El thumbnail puede verse
        // roto hasta corregir los permisos, pero el envío no se pierde.
        try {
            Storage::disk('public')->put($path, $content);
        } catch (\Throwable $e) {
            \Log::error('ChatController sendMedia: no se pudo guardar copia local (¿permisos de storage/app/public para www-data?)', [
                'path'  => $path,
                'error' => $e->getMessage(),
            ]);
        }

        $typeLabel = match ($type) {
            'image'    => 'Imagen',
            'video'    => 'Video',
            'audio'    => 'Audio',
            default    => 'Documento',
        };
        $defaultBody = match ($type) {
            'image'    => '📷 Imagen',
            'video'    => '🎥 Video',
            'audio'    => '🎤 Audio',
            default    => '📄 ' . ($origName ?: 'Documento'),
        };

        Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'contact_id'      => $contact->id,
            'body'            => $caption ?? $defaultBody,
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
        ConversationRead::markAnsweredForTeam($tenantId, $conversation->id);

        return back()->with('success', $typeLabel . ' enviado.');
    }

    /**
     * Transcodifica un audio (típicamente webm/opus grabado en el navegador) a
     * OGG/opus, el único formato que WhatsApp acepta como nota de voz.
     *
     * Requiere ffmpeg en el servidor (apt install ffmpeg). Devuelve el contenido
     * OGG, o null si ffmpeg no está disponible o falla.
     */
    private function transcodeToOggOpus(string $content, string $srcExt): ?string
    {
        $tmpIn  = tempnam(sys_get_temp_dir(), 'wa_in_') . '.' . ($srcExt ?: 'webm');
        $tmpOut = tempnam(sys_get_temp_dir(), 'wa_out_') . '.ogg';

        file_put_contents($tmpIn, $content);

        try {
            $process = new \Symfony\Component\Process\Process([
                config('media.ffmpeg_path', 'ffmpeg'),
                '-y',
                '-i', $tmpIn,
                '-vn',
                '-c:a', 'libopus',
                '-b:a', '32k',
                '-ar', '48000',
                '-ac', '1',
                $tmpOut,
            ]);
            $process->setTimeout(60);
            $process->run();

            if (! $process->isSuccessful() || ! is_file($tmpOut) || filesize($tmpOut) === 0) {
                \Log::error('ChatController: ffmpeg audio transcode failed', [
                    'exit'   => $process->getExitCode(),
                    'stderr' => $process->getErrorOutput(),
                ]);
                return null;
            }

            return file_get_contents($tmpOut);
        } catch (\Throwable $e) {
            \Log::error('ChatController: ffmpeg transcode exception: ' . $e->getMessage());
            return null;
        } finally {
            @unlink($tmpIn);
            @unlink($tmpOut);
        }
    }

    /**
     * Asigna (sync) las etiquetas de un contacto desde el chat. Reemplaza el set
     * completo por el enviado. Solo etiquetas tipo 'contact' del mismo tenant.
     */
    public function updateContactLabels(Request $request, Contact $contact)
    {
        $tenantId = app('tenant')->id;
        abort_if($contact->tenant_id !== $tenantId, 403);

        $validated = $request->validate([
            'label_ids'   => ['array'],
            'label_ids.*' => [
                'integer',
                Rule::exists('labels', 'id')
                    ->where('tenant_id', $tenantId)
                    ->where('type', 'contact'),
            ],
        ]);

        $contact->labels()->sync($validated['label_ids'] ?? []);

        return back()->with('success', 'Etiquetas actualizadas.');
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

        // Registrar la transferencia como mensaje de sistema dentro del chat.
        $actorName    = $request->user()->name;
        $previousName = $previousStaffId
            ? StaffMember::with('user:id,name')->find($previousStaffId)?->user?->name
            : null;
        $newStaff = $newStaffId
            ? StaffMember::with('user:id,name')->find($newStaffId)
            : null;
        $newName = $newStaff?->user?->name;

        if ($newStaffId === null) {
            $systemBody = "🔄 {$actorName} quitó la asignación de " . ($previousName ?? 'la conversación') . '.';
        } elseif ($previousName) {
            $systemBody = "🔄 {$actorName} transfirió la conversación de {$previousName} a {$newName}.";
        } else {
            $systemBody = "🔄 {$actorName} asignó la conversación a {$newName}.";
        }

        Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'contact_id'      => $conversation->contact_id,
            'body'            => $systemBody,
            'status'          => 'sent',
            'type'            => 'system',
            'sent_by_user_id' => $request->user()->id,
        ]);

        // Enviar mensaje al cliente notificando la transferencia, solo si se asignó
        // a alguien nuevo y la conversación está abierta.
        // El try-catch protege la asignación: si WA falla, el cambio de asesor
        // ya se guardó y el mensaje de sistema ya existe — no revertimos por eso.
        if ($newStaffId !== null && $conversation->status === 'open') {
            $conversation->loadMissing('contact');
            $destino = $conversation->contact?->waDestino();

            if ($destino) {
                try {
                    $clientMsg = "Has sido transferido con {$newName}. En breve te atenderé.";
                    $waResult  = $this->whatsappService->sendMessage($destino, $clientMsg);

                    Message::create([
                        'tenant_id'       => $tenantId,
                        'conversation_id' => $conversation->id,
                        'contact_id'      => $conversation->contact_id,
                        'body'            => $clientMsg,
                        'status'          => 'sent',
                        'sent_by_user_id' => $newStaff?->user_id,
                        'wa_message_id'   => $waResult['data']['messages'][0]['id'] ?? null,
                    ]);
                } catch (\Throwable $e) {
                    \Log::warning('ChatController@assign: WA notification failed', [
                        'conversation_id' => $conversation->id,
                        'phone'           => $phone,
                        'error'           => $e->getMessage(),
                    ]);
                }
            }
        }

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
     * Agrega una NOTA INTERNA a la conversación: un mensaje visible solo para
     * el equipo, que NUNCA se envía al cliente por WhatsApp. Se guarda como
     * Message type='note' para reaprovechar el timeline y el polling.
     *
     * No llama a touch(): una nota no debe reordenar la lista ni cambiar el
     * "último mensaje" mostrado (latestMessage ya excluye type='note').
     */
    public function storeNote(Request $request, Conversation $conversation)
    {
        $tenantId = app('tenant')->id;
        abort_if($conversation->tenant_id !== $tenantId, 403);

        $validated = $request->validate([
            'note' => ['required', 'string', 'min:1', 'max:4096'],
        ]);

        Message::create([
            'tenant_id'       => $tenantId,
            'conversation_id' => $conversation->id,
            'contact_id'      => $conversation->contact_id,
            'body'            => $validated['note'],
            'status'          => 'sent',
            'type'            => 'note',
            'sent_by_user_id' => $request->user()->id,
        ]);

        return back()->with('success', 'Nota interna agregada.');
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

    /**
     * Motivo legible de que un mensaje NO se haya entregado, o null si el
     * mensaje no falló.
     *
     * WhatsApp acepta el envío (200 + wamid) y recién después reporta el fallo
     * por webhook, así que el asesor ya vio su mensaje "enviado". La causa casi
     * siempre es la ventana de 24 h, y la salida es una plantilla — pero el
     * título que manda Meta viene en inglés y orientado a depurar la API, así
     * que aquí lo traducimos a la acción concreta que el asesor debe tomar.
     *
     * Códigos: https://developers.facebook.com/docs/whatsapp/cloud-api/support/error-codes
     */
    private function failureReason(Message $msg): ?string
    {
        if ($msg->status !== 'failed') {
            return null;
        }

        $failure = $msg->raw_metadata['failure'] ?? [];
        $code    = (int) ($failure['code'] ?? 0);

        return match ($code) {
            131047, 470 => 'Pasaron más de 24 h desde el último mensaje del cliente, así que WhatsApp no permite texto libre. Envíale una plantilla aprobada para reabrir la conversación.',
            131026      => 'El número no puede recibir mensajes de WhatsApp (no tiene cuenta, o está inactivo).',
            131049      => 'WhatsApp limitó este envío para cuidar la experiencia del usuario. Inténtalo más tarde.',
            131050      => 'El cliente pidió no recibir mensajes de tu empresa.',
            131000      => 'Error temporal de WhatsApp. Puedes reintentar.',
            0           => $failure['title'] ?? 'El cliente no recibió este mensaje.',
            default     => $failure['title'] ?? "WhatsApp rechazó el envío (código {$code}).",
        };
    }

    /**
     * Nombre con el que se muestra un contacto en la lista y el encabezado.
     *
     * Manda el titular del servicio en ispwatch: es el nombre con el que el ISP
     * conoce al cliente. Solo si el teléfono no está en ispwatch se cae al
     * nombre del perfil de WhatsApp, y en último caso al propio número.
     *
     * @param  array<int, string|null>  $ispwatchNames  [contact_id => nombre en ispwatch|null]
     */
    private function displayName(?Contact $contact, array $ispwatchNames): ?string
    {
        if (! $contact) {
            return null;
        }

        // display_name cubre el caso sin teléfono: cae al username de WhatsApp
        // antes que dejar el chat rotulado con un BSUID ilegible.
        return ($ispwatchNames[$contact->id] ?? null)
            ?: $contact->display_name;
    }

    /**
     * Nombre del perfil de WhatsApp SOLO cuando no es el que se está mostrando
     * (es decir, cuando ganó el nombre de ispwatch y son distintos). null si
     * coinciden o si no aporta nada, para no repetir el mismo texto en pantalla.
     *
     * @param  array<int, string|null>  $ispwatchNames  [contact_id => nombre en ispwatch|null]
     */
    private function whatsappAliasFor(?Contact $contact, array $ispwatchNames): ?string
    {
        if (! $contact || blank($contact->name)) {
            return null;
        }

        $shown = $this->displayName($contact, $ispwatchNames);

        return mb_strtolower(trim($shown ?? '')) === mb_strtolower(trim($contact->name))
            ? null
            : $contact->name;
    }

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
     * Con $agentScope=true, todos los conteos se restringen a las
     * conversaciones asignadas a $myStaffMemberId (vista de agente).
     *
     * @return array<string, int>
     */
    private function filterCounts(int $tenantId, int $myStaffMemberId, bool $agentScope = false): array
    {
        $unreadCount = Conversation::query()
            ->where('tenant_id', $tenantId)
            ->where('status', 'open')
            ->when($agentScope, fn ($q) => $q->where('assigned_to', $myStaffMemberId))
            ->whereIn('id', fn (QueryBuilder $q) => $this->applyUnreadJoin($q, $tenantId, $myStaffMemberId)->select('m.conversation_id'))
            ->count();

        if ($agentScope) {
            // Agente: solo sus conversaciones asignadas.
            $row = Conversation::query()
                ->where('tenant_id', $tenantId)
                ->where('assigned_to', $myStaffMemberId)
                ->selectRaw('COUNT(*) AS all_count')
                ->selectRaw("COUNT(*) FILTER (WHERE status = 'open') AS open_count")
                ->selectRaw("COUNT(*) FILTER (WHERE status = 'closed') AS closed_count")
                ->first();

            return [
                'all'        => (int) $row->all_count,
                'open'       => (int) $row->open_count,
                'mine'       => (int) $row->open_count,
                'unassigned' => 0,
                'closed'     => (int) $row->closed_count,
                'unread'     => $unreadCount,
            ];
        }

        // Admin: conteos globales del tenant.
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
            'unread'     => $unreadCount,
        ];
    }

    /**
     * Configura $query (una subquery de whereIn, o una query de messages
     * independiente) para seleccionar mensajes ENTRANTES no leídos por
     * $staffMemberId: sin fila en conversation_reads, o con last_read_at
     * anterior al mensaje. Reutilizado por el filtro "unread", filterCounts()
     * y el conteo agrupado por conversación en index().
     *
     * Las reacciones quedan afuera: el webhook las guarda como entrantes
     * (status='received', igual que cualquier mensaje), así que un 👍 del
     * cliente a nuestra propia respuesta devolvía la conversación al verde
     * como si tuviera algo pendiente. Un emoji sobre un mensaje ya contestado
     * no pide respuesta. Se siguen viendo en el timeline del chat.
     */
    private function applyUnreadJoin(QueryBuilder $query, int $tenantId, int $staffMemberId): QueryBuilder
    {
        return $query
            ->from('messages as m')
            ->leftJoin('conversation_reads as cr', function ($join) use ($staffMemberId) {
                $join->on('cr.conversation_id', '=', 'm.conversation_id')
                    ->where('cr.staff_member_id', '=', $staffMemberId);
            })
            ->where('m.tenant_id', $tenantId)
            ->where('m.status', 'received')
            ->where(function ($q) {
                $q->whereNull('m.type')->orWhere('m.type', '!=', 'reaction');
            })
            ->where(function ($q) {
                $q->whereNull('cr.last_read_at')->orWhereColumn('m.created_at', '>', 'cr.last_read_at');
            });
    }
}
