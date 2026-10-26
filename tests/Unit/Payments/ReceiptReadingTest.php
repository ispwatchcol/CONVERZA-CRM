<?php

namespace Tests\Unit\Payments;

use App\Models\PaymentChannel;
use App\Models\PaymentReport;
use App\Services\Payments\PaymentChannels;
use App\Services\Payments\ReceiptReading;
use Tests\TestCase;

/**
 * Lo que entra de la IA se acota antes de usarse, y la cuenta destino se
 * compara contra las de la empresa aunque el banco la muestre oculta.
 */
class ReceiptReadingTest extends TestCase
{
    public function test_lo_que_no_tiene_sentido_queda_en_null(): void
    {
        $reading = ReceiptReading::fromArray([
            'es_comprobante'     => 'true',         // un string no es un booleano
            'valor'              => -5000,
            'fecha'              => '2026-02-31',   // no existe
            'referencia'         => str_repeat('A', 300),
            'estado_transaccion' => 'aprobadísima',
            'confianza'          => 7,
            'alertas'            => ['uno', '', 'dos', 'tres', 'cuatro', 'cinco', 'seis'],
        ]);

        $this->assertFalse($reading->isReceipt);
        $this->assertNull($reading->amount);
        $this->assertNull($reading->date);
        $this->assertSame(80, mb_strlen((string) $reading->reference));
        $this->assertSame('desconocido', $reading->transactionStatus);
        $this->assertSame(1.0, $reading->confidence);
        $this->assertSame(['uno', 'dos', 'tres', 'cuatro'], $reading->alerts, 'Máximo 5, sin vacíos.');
    }

    public function test_una_lectura_normal_se_conserva(): void
    {
        $reading = ReceiptReading::fromArray([
            'es_comprobante' => true, 'valor' => 50000.4, 'fecha' => '2026-10-24', 'moneda' => 'cop',
            'referencia' => '  M 123  4567 ', 'estado_transaccion' => 'exitosa', 'confianza' => 0.9, 'alertas' => [],
        ]);

        $this->assertTrue($reading->isReceipt);
        $this->assertSame(50000.4, $reading->amount);
        $this->assertSame('2026-10-24', $reading->date->format('Y-m-d'));
        $this->assertSame('COP', $reading->currency);
        $this->assertSame('M 123 4567', $reading->reference);
        $this->assertSame('M1234567', PaymentReport::referenceKey($reading->reference));
    }

    public function test_la_cuenta_destino_se_compara_aunque_venga_oculta(): void
    {
        $channels = collect([
            new PaymentChannel(['name' => 'Nequi', 'account_number' => '300 123 4567']),
            new PaymentChannel(['name' => 'Bancolombia', 'account_number' => '123-456789-01']),
            new PaymentChannel(['name' => 'Efectivo']),
        ]);
        $match = fn (?string $destination) => (new PaymentChannels())->matchDestination($channels, $destination);

        $this->assertSame('Nequi', $match('3001234567')['channel']?->name);
        $this->assertSame('Nequi', $match('+57 300 123 4567')['channel']?->name);
        $this->assertSame('Nequi', $match('300 *** 4567')['channel']?->name);
        $this->assertSame('Bancolombia', $match('Ahorros *****8901')['channel']?->name);
        $this->assertSame('no_match', $match('311 999 8888')['status']);
        $this->assertSame('no_match', $match('4567')['status'], 'Cuatro dígitos sin ocultar no alcanzan para decir que es la misma cuenta.');
        $this->assertSame('unknown', $match(null)['status']);
        $this->assertSame('unknown', $match('Nequi')['status']);
    }

    public function test_sin_numeros_de_cuenta_no_se_puede_comparar(): void
    {
        $result = (new PaymentChannels())->matchDestination(collect([new PaymentChannel(['name' => 'Efectivo'])]), '3001234567');

        $this->assertSame('unknown', $result['status']);
    }
}
