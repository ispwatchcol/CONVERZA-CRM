<?php

namespace App\Services\Flows\Script;

/** Un espacio de nombres de solo lectura del bloque «Código»: Math, Object, JSON, console. */
final class Ns
{
    /** @param array<string, mixed> $members */
    public function __construct(public readonly string $name, public readonly array $members) {}
}
