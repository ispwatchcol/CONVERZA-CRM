<?php

namespace App\Jobs;

use App\Jobs\HandleBotResponse;
use App\Models\Campaign;
use App\Models\CampaignOptOut;
use App\Models\CampaignRecipient;
use App\Models\CampaignSend;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Assignment\ConversationAssigner;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessIncomingWhatsAppMessage implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;
    public int $backoff = 5;

    public function __construct(
        private readonly array $message,
        private readonly array $contacts = [],
        private readonly ?int $tenantId = null,
    ) {}

    public function handle(WhatsAppService $whatsapp): void
    {
        // `queue:work` mantiene UN mismo container de Laravel vivo durante toda la
        // vida del worker (no se reconstruye entre jobs). Un binding 'tenant' que
        // dejara un job anterior se filtraría a éste y archivaría el mensaje en el
        // TENANT EQUIVOCADO: una fuga grave de mensajes entre clientes distintos.
        //
        // Por eso aquí: (1) descartamos cualquier binding previo; (2) resolvemos
        // SIEMPRE desde $this->tenantId, que el webhook fijó según el
        // phone_number_id del mensaje; (3) limpiamos el binding al terminar, pase
        // lo que pase, para no contaminar el siguiente job del mismo worker.
        app()->forgetInstance('tenant');

        $tenant = $this->tenantId
            ? Tenant::find($this->tenantId)
            : Tenant::where('slug', 'default')->first();

        if ($tenant) {
            app()->instance('tenant', $tenant);
        }

        try {
            $this->process($whatsapp, $tenant);
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    private function process(WhatsAppService $whatsapp, ?Tenant $tenant): void
    {
        // Aseguramos que las descargas de media usen las credenciales del tenant
        // dueño del phone_number_id que recibió el mensaje, no las del .env.
        if ($tenant) {
            $whatsapp = $whatsapp->forTenant($tenant);
        }

        // tenant_id explícito en todas las escrituras: defensa en profundidad
        // sobre el global scope, para que el aislamiento no dependa solo del
        // binding del container.
        $tenantId = $tenant?->id;

        $phone       = $this->normalizePhone($this->message['from'] ?? '');
        $waMessageId = $this->message['id'] ?? null;
        $type        = $this->message['type'] ?? 'text';

        if (! $phone) {
            Log::warning('Skipped webhook message without sender phone', ['message' => $this->message]);
            return;
        }

        $contactName = $this->contacts[0]['profile']['name'] ?? null;

        $contact = Contact::firstOrCreate(
            ['phone' => $phone, 'tenant_id' => $tenantId],
            ['name'  => $contactName, 'tenant_id' => $tenantId],
        );

        // Reabrir la conversación más reciente si estaba cerrada, en lugar de crear una nueva.
        // Evita duplicar chats del mismo contacto en la lista.
        $conversation      = Conversation::where('tenant_id', $tenantId)
            ->where('contact_id', $contact->id)
            ->orderByDesc('updated_at')
            ->first();
        $isNewConversation = false;

        if ($conversation) {
            if ($conversation->status !== 'open') {
                $conversation->update(['status' => 'open']);
            }
        } else {
            $conversation      = Conversation::create(['contact_id' => $contact->id, 'tenant_id' => $tenantId]);
            $isNewConversation = true;
        }

        $attributes = $this->buildAttributes($waMessageId, $type, $contact, $conversation, $whatsapp);
        $attributes['tenant_id'] = $tenantId;

        $savedMessage = null;
        try {
            $savedMessage = Message::create($attributes);
            $conversation->touch();
        } catch (UniqueConstraintViolationException) {
            // Meta retried the webhook — message already stored, nothing to do.
            Log::info('Duplicate webhook job ignored', ['wa_message_id' => $waMessageId]);
        }

        if ($tenantId) {
            $this->handleCampaignSignals($tenantId, $phone, $type === 'text' ? ($attributes['body'] ?? '') : '');
        }

        // Despachar la respuesta del bot ANTES de auto-asignar: el bot puede estar
        // en medio de la calificación del lead (bot_active=true, assigned_to=null).
        // Si auto-assign corriera primero, el observer desactivaría el bot y el
        // dispatch nunca se ejecutaría.
        //
        // Condiciones para despachar:
        //   a) Conversación nueva → el bot decide si saludar según bot_settings.
        //   b) Conversación existente con bot_active=true → el bot procesa la respuesta.
        // Se excluyen duplicados de webhook (savedMessage = null) para no responder dos veces.
        if ($savedMessage && $tenantId && ($isNewConversation || $conversation->bot_active)) {
            HandleBotResponse::dispatch(
                conversationId:    $conversation->id,
                messageId:         $savedMessage->id,
                tenantId:          $tenantId,
                isNewConversation: $isNewConversation,
            );
        }

        // Auto-asignación "al menos ocupado": si el tenant la activó y la
        // conversación está abierta, sin dueño y sin bot activo, le asigna un agente.
        // El guard !bot_active evita interrumpir una sesión de bot en curso; cuando
        // el bot haga handoff él mismo llama a assignLeastBusy().
        if ($tenant && $tenant->auto_assign_enabled
            && $conversation->status === 'open'
            && is_null($conversation->assigned_to)
            && ! $conversation->bot_active
        ) {
            app(ConversationAssigner::class)->assignLeastBusy($conversation, $tenant);
        }
    }

    private function buildAttributes(
        ?string $waMessageId,
        string $type,
        Contact $contact,
        Conversation $conversation,
        WhatsAppService $whatsapp,
    ): array {
        $attributes = [
            'conversation_id' => $conversation->id,
            'contact_id'      => $contact->id,
            'status'          => 'received',
            'wa_message_id'   => $waMessageId,
            'type'            => $type,
            'body'            => '',
        ];

        // Stickers are not downloaded — irrelevant in CRM context, saves storage and API calls
        $mediaTypes = ['image', 'audio', 'video', 'document'];

        switch (true) {
            case $type === 'text':
                $attributes['body'] = $this->message['text']['body'] ?? '';
                break;

            case $type === 'sticker':
                $attributes['body'] = '[Sticker]';
                break;

            case $type === 'unsupported':
                // Meta envía esto para encuestas, view-once, tarjetas de contacto,
                // mensajes borrados y —caso típico aquí— códigos de verificación de
                // Meta/Facebook que llegan con botón "Copiar código". En todos estos
                // el CONTENIDO no viaja en el webhook: Meta solo manda el error, así
                // que el código real no es recuperable desde el chat. Antes
                // mostrábamos el título de error de Meta tal cual (crudo, en inglés,
                // pensado para debugging de la API) directamente en el chat del
                // agente; ahora mostramos un texto amigable y el detalle técnico
                // queda en raw_metadata + logs para poder auditar el caso después.
                $errors = $this->message['errors'] ?? [];
                $attributes['body'] = '🚫 El cliente envió un mensaje que WhatsApp no permite mostrar aquí '
                    . '(por ejemplo: encuesta, mensaje que se autodestruye, tarjeta de verificación u otro '
                    . 'formato no compatible con la API).';
                $attributes['raw_metadata'] = [
                    'reason' => 'unsupported',
                    'errors' => $errors,
                ];
                Log::info('Unsupported WhatsApp message received', [
                    'wa_message_id' => $waMessageId,
                    'error_code'    => $errors[0]['code'] ?? null,
                    'errors'        => $errors,
                ]);
                break;

            case $type === 'order':
                // Pedido armado desde el catálogo de WhatsApp (comercio conversacional).
                $order = $this->message['order'] ?? [];
                $items = $order['product_items'] ?? [];
                $count = count($items);
                $attributes['body'] = $count > 0
                    ? "🛒 Pedido: {$count} producto" . ($count === 1 ? '' : 's')
                    : '🛒 Pedido desde el catálogo';
                $attributes['raw_metadata'] = ['reason' => 'order', 'order' => $order];
                break;

            case $type === 'system':
                // Notificaciones del sistema de WhatsApp (p. ej. el contacto cambió
                // de número). Meta ya manda un texto legible en `system.body`.
                $system = $this->message['system'] ?? [];
                $attributes['body'] = 'ℹ️ ' . ($system['body'] ?? 'Notificación del sistema de WhatsApp');
                $attributes['raw_metadata'] = ['reason' => 'system', 'system' => $system];
                break;

            case $type === 'reaction':
                // Meta manda { message_id, emoji } — emoji vacío significa que el
                // contacto QUITÓ una reacción puesta antes. findFirstText no la
                // reconocía (busca claves como body/text/caption) y el mensaje
                // quedaba guardado como el literal "[reaction]" en el chat.
                $emoji = $this->message['reaction']['emoji'] ?? null;
                $attributes['body'] = $emoji ? "Reaccionó con {$emoji}" : 'Quitó su reacción';
                break;

            case $type === 'location':
                $loc = $this->message['location'] ?? [];
                $lat = $loc['latitude'] ?? null;
                $lng = $loc['longitude'] ?? null;
                $attributes['body'] = $lat && $lng
                    ? "📍 Ubicación: {$lat}, {$lng}"
                    : '[Ubicación]';
                break;

            case $type === 'contacts':
                $names = array_filter(array_map(
                    fn($c) => $c['name']['formatted_name'] ?? null,
                    $this->message['contacts'] ?? [],
                ));
                $attributes['body'] = $names
                    ? '👤 Contacto: ' . implode(', ', $names)
                    : '[Contacto]';
                break;

            case in_array($type, $mediaTypes, true):
                $this->applyMediaAttributes($attributes, $type, $whatsapp);
                break;

            case $type === 'button':
                // El contacto tocó un botón de respuesta rápida (quick reply):
                // el texto visible del botón es su respuesta.
                $btn = $this->message['button'] ?? [];
                $attributes['body'] = $btn['text'] ?? $btn['payload'] ?? '[Botón]';
                break;

            case $type === 'interactive':
                // Respuesta a un mensaje interactivo (botones o lista).
                $interactive = $this->message['interactive'] ?? [];
                $attributes['body'] = $interactive['button_reply']['title']
                    ?? $interactive['list_reply']['title']
                    ?? $interactive['list_reply']['description']
                    ?? '[Respuesta interactiva]';
                break;

            default:
                // Tipo sin case propio: Meta lanzó un formato nuevo que todavía no
                // contemplamos explícitamente. Antes de rendirnos con un placeholder,
                // intentamos rescatar cualquier texto legible del payload para no
                // perder contenido (p. ej. un código que llegue en un formato
                // distinto a `text`). Se loguea siempre como warning —a diferencia
                // del resto de casos— porque esta rama es justamente la señal de que
                // hay un tipo de mensaje nuevo que conviene agregarle un case propio.
                $extracted = $this->extractReadableText($type);
                $attributes['body'] = $extracted ?? '📩 Mensaje en un formato que todavía no soportamos aquí.';
                $attributes['raw_metadata'] = [
                    'reason'  => 'unhandled_type',
                    'type'    => $type,
                    'payload' => $this->message[$type] ?? $this->message,
                ];
                Log::warning('WhatsApp message type without explicit handler', [
                    'wa_message_id' => $waMessageId,
                    'type'          => $type,
                    'extracted'     => $extracted !== null,
                    'payload'       => $this->message,
                ]);
        }

        return $attributes;
    }

    /**
     * Rescate best-effort de texto legible para un mensaje cuyo `type` no tiene un
     * case propio. Recorre el sub-objeto del payload buscando los campos donde Meta
     * suele poner contenido (cuerpo, caption, título...). Devuelve el primer string
     * no vacío, o null si no hay nada presentable. Cubre estructuras anidadas como
     * interactive.button_reply.title.
     */
    private function extractReadableText(string $type): ?string
    {
        $payload = $this->message[$type] ?? null;

        if (is_string($payload)) {
            $payload = trim($payload);
            return $payload !== '' ? $payload : null;
        }

        if (! is_array($payload)) {
            return null;
        }

        $found = $this->findFirstText($payload, ['body', 'text', 'caption', 'title', 'description', 'name', 'payload']);

        return $found !== '' ? $found : null;
    }

    /**
     * Devuelve el primer valor string no vacío cuya clave esté en $keys, mirando
     * primero este nivel (en el orden de $keys) y luego descendiendo a sub-arrays.
     */
    private function findFirstText(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_string($data[$key]) && trim($data[$key]) !== '') {
                return trim($data[$key]);
            }
        }

        foreach ($data as $value) {
            if (is_array($value)) {
                $nested = $this->findFirstText($value, $keys);
                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private function applyMediaAttributes(array &$attributes, string $type, WhatsAppService $whatsapp): void
    {
        $mediaPayload = $this->message[$type] ?? [];
        $mediaId      = $mediaPayload['id'] ?? null;
        $caption      = $mediaPayload['caption'] ?? null;
        $mime         = $mediaPayload['mime_type'] ?? null;

        $attributes['media_id']       = $mediaId;
        $attributes['caption']        = $caption;
        $attributes['media_mime']     = $mime;
        $attributes['media_filename'] = $mediaPayload['filename'] ?? null;
        $attributes['body']           = $caption ?? $this->placeholderForType($type);

        // Tipos de media que se descargan y almacenan en disco.
        // Configurable vía .env MEDIA_DOWNLOAD_TYPES (default: image,audio).
        // Videos y documentos pueden pesar hasta 16 MB y saturar el
        // almacenamiento rápidamente. Para los no descargados solo guardamos
        // metadata (caption, filename, mime) y mostramos un placeholder.
        $downloadableTypes = config('media.download_types', ['image', 'audio']);

        if ($mediaId && in_array($type, $downloadableTypes, true)) {
            $downloaded = $whatsapp->downloadMedia($mediaId);
            if ($downloaded) {
                $attributes['media_path']     = $downloaded['path'];
                $attributes['media_mime']     = $downloaded['mime'];
                $attributes['media_filename'] = $mediaPayload['filename'] ?? $downloaded['filename'];
            } else {
                Log::warning('Media download failed', [
                    'media_id' => $mediaId,
                    'type'     => $type,
                ]);
            }
        }
    }

    /**
     * Cierra el loop con las campañas masivas para este teléfono:
     *   - Opt-out: si el texto coincide con una palabra clave configurada
     *     (STOP/BAJA/CANCELAR/...), lo agrega a campaign_opt_outs (futuras
     *     campañas lo saltan) y detiene cualquier secuencia en curso.
     *   - Conversión: si responde, DETIENE la secuencia (no más seguimientos) y
     *     marca la respuesta en el destinatario y en su último envío.
     */
    private function handleCampaignSignals(int $tenantId, string $phone, string $body): void
    {
        if ($this->isOptOutMessage($body)) {
            CampaignOptOut::firstOrCreate(
                ['tenant_id' => $tenantId, 'phone' => $phone],
                ['reason' => 'Palabra clave de baja: "' . trim($body) . '"'],
            );

            CampaignRecipient::where('tenant_id', $tenantId)
                ->where('phone', $phone)
                ->whereIn('enrollment_status', [CampaignRecipient::ENROLLMENT_ACTIVE, CampaignRecipient::ENROLLMENT_SENDING])
                ->update([
                    'enrollment_status' => CampaignRecipient::ENROLLMENT_OPTED_OUT,
                    'status' => CampaignRecipient::STATUS_OPTED_OUT,
                    'skip_reason' => 'El contacto se dio de baja (opt-out)',
                    'next_action_at' => null,
                ]);

            return;
        }

        // La respuesta del prospecto = conversión: corta la secuencia (activa, en
        // vuelo o recién completada) para no seguir mandando seguimientos.
        $recipient = CampaignRecipient::where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->whereIn('enrollment_status', [
                CampaignRecipient::ENROLLMENT_ACTIVE,
                CampaignRecipient::ENROLLMENT_SENDING,
                CampaignRecipient::ENROLLMENT_COMPLETED,
            ])
            ->whereNull('replied_at')
            ->orderByDesc('id')
            ->first();

        if (! $recipient) {
            return;
        }

        $recipient->update([
            'replied_at' => now(),
            'enrollment_status' => CampaignRecipient::ENROLLMENT_REPLIED,
            'next_action_at' => null,
        ]);

        // Marca la respuesta en el último envío que recibió (Postgres no permite
        // ORDER BY/LIMIT en UPDATE, así que se busca y se actualiza por modelo).
        $lastSend = CampaignSend::where('recipient_id', $recipient->id)
            ->whereIn('status', [CampaignSend::STATUS_SENT, CampaignSend::STATUS_DELIVERED, CampaignSend::STATUS_READ])
            ->whereNull('replied_at')
            ->orderByDesc('step_order')
            ->first();

        $lastSend?->update(['replied_at' => now()]);

        Campaign::where('tenant_id', $tenantId)->where('id', $recipient->campaign_id)->increment('replied_count');
    }

    private function isOptOutMessage(string $body): bool
    {
        $normalized = mb_strtoupper(trim($body));

        if ($normalized === '') {
            return false;
        }

        foreach (config('campaigns.opt_out_keywords', []) as $keyword) {
            if (str_contains($normalized, mb_strtoupper($keyword))) {
                return true;
            }
        }

        return false;
    }

    private function normalizePhone(string $phone): string
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10 && str_starts_with($phone, '3')) {
            $phone = '57' . $phone;
        }
        return $phone;
    }

    private function placeholderForType(string $type): string
    {
        // Con emoji prefijo (mismo estilo que ubicación/contacto arriba) para que
        // el preview en la lista de conversaciones sea legible de un vistazo,
        // en vez de un genérico "[imagen]" entre corchetes.
        return match ($type) {
            'image'    => '📷 Imagen',
            'sticker'  => '🎭 Sticker',
            'audio'    => '🎤 Audio',
            'video'    => '🎥 Video',
            'document' => '📎 Documento',
            default    => '[' . $type . ']',
        };
    }
}
