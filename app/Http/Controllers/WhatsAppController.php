<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class WhatsAppController extends Controller
{
    public function __construct(private WhatsAppService $whatsapp)
    {
    }

    public function verifyWebhook(Request $request)
    {
        $mode      = $request->query('hub_mode');
        $token     = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token === config('services.whatsapp.verify_token', 'ispwatch-token')) {
            return response($challenge, 200);
        }

        return response()->json(['error' => 'Forbidden'], 403);
    }

    public function handleWebhook(Request $request)
    {
        $payload = $request->all();
        Log::info('Webhook received:', $payload);

        $this->forwardWebhook($request);

        // Resolve tenant once for all messages in this webhook
        $tenantId = app()->bound('tenant') ? app('tenant')->id : null;
        if (! $tenantId) {
            $tenantId = \App\Models\Tenant::where('slug', 'default')->value('id');
        }

        // Meta can batch multiple messages in a single webhook (e.g. user sends
        // 2 images + 1 video → array has 3 items). Iterate ALL entries/changes/messages.
        $dispatched = 0;
        foreach ($payload['entry'] ?? [] as $entry) {
            foreach ($entry['changes'] ?? [] as $change) {
                $value    = $change['value'] ?? [];
                $messages = $value['messages'] ?? [];
                $contacts = $value['contacts'] ?? [];

                foreach ($messages as $message) {
                    ProcessIncomingWhatsAppMessage::dispatch($message, $contacts, $tenantId);
                    $dispatched++;
                }
            }
        }

        if ($dispatched === 0) {
            Log::warning('Webhook received but no messages found in payload', ['payload' => $payload]);
        }

        // Respond immediately — never make Meta wait or it will retry
        return response()->json(['status' => 'EVENT_RECEIVED']);
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
}
