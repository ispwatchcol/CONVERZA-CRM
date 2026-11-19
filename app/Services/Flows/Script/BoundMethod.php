<?php

namespace App\Services\Flows\Script;

/** Un método pegado a su valor: "hola".toUpperCase, lista.push. */
final class BoundMethod
{
    public function __construct(public readonly mixed $target, public readonly string $name) {}
}
