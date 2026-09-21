<?php

namespace Tests\Feature;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * La ventana de servicio de 24 h, comprobada en el SERVIDOR.
 *
 * Fuera de la ventana WhatsApp no entrega texto libre, pero no lo dice en el
 * momento: acepta con 200 y un wamid, y el rechazo (131047) llega después por
 * webhook. Entre el 09/08 y el 08/09/2026 se perdieron así 783 mensajes de
 * asesores hacia 564 clientes.
 *
 * CON-75 puso una confirmación en el navegador. Cubre al asesor que pasa por el
 * chat con el bundle nuevo y a nadie más: por la API directa, por el bot o desde
 * una pestaña vieja en caché, el envío seguía llegando a Meta para que Meta lo
 * rechazara, y cada rechazo gasta quality rating del número. Esto es la otra
 * mitad (CON-77), y estas son las pruebas que aquel PR no trajo.
 */
class VentanaDeServicioTest extends TestCase
{
    private Tenant $tenant;
    private User $user;
    private Contact $contact;
    private Conversation $conversation;

    protected function setUp(): void
    {
        parent::setUp();

        // Esquema mínimo: las migraciones reales son de Postgres y acá el motor
        // es sqlite en memoria.
        foreach (['conversation_reads', 'messages', 'conversations', 'contacts', 'staff_members', 'users', 'tenants'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
            $t->boolean('auto_assign_enabled')->default(false);
            $t->string('wa_phone_number_id')->nullable();
            $t->text('wa_access_token')->nullable();
            $t->timestamps();
        });

        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('email')->unique();
            $t->string('password')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->boolean('is_superadmin')->default(false);
            $t->string('internal_role')->nullable();
            $t->timestamps();
        });

        Schema::create('staff_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->unsignedBigInteger('team_id')->nullable();
            $t->string('role')->default('agent');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->string('phone')->nullable();
            $t->string('wa_user_id')->nullable();
            $t->string('wa_username')->nullable();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
            $t->unique(['tenant_id', 'phone']);
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

        Schema::create('conversation_reads', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('staff_member_id');
            $t->timestamp('last_read_at')->nullable();
            $t->timestamps();
            $t->unique(['conversation_id', 'staff_member_id']);
        });

        // slug 'default' = el tenant del dueño del SaaS, el único al que le
        // aplica la config del .env. Sin esto WhatsAppService entra en modo mock
        // y no sale ninguna petición que poder afirmar o negar.
        $this->tenant = Tenant::create([
            'slug' => 'default', 'name' => 'Tenant de prueba', 'is_active' => true,
        ]);
        config()->set('services.whatsapp.url', 'https://graph.facebook.com/v20.0/123');
        config()->set('services.whatsapp.token', 'test-token');

        $this->user = User::create([
            'name' => 'Asesora', 'email' => 'asesora@test.local',
            'password' => 'x', 'tenant_id' => $this->tenant->id,
        ]);

        $this->contact = Contact::create([
            'phone' => '573001112233', 'tenant_id' => $this->tenant->id, 'name' => 'Cliente',
        ]);

        $this->conversation = Conversation::create([
            'contact_id' => $this->contact->id, 'tenant_id' => $this->tenant->id, 'status' => 'open',
        ]);
    }

    /** Un mensaje DEL CLIENTE hace $horas, que es lo que abre la ventana. */
    private function entranteHace(float $horas): void
    {
        $mensaje = Message::create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'contact_id'      => $this->contact->id,
            'body'            => 'hola',
            'status'          => 'received',
        ]);

        // created_at no está en $fillable, así que por create() se ignora y el
        // entrante quedaría con fecha de ahora — con la ventana siempre abierta
        // estas pruebas pasarían en verde sin probar nada.
        $cuando = now()->subMinutes((int) round($horas * 60));
        $mensaje->forceFill(['created_at' => $cuando, 'updated_at' => $cuando])->saveQuietly();
    }

    private function enviar(array $extra = [])
    {
        return $this->actingAs($this->user)->from('/chat')->post('/chat/send', array_merge([
            'phone'           => $this->contact->phone,
            'message'         => 'Buenos días, ¿pudo revisar el pago?',
            'conversation_id' => $this->conversation->id,
        ], $extra));
    }

    // ── El corte ─────────────────────────────────────────────────────────────

    public function test_fuera_de_ventana_y_sin_confirmacion_el_mensaje_no_llega_a_meta(): void
    {
        Http::fake();
        $this->entranteHace(25);

        $respuesta = $this->enviar();

        // Lo que de verdad importa: no se gastó el envío. Cada rechazo de Meta
        // cuesta quality rating del número, y el asesor ni se enteraba.
        Http::assertNothingSent();
        $this->assertSame(0, Message::where('status', 'sent')->count(), 'No se guarda como enviado algo que no salió.');
        $respuesta->assertSessionHasErrors(['message', 'out_of_window']);
    }

    public function test_el_error_ofrece_la_plantilla_en_vez_de_dejar_al_asesor_sin_salida(): void
    {
        Http::fake();
        $this->entranteHace(25);

        $this->enviar();

        // El criterio de aceptación de CON-77: el error tiene que NOMBRAR el
        // camino que sí funciona. Un "no se pudo enviar" a secas repetiría el
        // problema con otra cara.
        $error = session('errors')->first('message');
        $this->assertStringContainsString('plantilla', $error);
        $this->assertStringContainsString('24 horas', $error);
    }

    public function test_un_contacto_que_nunca_escribio_tambien_queda_cortado(): void
    {
        Http::fake();
        // Sin entrantes no hay ventana que valga: escribirle por primera vez a
        // alguien que nunca nos escribió es texto libre fuera de ventana, y Meta
        // lo rechaza el 100 % de las veces. Es el camino del modal "Nuevo chat".
        $respuesta = $this->enviar();

        Http::assertNothingSent();
        $respuesta->assertSessionHasErrors(['out_of_window']);
    }

    // ── Lo que NO se puede romper ────────────────────────────────────────────

    public function test_dentro_de_ventana_el_envio_sale_sin_preguntar_nada(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.OK']]], 200)]);
        $this->entranteHace(2);

        $this->enviar();

        Http::assertSentCount(1);
        $this->assertDatabaseHas('messages', [
            'wa_message_id' => 'wamid.OK',
            'status'        => 'sent',
        ]);
    }

    public function test_con_la_confirmacion_del_asesor_el_envio_sale_igual_que_siempre(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.IGUAL']]], 200)]);
        $this->entranteHace(25);

        // La salida deliberada: la ventana la inferimos de NUESTROS registros y
        // Meta es la autoridad. Si un webhook entrante se perdiera —CON-68 lo
        // volvió real— un bloqueo duro dejaría al asesor sin poder responder algo
        // que sí era válido. Lo que se corta es el envío que nadie decidió.
        $this->enviar(['out_of_window_ack' => true]);

        Http::assertSentCount(1);
        $this->assertDatabaseHas('messages', ['wa_message_id' => 'wamid.IGUAL', 'status' => 'sent']);
    }

    // ── El mismo corte para los medios ───────────────────────────────────────

    public function test_un_archivo_fuera_de_ventana_ni_siquiera_se_sube_a_meta(): void
    {
        Http::fake();
        $this->entranteHace(25);

        $respuesta = $this->actingAs($this->user)->from('/chat')->post('/chat/send-media', [
            'phone'           => $this->contact->phone,
            'conversation_id' => $this->conversation->id,
            'file'            => UploadedFile::fake()->image('comprobante.jpg'),
        ]);

        // El upload de media ya es una llamada a Meta: un archivo que no se va a
        // poder entregar no tiene por qué llegar a sus servidores.
        Http::assertNothingSent();
        $respuesta->assertSessionHasErrors(['file', 'out_of_window']);
    }

    public function test_un_archivo_con_confirmacion_si_sale(): void
    {
        Http::fake([
            '*/media'    => Http::response(['id' => 'media-123'], 200),
            '*/messages' => Http::response(['messages' => [['id' => 'wamid.MEDIA']]], 200),
        ]);
        $this->entranteHace(25);

        $this->actingAs($this->user)->from('/chat')->post('/chat/send-media', [
            'phone'             => $this->contact->phone,
            'conversation_id'   => $this->conversation->id,
            'file'              => UploadedFile::fake()->image('comprobante.jpg'),
            'out_of_window_ack' => true,
        ]);

        $this->assertDatabaseHas('messages', ['wa_message_id' => 'wamid.MEDIA']);
    }

    // ── El cálculo, que ahora vive en un solo sitio ──────────────────────────

    public function test_la_ventana_se_mide_desde_el_ultimo_entrante_del_cliente(): void
    {
        $this->assertFalse($this->conversation->serviceWindowIsOpen(), 'Sin entrantes, cerrada.');
        $this->assertNull($this->conversation->serviceWindowExpiresAt());

        $this->entranteHace(23);
        $this->assertTrue($this->conversation->serviceWindowIsOpen());

        $this->conversation->messages()->delete();
        $this->entranteHace(24.5);
        $this->assertFalse($this->conversation->serviceWindowIsOpen());
    }

    public function test_los_mensajes_del_asesor_no_reabren_la_ventana(): void
    {
        // Solo el cliente reabre la ventana. Si contaran los salientes, el propio
        // mensaje fallido la mantendría "abierta" para siempre.
        Message::create([
            'tenant_id'       => $this->tenant->id,
            'conversation_id' => $this->conversation->id,
            'contact_id'      => $this->contact->id,
            'body'            => 'respuesta del asesor',
            'status'          => 'sent',
        ]);

        $this->assertFalse($this->conversation->serviceWindowIsOpen());
    }
}
