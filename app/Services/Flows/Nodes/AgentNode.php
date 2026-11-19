<?php

namespace App\Services\Flows\Nodes;

use App\Services\Ai\Agents\AgentReply;
use App\Services\Ai\Agents\AgentResponder;
use App\Services\Flows\AgentOutcome;
use App\Services\Flows\Execution;
use App\Services\Flows\Inbound;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;
use Carbon\CarbonImmutable;

/**
 * Conversa con el cliente usando un agente IA de la empresa (su prompt, su
 * proveedor, su modelo) hasta que haga falta salir:
 *
 *   Pasar a un asesor    → el cliente lo pidió, es una queja o un tema delicado,
 *                          el agente no supo, o se alcanzó el tope de respuestas.
 *   Conversación resuelta → el cliente se despidió o quedó resuelto.
 *   IA no disponible     → apagada, sin llave, sin presupuesto, el proveedor
 *                          falló o no contestó a tiempo.
 *
 * Igual que «Comprobante de pago», la llamada a la IA NO corre en el job del
 * flujo: el bloque pide una tarea (Step::waitTask), la contesta RunAiAgentTurn
 * en la cola `ai` y el flujo sigue por resume(). Y el agente no ejecuta nada:
 * solo responde o pide salir; lo que pase después lo deciden los bloques que el
 * ISP conecta a cada salida.
 *
 * Lo que el cliente escribe mientras el agente piensa no se pierde: al llegar la
 * respuesta, si hay mensajes nuevos, se le contestan enseguida.
 */
final class AgentNode extends NodeType
{
    /** Tipo de tarea que encola FlowRunner. */
    public const TASK = 'agent';

    /** El cuerpo que deja la ingesta en un medio sin pie de foto (ProcessIncomingWhatsAppMessage). */
    private const MEDIA_PLACEHOLDERS = ['📷 Imagen', '🎭 Sticker', '🎤 Audio', '🎥 Video', '📎 Documento'];

    public const VARIABLES = [
        'agente.respuesta' => 'Lo último que respondió el agente IA',
        'agente.salida'    => 'Por qué salió: asesor, resuelto, tope o no_disponible',
    ];

    public function type(): string
    {
        return 'ai_agent';
    }

    public function label(): string
    {
        return 'Agente IA';
    }

    public function waitsForInput(): bool
    {
        return true;
    }

    public function sendsMessage(array $data): bool
    {
        return true;
    }

    public function outputs(array $data): array
    {
        return ['human', 'done', 'unavailable'];
    }

    public function outputLabel(string $handle, array $data): string
    {
        return match ($handle) {
            'done'        => 'Conversación resuelta',
            'unavailable' => 'IA no disponible',
            default       => 'Pasar a un asesor',
        };
    }

    public function definesVariables(array $data): array
    {
        return array_keys(self::VARIABLES);
    }

