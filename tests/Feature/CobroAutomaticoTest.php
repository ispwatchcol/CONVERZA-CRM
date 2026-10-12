<?php

namespace Tests\Feature;

use App\Models\Brain\Account;
use App\Models\Brain\AccountBillingNotice;
use App\Models\Brain\AccountInvoice;
use App\Models\Brain\AccountPayment;
use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Brain\CobroAutomatico;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * El cobro automático del SaaS a los ISP.
 *
 * Lo que importa probar no es que "se cree una factura", sino las tres formas en
 * que esto le puede hacer daño a un cliente que no lo merece:
 *
 * 1. **Cobrarle dos veces el mismo mes** (el comando corre cada hora).
 * 2. **Cortarle habiendo pagado** (un pago suelto que no cubre la factura vencida,
 *    o una factura que nace ya vencida porque el servidor estuvo caído).
 * 3. **Cobrarle meses de antes** de encender el cobro, que pudo pagar por fuera.
 *
 * Y del lado del corte: que corte el PANEL y nada más, y que solo se levante solo
 * lo que cortó el cobro.
 */
class CobroAutomaticoTest extends TestCase
{
    private Tenant $tenantIsp;
    private Account $cuenta;
    private User $adminIsp;
    private User $interno;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach ([
            'brain_expenses', 'brain_recurring_expenses',
            'account_billing_notices', 'account_payments', 'account_invoices', 'account_products', 'accounts',
            'messages', 'conversations', 'contacts', 'staff_members', 'users', 'tenants',
        ] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        FinanzasTest::crearTablasDeEgresos();

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
            $t->string('ispwatch_tenant_id')->nullable();
            $t->string('status')->default('active');
            $t->string('health')->nullable();
            $t->string('contact_phone', 30)->nullable();
            $t->unsignedTinyInteger('billing_day')->nullable();
            $t->boolean('support_notify_enabled')->default(false);
            $t->string('support_notify_phone', 30)->nullable();
            $t->boolean('auto_billing_enabled')->default(false);
            $t->date('auto_billing_since')->nullable();
            $t->unsignedTinyInteger('billing_grace_days')->default(5);
            $t->timestamp('suspended_at')->nullable();
            $t->string('suspension_reason', 20)->nullable();
            $t->timestamps();
            $t->softDeletes();
        });

        Schema::create('account_products', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('product');
            $t->string('plan_key')->nullable();
            $t->string('plan')->nullable();
            $t->string('status')->default('active');
            $t->string('billing_cycle')->default('monthly');
            $t->decimal('amount', 12, 2)->default(0);
            $t->string('currency', 3)->default('USD');
            $t->date('started_at')->nullable();
            $t->date('renews_at')->nullable();
            $t->date('cancelled_at')->nullable();
            $t->timestamps();
        });

        Schema::create('account_invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('account_product_id')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->string('number', 40)->unique();
            $t->string('concept');
            $t->decimal('amount', 12, 2)->default(0);
            $t->decimal('tax', 12, 2)->default(0);
            $t->decimal('total', 12, 2)->default(0);
            $t->string('currency', 3)->default('USD');
            $t->string('status')->default('draft');
            $t->date('issued_at');
            $t->date('due_at')->nullable();
            $t->date('paid_at')->nullable();
            $t->date('period_start')->nullable();
            $t->date('period_end')->nullable();
            $t->string('source', 10)->default('manual');
            $t->string('billing_period', 7)->nullable();
            $t->timestamps();
            $t->unique(['account_id', 'currency', 'billing_period']);
        });

        Schema::create('account_payments', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('account_invoice_id')->nullable();
            $t->unsignedBigInteger('recorded_by')->nullable();
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3)->default('USD');
            $t->string('method')->default('transfer');
            $t->boolean('is_credit_application')->default(false);
            $t->string('reference', 120)->nullable();
            $t->date('paid_at');
            $t->timestamps();
        });

        Schema::create('account_billing_notices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->unsignedBigInteger('account_invoice_id')->nullable();
            $t->string('kind');
            $t->string('ref', 40);
            $t->string('status');
            $t->string('channel')->default('none');
            $t->string('phone', 30)->nullable();
            $t->string('template')->nullable();
            $t->string('wa_message_id')->nullable();
            $t->string('reason')->nullable();
            $t->timestamps();
            $t->unique(['account_id', 'kind', 'ref']);
        });

        Tenant::create(['slug' => 'default', 'name' => 'Converza', 'is_active' => true]);
        config()->set('services.whatsapp.url', 'https://graph.facebook.com/v20.0/123');
        config()->set('services.whatsapp.token', 'test-token');
        config()->set('brain.billing.timezone', 'America/Bogota');
        config()->set('brain.billing.payment_instructions', 'Nequi 300 000 0000');
        config()->set('brain.billing.templates', ['factura' => null, 'recordatorio' => null, 'suspension' => null, 'reactivacion' => null]);
        config()->set('brain.status_api_token', null);
        // El badge de tickets del Brain se calcula en cada página interna; acá no
        // hay tabla de tickets y no es lo que se prueba.
        Cache::put('brain.tickets.esperando', 0, 3600);

        $this->tenantIsp = Tenant::create(['slug' => 'isp-a', 'name' => 'ISP A', 'is_active' => true]);

        $this->cuenta = Account::create([
            'name' => 'ISP A', 'slug' => 'isp-a', 'tenant_id' => $this->tenantIsp->id,
            'ispwatch_tenant_id' => '19',
            'contact_phone' => '+57 300 111 2233',
            'billing_day' => 1,
            'auto_billing_enabled' => true,
            'auto_billing_since' => '2026-10-01',
            'billing_grace_days' => 5,
        ]);

        // Un combo: el precio va en la fila de Converza y la de ispwatch en 0.
        $this->cuenta->products()->create([
            'product' => 'converza', 'plan_key' => 'combo_800', 'plan' => 'Combo hasta 800',
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount' => 69, 'currency' => 'USD',
        ]);
        $this->cuenta->products()->create([
            'product' => 'ispwatch', 'plan_key' => 'combo_800', 'plan' => 'Combo hasta 800',
            'status' => 'active', 'billing_cycle' => 'monthly', 'amount' => 0, 'currency' => 'USD',
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
            'name' => 'Yo', 'email' => 'yo@converza.test',
            'password' => 'x', 'internal_role' => 'owner',
        ]);

        Http::fake(['*' => Http::response(['messages' => [['id' => 'wamid.X']]], 200)]);
    }

    private function correr(string $fecha): array
    {
        return app(CobroAutomatico::class)->run(CarbonImmutable::parse($fecha));
    }

    private function facturas()
    {
        return AccountInvoice::where('account_id', $this->cuenta->id)->orderBy('id')->get();
    }

    // ── Facturar ─────────────────────────────────────────────────────────────

    public function test_factura_el_mes_una_sola_vez_y_le_descuenta_el_saldo_a_favor(): void
    {
        // Pagó 24 de más el mes pasado, sin factura.
        AccountPayment::create([
            'account_id' => $this->cuenta->id, 'amount' => 24, 'currency' => 'USD',
            'method' => 'transfer', 'paid_at' => '2026-09-20',
        ]);

        $this->correr('2026-10-01');
        $this->correr('2026-10-01');
        $this->correr('2026-10-02');

        $facturas = $this->facturas();
        $this->assertCount(1, $facturas, 'El comando corre cada hora: el mes se factura una sola vez.');

        $f = $facturas->first()->load('payments');
        $this->assertSame('F-001', $f->number);
        $this->assertSame(AccountInvoice::SOURCE_AUTO, $f->source);
        $this->assertSame('2026-10', $f->billing_period);
        $this->assertEquals(69, (float) $f->total, 'La fila ispwatch del combo va en 0: no se cobra dos veces.');
        $this->assertSame('2026-10-01', $f->period_start->toDateString());
        $this->assertSame('2026-10-31', $f->period_end->toDateString());
        $this->assertSame('2026-10-06', $f->due_at->toDateString());
        $this->assertEquals(45, $f->balance_due, 'Los 24 a favor se descuentan solos.');
        $this->assertSame('partial', $f->status);
    }

    public function test_si_el_servidor_estuvo_caido_la_factura_no_nace_vencida(): void
    {
        // El período empezó el 1, pero el comando recién corre el 9.
        $this->correr('2026-10-09');

        $f = $this->facturas()->first();
        $this->assertSame('2026-10-09', $f->issued_at->toDateString());
        $this->assertSame('2026-10-14', $f->due_at->toDateString(),
            'El plazo se cuenta desde que se emite: una factura vencida al nacer es un corte injusto.');
        $this->assertFalse($this->cuenta->fresh()->isSuspended());
    }

    public function test_no_factura_los_meses_anteriores_a_encender_el_cobro(): void
    {
        $this->cuenta->update(['auto_billing_since' => '2026-10-12']);

        $this->correr('2026-10-12');
        $this->assertCount(0, $this->facturas(), 'Octubre pudo cobrarse por fuera: no se factura solo.');

        $this->correr('2026-11-01');
        $this->assertSame(['2026-11'], $this->facturas()->pluck('billing_period')->all());
    }

    public function test_respeta_la_factura_manual_del_mes(): void
    {
        AccountInvoice::create([
            'account_id' => $this->cuenta->id, 'number' => 'F-003', 'concept' => 'Octubre a mano',
            'amount' => 69, 'total' => 69, 'currency' => 'USD', 'status' => 'sent',
            'issued_at' => '2026-10-01', 'due_at' => '2026-10-31',
            'period_start' => '2026-10-01', 'period_end' => '2026-10-31',
        ]);

        $this->correr('2026-10-02');
        $this->assertCount(1, $this->facturas());

        // Y la siguiente automática sigue la numeración.
        $this->correr('2026-11-01');
        $this->assertSame('F-004', $this->facturas()->last()->number);
    }

    public function test_los_productos_que_no_son_mensuales_no_se_cobran_solos(): void
    {
        $this->cuenta->products()->where('product', 'converza')->update(['billing_cycle' => 'yearly', 'amount' => 700]);

        $this->correr('2026-10-01');

        $this->assertCount(0, $this->facturas(), 'Cobrar un anual cada mes sería cobrar doce veces.');
    }

    // ── Cortar y reactivar ───────────────────────────────────────────────────

    public function test_corta_al_vencer_sin_pago_y_el_pago_suelto_lo_reactiva(): void
    {
        $this->correr('2026-10-01');

        $this->correr('2026-10-05');
        $this->assertSame(1, AccountBillingNotice::where('kind', 'recordatorio')->count(), 'El día antes, recordatorio.');

        $this->correr('2026-10-06');
        $this->assertFalse($this->cuenta->fresh()->isSuspended(), 'El día del vencimiento todavía puede pagar.');

        $this->correr('2026-10-07');
        $cuenta = $this->cuenta->fresh();
        $this->assertTrue($cuenta->isSuspended());
        $this->assertSame(Account::SUSPENSION_BILLING, $cuenta->suspension_reason);
        $this->assertSame('overdue', $this->facturas()->first()->status);
        $this->assertSame(1, AccountBillingNotice::where('kind', 'suspension')->count());

        // Paga por "Registrar pago" general, sin elegir la factura.
        $this->travelTo(CarbonImmutable::parse('2026-10-08 15:00', 'America/Bogota'));
        $this->actingAs($this->interno)->from("/brain/accounts/{$this->cuenta->id}")
            ->post("/brain/accounts/{$this->cuenta->id}/payments", [
                'amount' => 69, 'currency' => 'USD', 'method' => 'transfer', 'paid_at' => '2026-10-08',
            ])->assertRedirect();

        $this->assertSame('paid', $this->facturas()->first()->status,
            'El pago suelto cubre la factura vencida: si esperara a la próxima, el cliente seguiría cortado habiendo pagado.');
        $cuenta = $this->cuenta->fresh();
        $this->assertFalse($cuenta->isSuspended());
        $this->assertNull($cuenta->suspension_reason);
        $this->assertSame(1, AccountBillingNotice::where('kind', 'reactivacion')->count());
    }

    public function test_un_corte_manual_no_se_levanta_solo_con_el_pago(): void
    {
        $this->correr('2026-10-01');

        $this->cuenta->suspend(Account::SUSPENSION_MANUAL);

        $this->travelTo(CarbonImmutable::parse('2026-10-03 15:00', 'America/Bogota'));
        $this->actingAs($this->interno)->from("/brain/accounts/{$this->cuenta->id}")
            ->post("/brain/accounts/{$this->cuenta->id}/payments", [
                'amount' => 69, 'currency' => 'USD', 'method' => 'transfer', 'paid_at' => '2026-10-03',
            ]);

        $this->correr('2026-10-04');

        $this->assertTrue($this->cuenta->fresh()->isSuspended(),
            'Lo cortó una persona por una razón que el cobro no conoce: solo una persona lo levanta.');
    }

    public function test_el_pago_va_primero_a_la_factura_mas_vieja(): void
    {
        foreach (['F-010' => '2026-09-01', 'F-011' => '2026-10-01'] as $numero => $fecha) {
            AccountInvoice::create([
                'account_id' => $this->cuenta->id, 'number' => $numero, 'concept' => $numero,
                'amount' => 69, 'total' => 69, 'currency' => 'USD', 'status' => 'sent',
                'issued_at' => $fecha, 'due_at' => $fecha,
            ]);
        }

        AccountPayment::create([
            'account_id' => $this->cuenta->id, 'amount' => 69, 'currency' => 'USD',
            'method' => 'transfer', 'paid_at' => '2026-10-02',
        ]);
        $this->cuenta->allocateCredit();

        $this->assertSame(['paid', 'sent'], $this->facturas()->pluck('status')->all());
    }

    // ── El aviso ─────────────────────────────────────────────────────────────

    public function test_fuera_de_la_ventana_y_sin_plantilla_no_se_manda_nada(): void
    {
        $this->correr('2026-10-01');

        Http::assertNothingSent();

        $aviso = AccountBillingNotice::where('kind', 'factura')->first();
        $this->assertSame('skipped', $aviso->status);
        $this->assertSame('sin_plantilla', $aviso->reason);
    }

    public function test_dentro_de_la_ventana_sale_como_texto_con_las_instrucciones_de_pago(): void
    {
        $converza = Tenant::where('slug', 'default')->first();
        $contacto = Contact::withoutGlobalScopes()->create(['tenant_id' => $converza->id, 'phone' => '573001112233']);
        $conv = Conversation::withoutGlobalScopes()->create(['tenant_id' => $converza->id, 'contact_id' => $contacto->id]);
        Message::withoutGlobalScopes()->create([
            'tenant_id' => $converza->id, 'conversation_id' => $conv->id,
            'contact_id' => $contacto->id, 'body' => 'hola', 'status' => 'received',
        ]);

        $this->correr('2026-10-01');

        Http::assertSent(fn ($r) => str_contains($r['text']['body'] ?? '', 'F-001')
            && str_contains($r['text']['body'] ?? '', 'Nequi 300 000 0000')
            && ($r['to'] ?? null) === '573001112233');

        $this->assertSame('sent', AccountBillingNotice::where('kind', 'factura')->first()->status);
    }

    // ── El panel del ISP ─────────────────────────────────────────────────────

    public function test_el_panel_del_isp_suspendido_muestra_servicio_suspendido_y_nada_mas(): void
    {
        $this->cuenta->suspend(Account::SUSPENSION_BILLING);

        $this->actingAs($this->adminIsp)->get('/')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('ServicioSuspendido')
                ->where('motivo', 'billing')
                ->where('instrucciones', 'Nequi 300 000 0000'));

        // Las llamadas de fondo reciben el motivo, no una página.
        $this->actingAs($this->adminIsp)->getJson('/metrics')
            ->assertStatus(423)
            ->assertJson(['code' => 'servicio_suspendido']);

        // Cerrar sesión siempre se puede.
        $this->actingAs($this->adminIsp)->post('/logout')->assertRedirect();
    }

    public function test_al_reactivar_el_panel_se_abre_en_el_acto(): void
    {
        $this->cuenta->suspend(Account::SUSPENSION_BILLING);
        $this->actingAs($this->adminIsp)->getJson('/metrics')->assertStatus(423);

        $this->cuenta->fresh()->reactivate();

        // Sin esperar a que venza el caché de un minuto.
        $this->assertFalse(Cache::has(Account::suspendedCacheKey($this->tenantIsp->id)));
    }

    public function test_el_equipo_interno_no_queda_bloqueado(): void
    {
        $this->cuenta->suspend(Account::SUSPENSION_BILLING);

        $this->actingAs($this->interno)->get('/brain/finanzas')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Brain/Finanzas'));
    }

    // ── Lo que ve ispwatch ───────────────────────────────────────────────────

    public function test_el_estado_para_ispwatch_exige_token_y_dice_si_esta_cortado(): void
    {
        $this->getJson('/saas/estado/19')->assertNotFound();

        config()->set('brain.status_api_token', 'secreto');

        $this->getJson('/saas/estado/19')->assertUnauthorized();
        $this->getJson('/saas/estado/19', ['Authorization' => 'Bearer otro'])->assertUnauthorized();

        $this->getJson('/saas/estado/19', ['Authorization' => 'Bearer secreto'])
            ->assertOk()
            ->assertJson(['suspended' => false, 'known' => true]);

        $this->cuenta->suspend(Account::SUSPENSION_BILLING);

        $this->getJson('/saas/estado/19', ['Authorization' => 'Bearer secreto'])
            ->assertOk()
            ->assertJson(['suspended' => true, 'reason' => 'billing']);

        // Un ISP que no es cliente nuestro no se corta.
        $this->getJson('/saas/estado/999', ['Authorization' => 'Bearer secreto'])
            ->assertOk()
            ->assertJson(['suspended' => false, 'known' => false]);
    }
}
