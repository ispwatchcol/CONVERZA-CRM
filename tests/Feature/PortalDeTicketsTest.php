<?php

namespace Tests\Feature;

use App\Models\Brain\Account;
use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El portal de tickets del ISP (CON-52): el cliente abre un requerimiento y le
 * sigue los avances desde su panel, sin preguntar por WhatsApp cómo va.
 *
 * Lo que de verdad hay que probar acá no es que la lista se pinte, sino la
 * frontera: desde que el ISP puede leer la bitácora de su ticket, cada cosa
 * escrita ahí tiene dos audiencias posibles, y una nota interna que se escape
 * ("este cliente está en mora, aguantarlo") no se puede deshacer. Por eso las
 * pruebas miran el PAYLOAD que sale del servidor, no la vista: si el filtro
 * viviera en el frontend estas pruebas seguirían en verde y el dato ya habría
 * viajado al navegador del cliente.
 */
class PortalDeTicketsTest extends TestCase
{
    private Tenant $tenantA;
    private Tenant $tenantB;
    private Account $cuentaA;
    private Account $cuentaB;
    private User $adminA;
    private User $asesorA;
    private User $interno;

    protected function setUp(): void
    {
        parent::setUp();

        // El root de Inertia es una vista Blade con @vite; sin build no resolvería.
        $this->withoutVite();

        // Esquema mínimo: las migraciones reales son de Postgres (enums con CHECK,
        // json) y acá el motor es sqlite en memoria.
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

        $this->tenantA = Tenant::create(['slug' => 'isp-a', 'name' => 'ISP A', 'is_active' => true]);
        $this->tenantB = Tenant::create(['slug' => 'isp-b', 'name' => 'ISP B', 'is_active' => true]);

        $this->cuentaA = Account::create(['name' => 'ISP A', 'slug' => 'isp-a', 'tenant_id' => $this->tenantA->id]);
        $this->cuentaB = Account::create(['name' => 'ISP B', 'slug' => 'isp-b', 'tenant_id' => $this->tenantB->id]);

        $this->adminA  = $this->usuarioDeTenant('admin@isp-a.test', $this->tenantA, 'admin');
        $this->asesorA = $this->usuarioDeTenant('asesor@isp-a.test', $this->tenantA, 'agent');

        // Nosotros: entra al Brain, no pertenece a ningún tenant.
        $this->interno = User::create([
            'name' => 'Soporte Converza', 'email' => 'yo@converza.test',
            'password' => 'x', 'internal_role' => 'owner',
        ]);
    }

    private function usuarioDeTenant(string $email, Tenant $tenant, string $rol): User
    {
        $user = User::create([
            'name' => $email, 'email' => $email, 'password' => 'x', 'tenant_id' => $tenant->id,
        ]);

        \DB::table('staff_members')->insert([
            'tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => $rol, 'is_active' => true,
        ]);

        return $user;
    }

    private function ticketDe(Account $cuenta, array $extra = []): SupportTicket
    {
        return SupportTicket::create(array_merge([
            'account_id' => $cuenta->id,
            'subject'    => 'No me llegan los avisos de factura',
            'status'     => 'open',
            'priority'   => 'normal',
            'source'     => 'portal',
        ], $extra));
    }

    // ── Abrir un requerimiento ───────────────────────────────────────────────

    public function test_el_isp_abre_un_requerimiento_y_queda_marcado_como_portal(): void
    {
        $this->actingAs($this->adminA)->post('/support', [
            'subject'  => 'El aviso de factura salió con el saldo mal',
            'body'     => 'A tres clientes les llegó el total en vez del saldo.',
            'category' => 'technical',
            'product'  => 'converza',
        ])->assertRedirect();

        $ticket = SupportTicket::first();

        $this->assertNotNull($ticket);
        $this->assertSame($this->cuentaA->id, $ticket->account_id, 'El account_id sale de la sesión, no de la petición.');
        // 'portal' es lo que distingue "entró solo y nadie lo ha visto" de
        // "nos llamó y lo anotamos".
        $this->assertSame('portal', $ticket->source);
        $this->assertSame('open', $ticket->status);
        $this->assertSame($this->adminA->id, $ticket->opened_by);
        // Su primer mensaje NO es nuestra primera respuesta.
        $this->assertNull($ticket->first_response_at);

        $evento = TicketEvent::where('support_ticket_id', $ticket->id)->first();
        $this->assertSame('message', $evento->type);
        $this->assertSame($this->adminA->id, $evento->author_user_id);
    }

