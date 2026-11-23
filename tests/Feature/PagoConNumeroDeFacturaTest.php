<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\Notifications\EventCatalog;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * El aviso de «pago registrado» dice qué factura pagó el cliente
 * (`{{numero_factura}}`).
 *
 * ispwatch no guarda la factura en el pago: la liga por `payment_allocations`,
 * y un mismo pago puede cubrir varias facturas o ninguna (todo a saldo a favor).
 * Por eso la consulta corre de verdad, sobre un SQLite con la forma de esas
 * tablas, en vez de un repositorio falso que devolvería lo que uno le diga.
 */
class PagoConNumeroDeFacturaTest extends TestCase
{
    private const TENANT = 77;

    private Tenant $tenant;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('database.connections.ispwatch', ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '']);
        DB::purge('ispwatch');

        $schema = DB::connection('ispwatch')->getSchemaBuilder();

        $schema->create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('tel')->nullable();
        });
        $schema->create('customer_profile', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('user_id');
            $t->string('name')->nullable();
            $t->string('last_name')->nullable();
        });
        $schema->create('invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('customer_id');
            $t->string('number')->nullable();
            $t->date('due_date');
            $t->decimal('balance_due', 15, 2)->default(0);
        });
        $schema->create('payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id');
            $t->unsignedBigInteger('customer_id');
            $t->decimal('amount', 15, 2);
            $t->date('payment_date');
            $t->string('method')->default('cash');
            $t->string('reference')->nullable();
            $t->string('status')->default('completed');
            $t->timestamp('created_at')->nullable();
        });
        $schema->create('payment_allocations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('payment_id');
            $t->unsignedBigInteger('invoice_id');
            $t->decimal('amount', 15, 2);
        });

        DB::connection('ispwatch')->table('users')->insert(['id' => 10, 'name' => 'juanp', 'tel' => '3001234567']);
        DB::connection('ispwatch')->table('customer_profile')->insert(['user_id' => 10, 'name' => 'Juan', 'last_name' => 'Pérez']);

        $this->tenant = (new Tenant)->forceFill(['name' => 'Mi ISP']);
    }

    private function factura(int $id, ?string $numero, string $vence): void
    {
        DB::connection('ispwatch')->table('invoices')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'customer_id' => 10,
            'number' => $numero, 'due_date' => $vence, 'balance_due' => 0,
        ]);
    }

    /** @param array<int, int> $facturas ids de las facturas que cubre, en el orden en que se asignan */
    private function pago(int $id, array $facturas): void
    {
        DB::connection('ispwatch')->table('payments')->insert([
            'id' => $id, 'tenant_id' => self::TENANT, 'customer_id' => 10, 'amount' => 50000,
            'payment_date' => '2026-11-23', 'created_at' => '2026-11-23 10:00:00',
        ]);
        foreach ($facturas as $factura) {
            DB::connection('ispwatch')->table('payment_allocations')->insert([
                'payment_id' => $id, 'invoice_id' => $factura, 'amount' => 25000,
            ]);
        }
    }

    /** El valor de {{numero_factura}} que saldría en el aviso de cada pago nuevo. */
    private function numeroFacturaDelAviso(): array
    {
        $filas = app(IspwatchRepository::class)->newPaymentsSince(self::TENANT, 0, 50);

        return collect($filas)->mapWithKeys(fn ($fila) => [
            $fila['payment_id'] => EventCatalog::resolveValues('payment_registered', $fila, $this->tenant)['numero_factura'],
        ])->all();
    }

    public function test_el_aviso_dice_el_numero_de_la_factura_que_pago(): void
    {
        $this->factura(1, '00000608', '2026-11-08');
        $this->pago(500, [1]);

        $this->assertSame([500 => '00000608'], $this->numeroFacturaDelAviso());
    }

    public function test_si_el_pago_cubrio_varias_facturas_las_nombra_todas_de_la_mas_vieja_a_la_mas_nueva(): void
    {
        $this->factura(1, '00001467', '2026-11-10');
        $this->factura(2, '00000608', '2026-10-08');
        // Asignadas al revés a propósito: el orden lo pone el vencimiento, no el id.
        $this->pago(500, [1, 2]);

        $this->assertSame([500 => '00000608, 00001467'], $this->numeroFacturaDelAviso());
    }

    public function test_un_pago_que_no_toco_ninguna_factura_no_manda_el_parametro_vacio(): void
    {
        // Meta rechaza la plantilla entera si un parámetro de texto llega vacío.
        $this->factura(1, null, '2026-11-08');
        $this->pago(500, []);  // todo quedó como saldo a favor
        $this->pago(501, [1]); // la factura no tiene número

        $this->assertSame([
            500 => EventCatalog::PAID_INVOICE_FALLBACK,
            501 => EventCatalog::PAID_INVOICE_FALLBACK,
        ], $this->numeroFacturaDelAviso());
    }

    public function test_las_plantillas_posicionales_que_ya_existen_no_se_corren(): void
    {
        // Las plantillas legadas ({{1}}, {{2}}…) toman los valores en este orden.
        // La variable nueva tiene que ir al final o cambiaría lo que dicen.
        $valores = EventCatalog::resolveValues('payment_registered', [
            'customer_name' => 'Juan Pérez', 'invoice_numbers' => '00000608',
        ], $this->tenant);

        $this->assertSame([
            'nombre_cliente', 'primer_nombre', 'monto', 'metodo', 'referencia',
            'fecha_pago', 'saldo_restante', 'empresa', 'numero_factura',
        ], array_keys($valores));
    }

    public function test_el_editor_de_plantillas_acepta_la_variable_en_el_aviso_de_pago(): void
    {
        $this->assertContains('numero_factura', EventCatalog::variableNames('payment_registered'));
        $this->assertNotEmpty(EventCatalog::samples('payment_registered')['numero_factura']);
    }
}
