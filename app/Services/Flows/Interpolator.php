<?php

namespace App\Services\Flows;

/**
 * Reemplaza {{variables}} en los textos de un flujo.
 *
 * Nombres con puntos para agrupar (`contacto.nombre`, `cliente.saldo_favor`).
 * Una variable sin valor se reemplaza por vacío: el validador ya impide
 * publicar una que no existe en ningún lado, así que un vacío en ejecución
 * significa "todavía no se preguntó", no un error de tipeo.
 */
final class Interpolator
{
    public const PATTERN = '/\{\{\s*([a-zA-Z_][a-zA-Z0-9_]*(?:\.[a-zA-Z_][a-zA-Z0-9_]*)*)\s*\}\}/';

    /** @param callable(string): ?string $resolve */
    public static function render(string $text, callable $resolve): string
    {
        // callback: un '$' en el valor no se interpreta como referencia.
        return (string) preg_replace_callback(
            self::PATTERN,
            fn (array $m) => (string) ($resolve($m[1]) ?? ''),
            $text,
        );
    }

    /** @return list<string> Nombres de variable usados en el texto, sin repetir. */
    public static function variablesIn(?string $text): array
    {
        preg_match_all(self::PATTERN, (string) $text, $m);

        return array_values(array_unique($m[1] ?? []));
    }
}
