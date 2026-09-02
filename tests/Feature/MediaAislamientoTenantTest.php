<?php

namespace Tests\Feature;

use App\Http\Controllers\MediaController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * El medio de un tenant no se le sirve a otro (M-03 / CON-14).
 *
 * `serve` exigía sesión pero no comprobaba de quién era el archivo: cualquier
 * usuario autenticado que conociera la ruta bajaba el medio de otro workspace.
 * Las rutas viajan en las props de Inertia, así que era alcanzable de verdad.
 *
 * Se prueba la comprobación en sí, por reflexión, y no la ruta HTTP completa:
 * montar sesión, rol y tenant enlazado traería media base de datos y no haría
 * más firme la garantía, que es exactamente este método.
 */
class MediaAislamientoTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('messages');
        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('media_path')->nullable();
        });

        \DB::table('messages')->insert([
            ['tenant_id' => 1, 'media_path' => 'whatsapp-media/del-tenant-1.jpg'],
            ['tenant_id' => 2, 'media_path' => 'whatsapp-media/del-tenant-2.jpg'],
        ]);
    }

    protected function tearDown(): void
    {
        app()->forgetInstance('tenant');

        parent::tearDown();
    }

    private function comprobar(string $path): void
    {
        $m = new \ReflectionMethod(MediaController::class, 'assertBelongsToCurrentTenant');
        $m->setAccessible(true);
        $m->invoke(app(MediaController::class), $path);
    }

    private function enlazarTenant(int $id): void
    {
        app()->instance('tenant', (object) ['id' => $id]);
    }

    /**
     * El código HTTP de un HttpException vive en getStatusCode(), no en
     * getCode() —que es 0—, así que expectExceptionCode() no sirve acá.
     */
    private function esperar404(string $path): void
    {
        try {
            $this->comprobar($path);
            $this->fail("Debía abortar con 404 para: $path");
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_el_medio_propio_se_sirve(): void
    {
        $this->enlazarTenant(1);

        $this->comprobar('whatsapp-media/del-tenant-1.jpg');

        $this->assertTrue(true, 'No debe lanzar: el archivo es de este tenant.');
    }

    public function test_el_medio_de_otro_tenant_da_404(): void
    {
        $this->enlazarTenant(1);

        $this->esperar404('whatsapp-media/del-tenant-2.jpg');
    }

    public function test_sin_tenant_enlazado_da_404(): void
    {
        // El caso que más importa: el global scope de BelongsToTenant se
        // DESACTIVA solo cuando no hay tenant, justo cuando dejaría pasar todo.
        // Por eso la comprobación falla cerrada antes de mirar la base.
        app()->forgetInstance('tenant');

        $this->esperar404('whatsapp-media/del-tenant-1.jpg');
    }

    public function test_una_ruta_inexistente_da_404(): void
    {
        $this->enlazarTenant(1);

        $this->esperar404('whatsapp-media/no-existe.jpg');
    }

    public function test_nunca_responde_403(): void
    {
        // 403 confirmaría que el archivo existe en otro tenant, que es la mitad
        // de lo que un atacante quiere averiguar. Siempre 404.
        $this->enlazarTenant(1);

        try {
            $this->comprobar('whatsapp-media/del-tenant-2.jpg');
            $this->fail('Debía abortar.');
        } catch (HttpException $e) {
            $this->assertSame(404, $e->getStatusCode(), 'Un 403 filtraría que el archivo existe.');
        }
    }
}
