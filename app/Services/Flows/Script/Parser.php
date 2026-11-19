<?php

namespace App\Services\Flows\Script;

/**
 * Arma el árbol del código del bloque «Código»: un subconjunto de JavaScript.
 *
 * Hay: let / const / var, asignaciones (= += -= *= /= %=, ++ --), if / else,
 * for (clásico y for…of), while, break, continue, return, comentarios,
 * números, textos, plantillas `…${…}…`, listas [..], objetos {..}, operadores
 * de JavaScript (incluidos ?:, ??, ?.) y llamadas a las funciones que da el
 * Interpreter.
 *
 * NO hay (a propósito): declarar funciones, flechas (=>), clases, new, this,
 * try/throw, import, eval… Sin funciones propias no hay recursión que se coma la
 * memoria, y todo lo que el código puede llamar es una lista cerrada.
 *
 * Nodo: un array con 'k' (tipo) y 'l' (línea).
 */
final class Parser
{
    private const MAX_DEPTH = 80;

    /** Palabras de JavaScript que no están, y qué decir. */
    private const UNSUPPORTED = [
        'function' => 'No se pueden declarar funciones: usa las que ya trae (ver la ayuda) o un for.',
        'new'      => '«new» no está disponible.',
        'class'    => 'Las clases no están disponibles.',
        'this'     => '«this» no está disponible: las variables del flujo están en vars.',
        'import'   => 'No se puede importar nada: el código no tiene acceso a librerías ni al servidor.',
        'export'   => '«export» no está disponible.',
        'async'    => 'No hay código asíncrono: el bloque no puede llamar a internet.',
        'await'    => 'No hay código asíncrono: el bloque no puede llamar a internet.',
        'try'      => 'try/catch no está disponible: si el código falla, el bloque sale por «Error».',
        'catch'    => 'try/catch no está disponible: si el código falla, el bloque sale por «Error».',
        'finally'  => 'try/finally no está disponible.',
        'throw'    => '«throw» no está disponible: devuelve el nombre de una salida con return.',
        'switch'   => '«switch» no está disponible: usa if / else if.',
        'eval'     => '«eval» no está disponible.',
    ];

    /** @var list<array{t: string, v: mixed, l: int}> */
    private array $t;

    private int $p = 0;

    private int $depth = 0;

    private function __construct(array $tokens)
    {
        $this->t = $tokens;
    }

    /** @return list<array> El programa: una lista de sentencias. */
    public static function parse(string $src): array
    {
        $parser = new self(Lexer::tokenize($src));
        $body   = [];

        while (! $parser->is('eof')) {
            $body[] = $parser->statement();
        }

        return $body;
    }

    /** Una expresión suelta (lo de adentro de un ${…}). */
    public static function parseExpression(string $src, int $line): array
    {
        $parser = new self(Lexer::tokenize($src, $line));
        $expr   = $parser->expression();

        if (! $parser->is('eof')) {
            throw new ScriptError('Dentro de ${…} va una sola expresión.', $line);
        }

        return $expr;
    }

    // ── Sentencias ───────────────────────────────────────────────────────────

    private function statement(): array
    {
        $this->enter();

        try {
            $tok = $this->peek();

            if ($tok['t'] === 'kw' && isset(self::UNSUPPORTED[$tok['v']])) {
                throw new ScriptError(self::UNSUPPORTED[$tok['v']], $tok['l']);
            }

            $node = match (true) {
                $this->is('op', '{')                                      => $this->block(),
                $this->is('op', ';')                                      => ['k' => 'empty', 'l' => $this->next()['l']],
                $tok['t'] === 'kw' && in_array($tok['v'], ['let', 'const', 'var'], true) => $this->declaration(),
                $this->is('kw', 'if')                                     => $this->ifStatement(),
                $this->is('kw', 'for')                                    => $this->forStatement(),
                $this->is('kw', 'while')                                  => $this->whileStatement(),
                $this->is('kw', 'break'), $this->is('kw', 'continue')     => ['k' => $this->next()['v'], 'l' => $tok['l']],
                $this->is('kw', 'return')                                 => $this->returnStatement(),
                $tok['t'] === 'kw' && in_array($tok['v'], ['do', 'with', 'yield', 'case', 'default', 'delete', 'super', 'extends', 'in', 'instanceof'], true)
                    => throw new ScriptError("«{$tok['v']}» no está disponible.", $tok['l']),
                default                                                   => ['k' => 'expr', 'e' => $this->expression(), 'l' => $tok['l']],
            };

            // El punto y coma es opcional.
            if ($this->is('op', ';')) {
                $this->next();
            }

            return $node;
        } finally {
            $this->depth--;
        }
    }

