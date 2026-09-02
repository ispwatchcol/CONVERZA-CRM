<?php

namespace Tests\Feature;

use App\Jobs\ProcessIncomingWhatsAppMessage;
use App\Models\Contact;
use App\Services\WhatsAppService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

/**
 * Mensajes de clientes que ocultaron su número con un username de WhatsApp.
 *
 * Meta manda entonces un BSUID en `from_user_id` y ningún `from`. Hasta el
 * 02/09/2026 el job hacía `return` y el mensaje desaparecía entero: ni contacto,
 * ni conversación, ni fila en `messages`. 20 mensajes reales perdidos (CON-68).
 */
class MensajeSinTelefonoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Esquema mínimo: las migraciones reales son de Postgres y acá el motor
        // es sqlite en memoria.
        Schema::dropIfExists('messages');
        Schema::dropIfExists('conversations');
        Schema::dropIfExists('contacts');
        Schema::dropIfExists('tenants');

        // El job resuelve el tenant aunque no se le pase uno (cae al slug
        // 'default'), así que la tabla tiene que existir aunque quede vacía.
        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->boolean('auto_assign_enabled')->default(false);
            $t->timestamps();
        });

        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->string('phone')->nullable();
            $t->string('wa_user_id')->nullable();
            $t->string('wa_username')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('avatar')->nullable();
            $t->text('notes')->nullable();
            $t->string('external_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'phone']);
            $t->unique(['tenant_id', 'wa_user_id']);
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contact_id');
            $t->string('status')->default('open');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->boolean('bot_active')->default(false);
            $t->string('bot_step')->nullable();
            $t->smallInteger('bot_failed_intents')->default(0);
            $t->json('bot_context')->nullable();
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->text('body')->nullable();
            $t->string('status')->nullable();
            $t->string('wa_message_id')->nullable()->unique();
            $t->string('type')->nullable();
            $t->string('media_id')->nullable();
            $t->string('media_path')->nullable();
            $t->string('media_mime')->nullable();
            $t->string('media_filename')->nullable();
            $t->text('caption')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('sent_by_user_id')->nullable();
            $t->json('raw_metadata')->nullable();
            $t->timestamps();
        });
    }

    public function test_un_mensaje_sin_telefono_ya_no_se_pierde(): void
    {
        $this->procesar([
            'from_user_id' => 'CO.1124418266822967',
            'id'           => 'wamid.SINTEL1',
            'type'         => 'text',
            'text'         => ['body' => 'Envio soporte de pago'],
        ], [[
            'profile' => ['name' => 'FGC', 'username' => 'FGChitiva'],
            'user_id' => 'CO.1124418266822967',
        ]]);

        $this->assertDatabaseHas('messages', [
            'wa_message_id' => 'wamid.SINTEL1',
            'body'          => 'Envio soporte de pago',
            'status'        => 'received',
        ]);

        $contacto = Contact::first();
        $this->assertNull($contacto->phone, 'No hay teléfono que inventar.');
        $this->assertSame('CO.1124418266822967', $contacto->wa_user_id);
        $this->assertSame('FGChitiva', $contacto->wa_username);
        $this->assertSame('FGC', $contacto->name);
    }

    public function test_los_mensajes_siguientes_caen_en_el_mismo_contacto(): void
    {
        foreach (['wamid.A', 'wamid.B', 'wamid.C'] as $i => $id) {
            $this->procesar([
                'from_user_id' => 'CO.999',
                'id'           => $id,
                'type'         => 'text',
                'text'         => ['body' => "mensaje $i"],
            ], [['profile' => ['name' => 'Ana'], 'user_id' => 'CO.999']]);
        }

        $this->assertSame(1, Contact::count(), 'El BSUID es estable: un solo contacto.');
        $this->assertSame(3, \DB::table('messages')->count());
        $this->assertSame(1, \DB::table('conversations')->count());
    }

    public function test_un_mensaje_con_telefono_guarda_tambien_el_bsuid(): void
    {
        // Caso mixto: Meta manda los dos. Guardar el BSUID ahora es lo que
        // permite reconocer a la misma persona el día que oculte el número.
        $this->procesar([
            'from'         => '573133799933',
            'from_user_id' => 'CO.555',
            'id'           => 'wamid.MIXTO',
            'type'         => 'text',
            'text'         => ['body' => 'hola'],
        ], [['profile' => ['name' => 'Pedro'], 'wa_id' => '573133799933', 'user_id' => 'CO.555']]);

        $contacto = Contact::first();
        $this->assertSame('573133799933', $contacto->phone);
        $this->assertSame('CO.555', $contacto->wa_user_id);
    }

    public function test_sin_ninguna_identidad_si_se_descarta(): void
    {
        $this->procesar([
            'id'   => 'wamid.NADA',
            'type' => 'text',
            'text' => ['body' => 'fantasma'],
        ], []);

        $this->assertSame(0, Contact::count());
        $this->assertSame(0, \DB::table('messages')->count());
    }

    public function test_el_nombre_a_mostrar_nunca_es_el_bsuid(): void
    {
        $sinNombre = new Contact(['wa_user_id' => 'CO.777', 'wa_username' => 'juanp']);
        $this->assertSame('@juanp', $sinNombre->display_name);

        $pelado = new Contact(['wa_user_id' => 'CO.888']);
        $this->assertSame('Cliente sin teléfono', $pelado->display_name);
    }

    public function test_el_destino_de_envio_cae_al_bsuid_cuando_no_hay_telefono(): void
    {
        $conTelefono = new Contact(['phone' => '573133799933', 'wa_user_id' => 'CO.1']);
        $this->assertSame('573133799933', $conTelefono->waDestino());
        $this->assertFalse($conTelefono->sinTelefono());

        $sinTelefono = new Contact(['wa_user_id' => 'CO.2']);
        $this->assertSame('CO.2', $sinTelefono->waDestino());
        $this->assertTrue($sinTelefono->sinTelefono());
    }

    /**
     * Lo más frágil del arreglo: si el payload de salida lleva `to` con un BSUID,
     * Meta lo rechaza y el asesor ve el chat pero no puede contestar. La doc es
     * explícita: BSUID va en `recipient` y `to` se omite.
     */
    public function test_a_un_bsuid_se_le_escribe_con_recipient_y_no_con_to(): void
    {
        config()->set('services.whatsapp.url', 'https://graph.facebook.com/v20.0/123');
        config()->set('services.whatsapp.token', 'test-token');
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        app(WhatsAppService::class)->sendMessage('CO.1124418266822967', 'hola');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return ($cuerpo['recipient'] ?? null) === 'CO.1124418266822967'
                && ! array_key_exists('to', $cuerpo);
        });
    }

    public function test_a_un_telefono_se_le_sigue_escribiendo_con_to(): void
    {
        config()->set('services.whatsapp.url', 'https://graph.facebook.com/v20.0/123');
        config()->set('services.whatsapp.token', 'test-token');
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['messages' => [['id' => 'wamid.OUT']]], 200)]);

        app(WhatsAppService::class)->sendMessage('573133799933', 'hola');

        \Illuminate\Support\Facades\Http::assertSent(function ($request) {
            $cuerpo = $request->data();

            return ($cuerpo['to'] ?? null) === '573133799933'
                && ! array_key_exists('recipient', $cuerpo);
        });
    }

    private function procesar(array $mensaje, array $contacts): void
    {
        $whatsapp = Mockery::mock(WhatsAppService::class);
        $whatsapp->shouldReceive('forTenant')->andReturnSelf();
        $whatsapp->shouldReceive('downloadMedia')->andReturn(null);

        (new ProcessIncomingWhatsAppMessage($mensaje, $contacts, null))->handle($whatsapp);
    }
}
