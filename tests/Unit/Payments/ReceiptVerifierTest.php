<?php

namespace Tests\Unit\Payments;

use App\Services\Payments\ReceiptReading;
use App\Services\Payments\ReceiptVerifier;
use Carbon\CarbonImmutable;
use PHPUnit\Framework\TestCase;

/**
 * Las reglas que deciden si un comprobante se registra sin que lo mire nadie.
 * Son puras: todo el contexto entra por parámetro.
 */
class ReceiptVerifierTest extends TestCase
{
    private CarbonImmutable $today;

    protected function setUp(): void
    {
        parent::setUp();
        $this->today = CarbonImmutable::create(2026, 10, 25);
    }

    public function test_un_comprobante_en_regla_pasa_todo(): void
    {
        $checks = $this->verify();

        $this->assertTrue(ReceiptVerifier::allPassed($checks), json_encode($checks, JSON_UNESCAPED_UNICODE));
        $this->assertSame(array_keys(ReceiptVerifier::LABELS), array_column($checks, 'code'));
    }

    public function test_un_comprobante_viejo_o_futuro_no_pasa(): void
    {
        $this->assertCheck(false, 'fecha', $this->verify(['fecha' => '2026-10-10']), 'más de 7 días');
        $this->assertCheck(false, 'fecha', $this->verify(['fecha' => '2026-10-28']), 'futura');
        $this->assertCheck(true, 'fecha', $this->verify(['fecha' => '2026-10-18']));
        $this->assertCheck(false, 'fecha', $this->verify(['fecha' => null]));
    }

    public function test_un_pago_pendiente_o_rechazado_no_pasa(): void
    {
        $this->assertCheck(false, 'comprobante', $this->verify(['estado_transaccion' => 'pendiente']), 'pendiente');
        $this->assertCheck(false, 'comprobante', $this->verify(['estado_transaccion' => 'desconocido']), 'no dice');
    }

    public function test_una_lectura_poco_segura_no_pasa(): void
    {
        $this->assertCheck(false, 'comprobante', $this->verify(['confianza' => 0.5]), '50 %');
    }

    public function test_el_valor_tiene_que_cuadrar_con_la_deuda(): void
    {
        $pending = [['balance_due' => '50000'], ['balance_due' => '50000']];

        $this->assertCheck(true, 'deuda', $this->verify(['valor' => 50000], ['pending' => $pending]));
        $this->assertCheck(true, 'deuda', $this->verify(['valor' => 100000], ['pending' => $pending]));
        $this->assertCheck(false, 'deuda', $this->verify(['valor' => 20000], ['pending' => $pending]), 'menos que su factura más vieja');
        $this->assertCheck(false, 'deuda', $this->verify(['valor' => 150000], ['pending' => $pending]), 'más de lo que debe');
        $this->assertCheck(false, 'deuda', $this->verify(['valor' => 50000], ['pending' => []]), 'saldo a favor');
    }

    public function test_sin_cliente_claro_no_pasa(): void
    {
        $this->assertCheck(false, 'cliente', $this->verify([], ['customer_status' => 'ambiguous']), 'comparten');
        $this->assertCheck(false, 'cliente', $this->verify([], ['customer_status' => 'not_found']));
        // No poder mirar tampoco deja pasar: null también manda a revisión.
        $checks = $this->verify([], ['customer_status' => 'unavailable']);
        $this->assertCheck(null, 'cliente', $checks);
        $this->assertFalse(ReceiptVerifier::allPassed($checks));
    }

    public function test_repetidos_y_ya_registrados_no_pasan(): void
    {
        $this->assertCheck(false, 'repetido', $this->verify([], ['duplicate_of' => 12]), '#12');
        $this->assertCheck(false, 'ispwatch', $this->verify([], ['ispwatch_payment' => ['id' => 80, 'same_customer' => true]]), 'ya tiene este pago');
        $this->assertCheck(false, 'ispwatch', $this->verify([], ['ispwatch_payment' => ['id' => 80, 'same_customer' => false]]), 'OTRO cliente');
        $this->assertCheck(null, 'ispwatch', $this->verify([], ['ispwatch_payment' => false]));
        $this->assertCheck(null, 'ispwatch', $this->verify(['referencia' => null]));
        $this->assertCheck(false, 'referencia', $this->verify(['referencia' => null]));
    }

    public function test_cuenta_tope_frecuencia_y_alertas(): void
    {
        $this->assertCheck(false, 'cuenta', $this->verify([], ['destination' => ['status' => 'no_match', 'detail' => 'otra cuenta']]));
        $this->assertCheck(null, 'cuenta', $this->verify([], ['destination' => ['status' => 'unknown', 'detail' => 'sin cuenta']]));
        $this->assertCheck(false, 'tope', $this->verify(['valor' => 400000], ['auto_max' => 300000, 'pending' => [['balance_due' => 400000]]]), 'tope');
        $this->assertCheck(false, 'frecuencia', $this->verify([], ['auto_today' => 2]), '2 pagos');
        $this->assertCheck(false, 'alertas', $this->verify(['alertas' => ['Números encimados en el valor']]), 'encimados');
    }

    private function verify(array $reading = [], array $ctx = []): array
    {
        return (new ReceiptVerifier())->verify(ReceiptReading::fromArray(array_merge([
            'es_comprobante'     => true,
            'motivo_si_no_es'    => null,
            'valor'              => 50000,
            'moneda'             => 'COP',
            'fecha'              => '2026-10-24',
            'referencia'         => 'M1234567',
            'medio'              => 'Nequi',
            'cuenta_destino'     => '3001234567',
            'titular_destino'    => null,
            'pagador'            => null,
            'estado_transaccion' => 'exitosa',
            'alertas'            => [],
            'confianza'          => 0.95,
        ], $reading)), array_merge([
            'today'            => $this->today,
            'min_confidence'   => 0.8,
            'max_age_days'     => 7,
            'duplicate_of'     => null,
            'ispwatch_payment' => null,
            'destination'      => ['status' => 'match', 'detail' => 'Pagado a Nequi.'],
            'customer_status'  => 'found',
            'pending'          => [['balance_due' => '50000']],
            'auto_max'         => 300000.0,
            'auto_today'       => 0,
            'auto_per_day'     => 2,
        ], $ctx));
    }

    private function assertCheck(?bool $expected, string $code, array $checks, ?string $detailContains = null): void
    {
        $check = collect($checks)->firstWhere('code', $code);

        $this->assertNotNull($check, "No hay verificación «{$code}».");
        $this->assertSame($expected, $check['ok'], "«{$code}»: " . ($check['detail'] ?? ''));

        if ($detailContains !== null) {
            $this->assertStringContainsString($detailContains, (string) $check['detail']);
        }
    }
}