    private function block(): array
    {
        $line = $this->expect('op', '{')['l'];
        $body = [];

        while (! $this->is('op', '}')) {
            if ($this->is('eof')) {
                throw new ScriptError('Falta cerrar un bloque con }.', $line);
            }
            $body[] = $this->statement();
        }

        $this->next();

        return ['k' => 'block', 'body' => $body, 'l' => $line];
    }

    private function declaration(): array
    {
        $tok   = $this->next();
        $kind  = $tok['v'] === 'const' ? 'const' : 'let';
        $decls = [];

        do {
            $name = $this->identifier('Después de ' . $tok['v'] . ' va el nombre de la variable.');
            $init = null;

            if ($this->is('op', '=')) {
                $this->next();
                $init = $this->expression();
            } elseif ($kind === 'const') {
                throw new ScriptError("A la constante «{$name}» hay que darle un valor (const {$name} = …).", $tok['l']);
            }

            $decls[] = [$name, $init];
        } while ($this->is('op', ',') && $this->next());

        return ['k' => 'let', 'const' => $kind === 'const', 'decls' => $decls, 'l' => $tok['l']];
    }

    private function ifStatement(): array
    {
        $line = $this->next()['l'];
        $this->expect('op', '(');
        $test = $this->expression();
        $this->expect('op', ')');
        $then = $this->statement();
        $else = null;

        if ($this->is('kw', 'else')) {
            $this->next();
            $else = $this->statement();
        }

        return ['k' => 'if', 'test' => $test, 'then' => $then, 'else' => $else, 'l' => $line];
    }

    private function forStatement(): array
    {
        $line = $this->next()['l'];
        $this->expect('op', '(');

        // for (const x of lista)
        if ($this->peek()['t'] === 'kw' && in_array($this->peek()['v'], ['let', 'const', 'var'], true)
            && ($this->t[$this->p + 1]['t'] ?? '') === 'id' && $this->isAt($this->p + 2, 'kw', 'of')) {
            $this->next();
            $name = $this->next()['v'];
            $this->next(); // of
            $iter = $this->expression();
            $this->expect('op', ')');

            return ['k' => 'forof', 'name' => $name, 'iter' => $iter, 'body' => $this->statement(), 'l' => $line];
        }

        // for (inicio; condición; paso)
        $init = null;
        if (! $this->is('op', ';')) {
            $init = $this->peek()['t'] === 'kw' && in_array($this->peek()['v'], ['let', 'const', 'var'], true)
                ? $this->declaration()
                : ['k' => 'expr', 'e' => $this->expression(), 'l' => $line];
        }
        $this->expect('op', ';', 'En un for van tres partes separadas por ; (o usa for (const x of lista)).');
        $test = $this->is('op', ';') ? null : $this->expression();
        $this->expect('op', ';', 'En un for van tres partes separadas por ;.');
        $update = $this->is('op', ')') ? null : $this->expression();
        $this->expect('op', ')');

        return ['k' => 'for', 'init' => $init, 'test' => $test, 'update' => $update, 'body' => $this->statement(), 'l' => $line];
    }

