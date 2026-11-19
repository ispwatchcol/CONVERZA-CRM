<?php

namespace App\Services\Flows\Script;

/** Una función de la lista cerrada del bloque «Código» (Math.round, numero, log…). */
final class NativeFn
{
    public function __construct(public readonly string $name, public readonly \Closure $fn) {}
}
