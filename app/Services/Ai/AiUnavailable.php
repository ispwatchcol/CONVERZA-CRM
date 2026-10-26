<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * La IA no se puede usar para esta empresa ahora mismo, y no es un error: el
 * interruptor general está apagado, la empresa no la activó, falta la llave del
 * proveedor o se agotó el presupuesto del mes. `$reason` es uno de
 * PaymentReport::REASONS.
 */
final class AiUnavailable extends RuntimeException
{
    public function __construct(public readonly string $reason)
    {
        parent::__construct("IA no disponible: {$reason}");
    }
}
