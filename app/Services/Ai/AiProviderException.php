<?php

namespace App\Services\Ai;

use RuntimeException;

/**
 * El proveedor falló. `$retryable` separa lo que se arregla solo (timeout, 429,
 * 5xx) de lo que no (llave inválida, modelo que no existe, petición mal
 * armada): reintentar esto último solo gasta tiempo del cliente que espera.
 */
final class AiProviderException extends RuntimeException
{
    public function __construct(
        string $message,
        public readonly bool $retryable,
        public readonly ?int $status = null,
    ) {
        parent::__construct($message);
    }
}
