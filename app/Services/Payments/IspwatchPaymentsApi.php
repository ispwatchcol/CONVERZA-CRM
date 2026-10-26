<?php

namespace App\Services\Payments;

use App\Models\PaymentReport;
use App\Models\Tenant;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Cliente del endpoint de ispwatch que registra un pago (contrato completo en
 * docs/agente-ia.md §7).
 *
 * Por qué una API y no un INSERT: la conexión de Converza a ispwatch es de solo
 * lectura a propósito, y registrar un pago allá no es una fila sino un proceso
 * (abonar a las facturas más viejas, arrastrar el saldo de un abono parcial,
 * reconectar al cliente en el router, dejar la bitácora). Reimplementarlo aquí
 * garantiza que las dos copias diverjan.
 *
 * Reglas del contrato que este cliente cumple:
 *
 *   - La credencial es de UNA empresa (la emite ispwatch para su tenant): el
 *     tenant lo decide la credencial, nunca el cuerpo de la petición.
 *   - Cada pedido lleva `Idempotency-Key` = el id del reporte. Un reintento
 *     tras un timeout devuelve el pago ya creado en vez de crear otro.
 */
final class IspwatchPaymentsApi
{
    public const PATH = '/api/v1/integrations/payments';

    /**
     * @throws IspwatchUnavailable Caída, timeout, 429 o 5xx: reintentar con la misma llave.
     */
    public function register(Tenant $tenant, PaymentReport $report, ?int $paymentMethodId, string $method): RegistrationResult
    {
        if (! $tenant->canRegisterPayments()) {
            return RegistrationResult::rejected('La empresa no tiene la conexión de pagos con ispwatch.');
        }

        $payload = array_filter([
            'customer_id'       => $report->ispwatch_customer_id,
            'amount'            => round((float) $report->amount, 2),
            'payment_date'      => $report->paid_on?->format('Y-m-d'),
            'payment_method_id' => $paymentMethodId,
            'method'            => $paymentMethodId === null ? mb_substr($method, 0, 100) : null,
            'reference'         => $report->reference,
            'notes'             => mb_substr("Reportado por WhatsApp y registrado desde Converza (comprobante #{$report->id})", 0, 255),
            'source'            => 'converza_whatsapp',
            'external_id'       => $report->idempotencyKey(),
        ], fn ($v) => $v !== null);

        try {
            $response = Http::withToken((string) $tenant->ispwatch_api_token)
                ->withHeaders(['Idempotency-Key' => $report->idempotencyKey()])
                ->acceptJson()
                ->asJson()
                ->connectTimeout(10)
                ->timeout(max(10, (int) config('services.ispwatch_api.timeout', 60)))
                ->post(rtrim((string) config('services.ispwatch_api.url'), '/') . self::PATH, $payload);
        } catch (ConnectionException $e) {
            throw new IspwatchUnavailable('Sin conexión con ispwatch: ' . mb_substr($e->getMessage(), 0, 200));
        }

        $status = $response->status();

        if ($status === 200 || $status === 201) {
            $paymentId = (int) $response->json('payment.id');

            if ($paymentId <= 0) {
                // Un 2xx sin el id del pago no deja saber qué se creó: que lo
                // mire una persona antes de darlo por registrado.
                return RegistrationResult::rejected('ispwatch respondió sin el id del pago.');
            }

            return RegistrationResult::registered($paymentId, array_filter([
                'allocations'  => $response->json('payment.allocations'),
                'reactivation' => $response->json('reactivation'),
                'replayed'     => $status === 200 ? true : null,
            ], fn ($v) => $v !== null));
        }

        if ($status === 409) {
            return RegistrationResult::duplicate(
                is_numeric($response->json('payment_id')) ? (int) $response->json('payment_id') : null,
                $this->message($response->json(), 'ispwatch ya tiene un pago con esa referencia.'),
            );
        }

        if ($status === 429 || $status >= 500) {
            throw new IspwatchUnavailable("ispwatch respondió {$status}.");
        }

        return RegistrationResult::rejected(match ($status) {
            401     => 'ispwatch no reconoció la credencial de la empresa (¿la revocaron o venció?).',
            403     => 'La credencial de la empresa no tiene permiso para registrar pagos.',
            404     => 'ispwatch no tiene habilitado el registro de pagos por API.',
            default => $this->message($response->json(), "ispwatch respondió {$status}."),
        });
    }

    private function message(mixed $body, string $fallback): string
    {
        $message = is_array($body) ? ($body['message'] ?? null) : null;

        return is_string($message) && $message !== '' ? mb_substr($message, 0, 300) : $fallback;
    }
}
