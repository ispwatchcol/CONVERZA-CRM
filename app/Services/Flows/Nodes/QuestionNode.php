<?php

namespace App\Services\Flows\Nodes;

use App\Services\Flows\Execution;
use App\Services\Flows\Inbound;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * Pregunta, espera la respuesta y la guarda en una variable.
 *
 * Es el `qualifying_name` del bot cableado, generalizado: puede validar la
 * respuesta (número, correo, teléfono) y copiarla a la ficha del contacto.
 */
final class QuestionNode extends NodeType
{
    public const VALIDATIONS = ['any', 'number', 'email', 'phone'];

    /** Variables que no se pueden pisar con "Guardar en": las pone el motor. */
    public const RESERVED = ['respuesta', 'opcion', 'mensaje_inicial', 'saludo'];

    public const MAX_RETRIES = 3;

    public function type(): string
    {
        return 'question';
    }

    public function label(): string
    {
        return 'Pregunta';
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
        return $this->validation($data) === 'any' ? ['next'] : ['next', 'invalid'];
    }

    public function outputLabel(string $handle, array $data): string
    {
        return $handle === 'invalid' ? 'Sin respuesta válida' : 'Respondió';
    }

    public function definesVariables(array $data): array
    {
        $saveAs = $this->str($data, 'save_as');

        return array_values(array_filter([$saveAs, 'respuesta']));
    }

    public function texts(array $data): array
    {
        return [$this->str($data, 'text'), $this->str($data, 'retry_text')];
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $data   = $node['data'];
        $issues = $this->requireText($node, 'text', 'la pregunta');

        $saveAs = $this->str($data, 'save_as');
        if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $saveAs)) {
            $issues[] = Issue::error('Pregunta: "Guardar respuesta en" debe ser un nombre en minúsculas, sin espacios ni tildes (ej. nombre, ciudad, numero_contrato).', $node['id']);
        } elseif (in_array($saveAs, self::RESERVED, true)) {
            $issues[] = Issue::error("Pregunta: \"{$saveAs}\" es una variable del sistema; usa otro nombre.", $node['id']);
        }

        if (! in_array($data['validation'] ?? 'any', self::VALIDATIONS, true)) {
            $issues[] = Issue::error('Pregunta: tipo de respuesta no válido.', $node['id']);
        }

        $retries = $data['max_retries'] ?? 1;
        if (! is_numeric($retries) || (int) $retries < 0 || (int) $retries > self::MAX_RETRIES) {
            $issues[] = Issue::error('Pregunta: los reintentos van de 0 a ' . self::MAX_RETRIES . '.', $node['id']);
        }

        if (! in_array($data['save_to_contact'] ?? '', ['', null, 'name', 'email'], true)) {
            $issues[] = Issue::error('Pregunta: campo de la ficha no válido.', $node['id']);
        }

        if (mb_strlen($this->str($data, 'retry_text')) > self::MAX_TEXT) {
            $issues[] = Issue::error('Pregunta: el texto de reintento es demasiado largo.', $node['id']);
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        $text = trim($ex->render($this->str($node['data'], 'text')));

        return $this->afterSend($ex, $ex->io->sendText($text), $text) ?? Step::waitInput();
    }

    public function receive(array $node, Execution $ex, Inbound $input): Step
    {
        $data  = $node['data'];
        $value = $this->accept($this->validation($data), trim($input->text));

        if ($value !== null) {
            $saveAs = $this->str($data, 'save_as');
            $ex->set($saveAs, $value);
            $ex->set('respuesta', $value);
            $ex->resetRetries($node['id']);

            $field = $data['save_to_contact'] ?? '';
            if (in_array($field, ['name', 'email'], true)) {
                $ex->io->saveContactField($field, $value);
            }

            $ex->note(detail: ['guardado' => [$saveAs => $value]], input: $input->text);

            return Step::next('next');
        }

        $maxRetries = max(0, min(self::MAX_RETRIES, $this->int($data, 'max_retries', 1)));

        if ($ex->bumpRetries($node['id']) > $maxRetries) {
            $ex->resetRetries($node['id']);
            $ex->note(detail: ['sin_respuesta_valida' => true], input: $input->text);

            return Step::next($this->validation($data) === 'any' ? 'next' : 'invalid');
        }

        $retry = trim($ex->render($this->str($data, 'retry_text'))) ?: $this->defaultRetry($this->validation($data));
        $ex->note(input: $input->text);

        return $this->afterSend($ex, $ex->io->sendText($retry), $retry) ?? Step::waitInput();
    }

    /** La respuesta normalizada si sirve, o null si no. */
    private function accept(string $validation, string $value): ?string
    {
        return match ($validation) {
            // "unos 500" → 500: lo que importa para una condición posterior es el número.
            'number' => preg_match('/-?\d+(?:[.,]\d+)?/', str_replace(' ', '', $value), $m)
                ? str_replace(',', '.', $m[0])
                : null,
            'email' => filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_strtolower($value) : null,
            'phone' => ($digits = preg_replace('/\D/', '', $value)) !== null && strlen($digits) >= 7 && strlen($digits) <= 15
                ? $digits
                : null,
            default => $value !== '' ? mb_substr($value, 0, 1000) : null,
        };
    }

    private function validation(array $data): string
    {
        $validation = $data['validation'] ?? 'any';

        return in_array($validation, self::VALIDATIONS, true) ? $validation : 'any';
    }

    private function defaultRetry(string $validation): string
    {
        return match ($validation) {
            'number' => 'Por favor respóndeme con un número 🙏',
            'email'  => 'Ese correo no parece válido. ¿Me lo escribes de nuevo?',
            'phone'  => 'Escríbeme un número de teléfono válido, por favor.',
            default  => '¿Me lo repites, por favor?',
        };
    }
}
