<?php

namespace App\Services\Flows\IO;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Label;
use App\Models\Message;
use App\Models\Team;
use App\Models\Tenant;
use App\Services\Assignment\ConversationAssigner;
use App\Services\Flows\CustomerLookup;
use App\Services\Flows\SendResult;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\WhatsAppService;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * El FlowIO de verdad: lo que el bloque hace, pasa.
 *
 * Todo envío sale por WhatsAppService (ahí viven las credenciales por tenant,
 * el modo mock y el manejo de BSUID) y queda como mensaje `type = 'bot'` en el
 * chat, para que el asesor lea exactamente lo que el cliente recibió.
 *
 * tenant_id explícito en cada escritura y consulta: defensa en profundidad
 * sobre el global scope, igual que en ProcessIncomingWhatsAppMessage.
 */
final class LiveFlowIO implements FlowIO
{
    public function __construct(
        private readonly WhatsAppService $whatsapp,
        private readonly Tenant $tenant,
        private readonly Conversation $conversation,
        private readonly Contact $contact,
        private readonly IspwatchRepository $ispwatch,
        private readonly ConversationAssigner $assigner,
    ) {}

    public function sendText(string $text): SendResult
    {
        return $this->deliver($text, fn (string $to) => $this->whatsapp->sendMessage($to, $text));
    }

    public function sendButtons(string $body, array $buttons): SendResult
    {
        $interactive = [
            'type'   => 'button',
            'body'   => ['text' => $body],
            'action' => [
                'buttons' => array_map(fn (array $b) => [
                    'type'  => 'reply',
                    'reply' => ['id' => $b['id'], 'title' => $b['title']],
                ], $buttons),
            ],
        ];

        $shown = $body . "\n\n" . implode("\n", array_map(fn (array $b) => '▫️ ' . $b['title'], $buttons));

        return $this->deliver($shown, fn (string $to) => $this->whatsapp->sendInteractive($to, $interactive), $interactive);
    }

    public function sendList(string $body, string $buttonLabel, array $rows): SendResult
    {
        $interactive = [
            'type'   => 'list',
            'body'   => ['text' => $body],
            'action' => [
                'button'   => $buttonLabel,
                'sections' => [[
                    'title' => 'Opciones',
                    'rows'  => array_map(fn (array $r) => array_filter([
                        'id'          => $r['id'],
                        'title'       => $r['title'],
                        'description' => $r['description'] ?? null,
                    ]), $rows),
                ]],
            ],
        ];

        $shown = $body . "\n\n" . implode("\n", array_map(fn (array $r) => '▫️ ' . $r['title'], $rows));

        return $this->deliver($shown, fn (string $to) => $this->whatsapp->sendInteractive($to, $interactive), $interactive);
    }

    public function contact(): array
    {
        return ['name' => $this->contact->name, 'phone' => $this->contact->phone];
    }

