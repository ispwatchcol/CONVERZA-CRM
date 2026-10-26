<?php

namespace App\Services\Payments;

use Carbon\CarbonImmutable;

/**
 * Las verificaciones de un comprobante leído. Código determinista y sin I/O: el
 * contexto (duplicados, cuentas, deuda del cliente) lo arma el procesador.
 *
 * Cada verificación da `ok` = true (pasó), false (no pasó) o null (no se pudo
 * mirar). El registro automático exige TODAS en true: un null también manda a
 * revisión, porque "no lo pude verificar" no es "está bien".
 *
 * Lo que estas reglas cubren es el fraude barato, que es el que escala con un
 * bot: reenviar un comprobante viejo o ajeno, el pantallazo de un pago a otra
 * cuenta, un abono simbólico para que lo reconecten. Lo que NO cubren es un
 * comprobante falsificado con cuidado (cuenta, valor y referencia creíbles):
 * eso solo lo ve la conciliación contra el extracto del banco. Por eso los
 * pagos del bot quedan marcados en ispwatch con su origen.
 */
final class ReceiptVerifier
{
    public const LABELS = [
        'comprobante' => 'Es el comprobante de un pago exitoso',
        'valor'       => 'Se leyó el valor',
        'fecha'       => 'La fecha es reciente',
        'referencia'  => 'Trae número de referencia',
        'repetido'    => 'No se había reportado antes',
        'ispwatch'    => 'No está registrado ya en ispwatch',
        'cuenta'      => 'Se pagó a una cuenta de la empresa',
        'cliente'     => 'El teléfono es de un cliente',
        'deuda'       => 'El valor cuadra con lo que debe',
        'tope'        => 'Dentro del tope para registrar solo',
        'frecuencia'  => 'No supera los pagos automáticos del día',
        'alertas'     => 'Sin señales de edición',
    ];