    private function whileStatement(): array
    {
        $line = $this->next()['l'];
        $this->expect('op', '(');
        $test = $this->expression();
        $this->expect('op', ')');

        return ['k' => 'while', 'test' => $test, 'body' => $this->statement(), 'l' => $line];
    }

    private function returnStatement(): array
    {
        $tok = $this->next();

        // return; · return } · return al final · return seguido de otra línea.
        if ($this->is('op', ';') || $this->is('op', '}') || $this->is('eof') || $this->peek()['l'] !== $tok['l']) {
            return ['k' => 'return', 'arg' => null, 'l' => $tok['l']];
        }

        return ['k' => 'return', 'arg' => $this->expression(), 'l' => $tok['l']];
    }

    // ── Expresiones (de menor a mayor precedencia) ───────────────────────────

    private function expression(): array
    {
        $this->enter();

        try {
            return $this->assignment();
        } finally {
            $this->depth--;
        }
    }

    private function assignment(): array
    {
        $left = $this->conditional();
        $tok  = $this->peek();

        if ($tok['t'] === 'op' && in_array($tok['v'], ['=', '+=', '-=', '*=', '/=', '%='], true)) {
            if (! in_array($left['k'], ['id', 'mem'], true)) {
                throw new ScriptError('A la izquierda de un = va una variable (o vars.nombre).', $tok['l']);
            }
            $this->next();

            return ['k' => 'asg', 'op' => $tok['v'], 'target' => $left, 'v' => $this->assignment(), 'l' => $tok['l']];
        }

        if ($tok['t'] === 'op' && $tok['v'] === '=>') {
            throw new ScriptError('Las funciones flecha (=>) no están disponibles: recorre la lista con un for.', $tok['l']);
        }

        return $left;
    }

    private function conditional(): array
    {
        $test = $this->binary(0);

        if (! $this->is('op', '?')) {
            return $test;
        }

        $line = $this->next()['l'];
        $then = $this->assignment();
        $this->expect('op', ':', 'En una condición ? … : … falta el «:».');

        return ['k' => 'cond', 't' => $test, 'a' => $then, 'b' => $this->assignment(), 'l' => $line];
    }

    /** Niveles de precedencia de los operadores binarios, de menor a mayor. */
    private const LEVELS = [
        ['??'],
        ['||'],
        ['&&'],
        ['==', '!=', '===', '!=='],
        ['<', '>', '<=', '>='],
        ['+', '-'],
        ['*', '/', '%'],
    ];

    private function binary(int $level): array
    {
        if ($level >= count(self::LEVELS)) {
            return $this->unary();
        }

        $left = $this->binary($level + 1);

        while ($this->peek()['t'] === 'op' && in_array($this->peek()['v'], self::LEVELS[$level], true)) {
            $op    = $this->next();
            $right = $this->binary($level + 1);
            $left  = [
                'k'  => in_array($op['v'], ['&&', '||', '??'], true) ? 'log' : 'bin',
                'op' => $op['v'],
                'a'  => $left,
                'b'  => $right,
                'l'  => $op['l'],
            ];
        }

        return $left;
    }

    private function unary(): array
    {
        $tok = $this->peek();

        if ($tok['t'] === 'op' && in_array($tok['v'], ['!', '-', '+'], true) || $this->is('kw', 'typeof')) {
            $this->next();
            $this->enter();

            try {
                return ['k' => 'un', 'op' => $tok['v'], 'a' => $this->unary(), 'l' => $tok['l']];
            } finally {
                $this->depth--;
            }
        }

        if ($tok['t'] === 'op' && in_array($tok['v'], ['++', '--'], true)) {
            $this->next();
            $target = $this->unary();
            $this->assertTarget($target, $tok['l']);

            return ['k' => 'upd', 'op' => $tok['v'], 'prefix' => true, 'target' => $target, 'l' => $tok['l']];
        }

        if ($tok['t'] === 'op' && in_array($tok['v'], ['**', '**=', '...'], true)) {
            throw new ScriptError("«{$tok['v']}» no está disponible (para potencias usa Math.pow).", $tok['l']);
        }

        $expr = $this->postfix();

        $after = $this->peek();
        if ($after['t'] === 'op' && in_array($after['v'], ['++', '--'], true) && $after['l'] === $this->t[$this->p - 1]['l']) {
            $this->next();
            $this->assertTarget($expr, $after['l']);

            return ['k' => 'upd', 'op' => $after['v'], 'prefix' => false, 'target' => $expr, 'l' => $after['l']];
        }

        if ($after['t'] === 'op' && in_array($after['v'], ['**', '**='], true)) {
            throw new ScriptError('«**» no está disponible: usa Math.pow(base, exponente).', $after['l']);
        }

        return $expr;
    }

