<?php

namespace Tests\Feature;

use App\Models\Brain\Account;
use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use App\Models\Brain\TicketNotificationLog;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Los avisos al ISP sobre sus tickets (CON-57).
 *
 * Dos cosas se prueban acá, y ninguna es "que llegue el mensaje":
 *
 * 1. **Que no salga lo que no debe.** Fuera de la ventana de 24 h, WhatsApp no
 *    entrega texto libre: lo acepta con 200 y lo rechaza después por webhook.
 *    Cada rechazo gasta quality rating del número, así que sin plantilla el aviso
 *    NO se intenta. Es el mismo error que costó 783 mensajes en CON-75/CON-77,
 *    visto desde el otro lado.
 * 2. **Que no salga dos veces.** La bitácora se escribe antes del envío y es
 *    única por (evento, tipo): un reintento del job no puede duplicar el aviso.
 */
class AvisosDeTicketTest extends TestCase
{
    private Tenant $converza;
    private Tenant $tenantIsp;
    private Account $cuenta;
    private User $adminIsp;
    private User $interno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach ([
            'ticket_notification_logs', 'ticket_events', 'support_tickets', 'account_notes', 'accounts',
            'messages', 'conversations', 'contacts', 'staff_members', 'users', 'tenants',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
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
            $t->string('role')->default('agent');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->string('phone')->nullable();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });

