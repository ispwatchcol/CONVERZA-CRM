<?php

namespace App\Services\Flows\Nodes;

use App\Models\BotFlow;
use App\Services\Flows\Execution;
use App\Services\Flows\Step;
use App\Services\Flows\TextMatcher;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * El punto de entrada. Guarda CUÁNDO arranca el flujo (el disparador) y la
 * zona horaria con la que se evalúan los horarios y el saludo.
 *
 *   conversation_start → conversación nueva, reabierta, o el cliente vuelve a
 *                        escribir después de `idle_hours` horas de silencio.
 *   keyword            → el mensaje trae una de las palabras clave.
 */
final class StartNode extends NodeType
{
    public const MAX_KEYWORDS      = 20;
    public const MAX_KEYWORD_CHARS = 40;
    public const MAX_IDLE_HOURS    = 720;

    public function type(): string
    {
        return 'start';
    }

    public function label(): string
    {
        return 'Inicio';
    }

    public function enter(array $node, Execution $ex): Step
    {
        return Step::next();
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $issues  = [];
        $data    = $node['data'];
        $trigger = is_array($data['trigger'] ?? null) ? $data['trigger'] : [];
        $type    = $trigger['type'] ?? null;

        if (! in_array($type, [BotFlow::TRIGGER_CONVERSATION_START, BotFlow::TRIGGER_KEYWORD], true)) {
            $issues[] = Issue::error('Inicio: elige cuándo arranca el flujo.', $node['id']);
        }

        if ($type === BotFlow::TRIGGER_KEYWORD) {
            $raw = is_array($trigger['keywords'] ?? null)
                ? $trigger['keywords']
                : explode(',', (string) ($trigger['keywords'] ?? ''));
            $keywords = TextMatcher::keywordList($raw);

            if ($keywords === []) {
                $issues[] = Issue::error('Inicio: escribe al menos una palabra clave.', $node['id']);
            }
            if (count($keywords) > self::MAX_KEYWORDS) {
                $issues[] = Issue::error('Inicio: máximo ' . self::MAX_KEYWORDS . ' palabras clave.', $node['id']);
            }
            foreach ($raw as $keyword) {
                if (mb_strlen(trim((string) $keyword)) > self::MAX_KEYWORD_CHARS) {
                    $issues[] = Issue::error('Inicio: cada palabra clave puede tener hasta ' . self::MAX_KEYWORD_CHARS . ' caracteres.', $node['id']);
                    break;
                }
            }
        }

        if ($type === BotFlow::TRIGGER_CONVERSATION_START) {
            $idle = $trigger['idle_hours'] ?? null;
            if ($idle !== null && $idle !== '' && (! is_numeric($idle) || (int) $idle < 1 || (int) $idle > self::MAX_IDLE_HOURS)) {
                $issues[] = Issue::error('Inicio: las horas de silencio van de 1 a ' . self::MAX_IDLE_HOURS . ' (30 días).', $node['id']);
            }
        }

        $timezone = $data['timezone'] ?? null;
        if ($timezone !== null && $timezone !== '' && ! in_array($timezone, $ctx->timezones, true)) {
            $issues[] = Issue::error('Inicio: zona horaria no válida.', $node['id']);
        }

        return $issues;
    }

    /**
     * El disparador en su forma canónica, la que se copia a bot_flows al
     * publicar para que el enrutador de mensajes no tenga que abrir el grafo.
     *
     * @return array{type: string, config: array<string, mixed>}
     */
    public static function triggerOf(array $data): array
    {
        $trigger = is_array($data['trigger'] ?? null) ? $data['trigger'] : [];

        if (($trigger['type'] ?? null) === BotFlow::TRIGGER_KEYWORD) {
            return [
                'type'   => BotFlow::TRIGGER_KEYWORD,
                'config' => [
                    'keywords' => TextMatcher::keywordList($trigger['keywords'] ?? []),
                    'match'    => ($trigger['match'] ?? 'contains') === 'exact' ? 'exact' : 'contains',
                ],
            ];
        }

        $idle = $trigger['idle_hours'] ?? null;
        $idle = is_numeric($idle) && (int) $idle > 0 ? (int) $idle : null;

        return [
            'type'   => BotFlow::TRIGGER_CONVERSATION_START,
            'config' => ['idle_hours' => $idle],
        ];
    }

    public static function timezoneOf(array $data): string
    {
        $timezone = $data['timezone'] ?? null;

        return is_string($timezone) && $timezone !== ''
            ? $timezone
            : (string) config('flows.default_timezone', 'America/Bogota');
    }
}
