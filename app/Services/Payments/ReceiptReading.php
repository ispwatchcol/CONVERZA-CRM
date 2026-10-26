<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * Lo que la IA leyó de un comprobante, ya saneado.
 *
 * El modelo devuelve JSON con un schema estricto, pero estricto no quiere decir
 * correcto: un número puede venir negativo, una fecha imposible, una referencia
 * de 500 caracteres. Aquí todo se acota antes de que lo mire el verificador o
 * se guarde. Lo que no pasa queda en null, que el verificador trata como "no se
 * leyó".
 */
final class ReceiptReading
{
    public const TRANSACTION_OK = 'exitosa';

    /**
     * @param list<string>         $alerts Señales de edición que vio el modelo.
     * @param array<string, mixed> $raw    La salida tal como llegó (va a la evidencia).
     */
    public function __construct(
        public readonly bool $isReceipt,
        public readonly float $confidence,
        public readonly ?string $notReceiptReason,
        public readonly ?float $amount,
        public readonly ?string $currency,
        public readonly ?CarbonImmutable $date,
        public readonly ?string $reference,
        public readonly ?string $channel,
        public readonly ?string $destinationAccount,
        public readonly ?string $destinationHolder,
        public readonly ?string $payerName,
        public readonly string $transactionStatus,
        public readonly array $alerts,
        public readonly array $raw,
    ) {}

    /** @param array<string, mixed> $data La salida del schema de ReceiptReader. */
    public static function fromArray(array $data): self
    {
        $amount = is_numeric($data['valor'] ?? null) ? round((float) $data['valor'], 2) : null;
        if ($amount !== null && ($amount <= 0 || $amount > 100_000_000)) {
            $amount = null;
        }

        $status = (string) ($data['estado_transaccion'] ?? '');

        return new self(
            isReceipt: ($data['es_comprobante'] ?? false) === true,
            confidence: max(0.0, min(1.0, is_numeric($data['confianza'] ?? null) ? (float) $data['confianza'] : 0.0)),
            notReceiptReason: self::text($data['motivo_si_no_es'] ?? null, 200),
            amount: $amount,
            currency: self::text($data['moneda'] ?? null, 3) !== null ? strtoupper((string) self::text($data['moneda'], 3)) : null,
            date: self::date($data['fecha'] ?? null),
            reference: self::text($data['referencia'] ?? null, 80),
            channel: self::text($data['medio'] ?? null, 60),
            destinationAccount: self::text($data['cuenta_destino'] ?? null, 60),
            destinationHolder: self::text($data['titular_destino'] ?? null, 120),
            payerName: self::text($data['pagador'] ?? null, 120),
            transactionStatus: in_array($status, ['exitosa', 'pendiente', 'rechazada'], true) ? $status : 'desconocido',
            alerts: array_values(array_filter(array_map(
                fn ($a) => self::text($a, 200),
                array_slice(is_array($data['alertas'] ?? null) ? $data['alertas'] : [], 0, 5),
            ))),
            raw: $data,
        );
    }

    private static function text(mixed $value, int $max): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', (string) $value) ?? '');

        return $value === '' ? null : mb_substr($value, 0, $max);
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = CarbonImmutable::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable) {
            return null;
        }

        // createFromFormat acepta 2026-02-31 y lo corre a marzo: eso no es una
        // fecha que diga el comprobante.
        return $date && $date->format('Y-m-d') === $value ? $date : null;
    }
}
