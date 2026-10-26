<?php

namespace App\Services\Payments;

/**
 * Qué contestó ispwatch al pedirle registrar un pago.
 *
 *   registered → el pago existe (recién creado, o el mismo de antes si la
 *                llave de idempotencia ya se había usado).
 *   duplicate  → ispwatch ya tenía un pago con esa referencia.
 *   rejected   → ispwatch no lo aceptó y reintentar no lo cambia (dato
 *                inválido, credencial revocada).
 *
 * Lo que sí se arregla reintentando (caída, timeout, 5xx) no llega aquí: es
 * una IspwatchUnavailable, y el job reintenta con la MISMA llave.
 */
final class RegistrationResult
{
    /** @param array<string, mixed> $details Facturas abonadas y reconexión, tal como las manda ispwatch. */
    private function __construct(
        public readonly string $status,
        public readonly ?int $paymentId = null,
        public readonly array $details = [],
        public readonly ?string $message = null,
    ) {}

    public static function registered(int $paymentId, array $details): self
    {
        return new self('registered', $paymentId, $details);
    }

    public static function duplicate(?int $paymentId, ?string $message): self
    {
        return new self('duplicate', $paymentId, [], $message);
    }

    public static function rejected(string $message): self
    {
        return new self('rejected', null, [], $message);
    }
}
