<?php

namespace App\Services\Flows\Script;

/**
 * Convierte el código del bloque «Código» en tokens.
 *
 * Token: ['t' => tipo, 'v' => valor, 'l' => línea]. Tipos: num, str, tmpl
 * (plantilla `…${…}…`: 'v' es una lista de partes ['s', texto] | ['e', código, línea]),
 * id, kw (palabra reservada), op (operador o signo), eof.
 *
 * Los identificadores son ASCII: lo que vaya con tildes tiene que ir entre comillas.
 */
final class Lexer
{
    /** Palabras reservadas. Las que no están en el lenguaje las rechaza el Parser con un mensaje claro. */
    public const KEYWORDS = [
        'let', 'const', 'var', 'if', 'else', 'for', 'of', 'while', 'break', 'continue', 'return',
        'true', 'false', 'null', 'undefined', 'typeof',
        // De JavaScript, pero NO disponibles (ver Parser::UNSUPPORTED).
        'function', 'new', 'class', 'this', 'import', 'export', 'async', 'await', 'delete', 'in',
        'instanceof', 'try', 'catch', 'finally', 'throw', 'switch', 'case', 'default', 'do', 'with',
        'yield', 'super', 'extends', 'eval',
    ];

    /** De más largo a más corto: el primero que calza gana. */
    private const OPERATORS = [
        '===', '!==', '**=', '...',
        '==', '!=', '<=', '>=', '&&', '||', '??', '+=', '-=', '*=', '/=', '%=', '++', '--', '=>', '?.', '**',
        '+', '-', '*', '/', '%', '<', '>', '=', '!', '?', ':', '.', ',', ';', '(', ')', '[', ']', '{', '}',
    ];

    /** @return list<array{t: string, v: mixed, l: int}> */
    public static function tokenize(string $src, int $line = 1): array
    {
        $tokens = [];
        $i      = 0;
        $n      = strlen($src);

        while ($i < $n) {
            $c = $src[$i];

            if ($c === "\n") {
                $line++;
                $i++;
                continue;
            }

            if ($c === ' ' || $c === "\t" || $c === "\r") {
                $i++;
                continue;
            }

            $next = $src[$i + 1] ?? '';

            if ($c === '/' && $next === '/') {
                while ($i < $n && $src[$i] !== "\n") {
                    $i++;
                }
                continue;
            }

            if ($c === '/' && $next === '*') {
                $end = strpos($src, '*/', $i + 2);
                if ($end === false) {
                    throw new ScriptError('Hay un comentario /* sin cerrar.', $line);
                }
                $line += substr_count($src, "\n", $i, $end - $i);
                $i     = $end + 2;
                continue;
            }

            if (ctype_digit($c) || ($c === '.' && ctype_digit($next))) {
                preg_match('/\G(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?/', $src, $m, 0, $i);
                $tokens[] = ['t' => 'num', 'v' => self::number($m[0]), 'l' => $line];
                $i += strlen($m[0]);

                if ($i < $n && (ctype_alpha($src[$i]) || $src[$i] === '_')) {
                    throw new ScriptError('Hay un número mal escrito.', $line);
                }
                continue;
            }

            if (ctype_alpha($c) || $c === '_' || $c === '$') {
                preg_match('/\G[A-Za-z_$][A-Za-z0-9_$]*/', $src, $m, 0, $i);
                $word     = $m[0];
                $tokens[] = ['t' => in_array($word, self::KEYWORDS, true) ? 'kw' : 'id', 'v' => $word, 'l' => $line];
                $i += strlen($word);
                continue;
            }

            if ($c === '"' || $c === "'") {
                [$text, $i]  = self::string($src, $i, $line);
                $tokens[]    = ['t' => 'str', 'v' => $text, 'l' => $line];
                continue;
            }

            if ($c === '`') {
                $start      = $line;
                [$parts, $i, $line] = self::template($src, $i, $line);
                $tokens[]   = ['t' => 'tmpl', 'v' => $parts, 'l' => $start];
                continue;
            }

            foreach (self::OPERATORS as $op) {
                if (substr($src, $i, strlen($op)) === $op) {
                    $tokens[] = ['t' => 'op', 'v' => $op, 'l' => $line];
                    $i += strlen($op);
                    continue 2;
                }
            }

            $char = mb_substr(substr($src, $i, 4), 0, 1);

            throw new ScriptError(
                ord($c) >= 0x80
                    ? "Carácter «{$char}» fuera de comillas. Los nombres van sin tildes ni ñ; un texto, entre comillas."
                    : "Carácter inesperado «{$char}».",
                $line,
            );
        }

        $tokens[] = ['t' => 'eof', 'v' => null, 'l' => $line];

        return $tokens;
    }

