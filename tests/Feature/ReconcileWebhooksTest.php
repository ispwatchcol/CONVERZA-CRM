<?php

namespace Tests\Feature;

use App\Services\WhatsApp\WebhookDispatcher;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Mockery;
use Tests\TestCase;

/**
 * Cubre la marca de agua de `webhooks:reconcile`.
 *
 * El caso que originó todo esto (CON-67) no se veía con una ventana fija de 60
 * min: hacía falta un corte MÁS LARGO que la ventana para que el hueco quedara
 * fuera de alcance. Por eso casi todas las pruebas de acá usan eventos de varias
 * horas atrás, que es justo lo que el código viejo no alcanzaba.
 */
class ReconcileWebhooksTest extends TestCase
{
    private string $storage;

    protected function setUp(): void
    {
        parent::setUp();

        // Tiempo congelado: varias pruebas dependen de en qué ARCHIVO diario cae
        // un evento, y eso cambia según la hora a la que se corra la suite.
        \Carbon\Carbon::setTestNow('2026-09-02 16:00:00');

        // storage temporal: el comando lee logs y escribe la marca con
        // storage_path(), y no queremos tocar el storage real del repo.
        $this->storage = sys_get_temp_dir().'/converza-test-'.uniqid();
        mkdir($this->storage.'/logs', 0775, true);
        mkdir($this->storage.'/app', 0775, true);
        $this->app->useStoragePath($this->storage);

        // Tabla mínima: el comando solo consulta messages.wa_message_id. Se crea
        // a mano en vez de correr las migraciones porque varias son específicas
        // de Postgres y acá el motor es sqlite en memoria.
        Schema::dropIfExists('messages');
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->string('wa_message_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
        });
    }

    protected function tearDown(): void
    {
        \Carbon\Carbon::setTestNow();
        $this->borrarDir($this->storage);

        parent::tearDown();
    }

    public function test_la_ventana_arranca_en_la_marca_y_alcanza_un_corte_de_mas_de_una_hora(): void
    {
        // El evento entró hace 3 horas: fuera del alcance de la ventana vieja.
        $this->escribirEvento(now()->subHours(3), 'wamid.VIEJO');
        $this->escribirMarca(now()->subHours(4));

        $this->esperarDespachos(1);

        $this->artisan('webhooks:reconcile')
            ->assertSuccessful()
            ->expectsOutputToContain('Huecos encontrados: 1');
    }

    public function test_sin_marca_previa_se_conserva_el_default_de_sesenta_minutos(): void
    {
        $this->escribirEvento(now()->subHours(3), 'wamid.VIEJO');

        $this->esperarDespachos(0);

        $this->artisan('webhooks:reconcile')
            ->assertSuccessful()
            ->expectsOutputToContain('Sin mensajes entrantes en la ventana');
    }

    public function test_la_ventana_puede_cruzar_varios_dias_de_log(): void
    {
        // Con hoy = 09-02 y la marca en 08-30, el evento del 08-31 queda en un
        // archivo INTERMEDIO. El `archivos()` viejo abría solo hoy y el día de
        // $desde (09-02 y 08-30), así que se lo saltaba en silencio.
        $this->escribirEvento(\Carbon\Carbon::parse('2026-08-31 12:00:00'), 'wamid.DELMEDIO');
        $this->escribirMarca(\Carbon\Carbon::parse('2026-08-30 12:00:00'));

        $this->esperarDespachos(1);

        $this->artisan('webhooks:reconcile --max-horas=96')
            ->assertSuccessful()
            ->expectsOutputToContain('Huecos encontrados: 1');
    }

    public function test_un_mensaje_ya_guardado_no_se_vuelve_a_despachar(): void
    {
        $this->escribirEvento(now()->subHours(3), 'wamid.YAESTA');
        $this->escribirMarca(now()->subHours(4));

        \DB::table('messages')->insert(['wa_message_id' => 'wamid.YAESTA', 'tenant_id' => 1]);

        $this->esperarDespachos(0);

        $this->artisan('webhooks:reconcile')
            ->assertSuccessful()
            ->expectsOutputToContain('Sin huecos');
    }

    public function test_una_corrida_con_exito_deja_la_marca(): void
    {
        $this->esperarDespachos(0);

        $this->artisan('webhooks:reconcile')->assertSuccessful();

        $this->assertFileExists($this->rutaMarca());
    }

    public function test_el_modo_seco_no_mueve_la_marca(): void
    {
        $this->escribirEvento(now()->subHours(3), 'wamid.SECO');
        $this->escribirMarca(now()->subHours(4));

        $this->esperarDespachos(0); // en seco no se despacha nada

        $this->artisan('webhooks:reconcile --dry-run')
            ->assertSuccessful()
            ->expectsOutputToContain('la marca de agua no se mueve');

        $this->assertSame(
            now()->subHours(4)->toDateTimeString(),
            trim(file_get_contents($this->rutaMarca())),
            'El modo seco le robaría la ventana a la corrida automática siguiente.'
        );
    }

    public function test_avisa_cuando_no_reconcilio_durante_un_rato_largo(): void
    {
        Log::spy();

        $this->escribirMarca(now()->subMinutes(40));
        $this->esperarDespachos(0);

        $this->artisan('webhooks:reconcile')
            ->assertSuccessful()
            ->expectsOutputToContain('El sistema pudo estar caído');

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($mensaje) => str_contains($mensaje, 'no corrió durante un rato largo'))
            ->once();
    }

    public function test_avisa_cuando_la_marca_supera_el_tope(): void
    {
        Log::spy();

        $this->escribirMarca(now()->subHours(50));
        $this->esperarDespachos(0);

        $this->artisan('webhooks:reconcile --max-horas=24')
            ->assertSuccessful()
            ->expectsOutputToContain('más vieja que el tope');

        Log::shouldHaveReceived('error')
            ->withArgs(fn ($mensaje) => str_contains($mensaje, 'excede el tope'))
            ->once();
    }

    public function test_la_ventana_fija_explicita_sigue_mandando_sobre_la_marca(): void
    {
        $this->escribirEvento(now()->subHours(3), 'wamid.VIEJO');
        $this->escribirMarca(now()->subHours(4));

        $this->esperarDespachos(0); // --minutos=30 deja el evento fuera

        $this->artisan('webhooks:reconcile --minutos=30')
            ->assertSuccessful()
            ->expectsOutputToContain('ventana fija de 30 min');
    }

    // ── Ayudantes ────────────────────────────────────────────────────────────

    private function esperarDespachos(int $veces): void
    {
        $mock = Mockery::mock(WebhookDispatcher::class);
        $mock->shouldReceive('dispatch')
            ->times($veces)
            ->andReturn(['dispatched' => 1, 'skipped_no_tenant' => 0]);

        $this->app->instance(WebhookDispatcher::class, $mock);
    }

    /** Escribe una línea con el mismo formato que produce el canal webhook_raw. */
    private function escribirEvento(\Carbon\Carbon $momento, string $waMessageId): void
    {
        $payload = [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => '1229573386905838'],
                        'messages' => [[
                            'id'   => $waMessageId,
                            'from' => '573133799933',
                            'text' => ['body' => 'hola'],
                        ]],
                    ],
                ]],
            ]],
        ];

        $linea = sprintf(
            '[%s] production.DEBUG: inbound %s'.PHP_EOL,
            $momento->format('Y-m-d H:i:s'),
            json_encode([
                'received_at' => $momento->toIso8601String(),
                'signature'   => '',
                'body'        => json_encode($payload),
            ])
        );

        file_put_contents(
            $this->storage."/logs/webhook-raw-{$momento->format('Y-m-d')}.log",
            $linea,
            FILE_APPEND
        );
    }

    private function rutaMarca(): string
    {
        return $this->storage.'/app/webhooks-reconcile.marca';
    }

    private function escribirMarca(\Carbon\Carbon $momento): void
    {
        file_put_contents($this->rutaMarca(), $momento->toDateTimeString().PHP_EOL);
    }

    private function borrarDir(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach (scandir($dir) as $e) {
            if ($e === '.' || $e === '..') {
                continue;
            }
            $ruta = $dir.'/'.$e;
            is_dir($ruta) ? $this->borrarDir($ruta) : @unlink($ruta);
        }

        @rmdir($dir);
    }
}
