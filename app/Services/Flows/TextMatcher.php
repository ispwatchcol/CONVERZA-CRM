<?php

namespace App\Services\Flows;

/**
 * Cómo se compara lo que escribe el cliente contra opciones y palabras clave.
 *
 * Todo se normaliza igual de los dos lados: minúsculas, sin tildes y sin
 * puntuación. Las palabras clave se buscan como palabra o frase COMPLETA, no
 * como substring: el bot cableado usa str_contains y por eso "ver" caía en
 * "verificar" (falso positivo anotado en docs/bot.md).
 */
final class TextMatcher
{
    private const ACCENTS = [
        'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
        'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
        'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
        'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
        'ñ' => 'n', 'ç' => 'c',
    ];

    public static function normalize(?string $text): string
    {
        $text = strtr(mb_strtolower(trim((string) $text)), self::ACCENTS);
        // Puntuación y emojis fuera: "¡Hola!" y "hola" son lo mismo.
        $text = (string) preg_replace('/[^\p{L}\p{N}\s]+/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /**
     * Número de opción que el cliente eligió, si escribió uno: "2", "2.",
     * "2)", "opción 2", o el emoji "2️⃣" (también dentro de una frase, como
     * "3️⃣ quiero la demo", que es como responden al copiar el menú).
     */
    public static function optionNumber(?string $raw): ?int
    {
        $raw = trim((string) $raw);

        if (str_contains($raw, '🔟')) {
            return 10;
        }

        if (preg_match('/([0-9])\x{FE0F}?\x{20E3}/u', $raw, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^(\d{1,2})\s*[.)\-:]?$/u', $raw, $m)) {
            return (int) $m[1];
        }

        if (preg_match('/^(?:opcion|opc|op|numero|la|el)\s*(\d{1,2})$/u', self::normalize($raw), $m)) {
            return (int) $m[1];
        }

        return null;
    }

    /** ¿La frase aparece completa dentro del texto? Ambos ya normalizados. */
    public static function containsPhrase(string $normalizedText, string $normalizedPhrase): bool
    {
        if ($normalizedPhrase === '' || $normalizedText === '') {
            return false;
        }

        return (bool) preg_match(
            '/(?:^|\s)' . preg_quote($normalizedPhrase, '/') . '(?:\s|$)/u',
            $normalizedText,
        );
    }

    /**
     * Palabras clave de un campo de texto separado por comas, normalizadas y
     * sin vacíos ni repetidos.
     *
     * @param string|array<int, string>|null $keywords
     * @return list<string>
     */
    public static function keywordList(string|array|null $keywords): array
    {
        $list = is_array($keywords) ? $keywords : explode(',', (string) $keywords);

        return array_values(array_unique(array_filter(array_map(
            fn ($k) => self::normalize((string) $k),
            $list,
        ), fn ($k) => $k !== '')));
    }
}
