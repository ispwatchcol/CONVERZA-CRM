<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    private function placeholderForType(string $type): string
    {
        return match ($type) {
            'image' => '[Imagen]',
            'sticker' => '[Sticker]',
            'audio' => '[Audio]',
            'video' => '[Video]',
            'document' => '[Documento]',
            default => '[' . $type . ']',
        };
    }

    private function normalizePhone($phone)
    {
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (strlen($phone) === 10 && str_starts_with($phone, '3')) {
            $phone = '57' . $phone;
        }
        return $phone;
    }

    public function verifyWebhook(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token', 'ispwatch-token')) {
            return response($challenge, 200);
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    private function forwardWebhook(Request $request): void
    {
        $forwardUrl = config('services.whatsapp.forward_url');

        if (! $forwardUrl) {
            return;
        }

        try {
            Http::timeout(3)
                ->withHeaders([
                    'X-Hub-Signature-256' => $request->header('X-Hub-Signature-256', ''),
                    'X-Forwarded-Webhook' => '1',
                ])
                ->post($forwardUrl, $request->all());
        } catch (\Throwable $e) {
            Log::warning('Webhook forward failed', ['error' => $e->getMessage(), 'url' => $forwardUrl]);
        }
    }

    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        Log::info('Webhook received:', $payload);

        $this->forwardWebhook($request);

        if (isset($payload['entry'][0]['changes'][0]['value']['messages'][0])) {
            $messageData = $payload['entry'][0]['changes'][0]['value']['messages'][0];

            $phone = $this->normalizePhone($messageData['from']);
            $waMessageId = $messageData['id'] ?? null;
            $type = $messageData['type'] ?? 'text';

            // Find or create contact
            $contact = Contact::firstOrCreate(
                ['phone' => $phone],
                ['name' => $payload['entry'][0]['changes'][0]['value']['contacts'][0]['profile']['name'] ?? null]
            );

            // Find or create open conversation
            $conversation = Conversation::firstOrCreate(
                ['contact_id' => $contact->id, 'status' => 'open'],
                ['contact_id' => $contact->id]
            );

            $attributes = [
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'status' => 'received',
                'wa_message_id' => $waMessageId,
                'type' => $type,
                'body' => '',
            ];

            $mediaTypes = ['image', 'sticker', 'audio', 'video', 'document'];

            if ($type === 'text') {
                $attributes['body'] = $messageData['text']['body'] ?? '';
            } elseif (in_array($type, $mediaTypes, true)) {
                $mediaPayload = $messageData[$type] ?? [];
                $mediaId = $mediaPayload['id'] ?? null;
                $caption = $mediaPayload['caption'] ?? null;
                $originalName = $mediaPayload['filename'] ?? null;
                $mime = $mediaPayload['mime_type'] ?? null;

                $attributes['media_id'] = $mediaId;
                $attributes['caption'] = $caption;
                $attributes['media_mime'] = $mime;
                $attributes['body'] = $caption ?? $this->placeholderForType($type);

                if ($mediaId) {
                    $downloaded = $this->whatsapp->downloadMedia($mediaId);
                    if ($downloaded) {
                        $attributes['media_path'] = $downloaded['path'];
                        $attributes['media_mime'] = $downloaded['mime'];
                        $attributes['media_filename'] = $originalName ?? $downloaded['filename'];
                    }
                }
            } else {
                $attributes['body'] = '[' . $type . ']';
            }

            Message::create($attributes);

            $conversation->touch();
        } else {
            Log::warning('Webhook received but no messages found in payload', ['payload' => $payload]);
        }

        return response()->json(['status' => 'EVENT_RECEIVED']);
    }
}
