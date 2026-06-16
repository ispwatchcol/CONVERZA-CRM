<?php

namespace App\Jobs;

use App\Models\Campaign;
use App\Models\CampaignOptOut;
use App\Models\CampaignRecipient;
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
        $conversation = Conversation::where('tenant_id', $tenantId)
            ->where('contact_id', $contact->id)
            ->orderByDesc('updated_at')
            ->first();

        if ($conversation) {
            if ($conversation->status !== 'open') {
                $conversation->update(['status' => 'open']);
            }
        } else {
            $conversation = Conversation::create(['contact_id' => $contact->id, 'tenant_id' => $tenantId]);
        }

        $attributes = $this->buildAttributes($waMessageId, $type, $contact, $conversation, $whatsapp);
        $attributes['tenant_id'] = $tenantId;

        try {
            Message::create($attributes);
            $conversation->touch();
        } catch (UniqueConstraintViolationException) {
            // Meta retried the webhook — message already stored, nothing to do.
            Log::info('Duplicate webhook job ignored', ['wa_message_id' => $waMessageId]);
        }

        if ($tenantId) {
            $this->handleCampaignSignals($tenantId, $phone, $type === 'text' ? ($attributes['body'] ?? '') : '');
        }

        // Auto-asignación "al menos ocupado": si el tenant la activó y la
        // conversación está abierta y sin dueño, le asigna un agente. Idempotente
        // ante reintentos de webhook: si ya tiene assigned_to, no hace nada.
        if ($tenant && $tenant->auto_assign_enabled
            && $conversation->status === 'open'
            && is_null($conversation->assigned_to)
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
                // Meta sends this for polls, view-once, contact cards, deleted messages,
                // or any client feature not yet supported by Cloud API.
                $errors = $this->message['errors'] ?? [];
                $detail = $errors[0]['title'] ?? $errors[0]['details'] ?? 'Tipo de mensaje no soportado';
                $attributes['body'] = '[' . $detail . ']';
                Log::info('Unsupported WhatsApp message received', [
                    'wa_message_id' => $waMessageId,
                    'errors'        => $errors,
                ]);
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

            default:
                $attributes['body'] = '[' . $type . ']';
        }

        return $attributes;
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
     *     campañas lo saltan) y saca cualquier envío pendiente/en cola.
     *   - Conversión: si responde, marca el último CampaignRecipient de este
     *     teléfono sin replied_at como respondido (señal de campaña efectiva).
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
                ->whereIn('status', [CampaignRecipient::STATUS_PENDING, CampaignRecipient::STATUS_QUEUED])
                ->update(['status' => CampaignRecipient::STATUS_OPTED_OUT, 'skip_reason' => 'El contacto se dio de baja (opt-out)']);

            return;
        }

        $recipient = CampaignRecipient::where('tenant_id', $tenantId)
            ->where('phone', $phone)
            ->whereIn('status', [CampaignRecipient::STATUS_SENT, CampaignRecipient::STATUS_DELIVERED, CampaignRecipient::STATUS_READ])
            ->whereNull('replied_at')
            ->orderByDesc('id')
            ->first();

        if ($recipient) {
            $recipient->update(['replied_at' => now()]);
            Campaign::where('tenant_id', $tenantId)->where('id', $recipient->campaign_id)->increment('replied_count');
        }
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
        return match ($type) {
            'image'    => '[Imagen]',
            'sticker'  => '[Sticker]',
            'audio'    => '[Audio]',
            'video'    => '[Video]',
            'document' => '[Documento]',
            default    => '[' . $type . ']',
        };
    }
}
