<?php

namespace App\Services\Flows\Nodes;

use App\Services\Bot\ScheduleWindow;
use App\Services\Flows\Execution;
use App\Services\Flows\Step;
use App\Services\Flows\TextMatcher;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * Ramifica en Sí / No según reglas sobre variables, etiquetas del contacto u
 * horario. Con varias reglas, `match` decide si tienen que cumplirse todas o
 * basta una.
 */
final class ConditionNode extends NodeType
{
    public const KINDS = ['variable', 'label', 'schedule'];

    public const OPERATORS = [
        'equals', 'not_equals', 'contains', 'not_contains', 'starts_with',
        'is_empty', 'is_not_empty', 'greater_than', 'less_than',
    ];

    public const MAX_RULES = 10;

    public function type(): string
    {
        return 'condition';
    }

    public function label(): string
    {
        return 'Condición';
    }

    public function outputs(array $data): array
    {
        return ['true', 'false'];
    }

    public function outputLabel(string $handle, array $data): string
    {
        return $handle === 'true' ? 'Sí' : 'No';
    }

    public function texts(array $data): array
    {
        $texts = [];
        foreach ($this->rules($data) as $rule) {
            if (($rule['kind'] ?? null) === 'variable') {
                $texts[] = '{{' . ($rule['variable'] ?? '') . '}}';
                $texts[] = (string) ($rule['value'] ?? '');
            }
        }

        return $texts;
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $id     = $node['id'];
        $rules  = $this->rules($node['data']);
        $issues = [];

        if ($rules === []) {
            return [Issue::error('Condición: agrega al menos una regla.', $id)];
        }
        if (count($rules) > self::MAX_RULES) {
            $issues[] = Issue::error('Condición: máximo ' . self::MAX_RULES . ' reglas.', $id);
        }
        if (! in_array($node['data']['match'] ?? 'all', ['all', 'any'], true)) {
            $issues[] = Issue::error('Condición: elige si deben cumplirse todas las reglas o basta una.', $id);
        }

        foreach ($rules as $i => $rule) {
            $n    = $i + 1;
            $kind = $rule['kind'] ?? null;

            if (! in_array($kind, self::KINDS, true)) {
                $issues[] = Issue::error("Condición: la regla {$n} no tiene tipo.", $id);
                continue;
            }

            if ($kind === 'variable') {
                if (! preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*$/', (string) ($rule['variable'] ?? ''))) {
                    $issues[] = Issue::error("Condición: la regla {$n} necesita una variable.", $id);
                }
                if (! in_array($rule['operator'] ?? null, self::OPERATORS, true)) {
                    $issues[] = Issue::error("Condición: la regla {$n} necesita una comparación.", $id);
                }
            }

            if ($kind === 'label') {
                if (! array_key_exists((int) ($rule['label_id'] ?? 0), $ctx->labels)) {
                    $issues[] = Issue::error("Condición: la etiqueta de la regla {$n} no existe (¿la borraron?).", $id);
                }
                if (! in_array($rule['operator'] ?? 'has', ['has', 'not_has'], true)) {
                    $issues[] = Issue::error("Condición: la regla {$n} tiene una comparación no válida.", $id);
                }
            }

            if ($kind === 'schedule') {
                $days = array_filter((array) ($rule['days'] ?? []), fn ($d) => is_numeric($d) && (int) $d >= 1 && (int) $d <= 7);
                if ($days === []) {
                    $issues[] = Issue::error("Condición: el horario de la regla {$n} no tiene días.", $id);
                }
                foreach (['start', 'end'] as $key) {
                    if (! preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', (string) ($rule[$key] ?? ''))) {
                        $issues[] = Issue::error("Condición: el horario de la regla {$n} necesita horas en formato HH:MM.", $id);
                        break;
                    }
                }
            }
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        $any     = ($node['data']['match'] ?? 'all') === 'any';
        $results = array_map(fn (array $rule) => $this->evaluate($rule, $ex), $this->rules($node['data']));

        $passed = $results !== [] && ($any
            ? in_array(true, $results, true)
            : ! in_array(false, $results, true));

        $ex->note(detail: ['reglas' => $results, 'resultado' => $passed ? 'sí' : 'no']);

        return Step::next($passed ? 'true' : 'false');
    }

    private function evaluate(array $rule, Execution $ex): bool
    {
        return match ($rule['kind'] ?? null) {
            'variable' => $this->compare(
                (string) ($ex->get((string) ($rule['variable'] ?? '')) ?? ''),
                (string) ($rule['operator'] ?? 'equals'),
                $ex->render((string) ($rule['value'] ?? '')),
            ),
            'label' => ($rule['operator'] ?? 'has') === 'not_has'
                ? ! $ex->io->contactHasLabel((int) ($rule['label_id'] ?? 0))
                : $ex->io->contactHasLabel((int) ($rule['label_id'] ?? 0)),
            'schedule' => ScheduleWindow::contains(
                (array) ($rule['days'] ?? []),
                $rule['start'] ?? null,
                $rule['end'] ?? null,
                $ex->now(),
            ),
            default => false,
        };
    }

    private function compare(string $actual, string $operator, string $expected): bool
    {
        $a = TextMatcher::normalize($actual);
        $e = TextMatcher::normalize($expected);

        return match ($operator) {
            'equals'       => $a === $e,
            'not_equals'   => $a !== $e,
            'contains'     => $e !== '' && str_contains($a, $e),
            'not_contains' => $e === '' || ! str_contains($a, $e),
            'starts_with'  => $e !== '' && str_starts_with($a, $e),
            'is_empty'     => trim($actual) === '',
            'is_not_empty' => trim($actual) !== '',
            'greater_than' => ($x = $this->number($actual)) !== null && ($y = $this->number($expected)) !== null && $x > $y,
            'less_than'    => ($x = $this->number($actual)) !== null && ($y = $this->number($expected)) !== null && $x < $y,
            default        => false,
        };
    }

    /** "$45.000" → 45000; "3" → 3; sin número → null. */
    private function number(string $value): ?float
    {
        $clean = preg_replace('/[^\d,.\-]/', '', $value);
        if ($clean === '' || $clean === null) {
            return null;
        }

        // Formato colombiano: el punto separa miles y la coma los decimales.
        if (preg_match('/^-?\d{1,3}(\.\d{3})+(,\d+)?$/', $clean)) {
            $clean = str_replace(['.', ','], ['', '.'], $clean);
        } else {
            $clean = str_replace(',', '.', $clean);
        }

        return is_numeric($clean) ? (float) $clean : null;
    }

    /** @return list<array<string, mixed>> */
    private function rules(array $data): array
    {
        return array_values(array_filter((array) ($data['rules'] ?? []), 'is_array'));
    }
}
