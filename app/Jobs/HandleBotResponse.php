<?php

namespace App\Jobs;

use App\Models\BotLog;
use App\Models\BotSetting;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Services\Assignment\ConversationAssigner;
use App\Services\Bot\IntentDetector;
use App\Services\WhatsAppService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class HandleBotResponse implements ShouldQueue
{
    use Queueable;

    public int $tries   = 2;
    public int $backoff = 3;

    public function __construct(
        private readonly int  $conversationId,
        private readonly int  $messageId,
        private readonly int  $tenantId,
        private readonly bool $isNewConversation = false,
    ) {}

    public function handle(WhatsAppService $whatsapp, IntentDetector $detector): void
    {
        // Mismo patrón que ProcessIncomingWhatsAppMessage: los workers de Laravel
        // mantienen el container vivo entre jobs, así que limpiamos y re-enlazamos
        // el tenant explícitamente para evitar fugas entre clientes.
        app()->forgetInstance('tenant');

        $tenant = Tenant::find($this->tenantId);
        if (! $tenant) {
            return;
        }

        app()->instance('tenant', $tenant);

        try {
            $this->process($whatsapp->forTenant($tenant), $detector, $tenant);
        } finally {
            app()->forgetInstance('tenant');
        }
    }

    private function process(WhatsAppService $whatsapp, IntentDetector $detector, Tenant $tenant): void
    {
        // Lock por conversación para evitar dobles respuestas si dos mensajes
        // del mismo cliente llegan en ráfaga y sus jobs corren en paralelo.
        $lock = Cache::lock("bot:conv:{$this->conversationId}", 30);

        if (! $lock->get()) {
            return;
        }

        try {
            $conversation = Conversation::with('contact')->find($this->conversationId);
            if (! $conversation) return;

            // Si un agente tomó la conversación entre el dispatch y ahora, no actuamos.
            if (! is_null($conversation->assigned_to)) return;

            $botSettings = BotSetting::where('tenant_id', $this->tenantId)->first();
            if (! $botSettings?->bot_enabled) return;

            $message = Message::find($this->messageId);
            if (! $message) return;

            $phone = $conversation->contact->phone;
            $step  = $conversation->bot_step;
            $body  = $message->body;

            // ── Conversación nueva: primer mensaje del lead ───────────────────
            // El bot activa el flag, envía el saludo y espera intención.
            if ($this->isNewConversation && is_null($step)) {
                $this->sendText(
                    $whatsapp, $phone, $botSettings->msg_greeting,
                    $conversation, 'greeting', null, false, $tenant,
                );
                $conversation->updateQuietly(['bot_active' => true, 'bot_step' => 'greeting_sent']);
                return;
            }

            // A partir de aquí el bot solo actúa si está en control activo.
            if (! $conversation->bot_active) return;

            // ── Paso: saludo enviado — detectar intención ─────────────────────
            if ($step === 'greeting_sent') {
                $intent = $detector->detect($body);

                if ($intent === 'agent') {
                    $this->sendText(
                        $whatsapp, $phone, $botSettings->msg_handoff,
                        $conversation, 'agent', $body, true, $tenant,
                    );
                    $this->deactivateBot($conversation, $tenant);
                    return;
                }

                if ($intent === 'unknown') {
                    $failed = $conversation->bot_failed_intents + 1;

                    if ($failed >= 2) {
                        $this->sendText(
                            $whatsapp, $phone, $botSettings->msg_fallback_2,
                            $conversation, 'fallback_2', $body, true, $tenant,
                        );
                        $this->deactivateBot($conversation, $tenant);
                    } else {
                        $this->sendText(
                            $whatsapp, $phone, $botSettings->msg_fallback_1,
                            $conversation, 'fallback_1', $body, false, $tenant,
                        );
                        $conversation->updateQuietly(['bot_failed_intents' => $failed]);
                    }
                    return;
                }

                // Intención reconocida: rama comercial + primera pregunta de calificación.
                $branchMsg = $this->branchMessage($intent, $botSettings);
                $this->sendText(
                    $whatsapp, $phone, $branchMsg,
                    $conversation, $intent, $body, false, $tenant,
                );
                $conversation->updateQuietly([
                    'bot_step'           => 'qualifying_subscribers',
                    'bot_failed_intents' => 0,
                    'bot_context'        => ['intent' => $intent],
                ]);
                return;
            }

            // ── Paso: esperando número de suscriptores ────────────────────────
            if ($step === 'qualifying_subscribers') {
                $context                 = $conversation->bot_context ?? [];
                $context['suscriptores'] = trim($body);

                // Si no conocemos el nombre del contacto, lo preguntamos.
                // Si ya lo tenemos (viene de la campaña / previo contacto), handoff directo.
                if (blank($conversation->contact->name)) {
                    $this->sendText(
                        $whatsapp, $phone, '¿Y cuál es tu nombre?',
                        $conversation, 'qualifying_name', $body, false, $tenant, $context,
                    );
                    $conversation->updateQuietly(['bot_step' => 'qualifying_name', 'bot_context' => $context]);
                } else {
                    $context['nombre'] = $conversation->contact->name;
                    $this->sendHandoffWithContext($whatsapp, $phone, $conversation, $botSettings, $body, $context, $tenant);
                }
                return;
            }

            // ── Paso: esperando nombre ────────────────────────────────────────
            if ($step === 'qualifying_name') {
                $context           = $conversation->bot_context ?? [];
                $context['nombre'] = trim($body);

                // Actualiza el perfil del contacto con el nombre capturado.
                $conversation->contact->updateQuietly(['name' => trim($body)]);

                $this->sendHandoffWithContext($whatsapp, $phone, $conversation, $botSettings, $body, $context, $tenant);
                return;
            }

        } finally {
            $lock->release();
        }
    }

    private function sendHandoffWithContext(
        WhatsAppService $whatsapp,
        string          $phone,
        Conversation    $conversation,
        BotSetting      $botSettings,
        string          $incomingBody,
        array           $context,
        Tenant          $tenant,
    ): void {
        $this->sendText(
            $whatsapp, $phone, $botSettings->msg_handoff,
            $conversation, 'handoff', $incomingBody, true, $tenant, $context,
        );
        $this->deactivateBot($conversation, $tenant);
    }

    /**
     * Desactiva el bot y opcionalmente asigna un agente.
     * updateQuietly evita disparar el observer de nuevo (evita el loop:
     * observer → updateQuietly → observer → …).
     */
    private function deactivateBot(Conversation $conversation, Tenant $tenant): void
    {
        $conversation->updateQuietly(['bot_active' => false, 'bot_step' => 'handed_off']);

        if ($tenant->auto_assign_enabled) {
            app(ConversationAssigner::class)->assignLeastBusy($conversation, $tenant);
        }
    }

    /**
     * Envía un mensaje de texto vía WhatsApp, crea el registro en messages
     * para que aparezca en el historial del chat, y guarda el log del bot.
     */
    private function sendText(
        WhatsAppService $whatsapp,
        string          $phone,
        string          $text,
        Conversation    $conversation,
        string          $intent,
        ?string         $incomingBody,
        bool            $escalated,
        Tenant          $tenant,
        array           $context = [],
    ): void {
        $result = $whatsapp->sendMessage($phone, $text);

        if ($result['success']) {
            Message::create([
                'tenant_id'       => $this->tenantId,
                'conversation_id' => $conversation->id,
                'contact_id'      => $conversation->contact->id,
                'body'            => $text,
                'status'          => 'sent',
                'type'            => 'bot',
                'wa_message_id'   => $result['data']['messages'][0]['id'] ?? null,
            ]);
            $conversation->touch();
        } else {
            Log::warning('Bot: fallo al enviar mensaje WhatsApp', [
                'conversation_id' => $conversation->id,
                'phone'           => $phone,
                'error'           => $result['error'] ?? 'unknown',
            ]);
        }

        BotLog::create([
            'tenant_id'       => $this->tenantId,
            'conversation_id' => $conversation->id,
            'incoming_body'   => $incomingBody,
            'intent_detected' => $intent,
            'bot_response'    => $text,
            'escalated'       => $escalated,
            'context_data'    => ! empty($context) ? json_encode($context, JSON_UNESCAPED_UNICODE) : null,
        ]);
    }

    private function branchMessage(string $intent, BotSetting $settings): string
    {
        return match ($intent) {
            'demo'  => $settings->msg_demo,
            'socio' => $settings->msg_socio,
            'price' => $settings->msg_price,
            default => $settings->msg_info,
        };
    }
}
