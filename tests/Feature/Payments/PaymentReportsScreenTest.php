<?php

namespace Tests\Feature\Payments;

use App\Models\Message;
use App\Models\PaymentChannel;
use App\Models\PaymentReport;
use App\Models\Tenant;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * «Pagos reportados», «Leer comprobante» en el chat y la configuración de IA y
 * medios de pago: quién puede qué, y que cada acción haga solo lo suyo.
 */
class PaymentReportsScreenTest extends PaymentsTestCase
{
    private User $admin;
    private User $asesor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin  = $this->userWithRole('admin@isp.test', 'admin');
        $this->asesor = $this->userWithRole('asesor@isp.test', 'agent');
    }

    public function test_solo_el_admin_ve_los_pagos_reportados(): void
    {
        $report = $this->pendingReport();

        $this->actingAs($this->asesor)->get('/pagos')->assertForbidden();
        $this->actingAs($this->asesor)->post("/pagos/{$report->id}/descartar", ['note' => 'no'])->assertForbidden();

        $this->actingAs($this->admin)->get('/pagos')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Payments/Index')
                ->where('filter', 'revision')
                ->where('counts.revision', 1)
                ->where('reports.data.0.id', $report->id)
                ->where('reports.data.0.customer.name', 'Ana Gómez')
                ->where('canRegister', true));
    }

    public function test_cualquier_asesor_puede_pedir_leer_un_comprobante_desde_el_chat(): void
    {
        $this->incoming('Buenas, ya pagué');
        $this->sendImage('MEDIA3');
        $image = Message::withoutGlobalScopes()->where('media_id', 'MEDIA3')->firstOrFail();

        $this->actingAs($this->asesor)
            ->postJson("/chat/messages/{$image->id}/receipt")
            ->assertOk()
            ->assertJsonPath('report.status', PaymentReport::STATUS_READING);

        // Sin flujo y desde el chat nunca se registra solo: queda para revisión.
        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('sin_auto', $report->reason);
        $this->assertSame($this->asesor->id, $report->requested_by);
        $this->assertStringContainsString('Comprobante leído ($50.000 · Nequi · ref M1234567', implode("\n", $this->notes()));

        // Pedirlo otra vez devuelve el mismo reporte: un reporte por imagen.
        $this->actingAs($this->asesor)->postJson("/chat/messages/{$image->id}/receipt")->assertOk();
        $this->assertSame(1, PaymentReport::withoutGlobalScopes()->count());
        $this->assertCount(1, $this->requestsTo('https://api.x.ai/'));
    }

    public function test_un_texto_no_se_lee_como_comprobante(): void
    {
        $this->incoming('Hola');
        $text = Message::withoutGlobalScopes()->where('type', 'text')->firstOrFail();

        $this->actingAs($this->asesor)->postJson("/chat/messages/{$text->id}/receipt")->assertStatus(422);
        $this->assertSame(0, PaymentReport::withoutGlobalScopes()->count());
    }

    public function test_registrar_desde_la_pantalla_usa_lo_que_corrigio_la_persona(): void
    {
        $report = $this->pendingReport();
        $nequi  = PaymentChannel::firstOrFail();

        $this->actingAs($this->admin)->post("/pagos/{$report->id}/registrar", [
            'amount'             => 55000,
            'paid_on'            => now()->format('Y-m-d'),
            'reference'          => 'M7654321',
            'payment_channel_id' => $nequi->id,
        ])->assertRedirect()->assertSessionHas('success');

        $report->refresh();
        $this->assertSame(PaymentReport::STATUS_REGISTERED, $report->status);
        $this->assertSame($this->admin->id, $report->registered_by);
        $this->assertSame($this->admin->id, $report->reviewed_by);

        [$api] = $this->requestsTo('https://ispwatch.test/');
        $this->assertEquals(55000, $api['amount']);
        $this->assertSame('M7654321', $api['reference']);
        $this->assertSame(4, $api['payment_method_id']);
    }

    public function test_sin_la_conexion_de_pagos_no_se_registra_desde_la_pantalla(): void
    {
        $report = $this->pendingReport();
        $this->tenant->update(['ispwatch_api_token' => null]);

        $this->actingAs($this->admin)->post("/pagos/{$report->id}/registrar", [
            'amount' => 50000, 'paid_on' => now()->format('Y-m-d'),
        ])->assertSessionHas('error');

        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->fresh()->status);
        $this->assertSame([], $this->requestsTo('https://ispwatch.test/'));
    }

    public function test_marcar_como_registrado_y_descartar(): void
    {
        $first  = $this->pendingReport();
        $second = $this->pendingReport('MEDIA9');

        $this->actingAs($this->admin)->post("/pagos/{$first->id}/ya-registrado", ['ispwatch_payment_id' => 321, 'note' => 'Lo registré en caja'])
            ->assertSessionHas('success');
        $this->actingAs($this->admin)->post("/pagos/{$second->id}/descartar", [])->assertSessionHasErrors('note');
        $this->actingAs($this->admin)->post("/pagos/{$second->id}/descartar", ['note' => 'Comprobante editado'])
            ->assertSessionHas('success');

        $first->refresh();
        $this->assertSame(PaymentReport::STATUS_REGISTERED, $first->status);
        $this->assertTrue($first->registered_manually);
        $this->assertSame(321, $first->ispwatch_payment_id);
        $this->assertSame(PaymentReport::STATUS_REJECTED, $second->fresh()->status);
        $this->assertSame('Comprobante editado', $second->fresh()->review_note);

        // Un pago registrado ya no se descarta desde aquí.
        $this->actingAs($this->admin)->post("/pagos/{$first->id}/descartar", ['note' => 'otra cosa'])->assertSessionHas('error');
        $this->assertSame(PaymentReport::STATUS_REGISTERED, $first->fresh()->status);
    }

    public function test_asignar_el_cliente_a_mano(): void
    {
        $this->customer = null;
        $report = $this->pendingReport();
        $this->assertNull($report->ispwatch_customer_id);

        $this->actingAs($this->admin)->getJson('/pagos/clientes?q=pedro')
            ->assertOk()
            ->assertJsonPath('customers.0.user_id', 777);

        $this->actingAs($this->admin)->put("/pagos/{$report->id}/cliente", ['customer_id' => 777])->assertSessionHas('success');
        $this->actingAs($this->admin)->put("/pagos/{$report->id}/cliente", ['customer_id' => 12345])->assertSessionHas('error');

        $report->refresh();
        $this->assertSame(777, $report->ispwatch_customer_id);
        $this->assertSame('Pedro Ruiz', $report->customer_name);
        $this->assertSame('manual', $report->customer_match);
    }

    public function test_un_reporte_de_otra_empresa_no_existe(): void
    {
        $report = $this->pendingReport();
        $otro   = Tenant::create(['slug' => 'otro-isp', 'name' => 'Otro ISP', 'is_active' => true]);
        $report->forceFill(['tenant_id' => $otro->id])->saveQuietly();

        $this->actingAs($this->admin)->getJson("/pagos/{$report->id}")->assertNotFound();
        $this->actingAs($this->admin)->post("/pagos/{$report->id}/descartar", ['note' => 'x x x'])->assertNotFound();
    }

    public function test_el_admin_enciende_la_ia_y_arma_sus_medios_de_pago(): void
    {
        $this->actingAs($this->asesor)->put('/settings/ai', ['ai_enabled' => false])->assertForbidden();

        $this->actingAs($this->admin)->put('/settings/ai', ['ai_enabled' => false, 'receipts_auto_max_amount' => 200000])
            ->assertSessionHas('success');
        $this->assertFalse($this->tenant->fresh()->ai_enabled);
        $this->assertEquals(200000, $this->tenant->fresh()->receipts_auto_max_amount);

        // Traer el catálogo de ispwatch: solo lo activo y sin duplicar lo enlazado
        // (Transacción ya está enlazada al Nequi de la prueba).
        $this->actingAs($this->admin)->post('/settings/payment-channels/import')->assertSessionHas('success');
        $efectivo = PaymentChannel::where('name', 'Efectivo')->firstOrFail();
        $this->assertSame(1, $efectivo->ispwatch_payment_method_id);
        $this->assertFalse($efectivo->show_in_bot, 'Sin número de cuenta no se le muestra al cliente.');
        $this->assertSame(0, PaymentChannel::where('name', 'Baloto')->count());
        $this->assertSame(0, PaymentChannel::where('name', 'Transacción')->count());

        $this->actingAs($this->admin)->post('/settings/payment-channels', [
            'name' => 'Bancolombia', 'ispwatch_payment_method_id' => 4, 'account_number' => '123-456789-01',
            'account_holder' => 'Fibra Chaguaní SAS', 'instructions' => 'Cuenta de ahorros', 'show_in_bot' => true, 'is_active' => true,
        ])->assertSessionHas('success');

        // Una forma de pago que no es de ispwatch para esta empresa no se acepta.
        $this->actingAs($this->admin)->post('/settings/payment-channels', [
            'name' => 'Raro', 'ispwatch_payment_method_id' => 999, 'show_in_bot' => true, 'is_active' => true,
        ])->assertSessionHasErrors('ispwatch_payment_method_id');

        $bancolombia = PaymentChannel::where('name', 'Bancolombia')->firstOrFail();
        $this->actingAs($this->admin)->put("/settings/payment-channels/{$bancolombia->id}", [
            'name' => 'Bancolombia ahorros', 'ispwatch_payment_method_id' => 4, 'account_number' => '123-456789-01',
            'show_in_bot' => false, 'is_active' => true,
        ])->assertSessionHas('success');
        $this->assertSame('Bancolombia ahorros', $bancolombia->fresh()->name);

        $this->actingAs($this->admin)->delete("/settings/payment-channels/{$bancolombia->id}")->assertSessionHas('success');
        $this->assertNull($bancolombia->fresh());
    }

    /** Un comprobante leído desde el chat, para revisión. */
    private function pendingReport(string $mediaId = 'MEDIA5'): PaymentReport
    {
        if (! Message::withoutGlobalScopes()->exists()) {
            $this->incoming('Hola');
        }

        $this->sendImage($mediaId);
        $image = Message::withoutGlobalScopes()->where('media_id', $mediaId)->firstOrFail();

        $this->reading = $this->validReading(['referencia' => 'REF-' . $mediaId]);
        $this->actingAs($this->asesor)->postJson("/chat/messages/{$image->id}/receipt")->assertOk();

        $report = PaymentReport::withoutGlobalScopes()->where('message_id', $image->id)->firstOrFail();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);

        return $report;
    }
}