    public function texts(array $data): array
    {
        return [$this->str($data, 'greeting')];
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $data   = $node['data'];
        $issues = [];

        $agentId = $data['agent_id'] ?? null;
        if (! is_numeric($agentId) || ! array_key_exists((int) $agentId, $ctx->agents)) {
            $issues[] = Issue::error('Agente IA: elige uno de tus agentes activos (se crean en Agentes IA).', $node['id']);
        }

        $limit = (int) config('ai.agents.max_turns_limit', 30);
        $turns = $data['max_turns'] ?? config('ai.agents.default_max_turns', 10);
        if (! is_numeric($turns) || (int) $turns < 1 || (int) $turns > $limit) {
            $issues[] = Issue::error("Agente IA: el tope de respuestas va de 1 a {$limit}.", $node['id']);
        }

        if (mb_strlen($this->str($data, 'greeting')) > self::MAX_TEXT) {
            $issues[] = Issue::error('Agente IA: el saludo supera los ' . self::MAX_TEXT . ' caracteres que permite WhatsApp.', $node['id']);
        }

        if (! $ctx->aiEnabled) {
            $issues[] = Issue::warning('Agente IA: la IA no está lista para tu empresa (Configuración → Pagos e IA: enciéndela y carga tu llave). Mientras tanto el bloque sale siempre por «IA no disponible».', $node['id']);
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        $ex->set('agente.respuesta', '');
        $ex->set('agente.salida', '');
        $ex->state['agent'][$node['id']] = ['history' => [], 'turns' => 0, 'seen' => $ex->io->latestInboundId()];

        // Con saludo: el bot lo dice y espera al cliente. Sin saludo: el agente
        // contesta de una vez lo que el cliente escribió al abrir el chat.
        $greeting = trim($ex->render($this->str($node['data'], 'greeting')));

        if ($greeting !== '') {
            $this->remember($ex, $node, 'assistant', $greeting);

            return $this->afterSend($ex, $ex->io->sendText($greeting), $greeting) ?? Step::waitInput();
        }

        $opening = trim((string) ($ex->get('mensaje_inicial') ?? ''));
        $this->remember($ex, $node, 'user', $opening !== '' ? $opening : 'Hola');

        return $this->ask($node, $ex);
    }

    public function receive(array $node, Execution $ex, Inbound $input): Step
    {
        $ex->note(input: $input->text);
        $this->remember($ex, $node, 'user', $this->describe($input->text, $input->type ?? 'text'));

        if ($input->messageId !== null && $input->messageId > 0) {
            $ex->state['agent'][$node['id']]['seen'] = $input->messageId;
        }

        return $this->ask($node, $ex);
    }

    public function resume(array $node, Execution $ex): Step
    {
        $state = $ex->state['agent'][$node['id']] ?? null;

        if (! is_array($state) || ! isset($state['turn'])) {
            return $this->leave($ex, $node, 'unavailable', 'no_disponible', ['motivo' => 'estado_perdido']);
        }

        $turnId  = (int) $state['turn'];
        $until   = CarbonImmutable::parse((string) ($state['until'] ?? 'now'));
        $outcome = $ex->io->agentTurn($turnId);

        if ($outcome !== null && $outcome->pending()) {
            if ($ex->io->now()->lt($until)) {
                // Lo despertó un aviso viejo y la IA sigue pensando: a esperar.
                return Step::waitTask(self::TASK, $turnId, $until, dispatch: false);
            }

            $outcome = $ex->io->agentTurn($turnId, giveUp: true) ?? $outcome;
            $ex->note(detail: ['plazo_vencido' => true]);
        }

        unset($ex->state['agent'][$node['id']]['turn'], $ex->state['agent'][$node['id']]['until']);

        return $this->conclude($node, $ex, $outcome);
    }

    /** Pide la siguiente respuesta del agente con todo lo conversado hasta ahora. */
    private function ask(array $node, Execution $ex): Step
    {
        $state = (array) ($ex->state['agent'][$node['id']] ?? []) + ['history' => [], 'turns' => 0];
        $max   = max(1, min((int) config('ai.agents.max_turns_limit', 30), $this->int($node['data'], 'max_turns', (int) config('ai.agents.default_max_turns', 10))));

        if ((int) $state['turns'] >= $max) {
            return $this->leave($ex, $node, 'human', 'tope', ['tope_de_respuestas' => $max]);
        }

        $agent = $ex->io->agent($this->int($node['data'], 'agent_id'));

        if ($agent === null || ! $agent['active']) {
            return $this->leave($ex, $node, 'unavailable', 'no_disponible', ['motivo' => $agent === null ? 'agente_borrado' : 'agente_inactivo']);
        }

        $instructions = AgentResponder::instructions($ex->render($agent['instructions']), [
            'empresa'  => $ex->get('empresa.nombre'),
            'contacto' => $ex->get('contacto.nombre'),
            'dia'      => $ex->get('fecha.dia'),
            'fecha'    => $ex->get('fecha.hoy'),
            'hora'     => $ex->get('fecha.hora'),
        ]);

        $ex->state['agent'][$node['id']]['turns'] = (int) $state['turns'] + 1;

        $outcome = $ex->io->startAgentTurn($agent['id'], $instructions, AgentResponder::history($state['history']), $agent['model']);
        $ex->note(detail: ['agente' => $agent['name'], 'respuesta_n' => (int) $state['turns'] + 1]);

        if (! $outcome->pending()) {
            return $this->conclude($node, $ex, $outcome);
        }

        $until = $ex->io->now()->addMinutes(max(1, (int) config('ai.agents.task_timeout_minutes', 3)));
        $ex->state['agent'][$node['id']]['turn']  = $outcome->turnId;
        $ex->state['agent'][$node['id']]['until'] = $until->toIso8601String();

        return Step::waitTask(self::TASK, $outcome->turnId, $until);
    }

    private function conclude(array $node, Execution $ex, ?AgentOutcome $outcome): Step
    {
        if ($outcome === null || ! $outcome->answered()) {
            return $this->leave($ex, $node, 'unavailable', 'no_disponible', array_filter(['motivo' => $outcome?->reason ?? 'sin_respuesta']));
        }

        $reply = trim((string) $outcome->reply);

        if ($reply !== '') {
            $this->remember($ex, $node, 'assistant', $reply);
            $ex->set('agente.respuesta', $reply);

            if (($stop = $this->afterSend($ex, $ex->io->sendText($reply), $reply)) !== null) {
                return $stop;
            }
        }

        if ($outcome->action === AgentReply::HUMAN) {
            return $this->leave($ex, $node, 'human', 'asesor');
        }

        if ($outcome->action === AgentReply::END) {
            return $this->leave($ex, $node, 'done', 'resuelto');
        }

        // Lo que escribió el cliente mientras el agente pensaba: se le contesta ya.
        $seen = $ex->state['agent'][$node['id']]['seen'] ?? null;
        $more = $seen !== null ? $ex->io->inboundAfter((int) $seen) : [];

        if ($more !== []) {
            foreach ($more as $message) {
                $this->remember($ex, $node, 'user', $this->describe($message['text'], $message['type']));
                $ex->state['agent'][$node['id']]['seen'] = $message['id'];
            }

            $ex->note(detail: ['mensajes_mientras_pensaba' => count($more)]);

            return $this->ask($node, $ex);
        }

        return Step::waitInput();
    }

    /** Sale del bloque y limpia su estado. */
    private function leave(Execution $ex, array $node, string $handle, string $why, array $detail = []): Step
    {
        $ex->set('agente.salida', $why);
        $ex->note(detail: ['salida' => $why] + $detail);
        unset($ex->state['agent'][$node['id']]);

        return Step::next($handle);
    }

    private function remember(Execution $ex, array $node, string $role, string $content): void
    {
        $history   = (array) ($ex->state['agent'][$node['id']]['history'] ?? []);
        $history[] = ['role' => $role, 'content' => mb_substr($content, 0, 2000)];

        // Lo que no va a viajar a la IA tampoco se guarda en la ejecución.
        $ex->state['agent'][$node['id']]['history'] = array_slice($history, -max(2, (int) config('ai.agents.history_messages', 20)));
    }

    /** Un audio o una foto no se le pasan a la IA en esta versión: se le dice qué llegó. */
    private function describe(string $text, string $type): string
    {
        $text = trim($text);

        if (in_array($type, ['text', 'interactive', 'button'], true)) {
            return $text;
        }

        $what = match ($type) {
            'image'    => 'una imagen',
            'audio'    => 'una nota de voz',
            'video'    => 'un video',
            'document' => 'un documento',
            'sticker'  => 'un sticker',
            'location' => 'una ubicación',
            default    => "un mensaje de tipo {$type}",
        };

        // Sin pie de foto, el cuerpo es el marcador de la bandeja («📷 Imagen»).
        $caption = in_array($text, self::MEDIA_PLACEHOLDERS, true) ? '' : $text;

        return "(El cliente envió {$what}" . ($caption !== '' ? " con el texto: «{$caption}»" : '') . '. No puedes verlo ni escucharlo.)';
    }
}