    private function postfix(): array
    {
        $expr = $this->primary();

        while (true) {
            $tok = $this->peek();

            if ($this->is('op', '.') || $this->is('op', '?.')) {
                $this->next();
                $optional = $tok['v'] === '?.';

                if ($optional && $this->is('op', '(')) {
                    throw new ScriptError('«?.(» no está disponible.', $tok['l']);
                }

                if ($optional && $this->is('op', '[')) {
                    $this->next();
                    $key = $this->expression();
                    $this->expect('op', ']');
                    $expr = ['k' => 'mem', 'o' => $expr, 'p' => $key, 'opt' => true, 'l' => $tok['l']];
                    continue;
                }

                $name = $this->next();
                if ($name['t'] !== 'id' && $name['t'] !== 'kw') {
                    throw new ScriptError('Después del punto va el nombre de una propiedad.', $tok['l']);
                }
                $expr = ['k' => 'mem', 'o' => $expr, 'p' => ['k' => 'str', 'v' => $name['v'], 'l' => $name['l']], 'opt' => $optional, 'l' => $tok['l']];
                continue;
            }

            if ($this->is('op', '[')) {
                $this->next();
                $key = $this->expression();
                $this->expect('op', ']', 'Falta cerrar el ].');
                $expr = ['k' => 'mem', 'o' => $expr, 'p' => $key, 'opt' => false, 'l' => $tok['l']];
                continue;
            }

            if ($this->is('op', '(')) {
                $this->next();
                $args = $this->list(')');
                $expr = ['k' => 'call', 'c' => $expr, 'args' => $args, 'l' => $tok['l']];
                continue;
            }

            if ($tok['t'] === 'tmpl') {
                throw new ScriptError('Las plantillas con etiqueta (fn`…`) no están disponibles.', $tok['l']);
            }

            return $expr;
        }
    }

    private function primary(): array
    {
        $tok = $this->next();

        switch ($tok['t']) {
            case 'num':
                return ['k' => 'num', 'v' => $tok['v'], 'l' => $tok['l']];

            case 'str':
                return ['k' => 'str', 'v' => $tok['v'], 'l' => $tok['l']];

            case 'tmpl':
                $parts = [];
                foreach ($tok['v'] as $part) {
                    $parts[] = $part[0] === 's' ? $part[1] : self::parseExpression($part[1], $part[2]);
                }

                return ['k' => 'tmpl', 'parts' => $parts, 'l' => $tok['l']];

            case 'id':
                return ['k' => 'id', 'n' => $tok['v'], 'l' => $tok['l']];

            case 'kw':
                return match ($tok['v']) {
                    'true'              => ['k' => 'lit', 'v' => true, 'l' => $tok['l']],
                    'false'             => ['k' => 'lit', 'v' => false, 'l' => $tok['l']],
                    'null', 'undefined' => ['k' => 'lit', 'v' => null, 'l' => $tok['l']],
                    default             => throw new ScriptError(self::UNSUPPORTED[$tok['v']] ?? "«{$tok['v']}» no se puede usar aquí.", $tok['l']),
                };

            case 'op':
                if ($tok['v'] === '(') {
                    $expr = $this->expression();
                    $this->expect('op', ')', 'Falta cerrar el paréntesis.');

                    return $expr;
                }

                if ($tok['v'] === '[') {
                    return ['k' => 'arr', 'items' => $this->list(']'), 'l' => $tok['l']];
                }

                if ($tok['v'] === '{') {
                    return $this->objectLiteral($tok['l']);
                }

                throw new ScriptError("No se esperaba «{$tok['v']}» aquí.", $tok['l']);

            case 'eof':
                throw new ScriptError('El código terminó antes de tiempo: falta algo al final.', $tok['l']);
        }

        throw new ScriptError('No se entiende esta parte del código.', $tok['l']);
    }