    /**
     * @param array{
     *     today: CarbonImmutable,
     *     min_confidence: float,
     *     max_age_days: int,
     *     duplicate_of: ?int,
     *     ispwatch_payment: array{id: int, same_customer: bool}|false|null,
     *     destination: array{status: string, detail: string},
     *     customer_status: string,
     *     pending: list<array{balance_due?: mixed, number?: ?string}>,
     *     auto_max: float,
     *     auto_today: int,
     *     auto_per_day: int,
     * } $ctx `ispwatch_payment`: el pago que ya trae esa referencia, null si no hay,
     *     false si no se pudo mirar. `customer_status`: found | ambiguous | not_found | unavailable.
     *
     * @return list<array{code: string, ok: ?bool, detail: ?string}>
     */
    public function verify(ReceiptReading $reading, array $ctx): array
    {
        $checks = [];
        $add = function (string $code, ?bool $ok, ?string $detail = null) use (&$checks) {
            $checks[] = ['code' => $code, 'ok' => $ok, 'detail' => $detail];
        };

        // 1. ¿Es el comprobante de un pago que salió?
        if ($reading->transactionStatus !== ReceiptReading::TRANSACTION_OK) {
            $add('comprobante', false, $reading->transactionStatus === 'desconocido'
                ? 'El comprobante no dice que el pago haya sido exitoso.'
                : "El comprobante dice que el pago está {$reading->transactionStatus}.");
        } elseif ($reading->confidence < $ctx['min_confidence']) {
            $add('comprobante', false, sprintf('Lectura poco segura (%d %%).', (int) round($reading->confidence * 100)));
        } else {
            $add('comprobante', true);
        }

        // 2. El valor.
        $amount = $reading->amount;
        $add('valor', $amount !== null, $amount !== null ? null : 'No se pudo leer el valor.');

        // 3. La fecha: ni futura ni vieja. Reenviar un comprobante viejo es la
        //    forma más simple de cobrar dos veces el mismo pago.
        $date = $reading->date;
        if ($date === null) {
            $add('fecha', false, 'No se pudo leer la fecha.');
        } elseif ($date->gt($ctx['today']->addDay())) {
            $add('fecha', false, 'La fecha del comprobante es futura (' . $date->format('d/m/Y') . ').');
        } elseif ($date->lt($ctx['today']->subDays($ctx['max_age_days']))) {
            $add('fecha', false, "El comprobante tiene más de {$ctx['max_age_days']} días (" . $date->format('d/m/Y') . ').');
        } else {
            $add('fecha', true, 'Del ' . $date->format('d/m/Y') . '.');
        }

        // 4. La referencia es la llave anti-duplicados: sin ella no hay forma
        //    de saber si este pago ya entró.
        $add('referencia', $reading->reference !== null, $reading->reference !== null ? null : 'No trae número de referencia.');

        // 5. ¿Ya lo había mandado alguien (esta imagen o esta referencia)?
        $add('repetido', $ctx['duplicate_of'] === null, $ctx['duplicate_of'] !== null ? "Es el mismo del reporte #{$ctx['duplicate_of']}." : null);

        // 6. ¿La cajera ya lo registró a mano en ispwatch? Si la referencia está
        //    en el pago de OTRO cliente, es el mismo comprobante usado dos veces.
        $existing = $ctx['ispwatch_payment'];
        if ($reading->reference === null || $existing === false) {
            $add('ispwatch', null, $existing === false ? 'No se pudo consultar ispwatch.' : 'Sin referencia no se puede buscar.');
        } elseif ($existing === null) {
            $add('ispwatch', true);
        } else {
            $add('ispwatch', false, $existing['same_customer']
                ? "ispwatch ya tiene este pago registrado (pago #{$existing['id']})."
                : "ispwatch tiene esa referencia en el pago #{$existing['id']}, de OTRO cliente.");
        }

        // 7. ¿Se le pagó a una cuenta de la empresa?
        $destination = $ctx['destination'];
        $add('cuenta', match ($destination['status']) {
            'match'    => true,
            'no_match' => false,
            default    => null,
        }, $destination['detail']);

        // 8. ¿De quién es? Solo cuenta el teléfono del chat: nada de lo que
        //    diga la imagen o el cliente cambia a quién se le abona.
        $add('cliente', match ($ctx['customer_status']) {
            'found'       => true,
            'unavailable' => null,
            default       => false,
        }, match ($ctx['customer_status']) {
            'ambiguous'   => 'Varios clientes comparten este teléfono.',
            'not_found'   => 'El teléfono del chat no es de ningún cliente en ispwatch.',
            'unavailable' => 'No se pudo consultar ispwatch.',
            default       => null,
        });

        // 9. ¿Cuadra con lo que debe? Un abono menor que su factura más vieja
        //    lo sacaría de la mora (ispwatch cierra la factura y arrastra el
        //    resto) y lo reconectaría: con un bot, eso escala. Pagar de más
        //    deja saldo a favor: que lo mire alguien.
        $add('deuda', ...$this->debt($amount, $ctx['pending'], $ctx['customer_status']));

        // 10. Tope por comprobante y por cliente al día.
        $add('tope', $amount === null ? null : $amount <= $ctx['auto_max'], $amount !== null && $amount > $ctx['auto_max']
            ? 'Supera el tope de ' . $this->money($ctx['auto_max']) . ' para registrar sin revisión.'
            : null);
        $add('frecuencia', $ctx['auto_today'] < $ctx['auto_per_day'], $ctx['auto_today'] >= $ctx['auto_per_day']
            ? "Ya se le registraron {$ctx['auto_today']} pagos automáticos hoy."
            : null);

        // 11. Lo que el modelo vio raro en la imagen.
        $add('alertas', $reading->alerts === [], $reading->alerts !== [] ? implode(' · ', $reading->alerts) : null);

        return $checks;
    }

    /** @param list<array{ok: ?bool}> $checks */
    public static function allPassed(array $checks): bool
    {
        foreach ($checks as $check) {
            if (($check['ok'] ?? null) !== true) {
                return false;
            }
        }

        return $checks !== [];
    }

    /** @return array{0: ?bool, 1: ?string} */
    private function debt(?float $amount, array $pending, string $customerStatus): array
    {
        if ($customerStatus !== 'found' || $amount === null) {
            return [null, null];
        }

        $balances = array_values(array_filter(array_map(fn ($i) => (float) ($i['balance_due'] ?? 0), $pending), fn ($b) => $b > 0));

        if ($balances === []) {
            return [false, 'No tiene facturas pendientes: el pago quedaría como saldo a favor.'];
        }

        $oldest = $balances[0];
        $total  = array_sum($balances);

        if ($amount + 0.5 < $oldest) {
            return [false, 'Abona ' . $this->money($amount) . ', menos que su factura más vieja (' . $this->money($oldest) . ').'];
        }

        if ($amount > $total + 0.5) {
            return [false, 'Paga ' . $this->money($amount) . ', más de lo que debe (' . $this->money($total) . ').'];
        }

        return [true, 'Debe ' . $this->money($total) . '.'];
    }

    private function money(float $value): string
    {
        return '$' . number_format($value, 0, ',', '.');
    }
}
