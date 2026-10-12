<?php

namespace App\Services\Brain;

/** Montos legibles para los mensajes al ISP: "45,00 USD", "100.000 COP". */
class Dinero
{
    public static function formato(float|string|null $monto, string $moneda): string
    {
        $monto = (float) $monto;
        // El peso no usa centavos en la práctica; el dólar sí.
        $decimales = $moneda === 'COP' && floor($monto) == $monto ? 0 : 2;

        return number_format($monto, $decimales, ',', '.') . ' ' . $moneda;
    }

    /** @param  array<string, float>  $porMoneda */
    public static function lista(array $porMoneda): string
    {
        $partes = [];

        foreach ($porMoneda as $moneda => $monto) {
            $partes[] = self::formato($monto, $moneda);
        }

        return implode(' + ', $partes) ?: '—';
    }
}
