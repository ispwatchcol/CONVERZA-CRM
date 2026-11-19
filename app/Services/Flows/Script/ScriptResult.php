<?php

namespace App\Services\Flows\Script;

/** Lo que dejó una ejecución del bloque «Código». */
final class ScriptResult
{
    /**
     * @param array<string, string> $writes   Variables del flujo que creó o cambió (siempre texto).
     * @param mixed                 $returned Lo que devolvió con return (el nombre de una salida, o nada).
     * @param list<string>          $logs     Lo que imprimió con log().
     */
    public function __construct(
        public readonly array $writes,
        public readonly mixed $returned,
        public readonly array $logs,
        public readonly int $steps,
    ) {}
}
