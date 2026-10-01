<?php

namespace App\Services\Flows\Nodes;

use App\Services\Flows\Execution;
use App\Services\Flows\Inbound;
use App\Services\Flows\Step;
use App\Services\Flows\TextMatcher;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * Opciones con una rama por opción. Es el menú fijo de cinco opciones del bot
 * cableado, ahora configurable.
 *
 * Tres estilos, porque WhatsApp los trata distinto:
 *   text    → texto con las opciones numeradas (1️⃣ …). Sirve en cualquier teléfono.
 *   buttons → hasta 3 botones de respuesta (título de hasta 20 caracteres).
 *   list    → una lista desplegable de hasta 10 opciones (título de hasta 24).
 *
 * Qué cuenta como elegir una opción, en este orden: tocar el botón o la fila
 * (llega su id), escribir su número (o el emoji), escribir su nombre, o una de
 * sus palabras clave. Es lo que hacía IntentDetector, por bloque y sin deploy.
 */
final class MenuNode extends NodeType
{
    public const STYLES         = ['text', 'buttons', 'list'];
    public const MAX_OPTIONS    = 10;
    public const MAX_BUTTONS    = 3;
    public const BUTTON_TITLE   = 20;
    public const LIST_TITLE     = 24;
    public const TEXT_TITLE     = 80;
    public const INTERACTIVE_BODY = 1024;
    public const MAX_RETRIES    = 3;

    private const KEYCAPS = ['1️⃣', '2️⃣', '3️⃣', '4️⃣', '5️⃣', '6️⃣', '7️⃣', '8️⃣', '9️⃣', '🔟'];

    public function type(): string
    {
        return 'menu';
    }

    public function label(): string
    {
        return 'Menú';
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
        $handles = array_map(fn (array $o) => 'opt_' . $o['id'], $this->options($data));
        $handles[] = 'no_match';

        return $handles;
    }

    public function outputLabel(string $handle, array $data): string
    {
        if ($handle === 'no_match') {
            return 'No entendió';
        }

        foreach ($this->options($data) as $option) {
            if ('opt_' . $option['id'] === $handle) {
                return "la opción «{$option['label']}»";
            }
        }

        return $handle;
    }

    public function definesVariables(array $data): array
    {
        return ['opcion', 'respuesta'];
    }

