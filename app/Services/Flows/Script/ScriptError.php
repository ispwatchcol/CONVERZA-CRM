<?php

namespace App\Services\Flows\Script;

use RuntimeException;

/** Un error del código del bloque «Código»: de sintaxis, de ejecución o un tope alcanzado. */
final class ScriptError extends RuntimeException
{
    public function __construct(public readonly string $reason, public readonly ?int $codeLine = null)
    {
        parent::__construct($codeLine !== null ? "Línea {$codeLine}: {$reason}" : $reason);
    }
}
