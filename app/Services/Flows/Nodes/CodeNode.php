<?php

namespace App\Services\Flows\Nodes;

use App\Services\Flows\Execution;
use App\Services\Flows\Script\Interpreter;
use App\Services\Flows\Script\ScriptError;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;

/**
 * «Código»: un poco de JavaScript del ISP para lo que los otros bloques no
 * alcanzan a expresar: calcular (mora, descuentos, cuotas), armar textos,
 * clasificar con varias reglas, limpiar lo que escribió el cliente.
 *
 * Corre en la caja cerrada de Script\Interpreter: sin red, archivos, base de
 * datos ni nada del servidor, con topes de pasos, tiempo y tamaño. Lee las
 * variables del flujo (`vars`), deja las suyas (`vars.total = …`) y elige la
 * salida con `return "nombre"`:
 *
 *   Siguiente          → no devolvió nada, o devolvió algo que no es una salida.
 *   <salidas propias>  → devolvió su nombre (hasta 5, las define el ISP).
 *   Error              → el código falló o pasó un tope ({{codigo.error}} dice por qué).
 *
 * No espera al cliente ni le escribe: es instantáneo y corre igual en el
 * simulador que en una conversación real.
 */
final class CodeNode extends NodeType
{
    public const MAX_EXITS = 5;

    public const VARIABLES = [
        'codigo.resultado' => 'Lo que devolvió el último bloque «Código» con return',
        'codigo.error'     => 'Por qué falló el último bloque «Código» (vacío si salió bien)',
    ];

    public function type(): string
    {
        return 'code';
    }

    public function label(): string
    {
        return 'Código';
    }

    public function outputs(array $data): array
    {
        return ['next', ...array_map(fn (array $exit) => 'exit_' . $exit['id'], $this->exits($data)), 'error'];
    }

    public function outputLabel(string $handle, array $data): string
    {
        if ($handle === 'error') {
            return 'Error';
        }

        foreach ($this->exits($data) as $exit) {
            if ($handle === 'exit_' . $exit['id']) {
                return $exit['name'];
            }
        }

        return 'Siguiente';
    }

    public function definesVariables(array $data): array
    {
        $names = array_filter(
            Interpreter::writtenNames($this->str($data, 'code')),
            fn (string $name) => (bool) preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name),
        );

        return array_values(array_unique([...$names, ...array_keys(self::VARIABLES)]));
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $data   = $node['data'];
        $code   = (string) ($data['code'] ?? '');
        $issues = [];

        if (trim($code) === '') {
            return [Issue::error('Código: escribe el código del bloque.', $node['id'])];
        }

        if (($error = Interpreter::check($code)) !== null) {
            $issues[] = Issue::error("Código: {$error->getMessage()}", $node['id']);
        }

        foreach (Interpreter::writtenNames($code) as $name) {
            if (! preg_match('/^[a-z][a-z0-9_]{0,39}$/', $name)) {
                $issues[] = Issue::error("Código: «vars.{$name}» no sirve como variable del flujo (minúsculas, números y _).", $node['id']);
            } elseif (in_array($name, QuestionNode::RESERVED, true)) {
                $issues[] = Issue::error("Código: «{$name}» es una variable del sistema; usa otro nombre.", $node['id']);
            }
        }

        $raw = $data['exits'] ?? [];
        if (! is_array($raw) || count($raw) > self::MAX_EXITS) {
            $issues[] = Issue::error('Código: hasta ' . self::MAX_EXITS . ' salidas propias.', $node['id']);
        }

        $seen = [];
        foreach (is_array($raw) ? $raw : [] as $exit) {
            $name = is_array($exit) ? trim((string) ($exit['name'] ?? '')) : '';
            $id   = is_array($exit) ? (string) ($exit['id'] ?? '') : '';

            if ($name === '' || mb_strlen($name) > 40) {
                $issues[] = Issue::error('Código: cada salida necesita un nombre (hasta 40 caracteres).', $node['id']);
            } elseif (isset($seen[$key = mb_strtolower($name)])) {
                $issues[] = Issue::error("Código: hay dos salidas que se llaman «{$name}».", $node['id']);
            } elseif (in_array($key, ['siguiente', 'error'], true)) {
                $issues[] = Issue::error("Código: «{$name}» ya es una salida del bloque; usa otro nombre.", $node['id']);
            } else {
                $seen[$key] = true;
            }

            if (! preg_match('/^[A-Za-z0-9_-]{1,32}$/', $id)) {
                $issues[] = Issue::error('Código: una salida tiene un identificador inválido; bórrala y créala de nuevo.', $node['id']);
            }
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        $code        = (string) ($node['data']['code'] ?? '');
        $interpreter = new Interpreter(fn (string $name) => $ex->get($name));

        try {
            $result = $interpreter->run($code);
        } catch (ScriptError $e) {
            $ex->set('codigo.error', $e->getMessage());
            $ex->set('codigo.resultado', '');
            $ex->note(detail: ['error' => $e->getMessage()]);

            return Step::next('error');
        }

        foreach ($result->writes as $name => $value) {
            $ex->set($name, $value);
        }

        $returned = $result->returned === null ? '' : $interpreter->str($result->returned);
        $ex->set('codigo.resultado', mb_substr($returned, 0, Interpreter::MAX_VALUE));
        $ex->set('codigo.error', '');

        $handle = 'next';
        $exitName = null;

        foreach ($this->exits($node['data']) as $exit) {
            if ($returned !== '' && mb_strtolower(trim($returned)) === mb_strtolower($exit['name'])) {
                $handle   = 'exit_' . $exit['id'];
                $exitName = $exit['name'];
                break;
            }
        }

        $ex->note(detail: array_filter([
            'variables' => $result->writes ?: null,
            'devolvio'  => $returned !== '' ? mb_substr($returned, 0, 200) : null,
            'salida'    => $exitName,
            'log'       => $result->logs ?: null,
            'pasos'     => $result->steps,
        ], fn ($v) => $v !== null));

        return Step::next($handle);
    }

    /** @return list<array{id: string, name: string}> */
    private function exits(array $data): array
    {
        $exits = [];

        foreach (array_slice(is_array($data['exits'] ?? null) ? $data['exits'] : [], 0, self::MAX_EXITS) as $exit) {
            if (is_array($exit) && isset($exit['id']) && is_scalar($exit['id'])) {
                $exits[] = ['id' => (string) $exit['id'], 'name' => trim((string) ($exit['name'] ?? ''))];
            }
        }

        return $exits;
    }
}