    public function tenantName(): string
    {
        return (string) $this->tenant->name;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function contactHasLabel(int $labelId): bool
    {
        return $this->contact->labels()->where('labels.id', $labelId)->exists();
    }

    public function addLabel(int $labelId): ?string
    {
        $label = $this->label($labelId);
        $label && $this->contact->labels()->syncWithoutDetaching([$label->id]);

        return $label?->name;
    }

    public function removeLabel(int $labelId): ?string
    {
        $label = $this->label($labelId);
        $label && $this->contact->labels()->detach($label->id);

        return $label?->name;
    }

    public function saveContactField(string $field, string $value): void
    {
        $value = trim($value);

        if ($field === 'name' && $value !== '') {
            $this->contact->updateQuietly(['name' => mb_substr($value, 0, 120)]);
        }

        if ($field === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->contact->updateQuietly(['email' => mb_strtolower($value)]);
        }
    }

    public function lookupCustomer(): CustomerLookup
    {
        if (! $this->tenant->ispwatch_tenant_id) {
            return CustomerLookup::unavailable('sin_vinculo');
        }

        // Un cliente con username de WhatsApp ocultó su número: en ispwatch se
        // busca por teléfono y el BSUID no le dice nada.
        if (! $this->contact->phone) {
            return CustomerLookup::unavailable('sin_telefono');
        }

        try {
            $customer = $this->ispwatch->customerByPhone(
                (int) $this->tenant->ispwatch_tenant_id,
                $this->contact->phone,
                $this->contact->name,
            );

            if ($customer === null) {
                return CustomerLookup::notFound();
            }

            return CustomerLookup::found(
                $customer,
                $this->ispwatch->pendingInvoicesForCustomer((int) $this->tenant->ispwatch_tenant_id, (int) $customer['user_id']),
            );
        } catch (\Throwable $e) {
            // ispwatch caído no puede tumbar la atención: el flujo sigue por
            // "No encontrado" y la traza dice que fue un error.
            Log::warning('Flujo: no se pudo consultar ispwatch', [
                'tenant_id' => $this->tenant->id,
                'error'     => $e->getMessage(),
            ]);

            return CustomerLookup::unavailable('error');
        }
    }

    public function handoff(?int $teamId, bool $assign, ?string $note): array
    {
        $done = [];

        if ($teamId !== null) {
            $team = Team::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->find($teamId);

            if ($team) {
                $this->conversation->updateQuietly(['team_id' => $team->id]);
                $this->system("🤖 El bot pasó la conversación al equipo {$team->name}.");
                $done['equipo'] = $team->name;
            }
        }

        if ($note !== null && trim($note) !== '') {
            Message::create([
                'tenant_id'       => $this->tenant->id,
                'conversation_id' => $this->conversation->id,
                'contact_id'      => $this->contact->id,
                'body'            => $note,
                'status'          => 'sent',
                'type'            => 'note',
                'sent_by_user_id' => null,
            ]);
            $done['nota'] = true;
        }

        // Se apaga el bot ANTES de asignar: la asignación dispara el observer y,
        // con el flag en true, la conversación seguiría pareciendo del bot.
        $this->conversation->updateQuietly(['bot_active' => false, 'bot_step' => 'handed_off']);

        if ($assign && $this->tenant->auto_assign_enabled && $this->conversation->assigned_to === null) {
            $staff = $this->assigner->assignLeastBusy($this->conversation, $this->tenant, $teamId);
            $done['asignado_a'] = $staff?->user?->name;
        }

        return $done;
    }

    /**
     * Envía, deja el mensaje en el chat y devuelve qué pasó.
     *
     * La ventana se mira ANTES de llamar a Meta: fuera de ella el texto libre
     * no se entrega, pero Meta no lo dice en el momento (acepta con 200 y lo
     * rechaza por webhook), y cada rechazo gasta calidad del número.
     */
    private function deliver(string $shown, callable $send, ?array $interactive = null): SendResult
    {
        $to = $this->contact->waDestino();
        if (! $to) {
            return SendResult::failed('sin_destino');
        }

        if (! $this->conversation->serviceWindowIsOpen()) {
            return SendResult::skipped('fuera_de_ventana');
        }

        $result = $send($to);
        $ok     = (bool) ($result['success'] ?? false);

        $metadata = array_filter([
            'interactive' => $interactive,
            'failure'     => $ok ? null : [
                'title' => 'El bot no pudo enviar este mensaje: ' . mb_substr((string) ($result['error'] ?? 'error desconocido'), 0, 300),
                'at'    => now()->toIso8601String(),
            ],
        ]);

        $message = Message::create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'contact_id'      => $this->contact->id,
            'body'            => $shown,
            'status'          => $ok ? 'sent' : 'failed',
            'type'            => 'bot',
            'wa_message_id'   => $result['data']['messages'][0]['id'] ?? null,
            'raw_metadata'    => $metadata !== [] ? $metadata : null,
        ]);

        $this->conversation->touch();

        if (! $ok) {
            Log::warning('Flujo: fallo al enviar por WhatsApp', [
                'conversation_id' => $this->conversation->id,
                'error'           => $result['error'] ?? 'unknown',
            ]);

            return SendResult::failed('error_whatsapp', $message->id);
        }

        return SendResult::sent($message->id);
    }

    private function label(int $labelId): ?Label
    {
        return Label::withoutGlobalScopes()->where('tenant_id', $this->tenant->id)->find($labelId);
    }

    private function system(string $body): void
    {
        Message::create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'contact_id'      => $this->contact->id,
            'body'            => $body,
            'status'          => 'sent',
            'type'            => 'system',
            'sent_by_user_id' => null,
        ]);
    }
}
