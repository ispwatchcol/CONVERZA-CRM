<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
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
            $text = $messageData['text']['body'] ?? '';
            $waMessageId = $messageData['id'] ?? null;

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

            // Create message
            Message::create([
                'conversation_id' => $conversation->id,
                'contact_id' => $contact->id,
                'body' => $text,
                'status' => 'received',
                'wa_message_id' => $waMessageId,
            ]);

            $conversation->touch();
        } else {
            Log::warning('Webhook received but no messages found in payload', ['payload' => $payload]);
        }

        return response()->json(['status' => 'EVENT_RECEIVED']);
    }
}
