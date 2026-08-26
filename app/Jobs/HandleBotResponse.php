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
            if (! $conversation->contact) return;

            // Si un agente tomó la conversación entre el dispatch y ahora, no actuamos.
            if (! is_null($conversation->assigned_to)) return;

            $botSettings = BotSetting::where('tenant_id', $this->tenantId)->first();

            // El tenant nunca configuró el bot: silencio absoluto.
            if (! $botSettings) return;

            // Interruptor maestro y horario de atención. Si el bot venía
            // conduciendo la conversación, no la dejamos en el aire: cede el
            // control con el mensaje de handoff.
            if (! $botSettings->bot_enabled || ! $botSettings->respondsAt()) {
                $this->cutOff($whatsapp, $conversation, $botSettings, $tenant);
                return;
            }

            $message = Message::find($this->messageId);
            if (! $message) return;

            $phone = $conversation->contact->phone;
            $step  = $conversation->bot_step;
            $body  = $message->body;

            // ── Conversación nueva: primer mensaje del lead ───────────────────
            // El bot activa el flag, envía el saludo y espera intención.
            if ($this->isNewConversation && is_null($step)) {
                // Sin menú de bienvenida el bot no conduce nada: acusa recibo con
                // el mensaje de handoff y cede de inmediato a un asesor.
                if (! $botSettings->step_greeting_enabled) {
                    $this->sendText(
                        $whatsapp, $phone, $this->text($botSettings, 'msg_handoff'),
                        $conversation, 'handoff', $body, true, $tenant,
                    );
                    $this->deactivateBot($conversation, $tenant);
                    return;
                }

                $this->sendText(
                    $whatsapp, $phone, $this->text($botSettings, 'msg_greeting'),
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
                        $whatsapp, $phone, $this->text($botSettings, 'msg_handoff'),
                        $conversation, 'agent', $body, true, $tenant,
                    );
                    $this->deactivateBot($conversation, $tenant);
                    return;
                }

                if ($intent === 'unknown') {
                    // Sin reintentos: el primer "no entendí" escala directo.
                    if (! $botSettings->step_fallback_enabled) {
                        $this->sendText(
                            $whatsapp, $phone, $this->text($botSettings, 'msg_fallback_2'),
                            $conversation, 'fallback_2', $body, true, $tenant,
                        );
                        $this->deactivateBot($conversation, $tenant);
                        return;
                    }

                    $failed = $conversation->bot_failed_intents + 1;

                    if ($failed >= 2) {
                        $this->sendText(
                            $whatsapp, $phone, $this->text($botSettings, 'msg_fallback_2'),
                            $conversation, 'fallback_2', $body, true, $tenant,
                        );
                        $this->deactivateBot($conversation, $tenant);
                    } else {
                        $this->sendText(
                            $whatsapp, $phone, $this->text($botSettings, 'msg_fallback_1'),
                            $conversation, 'fallback_1', $body, false, $tenant,
                        );
                        $conversation->updateQuietly(['bot_failed_intents' => $failed]);
                    }
                    return;
                }

                // Intención reconocida. La rama detectada viaja como contexto para
                // el asesor aunque no se envíe el discurso comercial.
                $context = ['intent' => $intent];
                $conversation->updateQuietly(['bot_failed_intents' => 0]);

                // Con las ramas apagadas el bot no vende: salta el discurso y va
                // derecho a calificar (o al handoff si tampoco hay calificación).
                // Como no se envió una rama, la pregunta por los suscriptores sí
                // hay que hacerla explícitamente.
                if (! $botSettings->step_branches_enabled) {
                    $this->advanceQualification(
                        $whatsapp, $phone, $conversation, $botSettings, $body, $context, $tenant,
                        from: 'branch', subscribersAlreadyAsked: false,
                    );
                    return;
                }

                $this->sendText(
                    $whatsapp, $phone, $this->branchMessage($intent, $botSettings),
                    $conversation, $intent, $body, false, $tenant,
                );

                // Los textos de rama YA terminan preguntando por los suscriptores,
                // así que al entrar al paso desde aquí no repetimos la pregunta.
                $this->advanceQualification(
                    $whatsapp, $phone, $conversation, $botSettings, $body, $context, $tenant,
                    from: 'branch', subscribersAlreadyAsked: true,
                );
                return;
            }

            // ── Paso: esperando número de suscriptores ────────────────────────
            if ($step === 'qualifying_subscribers') {
                $context                 = $conversation->bot_context ?? [];
                $context['suscriptores'] = trim($body);

                $this->advanceQualification(
                    $whatsapp, $phone, $conversation, $botSettings, $body, $context, $tenant,
                    from: 'subscribers',
                );
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

    /**
     * Avanza a la siguiente etapa de calificación habilitada.
     *
     * Cada paso desactivado se salta y se pasa al siguiente; si no queda
     * ninguno, el bot hace handoff. Así los switches de Configuración componen
     * entre sí sin dejar al cliente esperando una pregunta que nunca llega.
     *
     * @param string $from                    'branch' (venimos de una rama comercial)
     *                                        | 'subscribers' (ya capturamos los suscriptores)
     * @param bool   $subscribersAlreadyAsked El mensaje recién enviado ya contiene
     *                                        la pregunta por los suscriptores.
     */
    private function advanceQualification(
        WhatsAppService $whatsapp,
        string          $phone,
        Conversation    $conversation,
        BotSetting      $settings,
        ?string         $incomingBody,
        array           $context,
        Tenant          $tenant,
        string          $from,
        bool            $subscribersAlreadyAsked = false,
    ): void {
        if ($from === 'branch' && $settings->step_qualify_subscribers_enabled) {
            if (! $subscribersAlreadyAsked) {
                $this->sendText(
                    $whatsapp, $phone, $this->text($settings, 'msg_ask_subscribers'),
                    $conversation, 'qualifying_subscribers', $incomingBody, false, $tenant, $context,
                );
            }

            $conversation->updateQuietly([
                'bot_step'    => 'qualifying_subscribers',
                'bot_context' => $context,
            ]);
            return;
        }

        // Si no conocemos el nombre del contacto, lo preguntamos.
        // Si ya lo tenemos (viene de la campaña / previo contacto), handoff directo.
        if ($settings->step_qualify_name_enabled) {
            if (blank($conversation->contact->name)) {
                $this->sendText(
                    $whatsapp, $phone, $this->text($settings, 'msg_ask_name'),
                    $conversation, 'qualifying_name', $incomingBody, false, $tenant, $context,
                );

                $conversation->updateQuietly([
                    'bot_step'    => 'qualifying_name',
                    'bot_context' => $context,
                ]);
                return;
            }

            $context['nombre'] = $conversation->contact->name;
        }

        $this->sendHandoffWithContext($whatsapp, $phone, $conversation, $settings, $incomingBody, $context, $tenant);
    }

    /**
     * Corte del bot a mitad de conversación: el interruptor maestro se apagó o
     * el horario se cerró mientras el bot conducía el flujo. Enviamos el handoff
     * para que el cliente no quede en el aire y cedemos el control.
     *
     * Solo actúa si el bot tenía el control. Como deactivateBot() pone
     * bot_active en false, el corte ocurre exactamente una vez por conversación:
     * los mensajes siguientes ya no despiertan al bot.
     */
    private function cutOff(
        WhatsAppService $whatsapp,
        Conversation    $conversation,
        BotSetting      $settings,
        Tenant          $tenant,
    ): void {
        if (! $conversation->bot_active) {
            return;
        }

        $this->sendText(
            $whatsapp,
            $conversation->contact->phone,
            $this->text($settings, 'msg_handoff'),
            $conversation,
            'cut_off',
            null,
            true,
            $tenant,
            $conversation->bot_context ?? [],
        );

        $this->deactivateBot($conversation, $tenant);
    }

    private function sendHandoffWithContext(
        WhatsAppService $whatsapp,
        string          $phone,
        Conversation    $conversation,
        BotSetting      $botSettings,
        ?string         $incomingBody,
        array           $context,
        Tenant          $tenant,
    ): void {
        $this->sendText(
            $whatsapp, $phone, $this->text($botSettings, 'msg_handoff'),
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
            'demo'  => $this->text($settings, 'msg_demo'),
            'socio' => $this->text($settings, 'msg_socio'),
            'price' => $this->text($settings, 'msg_price'),
            default => $this->text($settings, 'msg_info'),
        };
    }

    /**
     * Texto configurado del bot, cayendo al default cuando la columna está
     * vacía. Los msg_ask_* se añadieron después y son nullable, así que las
     * filas creadas antes de la migración no los tienen.
     */
    private function text(BotSetting $settings, string $field): string
    {
        $value = $settings->{$field};

        return filled($value) ? $value : (BotSetting::defaults()[$field] ?? '');
    }
}
