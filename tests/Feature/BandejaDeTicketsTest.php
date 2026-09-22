<?php

namespace Tests\Feature;

use App\Models\Brain\Account;
use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * La bandeja interna de tickets (CON-58).
 *
 * Lo que decide si esta pantalla sirve o no es UNA pregunta: ¿qué lleva más
 * tiempo esperándonos? Por eso lo que se prueba acá no es que la lista se pinte,
 * sino la definición de "pendiente" —nunca contestamos, o el cliente escribió
 * después de nuestra última respuesta— y que sea la MISMA en la bandeja, en sus
 * contadores y en el badge de la navegación. Si se desincronizan, el badge deja
 * de significar algo y nadie vuelve a mirarlo.
 */
class BandejaDeTicketsTest extends TestCase
{
    private Tenant $tenant;
    private Account $cuenta;
    private User $cliente;
    private User $interno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['ticket_events', 'support_tickets', 'account_notes', 'accounts', 'staff_members', 'users', 'tenants'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        Schema::create('tenants', function (Blueprint $t) {
            $t->id();
            $t->string('slug')->nullable();
            $t->string('name')->nullable();
            $t->boolean('is_active')->default(true);
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

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status')->default('active');
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

        $this->tenant = Tenant::create(['slug' => 'isp-a', 'name' => 'ISP A', 'is_active' => true]);
        $this->cuenta = Account::create(['name' => 'ISP A', 'slug' => 'isp-a', 'tenant_id' => $this->tenant->id]);

        $this->cliente = User::create([
            'name' => 'Admin del ISP', 'email' => 'admin@isp-a.test',
            'password' => 'x', 'tenant_id' => $this->tenant->id,
        ]);

        $this->interno = User::create([
            'name' => 'Soporte Converza', 'email' => 'yo@converza.test',
            'password' => 'x', 'internal_role' => 'owner',
        ]);

        // El contador del badge se cachea 30 s; entre pruebas eso mentiría.
        Cache::flush();
    }

    private function ticket(array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'account_id' => $this->cuenta->id,
            'subject'    => 'Los avisos salen con el saldo mal',
            'status'     => 'open',
            'priority'   => 'normal',
            'source'     => 'portal',
        ], $extra));
    }

    /** Un evento con fecha controlada: `created_at` no es fillable (lo pone la BD). */
    private function evento(SupportTicket $ticket, string $type, ?User $autor, string $cuando, ?string $body = 'texto'): TicketEvent
    {
        $e = TicketEvent::create([
            'support_ticket_id' => $ticket->id,
            'author_user_id'    => $autor?->id,
            'type'              => $type,
            'body'              => $body,
        ]);

        $e->forceFill(['created_at' => $cuando])->saveQuietly();

        return $e;
    }

    // ── Qué cuenta como pendiente ────────────────────────────────────────────

    public function test_un_ticket_sin_responder_esta_esperandonos(): void
    {
        $t = $this->ticket();
        $this->evento($t, 'message', $this->cliente, now()->subHours(30)->toDateTimeString());

        $this->assertSame(1, SupportTicket::esperandoNuestraRespuesta()->count());
    }

    public function test_una_nota_interna_no_saca_el_ticket_de_pendientes(): void
    {
        $t = $this->ticket();
        $this->evento($t, 'message', $this->cliente, now()->subHours(30)->toDateTimeString());
        $this->evento($t, 'note', $this->interno, now()->subHour()->toDateTimeString());

        // El cliente no lee las notas: para él seguimos sin contestar.
        $this->assertSame(1, SupportTicket::esperandoNuestraRespuesta()->count());
    }

    public function test_al_responderle_deja_de_estar_pendiente(): void
    {
        $t = $this->ticket();
        $this->evento($t, 'message', $this->cliente, now()->subHours(30)->toDateTimeString());

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->post("/brain/tickets/{$t->id}/events", ['type' => 'message', 'body' => 'Ya lo estamos viendo.'])
            ->assertRedirect();

        $this->assertSame(0, SupportTicket::esperandoNuestraRespuesta()->count());
        $this->assertNotNull($t->refresh()->first_response_at, 'La respuesta visible marca first_response_at.');
    }

    public function test_si_el_cliente_vuelve_a_escribir_el_ticket_regresa_a_pendientes(): void
    {
        $t = $this->ticket(['first_response_at' => now()->subHours(5)]);
        $this->evento($t, 'message', $this->cliente, now()->subHours(6)->toDateTimeString());
        $this->evento($t, 'message', $this->interno, now()->subHours(5)->toDateTimeString());

        $this->assertSame(0, SupportTicket::esperandoNuestraRespuesta()->count(), 'Le contestamos y nadie ha escrito después.');

        $this->evento($t, 'message', $this->cliente, now()->subHour()->toDateTimeString());

        // Un ticket ya contestado pero con la última palabra del cliente vuelve a
        // ser trabajo nuestro: si no, se queda esperando a que alguien se acuerde.
        $this->assertSame(1, SupportTicket::esperandoNuestraRespuesta()->count());
    }

    public function test_un_ticket_resuelto_no_cuenta_como_pendiente(): void
    {
        $t = $this->ticket(['status' => 'resolved', 'resolved_at' => now()]);
        $this->evento($t, 'message', $this->cliente, now()->subDays(2)->toDateTimeString());

        $this->assertSame(0, SupportTicket::esperandoNuestraRespuesta()->count());
    }

    // ── La bandeja ───────────────────────────────────────────────────────────

    public function test_arriba_va_lo_que_lleva_mas_tiempo_esperando(): void
    {
        $reciente = $this->ticket(['subject' => 'Entró hace un rato']);
        $this->evento($reciente, 'message', $this->cliente, now()->subHour()->toDateTimeString());

        $viejo = $this->ticket(['subject' => 'Lleva tres días']);
        $this->evento($viejo, 'message', $this->cliente, now()->subDays(3)->toDateTimeString());

        $contestado = $this->ticket(['subject' => 'Ya contestado', 'first_response_at' => now()->subDay()]);
        $this->evento($contestado, 'message', $this->cliente, now()->subDays(2)->toDateTimeString());
        $this->evento($contestado, 'message', $this->interno, now()->subDay()->toDateTimeString());

        $this->actingAs($this->interno)->get('/brain/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Brain/Tickets/Index')
                ->has('tickets.data', 3)
                ->where('tickets.data.0.subject', 'Lleva tres días')
                ->where('tickets.data.1.subject', 'Entró hace un rato')
                // El que ya contestamos y nadie respondió va al final, sin espera.
                ->where('tickets.data.2.subject', 'Ya contestado')
                ->where('tickets.data.2.esperando_desde', null)
            );
    }

    public function test_la_vista_previa_dice_si_el_ultimo_mensaje_es_del_cliente(): void
    {
        $t = $this->ticket();
        $this->evento($t, 'message', $this->cliente, now()->subHours(2)->toDateTimeString(), 'Sigue sin funcionar');

        $this->actingAs($this->interno)->get('/brain/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('tickets.data.0.ultimo_mensaje.from_client', true)
                ->where('tickets.data.0.ultimo_mensaje.body', 'Sigue sin funcionar')
            );
    }

    public function test_los_contadores_y_el_badge_cuentan_lo_mismo(): void
    {
        $esperando = $this->ticket();
        $this->evento($esperando, 'message', $this->cliente, now()->subDay()->toDateTimeString());

        $tranquilo = $this->ticket(['subject' => 'Contestado', 'first_response_at' => now()->subHour()]);
        $this->evento($tranquilo, 'message', $this->interno, now()->subHour()->toDateTimeString());

        $this->actingAs($this->interno)->get('/brain/tickets')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('contadores.esperando', 1)
                ->where('contadores.abiertos', 2)
                ->where('contadores.portal', 2)
                // El badge de la navegación sale de la misma definición.
                ->where('brainPendientes', 1)
            );
    }

    public function test_el_filtro_de_estado_acota_la_lista(): void
    {
        $abierto  = $this->ticket(['subject' => 'Abierto']);
        $resuelto = $this->ticket(['subject' => 'Resuelto', 'status' => 'resolved']);

        $this->actingAs($this->interno)->get('/brain/tickets?estado=resolved')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('tickets.data', 1)
                ->where('tickets.data.0.subject', 'Resuelto')
            );
    }

    // ── Escribir desde la bandeja ────────────────────────────────────────────

    public function test_la_nota_interna_no_cuenta_como_respuesta(): void
    {
        $t = $this->ticket();
        $this->evento($t, 'message', $this->cliente, now()->subDay()->toDateTimeString());

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->post("/brain/tickets/{$t->id}/events", ['type' => 'note', 'body' => 'Revisar con calma.'])
            ->assertRedirect();

        $this->assertNull($t->refresh()->first_response_at);
        $this->assertSame(1, SupportTicket::esperandoNuestraRespuesta()->count());
    }

    public function test_cambiar_el_estado_deja_bitacora(): void
    {
        $t = $this->ticket();

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->patch("/brain/tickets/{$t->id}/status", ['status' => 'resolved'])
            ->assertRedirect();

        $t->refresh();
        $this->assertSame('resolved', $t->status);
        $this->assertNotNull($t->resolved_at);

        $evento = TicketEvent::where('type', 'status_change')->first();
        $this->assertSame(['from' => 'open', 'to' => 'resolved'], $evento->meta);
        $this->assertSame($this->interno->id, $evento->author_user_id);
    }

    public function test_reabrir_limpia_la_fecha_de_resolucion(): void
    {
        $t = $this->ticket(['status' => 'resolved', 'resolved_at' => now()->subDays(3)]);

        $this->actingAs($this->interno)->from('/brain/tickets')
            ->patch("/brain/tickets/{$t->id}/status", ['status' => 'open']);

        // Si no se limpiara, el ticket seguiría diciendo que se resolvió un día en
        // el que evidentemente no quedó resuelto.
        $this->assertNull($t->refresh()->resolved_at);
    }

    // ── Quién entra ──────────────────────────────────────────────────────────

    public function test_un_usuario_de_tenant_no_entra_a_la_bandeja(): void
    {
        $t = $this->ticket();

        $this->actingAs($this->cliente)->get('/brain/tickets')->assertForbidden();
        $this->actingAs($this->cliente)->patch("/brain/tickets/{$t->id}/status", ['status' => 'closed'])->assertForbidden();
        $this->actingAs($this->cliente)->post("/brain/tickets/{$t->id}/events", [
            'type' => 'note', 'body' => 'me cuelo',
        ])->assertForbidden();

        $this->assertSame('open', $t->refresh()->status);
        $this->assertSame(0, TicketEvent::count());
    }
}
