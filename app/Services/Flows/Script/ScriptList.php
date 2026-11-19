<?php

namespace App\Services\Flows\Script;

/** Una lista del bloque «Código»: [1, 2, 3]. Objeto para que se pase por referencia, como en JavaScript. */
final class ScriptList
{
    /** @param list<mixed> $items */
    public function __construct(public array $items = []) {}
}
