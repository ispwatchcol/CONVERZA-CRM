<?php

namespace App\Services\Flows\Script;

/** Un objeto del bloque «Código»: { plan: "20 megas", precio: 60000 }. Las llaves siempre son texto. */
final class ScriptMap
{
    /** @param array<string, mixed> $props */
    public function __construct(public array $props = []) {}
}