        Schema::create('conversations', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('contact_id');
            $t->string('status')->default('open');
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });

        Schema::create('messages', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('conversation_id');
            $t->unsignedBigInteger('contact_id')->nullable();
            $t->text('body')->nullable();
            $t->string('status')->nullable();
            $t->string('type')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status')->default('active');
            $t->string('contact_phone', 30)->nullable();
            $t->boolean('support_notify_enabled')->default(false);
            $t->string('support_notify_phone', 30)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('support_tickets', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('assigned_to')->nullable();
            $t->unsignedBigInteger('opened_by')->nullable();
            $t->string('subject');
            $t->string('status')->default('open');
            $t->string('priority')->default('normal');
            $t->string('category', 40)->nullable();
            $t->string('product')->nullable();
            $t->string('source')->default('manual');
            $t->timestamp('first_response_at')->nullable();
            $t->timestamp('resolved_at')->nullable();
            $t->timestamps();
        });

        Schema::create('ticket_events', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('support_ticket_id');
            $t->unsignedBigInteger('author_user_id')->nullable();
            $t->string('type');
            $t->text('body')->nullable();
            $t->json('meta')->nullable();
            $t->timestamp('created_at')->useCurrent();
        });

        Schema::create('ticket_notification_logs', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('support_ticket_id');
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('ticket_event_id')->nullable();
            $t->string('kind');
            $t->string('status');
            $t->string('channel')->default('none');
            $t->string('phone', 30)->nullable();
            $t->string('template')->nullable();
            $t->string('wa_message_id')->nullable();
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['ticket_event_id', 'kind']);
        });

        // El tenant `default` es el workspace del dueño del SaaS: el único que usa
        // las credenciales del .env, y el número desde el que sale el aviso.
        $this->converza = Tenant::create(['slug' => 'default', 'name' => 'Converza', 'is_active' => true]);
        config()->set('services.whatsapp.url', 'https://graph.facebook.com/v20.0/123');
        config()->set('services.whatsapp.token', 'test-token');
        config()->set('support.notify.language', 'es_CO');
        config()->set('support.notify.templates', ['acuse' => null, 'respuesta' => null, 'resuelto' => null]);

        $this->tenantIsp = Tenant::create(['slug' => 'isp-a', 'name' => 'ISP A', 'is_active' => true]);

        $this->cuenta = Account::create([
            'name' => 'ISP A', 'slug' => 'isp-a', 'tenant_id' => $this->tenantIsp->id,
            'contact_phone' => '573001112233',
            'support_notify_enabled' => true,
        ]);

        $this->adminIsp = User::create([
            'name' => 'Admin del ISP', 'email' => 'admin@isp-a.test',
            'password' => 'x', 'tenant_id' => $this->tenantIsp->id,
        ]);
        \DB::table('staff_members')->insert([
            'tenant_id' => $this->tenantIsp->id, 'user_id' => $this->adminIsp->id,
            'role' => 'admin', 'is_active' => true,
        ]);

        $this->interno = User::create([
            'name' => 'Soporte Converza', 'email' => 'yo@converza.test',
            'password' => 'x', 'internal_role' => 'owner',
        ]);
    }

    /** Abre la ventana de 24 h: un entrante DE ESE NÚMERO a nuestro propio workspace. */
    private function ventanaAbiertaCon(string $telefono): void
    {
        $contacto = Contact::withoutGlobalScopes()->create([
            'tenant_id' => $this->converza->id, 'phone' => $telefono, 'name' => 'Admin del ISP',
        ]);

        $conversacion = Conversation::withoutGlobalScopes()->create([
            'tenant_id' => $this->converza->id, 'contact_id' => $contacto->id, 'status' => 'open',
        ]);

        Message::withoutGlobalScopes()->create([
            'tenant_id' => $this->converza->id, 'conversation_id' => $conversacion->id,
            'contact_id' => $contacto->id, 'body' => 'hola', 'status' => 'received',
        ]);
    }

    private function abrirTicketDesdeElPortal(): SupportTicket
    {
        $this->actingAs($this->adminIsp)->post('/support', [
            'subject' => 'No me llegan los avisos', 'body' => 'Desde ayer.',
        ])->assertRedirect();

        return SupportTicket::firstOrFail();
    }

    // ── Los tres momentos ────────────────────────────────────────────────────

    public function test_al_abrir_un_requerimiento_le_llega_el_acuse(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.ACUSE']]], 200)]);
        $this->ventanaAbiertaCon('573001112233');

        $ticket = $this->abrirTicketDesdeElPortal();

        $log = TicketNotificationLog::where('kind', TicketNotificationLog::KIND_ACUSE)->first();
        $this->assertNotNull($log);
        $this->assertSame('sent', $log->status);
        $this->assertSame('whatsapp_text', $log->channel, 'Dentro de la ventana se escribe como una persona.');
        $this->assertSame('wamid.ACUSE', $log->wa_message_id);

        // El número del ticket tiene que ir en el aviso: es lo que le permite
        // reconocerlo cuando entre al portal.
        Http::assertSent(fn ($r) => str_contains($r['text']['body'] ?? '', "#{$ticket->id}"));
    }

    public function test_al_responderle_recibe_aviso_y_una_nota_interna_no_dispara_nada(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $this->ventanaAbiertaCon('573001112233');

        $ticket = $this->abrirTicketDesdeElPortal();

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->post("/brain/tickets/{$ticket->id}/events", ['type' => 'note', 'body' => 'Está en mora, aguantarlo.']);

        $this->assertSame(0, TicketNotificationLog::where('kind', TicketNotificationLog::KIND_RESPUESTA)->count(),
            'Una nota interna no genera aviso: el cliente no la lee, no hay nada que ir a ver.');

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->post("/brain/tickets/{$ticket->id}/events", ['type' => 'message', 'body' => 'Ya quedó arreglado.']);

        $this->assertSame('sent', TicketNotificationLog::where('kind', TicketNotificationLog::KIND_RESPUESTA)->first()?->status);
    }

    public function test_al_resolver_se_avisa_una_sola_vez_y_los_demas_estados_no(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $this->ventanaAbiertaCon('573001112233');

        $ticket = $this->abrirTicketDesdeElPortal();

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->patch("/brain/tickets/{$ticket->id}/status", ['status' => 'pending']);

        $this->assertSame(0, TicketNotificationLog::where('kind', TicketNotificationLog::KIND_RESUELTO)->count(),
            'Pasar a "pendiente" es movimiento interno: un aviso por cada movimiento es ruido y el cliente lo apaga.');

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->patch("/brain/tickets/{$ticket->id}/status", ['status' => 'resolved']);

        $this->assertSame(1, TicketNotificationLog::where('kind', TicketNotificationLog::KIND_RESUELTO)->count());
    }

    // ── Lo que NO debe salir ─────────────────────────────────────────────────

    public function test_fuera_de_la_ventana_y_sin_plantilla_no_se_manda_nada(): void
    {
        Http::fake();
        // Sin entrante previo: la ventana está cerrada.

        $ticket = $this->abrirTicketDesdeElPortal();

        // Lo que de verdad importa: no se gastó el envío. Meta lo aceptaría con
        // 200 y lo rechazaría después, y cada rechazo cuesta quality rating.
        Http::assertNothingSent();

        $log = TicketNotificationLog::first();
        $this->assertSame('skipped', $log->status);
        $this->assertSame('sin_plantilla', $log->reason);
        $this->assertSame('none', $log->channel);
    }

    public function test_fuera_de_la_ventana_con_plantilla_sale_por_plantilla(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.TPL']]], 200)]);
        config()->set('support.notify.templates.acuse', 'ticket_recibido');

        $ticket = $this->abrirTicketDesdeElPortal();

        $log = TicketNotificationLog::first();
        $this->assertSame('sent', $log->status);
        $this->assertSame('whatsapp_template', $log->channel);
        $this->assertSame('ticket_recibido', $log->template);

        Http::assertSent(fn ($r) => ($r['type'] ?? null) === 'template'
            && ($r['template']['name'] ?? null) === 'ticket_recibido');
    }

    public function test_si_la_cuenta_tiene_los_avisos_apagados_no_se_manda_ni_se_encola(): void
    {
        Http::fake();
        $this->cuenta->update(['support_notify_enabled' => false]);
        $this->ventanaAbiertaCon('573001112233');

        $this->abrirTicketDesdeElPortal();

        Http::assertNothingSent();
        // Ni siquiera se registra: una cuenta con los avisos apagados no tiene por
        // qué llenar de filas la bitácora de cada ticket.
        $this->assertSame(0, TicketNotificationLog::count());
    }

    public function test_sin_telefono_queda_registrado_por_que_no_salio(): void
    {
        Http::fake();
        $this->cuenta->update(['contact_phone' => null, 'support_notify_phone' => null]);

        $this->abrirTicketDesdeElPortal();

        Http::assertNothingSent();
        $this->assertSame('sin_telefono', TicketNotificationLog::first()->reason);
    }

    // ── Idempotencia y rastro ────────────────────────────────────────────────

    public function test_el_mismo_evento_no_avisa_dos_veces(): void
    {
        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
        $this->ventanaAbiertaCon('573001112233');

        $ticket = $this->abrirTicketDesdeElPortal();
        $evento = TicketEvent::where('type', 'message')->firstOrFail();

        // Como si el job se reintentara después de un fallo de red.
        app(\App\Services\Support\AvisosDeTicket::class)->acuse($ticket, $evento);
        app(\App\Services\Support\AvisosDeTicket::class)->acuse($ticket, $evento);

        $this->assertSame(1, TicketNotificationLog::where('kind', TicketNotificationLog::KIND_ACUSE)->count());
        Http::assertSentCount(1);
    }

    public function test_cada_intento_queda_escrito_en_la_bitacora_del_ticket(): void
    {
        Http::fake();

        $ticket = $this->abrirTicketDesdeElPortal();

        // Una `note`, no un `message`: es información nuestra sobre el ticket y el
        // ISP no la ve nunca (TicketEvent::VISIBLE_AL_ISP).
        $nota = TicketEvent::where('type', 'note')->first();
        $this->assertNotNull($nota, 'El intento de aviso deja rastro donde se mira: en el propio ticket.');
        $this->assertStringContainsString('ventana de 24 h', $nota->body);

        $this->actingAs($this->adminIsp)->get("/support/{$ticket->id}")
            ->assertOk()
            ->assertDontSee('ventana de 24 h', false);
    }
}