    public function test_lo_que_el_isp_no_puede_decidir_se_ignora_aunque_lo_mande(): void
    {
        $this->actingAs($this->adminA)->post('/support', [
            'subject'     => 'Urgente',
            'body'        => 'Necesito esto ya.',
            // Nada de esto es del cliente: priorizar, cerrar y asignar es nuestro.
            'priority'    => 'urgent',
            'status'      => 'closed',
            'assigned_to' => $this->interno->id,
            'source'      => 'manual',
        ])->assertRedirect();

        $ticket = SupportTicket::first();

        $this->assertSame('normal', $ticket->priority);
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->assigned_to);
        $this->assertSame('portal', $ticket->source);
    }

    // ── Aislamiento entre ISPs ───────────────────────────────────────────────

    public function test_un_ticket_de_otro_isp_no_existe_para_mi(): void
    {
        $ajeno = $this->ticketDe($this->cuentaB);

        // 404 y no 403: un 403 ya confirmaría que ese ticket existe.
        $this->actingAs($this->adminA)->get("/support/{$ajeno->id}")->assertNotFound();

        $this->actingAs($this->adminA)
            ->post("/support/{$ajeno->id}/messages", ['body' => 'Me cuelo acá'])
            ->assertNotFound();

        $this->assertSame(0, TicketEvent::count(), 'No se escribió nada en el ticket del otro ISP.');
    }

    public function test_el_listado_solo_trae_los_tickets_de_mi_cuenta(): void
    {
        $mio   = $this->ticketDe($this->cuentaA, ['subject' => 'Lo mío']);
        $ajeno = $this->ticketDe($this->cuentaB, ['subject' => 'Lo del vecino']);

        $this->actingAs($this->adminA)->get('/support')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Support/Index')
                ->has('tickets', 1)
                ->where('tickets.0.id', $mio->id)
            );
    }

    // ── La frontera de visibilidad (CON-56) ──────────────────────────────────

    public function test_una_nota_interna_no_llega_nunca_al_payload_del_isp(): void
    {
        $ticket = $this->ticketDe($this->cuentaA);

        TicketEvent::create([
            'support_ticket_id' => $ticket->id, 'author_user_id' => $this->adminA->id,
            'type' => 'message', 'body' => 'Buenos días, necesito ayuda.',
        ]);
        TicketEvent::create([
            'support_ticket_id' => $ticket->id, 'author_user_id' => $this->interno->id,
            'type' => 'note', 'body' => 'Este cliente está en mora, aguantarlo.',
        ]);

        $respuesta = $this->actingAs($this->adminA)->get("/support/{$ticket->id}")->assertOk();

        $respuesta->assertInertia(fn (Assert $page) => $page
            ->component('Support/Show')
            ->has('ticket.events', 1)
            ->where('ticket.events.0.type', 'message')
        );

        // Y por si acaso: la frase tampoco aparece en ninguna parte de la respuesta.
        $respuesta->assertDontSee('en mora', false);
    }

    public function test_una_asignacion_no_llega_al_payload_del_isp(): void
    {
        $ticket = $this->ticketDe($this->cuentaA);

        TicketEvent::create([
            'support_ticket_id' => $ticket->id, 'author_user_id' => $this->interno->id,
            'type' => 'assignment', 'meta' => ['to' => $this->interno->id],
        ]);
        TicketEvent::create([
            'support_ticket_id' => $ticket->id, 'author_user_id' => $this->interno->id,
            'type' => 'status_change', 'meta' => ['from' => 'open', 'to' => 'pending'],
        ]);

        $this->actingAs($this->adminA)->get("/support/{$ticket->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->has('ticket.events', 1)
                // El cambio de estado sí lo ve, en forma legible; a quién se lo
                // asignamos, no.
                ->where('ticket.events.0.type', 'status_change')
                ->where('ticket.events.0.to', 'pending')
            );
    }

    public function test_al_isp_no_se_le_dice_quien_de_nosotros_le_contesto(): void
    {
        $ticket = $this->ticketDe($this->cuentaA);

        TicketEvent::create([
            'support_ticket_id' => $ticket->id, 'author_user_id' => $this->interno->id,
            'type' => 'message', 'body' => 'Ya lo estamos revisando.',
        ]);

        $this->actingAs($this->adminA)->get("/support/{$ticket->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('ticket.events.0.author_side', 'converza')
                ->where('ticket.events.0.author_name', 'Soporte Converza')
            );
    }

    // ── Escribir en el hilo ──────────────────────────────────────────────────

    public function test_el_mensaje_del_cliente_sobre_un_resuelto_lo_vuelve_a_abrir(): void
    {
        $ticket = $this->ticketDe($this->cuentaA, ['status' => 'resolved', 'resolved_at' => now()]);

        $this->actingAs($this->adminA)
            ->post("/support/{$ticket->id}/messages", ['body' => 'Sigue pasando.'])
            ->assertRedirect();

        $ticket->refresh();

        // Un resuelto con una respuesta nueva encima no aparecería en ninguna
        // bandeja nuestra y quedaría esperando a que alguien se acuerde.
        $this->assertSame('open', $ticket->status);
        $this->assertNull($ticket->resolved_at);
        $this->assertSame(1, TicketEvent::where('type', 'status_change')->count());
    }

    public function test_sobre_un_ticket_cerrado_no_se_escribe(): void
    {
        $ticket = $this->ticketDe($this->cuentaA, ['status' => 'closed']);

        $this->actingAs($this->adminA)->from('/support')
            ->post("/support/{$ticket->id}/messages", ['body' => 'Hola?'])
            ->assertRedirect('/support');

        $this->assertSame(0, TicketEvent::count());
        $this->assertSame('closed', $ticket->refresh()->status);
    }

    // ── Quién entra ──────────────────────────────────────────────────────────

    public function test_el_asesor_no_entra_al_soporte_del_workspace(): void
    {
        // Es la relación comercial del ISP con nosotros, no trabajo de la bandeja.
        $this->actingAs($this->asesorA)->get('/support')->assertForbidden();
        $this->actingAs($this->asesorA)->post('/support', [
            'subject' => 'x', 'body' => 'y',
        ])->assertForbidden();
    }

    public function test_ningun_usuario_de_tenant_alcanza_una_ruta_del_brain(): void
    {
        $ticket = $this->ticketDe($this->cuentaA);

        // El principio nº1 del Brain sigue intacto: la puerta que abrió el portal
        // es /support, no una rebaja de EnsureInternalAccess.
        $this->actingAs($this->adminA)->get("/brain/accounts/{$this->cuentaA->id}")->assertForbidden();
        $this->actingAs($this->adminA)->post("/brain/accounts/{$this->cuentaA->id}/tickets", [
            'subject' => 'x', 'priority' => 'urgent', 'source' => 'manual',
        ])->assertForbidden();
        $this->actingAs($this->adminA)->post("/brain/accounts/{$this->cuentaA->id}/tickets/{$ticket->id}/events", [
            'type' => 'note', 'body' => 'me escribo una nota interna',
        ])->assertForbidden();

        $this->assertSame(0, TicketEvent::count());
    }

    public function test_un_workspace_sin_cuenta_en_el_brain_no_revienta(): void
    {
        $sinCuenta = Tenant::create(['slug' => 'isp-nuevo', 'name' => 'ISP Nuevo', 'is_active' => true]);
        $admin     = $this->usuarioDeTenant('admin@isp-nuevo.test', $sinCuenta, 'admin');

        // Pasa de verdad con un tenant recién creado: la página lo dice en vez de
        // ofrecer un botón que falla.
        $this->actingAs($admin)->get('/support')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->where('account_linked', false)
                ->has('tickets', 0)
            );

        $this->actingAs($admin)->post('/support', ['subject' => 'x', 'body' => 'y'])->assertNotFound();
    }

    // ── first_response_at: lo que cuenta como haber contestado ───────────────

    public function test_una_nota_interna_no_cuenta_como_primera_respuesta(): void
    {
        $ticket = $this->ticketDe($this->cuentaA);

        $this->actingAs($this->interno)->from('/brain')
            ->post("/brain/accounts/{$this->cuentaA->id}/tickets/{$ticket->id}/events", [
                'type' => 'note', 'body' => 'Revisar con calma, no corre prisa.',
            ])->assertRedirect();

        // Para el cliente seguimos sin contestar: él no lee esto.
        $this->assertNull($ticket->refresh()->first_response_at);

        $this->actingAs($this->interno)->from('/brain')
            ->post("/brain/accounts/{$this->cuentaA->id}/tickets/{$ticket->id}/events", [
                'type' => 'message', 'body' => 'Buenos días, ya lo estamos viendo.',
            ])->assertRedirect();

        $this->assertNotNull($ticket->refresh()->first_response_at);
    }
}