    public function texts(array $data): array
    {
        return array_merge(
            [$this->str($data, 'text'), $this->str($data, 'retry_text')],
            array_column($this->options($data), 'label'),
        );
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $data   = $node['data'];
        $id     = $node['id'];
        $style  = $this->style($data);
        $issues = [];

        if (! in_array($data['style'] ?? 'text', self::STYLES, true)) {
            $issues[] = Issue::error('Menú: estilo no válido.', $id);
        }

        $bodyMax = $style === 'text' ? self::MAX_TEXT : self::INTERACTIVE_BODY;
        array_push($issues, ...$this->requireText($node, 'text', 'el texto del menú', $bodyMax));

        $raw     = is_array($data['options'] ?? null) ? $data['options'] : [];
        $options = $this->options($data);

        if (count($raw) !== count($options)) {
            $issues[] = Issue::error('Menú: hay opciones sin nombre.', $id);
        }

        $max = match ($style) {
            'buttons' => self::MAX_BUTTONS,
            default   => self::MAX_OPTIONS,
        };

        if ($options === []) {
            $issues[] = Issue::error('Menú: agrega al menos una opción.', $id);
        } elseif (count($options) > $max) {
            $issues[] = Issue::error($style === 'buttons'
                ? 'Menú: WhatsApp permite hasta 3 botones. Usa el estilo lista para más opciones.'
                : 'Menú: máximo 10 opciones.', $id);
        }

        $titleMax = match ($style) {
            'buttons' => self::BUTTON_TITLE,
            'list'    => self::LIST_TITLE,
            default   => self::TEXT_TITLE,
        };

        $ids = [];
        $labels = [];
        foreach ($options as $option) {
            if (mb_strlen($option['label']) > $titleMax) {
                $issues[] = Issue::error("Menú: «{$option['label']}» supera los {$titleMax} caracteres que permite este estilo.", $id);
            }
            if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $option['id']) || isset($ids[$option['id']])) {
                $issues[] = Issue::error('Menú: hay opciones con identificador repetido o inválido. Bórrala y créala de nuevo.', $id);
            }
            $normalized = TextMatcher::normalize($option['label']);
            if (isset($labels[$normalized])) {
                $issues[] = Issue::error("Menú: la opción «{$option['label']}» está repetida.", $id);
            }
            foreach (TextMatcher::keywordList($option['keywords']) as $keyword) {
                if (mb_strlen($keyword) > StartNode::MAX_KEYWORD_CHARS) {
                    $issues[] = Issue::error("Menú: una palabra clave de «{$option['label']}» es demasiado larga.", $id);
                    break;
                }
            }
            $ids[$option['id']] = true;
            $labels[$normalized] = true;
        }

        if ($style === 'list') {
            $button = $this->str($data, 'button_label');
            if ($button === '' || mb_strlen($button) > self::BUTTON_TITLE) {
                $issues[] = Issue::error('Menú: el botón que abre la lista necesita un texto de hasta 20 caracteres.', $id);
            }
        }

        $retries = $data['max_retries'] ?? 1;
        if (! is_numeric($retries) || (int) $retries < 0 || (int) $retries > self::MAX_RETRIES) {
            $issues[] = Issue::error('Menú: los reintentos van de 0 a ' . self::MAX_RETRIES . '.', $id);
        }

        if (mb_strlen($this->str($data, 'retry_text')) > $bodyMax) {
            $issues[] = Issue::error('Menú: el texto de reintento es demasiado largo.', $id);
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        $intro = trim($ex->render($this->str($node['data'], 'text')));

        return $this->sendMenu($ex, $node['data'], $intro) ?? Step::waitInput();
    }

    public function receive(array $node, Execution $ex, Inbound $input): Step
    {
        $data   = $node['data'];
        $option = $this->match($this->options($data), $input);

        if ($option !== null) {
            $ex->set('opcion', $option['label']);
            $ex->set('respuesta', trim($input->text));
            $ex->resetRetries($node['id']);
            $ex->note(detail: ['opcion' => $option['label']], input: $input->text);

            return Step::next('opt_' . $option['id']);
        }

        $maxRetries = max(0, min(self::MAX_RETRIES, $this->int($data, 'max_retries', 1)));

        if ($ex->bumpRetries($node['id']) > $maxRetries) {
            $ex->resetRetries($node['id']);
            $ex->note(detail: ['no_entendio' => true], input: $input->text);

            return Step::next('no_match');
        }

        $retry = trim($ex->render($this->str($data, 'retry_text')))
            ?: 'No entendí tu respuesta 😅. Elige una de las opciones:';
        $ex->note(detail: ['reintento' => $ex->retries($node['id'])], input: $input->text);

        return $this->sendMenu($ex, $data, $retry) ?? Step::waitInput();
    }

    /**
     * Opciones válidas (con nombre), con las palabras clave como texto.
     *
     * @return list<array{id: string, label: string, keywords: string}>
     */
    public function options(array $data): array
    {
        $options = [];

        foreach ((array) ($data['options'] ?? []) as $option) {
            if (! is_array($option)) {
                continue;
            }

            $label = trim((string) ($option['label'] ?? ''));
            if ($label === '') {
                continue;
            }

            $keywords = $option['keywords'] ?? '';
            $options[] = [
                'id'       => (string) ($option['id'] ?? ''),
                'label'    => $label,
                'keywords' => is_array($keywords) ? implode(', ', $keywords) : (string) $keywords,
            ];
        }

        return $options;
    }

    /**
     * @param list<array{id: string, label: string, keywords: string}> $options
     * @return array{id: string, label: string, keywords: string}|null
     */
    public function match(array $options, Inbound $input): ?array
    {
        // 1. Tocó un botón o una fila de la lista.
        if ($input->replyId !== null) {
            foreach ($options as $option) {
                if ('opt_' . $option['id'] === $input->replyId) {
                    return $option;
                }
            }
        }

        // 2. Escribió el número (o su emoji).
        $number = TextMatcher::optionNumber($input->text);
        if ($number !== null && $number >= 1 && $number <= count($options)) {
            return $options[$number - 1];
        }

        $text = TextMatcher::normalize($input->text);
        if ($text === '') {
            return null;
        }

        // 3. Escribió el nombre de la opción.
        foreach ($options as $option) {
            if (TextMatcher::normalize($option['label']) === $text) {
                return $option;
            }
        }

        // 4. Una palabra clave, como palabra o frase completa. El orden de las
        //    opciones decide los empates.
        foreach ($options as $option) {
            foreach (TextMatcher::keywordList($option['keywords']) as $keyword) {
                if (TextMatcher::containsPhrase($text, $keyword)) {
                    return $option;
                }
            }
        }

        // 5. El nombre de la opción dentro de una frase ("quiero consultar mi
        //    saldo" elige «Consultar mi saldo»). Va después de las palabras
        //    clave: esas las puso el admin a propósito, esto es una coincidencia.
        foreach ($options as $option) {
            if (TextMatcher::containsPhrase($text, TextMatcher::normalize($option['label']))) {
                return $option;
            }
        }

        return null;
    }

    /** @return Step|null null = salió bien */
    private function sendMenu(Execution $ex, array $data, string $intro): ?Step
    {
        $options = $this->options($data);

        switch ($this->style($data)) {
            case 'buttons':
                $buttons = array_map(fn (array $o) => ['id' => 'opt_' . $o['id'], 'title' => $o['label']], $options);
                $shown   = $intro . "\n\n" . implode("\n", array_map(fn (array $b) => '▫️ ' . $b['title'], $buttons));

                return $this->afterSend($ex, $ex->io->sendButtons($intro, $buttons), $shown);

            case 'list':
                $rows  = array_map(fn (array $o) => ['id' => 'opt_' . $o['id'], 'title' => $o['label']], $options);
                $shown = $intro . "\n\n" . implode("\n", array_map(fn (array $r) => '▫️ ' . $r['title'], $rows));

                return $this->afterSend($ex, $ex->io->sendList($intro, $this->str($data, 'button_label', 'Ver opciones'), $rows), $shown);

            default:
                $text = $intro;
                if ($this->bool($data, 'append_options', true)) {
                    $lines = [];
                    foreach ($options as $i => $option) {
                        $lines[] = (self::KEYCAPS[$i] ?? ($i + 1) . '.') . ' ' . $option['label'];
                    }
                    $text = trim($intro . "\n\n" . implode("\n", $lines));
                }

                return $this->afterSend($ex, $ex->io->sendText($text), $text);
        }
    }

    private function style(array $data): string
    {
        $style = $data['style'] ?? 'text';

        return in_array($style, self::STYLES, true) ? $style : 'text';
    }
}