    private function objectLiteral(int $line): array
    {
        $props = [];

        while (! $this->is('op', '}')) {
            $key = $this->next();

            if (! in_array($key['t'], ['id', 'str', 'num', 'kw'], true)) {
                throw new ScriptError('En un objeto { } cada propiedad es nombre: valor.', $key['l']);
            }

            $name = (string) $key['v'];

            if ($key['t'] === 'id' && ($this->is('op', ',') || $this->is('op', '}'))) {
                // { a } = { a: a }
                $props[] = [$name, ['k' => 'id', 'n' => $name, 'l' => $key['l']]];
            } else {
                $this->expect('op', ':', 'En un objeto { } cada propiedad es nombre: valor.');
                $props[] = [$name, $this->expression()];
            }

            if (! $this->is('op', '}')) {
                $this->expect('op', ',', 'Separa las propiedades del objeto con comas.');
            }
        }

        $this->next();

        return ['k' => 'obj', 'props' => $props, 'l' => $line];
    }

    /** Elementos separados por coma hasta $close (se permite una coma al final). */
    private function list(string $close): array
    {
        $items = [];

        while (! $this->is('op', $close)) {
            if ($this->is('eof')) {
                throw new ScriptError("Falta cerrar con «{$close}».", $this->peek()['l']);
            }
            $items[] = $this->expression();

            if (! $this->is('op', $close)) {
                $this->expect('op', ',', "Separa con comas (o cierra con «{$close}»).");
            }
        }

        $this->next();

        return $items;
    }

    // ── Ayudas ───────────────────────────────────────────────────────────────

    private function assertTarget(array $expr, int $line): void
    {
        if (! in_array($expr['k'], ['id', 'mem'], true)) {
            throw new ScriptError('++ y -- van sobre una variable.', $line);
        }
    }

    private function identifier(string $message): string
    {
        $tok = $this->next();

        if ($tok['t'] !== 'id') {
            throw new ScriptError($tok['t'] === 'kw' ? "«{$tok['v']}» es una palabra reservada: usa otro nombre." : $message, $tok['l']);
        }

        return $tok['v'];
    }

    private function enter(): void
    {
        if (++$this->depth > self::MAX_DEPTH) {
            throw new ScriptError('El código está demasiado anidado.', $this->peek()['l']);
        }
    }

    private function peek(): array
    {
        return $this->t[$this->p];
    }

    private function next(): array
    {
        $tok = $this->t[$this->p];

        if ($tok['t'] !== 'eof') {
            $this->p++;
        }

        return $tok;
    }

    private function is(string $type, mixed $value = null): bool
    {
        return $this->isAt($this->p, $type, $value);
    }

    private function isAt(int $p, string $type, mixed $value = null): bool
    {
        $tok = $this->t[$p] ?? null;

        return $tok !== null && $tok['t'] === $type && ($value === null || $tok['v'] === $value);
    }

    private function expect(string $type, string $value, ?string $message = null): array
    {
        if (! $this->is($type, $value)) {
            $tok  = $this->peek();
            $seen = $tok['t'] === 'eof' ? 'el final del código' : '«' . (is_scalar($tok['v']) ? $tok['v'] : '…') . '»';

            throw new ScriptError($message ?? "Se esperaba «{$value}» y vino {$seen}.", $tok['l']);
        }

        return $this->next();
    }
}
