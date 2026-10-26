<?php

namespace App\Services\Flows;

use App\Models\PaymentReport;

/**
 * En qué va un comprobante, tal como lo ve el bloque «Comprobante de pago». Es
 * lo único del reporte que llega al flujo (y de ahí, a lo que se le dice al
 * cliente): el detalle de las verificaciones queda para el equipo. Decirle al
 * cliente QUÉ verificación falló le enseña a un estafador qué corregir.
 */
final class ReceiptOutcome
{
    public function __construct(
        public readonly int $reportId,
        public readonly string $status,
        public readonly ?float $amount = null,
        public readonly ?string $date = null,
        public readonly ?string $reference = null,
        public readonly ?string $channel = null,
        public readonly ?string $reason = null,
        public readonly string $service = '',
    ) {}

    public static function fromReport(PaymentReport $report): self
    {
        return new self(
            reportId: (int) $report->id,
            status: (string) $report->status,
            amount: $report->amount !== null ? (float) $report->amount : null,
            date: $report->paid_on?->format('d/m/Y'),
            reference: $report->reference,
            channel: $report->channel,
            reason: $report->reason,
            service: self::serviceText((array) ($report->registration['reactivation'] ?? [])),
        );
    }

    /** ¿Todavía se está leyendo o registrando? */
    public function pending(): bool
    {
        return in_array($this->status, [PaymentReport::STATUS_READING, PaymentReport::STATUS_REGISTERING], true);
    }

    /** Lo que se le puede decir al cliente de la reconexión que hizo ispwatch al registrar. */
    private static function serviceText(array $reactivation): string
    {
        if (empty($reactivation['was_suspended'])) {
            return '';
        }

        return ! empty($reactivation['router_ok'])
            ? 'Tu servicio ya quedó activo de nuevo ✅'
            : 'Estamos reactivando tu servicio: si en unos minutos no tienes conexión, escríbenos.';
    }
}
