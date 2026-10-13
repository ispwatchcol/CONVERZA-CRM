<?php

namespace Tests\Feature;

use App\Models\Brain\Account;
use App\Models\Brain\AccountPayment;
use App\Models\Brain\Expense;
use App\Models\Brain\RecurringExpense;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

/**
 * Finanzas del Brain: ingresos (cobros a los ISP) contra egresos (lo que cuestan
 * las apps).
 *
 * Lo delicado son los gastos fijos: se registran solos, así que tienen que
 * registrarse UNA vez por período —el comando puede correr de más— y nunca
 * inventar meses viejos de golpe.
 */
class FinanzasTest extends TestCase
{
    private User $interno;

    /** También la usa CobroAutomaticoTest, que abre la página de Finanzas. */
    public static function crearTablasDeEgresos(): void
    {
        Schema::create('brain_recurring_expenses', function (Blueprint $t) {
            $t->id();
            $t->string('concept', 120);
            $t->string('vendor', 80)->nullable();
            $t->string('category', 30);
            $t->string('app', 40);
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3)->default('USD');
            $t->string('cycle')->default('monthly');
            $t->unsignedTinyInteger('day_of_month')->default(1);
            $t->unsignedTinyInteger('month_of_year')->nullable();
            $t->date('starts_on');
            $t->date('ends_on')->nullable();
            $t->boolean('active')->default(true);
            $t->text('notes')->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
        });