    private static function number(string $raw): int|float
    {
        if (preg_match('/^\d+$/', $raw) && strlen($raw) < 16) {
            return (int) $raw;
        }

        return (float) $raw;
    }

    /** @return array{0: string, 1: int} [texto, posición después de la comilla de cierre] */
    private static function string(string $src, int $i, int $line): array
    {
        $quote = $src[$i];
        $out   = '';
        $i++;
        $n = strlen($src);

        while ($i < $n) {
            $c = $src[$i];

            if ($c === $quote) {
                return [$out, $i + 1];
            }

            if ($c === "\n") {
                break;
            }

            if ($c === '\\') {
                [$piece, $i] = self::escape($src, $i, $line);
                $out .= $piece;
                continue;
            }

            $out .= $c;
            $i++;
        }

        throw new ScriptError('Hay un texto sin cerrar: falta la comilla del final (para varias líneas usa `…`).', $line);
    }

    /** @return array{0: string, 1: int} */
    private static function escape(string $src, int $i, int $line): array
    {
        $e = $src[$i + 1] ?? '';

        return match ($e) {
            'n'     => ["\n", $i + 2],
            't'     => ["\t", $i + 2],
            'r'     => ["\r", $i + 2],
            '0'     => ["\0", $i + 2],
            'u'     => preg_match('/\G[0-9a-fA-F]{4}/', $src, $m, 0, $i + 2)
                ? [mb_chr(hexdec($m[0]), 'UTF-8') ?: '', $i + 6]
                : throw new ScriptError('Escape \\u mal escrito (son 4 dígitos hexadecimales).', $line),
            ''      => throw new ScriptError('Hay un texto sin cerrar.', $line),
            default => [$e, $i + 2],
        };
    }

    /**
     * Una plantilla `Hola ${nombre}`. Las expresiones dentro de ${…} se guardan
     * como código fuente y el Parser las analiza aparte.
     *
     * @return array{0: list<array>, 1: int, 2: int} [partes, posición, línea]
     */
    private static function template(string $src, int $i, int $line): array
    {
        $parts = [];
        $buf   = '';
        $i++;
        $n = strlen($src);

        while ($i < $n) {
            $c = $src[$i];

            if ($c === '`') {
                if ($buf !== '') {
                    $parts[] = ['s', $buf];
                }

                return [$parts, $i + 1, $line];
            }

            if ($c === '\\') {
                [$piece, $i] = self::escape($src, $i, $line);
                $buf .= $piece;
                continue;
            }

            if ($c === '$' && ($src[$i + 1] ?? '') === '{') {
                if ($buf !== '') {
                    $parts[] = ['s', $buf];
                    $buf     = '';
                }

                [$expr, $end] = self::templateExpression($src, $i + 2, $line);
                $parts[]      = ['e', $expr, $line];
                $line += substr_count($expr, "\n");
                $i = $end;
                continue;
            }

            if ($c === "\n") {
                $line++;
            }

            $buf .= $c;
            $i++;
        }

        throw new ScriptError('Hay una plantilla ` sin cerrar.', $line);
    }

    /** @return array{0: string, 1: int} [código de la expresión, posición después de la } de cierre] */
    private static function templateExpression(string $src, int $i, int $line): array
    {
        $start = $i;
        $depth = 1;
        $n     = strlen($src);

        while ($i < $n) {
            $c = $src[$i];

            if ($c === '"' || $c === "'") {
                [, $i] = self::string($src, $i, $line);
                continue;
            }

            if ($c === '`') {
                throw new ScriptError('No se puede poner una plantilla ` dentro de otra.', $line);
            }

            if ($c === '{') {
                $depth++;
            } elseif ($c === '}' && --$depth === 0) {
                return [substr($src, $start, $i - $start), $i + 1];
            }

            $i++;
        }

        throw new ScriptError('Hay un ${ sin cerrar en una plantilla.', $line);
    }
}
