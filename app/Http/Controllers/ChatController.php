<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\ConversationRead;
use App\Models\Label;
use App\Models\Message;
use App\Models\QuickReply;
use App\Models\StaffMember;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\Presence\PresenceService;
use App\Services\WhatsAppService;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ChatController extends Controller
{
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

        $conversationModels = $conversationsQuery->orderByDesc('updated_at')->get();

        // Conteo de mensajes entrantes no leídos por conversación, para ESTE
        // agente (conversation_reads.staff_member_id). Una sola query agrupada
        // en vez de N+1 por fila.
        $unreadCounts = $this->applyUnreadJoin(DB::table('messages as m'), $tenantId, $myStaffMember->id)
            ->whereIn('m.conversation_id', $conversationModels->pluck('id'))
            ->groupBy('m.conversation_id')
            ->selectRaw('m.conversation_id, COUNT(*) AS unread_count')
            ->pluck('unread_count', 'm.conversation_id');

        $conversations = $conversationModels->map(function (Conversation $conv) use ($unreadCounts) {
            $unread = (int) ($unreadCounts[$conv->id] ?? 0);

            return [
                'id'                  => $conv->id,
                'contact_id'          => $conv->contact_id,
                'phone'               => $conv->contact?->phone,
                'name'                => $conv->contact?->name ?: $conv->contact?->phone,
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

        // En full page loads el elseif auto-selecciona la primera conversación cuando
        // no hay ?conversation= en la URL. En partial reloads (polling) ese auto-select
        // debe bloquearse: sin el param la respuesta devolvería mensajes de otra
        // conversación (cross-contamination). El frontend ahora siempre incluye el param
        // explícitamente en cada poll (construido desde props.activeConversationId),
        // por lo que en condiciones normales el bloque if siempre se ejecuta y el elseif
        // nunca se alcanza en polls — pero el guard queda como defensa en profundidad.
        $isPartialReload = $request->hasHeader('X-Inertia-Partial-Data');

        if ($activeConversationId) {
            $convQuery = Conversation::with(['contact', 'assignee.user'])
                ->where('tenant_id', $tenantId);
            if ($isAgent) {
                // Evita que el agente acceda a conversaciones ajenas por URL.
                $convQuery->where('assigned_to', $myStaffMember->id);
            }
            $activeConversation = $convQuery->find($activeConversationId);

            // Agente reasignado en mitad de la sesión: el scope assigned_to ya no
            // resuelve la conversación. En partial reloads no blanqueamos el hilo —
            // caemos a scope de tenant para mantener los mensajes visibles (solo lectura;
            // las acciones de escritura siguen bloqueadas en sus propios endpoints).
            if (!$activeConversation && $isPartialReload && $isAgent) {
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
        }

        // Heartbeat de presencia: registra que este usuario está en línea y, si
        // hay conversación activa, que la está viendo (base de la colisión). Corre
        // también en cada poll de 5s, porque el partial reload ejecuta el método
        // completo aunque solo serialice algunas props.
        $this->presence->heartbeat($tenantId, $userId, $request->user()->name, $activeConversation?->id);

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
            'activeContactId'      => $activeConversation?->contact?->id,

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

        // Bloquear envío a conversaciones cerradas. La reapertura debe ser explícita.
        if ($conversation->status === 'closed') {
            return back()->withErrors(['message' => 'La conversación está cerrada. Reabrela primero para enviar mensajes.']);
        }

        // Prefijo del asesor en el texto enviado a WhatsApp: "Nombre: mensaje".
        // Se aplica solo al payload de WhatsApp; en BD guardamos el cuerpo limpio
        // para no duplicar el nombre en la UI (sent_by_user_id ya identifica al agente).
        $agentName    = $request->user()->name;
        $prefix       = '*' . $agentName . ':* ';
        $messageToWA  = str_starts_with($messageContent, $prefix) ? $messageContent : $prefix . $messageContent;

        $result = $this->whatsappService->sendMessage($phone, $messageToWA);

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
     * Envía una imagen, audio, video o documento al cliente vía WhatsApp Cloud API.
     * Funciona dentro de la ventana de 24h sin costo adicional por mensaje.
     */
    public function sendMedia(Request $request)
    {
        $tenant   = app('tenant');
        $tenantId = $tenant->id;

        $request->validate([
            'phone'           => 'required|string',
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
            return back()->withErrors(['file' => 'La conversación está cerrada. Reabrela primero para enviar archivos.']);
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
        $result = $this->whatsappService->sendMedia($phone, $type, $waMediaId, $caption, $origName);

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
            $phone = $conversation->contact?->phone;

            if ($phone) {
                try {
                    $clientMsg = "Has sido transferido con {$newName}. En breve te atenderé.";
                    $waResult  = $this->whatsappService->sendMessage($phone, $clientMsg);

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
                $q->whereNull('cr.last_read_at')->orWhereColumn('m.created_at', '>', 'cr.last_read_at');
            });
    }
}