        Schema::create('brain_expenses', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('recurring_expense_id')->nullable();
            $t->string('concept', 120);
            $t->string('vendor', 80)->nullable();
            $t->string('category', 30);
            $t->string('app', 40);
            $t->string('kind');
            $t->decimal('amount', 12, 2);
            $t->string('currency', 3)->default('USD');
            $t->date('spent_at');
            $t->string('method', 20)->nullable();
            $t->string('reference', 120)->nullable();
            $t->text('notes')->nullable();
            $t->string('period', 7)->nullable();
            $t->unsignedBigInteger('created_by')->nullable();
            $t->timestamps();
            $t->unique(['recurring_expense_id', 'period']);
        });
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        foreach (['brain_expenses', 'brain_recurring_expenses', 'account_payments', 'account_invoices', 'account_products', 'accounts', 'staff_members', 'users', 'tenants'] as $tabla) {
            Schema::dropIfExists($tabla);
        }

        self::crearTablasDeEgresos();

        Schema::create('staff_members', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('role')->default('agent');
            $t->boolean('is_active')->default(true);
            $t->timestamps();
        });

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

        Schema::create('accounts', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->string('slug')->unique();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->string('status')->default('active');
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
            $t->timestamps();
        });

        Schema::create('account_invoices', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('account_id');
            $t->string('number', 40)->unique();
            $t->timestamps();
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

        config()->set('services.whatsapp.url', null);
        Cache::put('brain.tickets.esperando', 0, 3600);
        Http::fake();

        $this->interno = User::create(['name' => 'Yo', 'email' => 'yo@converza.test', 'password' => 'x', 'internal_role' => 'owner']);
    }

    private function nuevoFijo(array $datos = []): void
    {
        $this->actingAs($this->interno)->from('/brain/finanzas')->post('/brain/finanzas/fijos', [
            'concept' => 'Droplet DigitalOcean', 'vendor' => 'DigitalOcean', 'category' => 'infraestructura',
            'app' => 'Converza ', 'amount' => 6, 'currency' => 'USD', 'cycle' => 'monthly',
            'day_of_month' => 5, 'starts_on' => '2026-10-01', 'active' => true,
            ...$datos,
        ])->assertRedirect()->assertSessionHasNoErrors();
    }

    public function test_un_gasto_fijo_se_registra_una_vez_por_periodo(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'America/Bogota'));

        $this->nuevoFijo();

        // Ya pasó el 5: el de octubre se registra en el acto, como fijo.
        $gasto = Expense::sole();
        $this->assertSame('fixed', $gasto->kind);
        $this->assertSame('2026-10-05', $gasto->spent_at->toDateString());
        $this->assertSame('2026-10', $gasto->period);
        $this->assertSame('converza', $gasto->app, '"Converza " y "converza" son la misma app en el reporte.');

        $this->artisan('brain:expenses-recurring')->assertSuccessful();
        $this->artisan('brain:expenses-recurring')->assertSuccessful();
        $this->assertSame(1, Expense::count(), 'Correr el comando de más no duplica el mes.');

        $this->travelTo(CarbonImmutable::parse('2026-11-04 10:00', 'America/Bogota'));
        $this->artisan('brain:expenses-recurring');
        $this->assertSame(1, Expense::count(), 'El de noviembre todavía no: su día es el 5.');

        $this->travelTo(CarbonImmutable::parse('2026-11-05 10:00', 'America/Bogota'));
        $this->artisan('brain:expenses-recurring');
        $this->assertSame(['2026-10', '2026-11'], Expense::orderBy('id')->pluck('period')->all());
    }

    public function test_tras_meses_apagado_no_aparecen_meses_viejos_de_golpe(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'America/Bogota'));
        $this->nuevoFijo(['starts_on' => '2026-01-01']);

        $this->assertSame(['2026-10'], Expense::pluck('period')->all(),
            'Arrancó en enero, pero se registra solo el período vigente: lo que falte se carga a mano, a la vista.');
    }

    public function test_un_gasto_anual_solo_se_registra_en_su_mes(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'America/Bogota'));
        $this->nuevoFijo(['concept' => 'Dominio converza.co', 'cycle' => 'yearly', 'month_of_year' => 3, 'day_of_month' => 1, 'amount' => 24]);

        $this->assertSame(0, Expense::count());
        $this->assertEquals(2, RecurringExpense::sole()->monthlyAmount(), 'Contra el MRR pesa 24/12 al mes.');
    }

    public function test_el_resultado_del_mes_cruza_lo_que_entro_con_lo_que_salio(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-10-12 10:00', 'America/Bogota'));

        $cuenta = Account::create(['name' => 'ISP A', 'slug' => 'isp-a']);
        AccountPayment::create(['account_id' => $cuenta->id, 'amount' => 69, 'currency' => 'USD', 'method' => 'transfer', 'paid_at' => '2026-10-02']);
        // El saldo a favor aplicado es plata que ya se contó al entrar: no es ingreso nuevo.
        AccountPayment::create(['account_id' => $cuenta->id, 'amount' => 24, 'currency' => 'USD', 'method' => 'other', 'is_credit_application' => true, 'paid_at' => '2026-10-02']);

        $this->nuevoFijo();
        $this->actingAs($this->interno)->from('/brain/finanzas')->post('/brain/finanzas/egresos', [
            'concept' => 'Saldo Meta', 'category' => 'whatsapp_meta', 'app' => 'converza', 'kind' => 'variable',
            'amount' => 10, 'currency' => 'USD', 'spent_at' => '2026-10-10',
        ])->assertSessionHasNoErrors();

        $this->actingAs($this->interno)->get('/brain/finanzas?month=2026-10')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Brain/Finanzas')
                ->where('summary.income', ['USD' => '69.00'])
                ->where('summary.expenses', ['USD' => '16.00'])
                ->where('summary.fixed', ['USD' => '6.00'])
                ->where('summary.variable', ['USD' => '10.00'])
                ->where('unit.fixed_monthly', ['USD' => '6.00']));
    }

    public function test_un_usuario_de_tenant_no_llega_a_finanzas(): void
    {
        $isp = User::create(['name' => 'Admin ISP', 'email' => 'a@isp.test', 'password' => 'x', 'tenant_id' => 99]);

        $this->actingAs($isp)->get('/brain/finanzas')->assertForbidden();
        $this->actingAs($isp)->post('/brain/finanzas/egresos', [])->assertForbidden();
    }
}
