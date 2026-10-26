<?php

namespace Tests\Feature\Payments;

use App\Jobs\ProcessPaymentReport;
use App\Jobs\RunBotFlow;
use App\Models\AiUsageLog;
use App\Models\BotFlowRun;
use App\Models\Message;
use App\Models\PaymentReport;
use App\Services\Flows\FlowValidator;
use App\Services\Flows\GraphBuilder;
use App\Services\Flows\Validation\ValidationContext;
use App\Services\Payments\IspwatchUnavailable;
use App\Services\Payments\PaymentReportProcessor;
use App\Services\Payments\ReceiptVerifier;
use Illuminate\Support\Facades\Queue;

/**
 * El bloque «Comprobante de pago» de punta a punta: la foto entra por el
 * webhook, la IA la lee en su cola, el código verifica y decide, ispwatch
 * registra (o no) y el flujo le contesta al cliente.
 */
class ReceiptFlowTest extends PaymentsTestCase
{
    public function test_un_comprobante_valido_se_registra_solo_y_el_bot_lo_confirma(): void
    {
        $this->publishedFlow($this->receiptFlow());

        // Lo más común: el cliente abre el chat mandando la foto.
        $this->sendImage('MEDIA1');

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_REGISTERED, $report->status);
        $this->assertSame(9001, $report->ispwatch_payment_id);
        $this->assertSame(501, $report->ispwatch_customer_id);
        $this->assertSame('telefono', $report->customer_match);
        $this->assertNull($report->registered_by, 'Lo registró el bot, no una persona.');
        $this->assertTrue(ReceiptVerifier::allPassed($report->checks));
        $this->assertSame('Nequi', $report->channelRecord->name);

        // xAI: sin retención, con la imagen y con la salida estricta.
        $xai = $this->requestsTo('https://api.x.ai/');
        $this->assertCount(1, $xai);
        $this->assertSame('https://api.x.ai/v1/responses', $xai[0]->url());
        $this->assertFalse($xai[0]['store']);
        $this->assertSame('grok-4.3', $xai[0]['model']);
        $this->assertSame(['effort' => 'low'], $xai[0]['reasoning']);
        $this->assertSame('json_schema', $xai[0]['text']['format']['type']);
        $this->assertTrue($xai[0]['text']['format']['strict']);
        $this->assertStringStartsWith('data:image/jpeg;base64,', $xai[0]['input'][0]['content'][1]['image_url']);

        // ispwatch: con la credencial de la empresa y la llave de idempotencia.
        $api = $this->requestsTo('https://ispwatch.test/');
        $this->assertCount(1, $api);
        $this->assertSame('https://ispwatch.test/api/v1/integrations/payments', $api[0]->url());
        $this->assertTrue($api[0]->hasHeader('Authorization', 'Bearer tok-77'));
        $this->assertTrue($api[0]->hasHeader('Idempotency-Key', 'converza-payment-report-' . $report->id));
        $this->assertSame(501, $api[0]['customer_id']);
        $this->assertEquals(50000, $api[0]['amount']);
        $this->assertSame(4, $api[0]['payment_method_id']);
        $this->assertSame('M1234567', $api[0]['reference']);
        $this->assertSame('converza_whatsapp', $api[0]['source']);

        // El cliente recibe la confirmación, con la reconexión que hizo ispwatch.
        $this->assertSame(
            ['✅ Registramos tu pago de $50.000 (ref M1234567). Tu servicio ya quedó activo de nuevo ✅'],
            $this->whatsappTexts(),
        );
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $this->flowRun()->status);

        // El gasto de IA queda con el costo que informó xAI.
        $log = AiUsageLog::firstOrFail();
        $this->assertSame('receipt', $log->purpose);
        $this->assertEqualsWithDelta(0.0033, $log->cost_usd, 0.000001);
        $this->assertFalse($log->cost_estimated);
        $this->assertSame('PaymentReport', $log->subject_type);

        // Y el asesor lo ve en el chat.
        $this->assertStringContainsString('Pago registrado en ispwatch por el bot', implode("\n", $this->notes()));
        $this->assertStringContainsString('quedó reconectado', implode("\n", $this->notes()));
    }

    public function test_si_no_trae_la_foto_la_pide_y_la_lee_cuando_llega(): void
    {
        $this->publishedFlow($this->receiptFlow());

        $this->incoming('Hola, quiero reportar un pago');
        $this->assertSame(['Envíame la foto del comprobante 📸'], $this->whatsappTexts());
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $this->flowRun()->status);

        $this->sendImage('MEDIA7');

        $this->assertSame(PaymentReport::STATUS_REGISTERED, $this->report()->status);
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $this->flowRun()->status);
        $this->assertCount(2, $this->whatsappTexts());
    }

    public function test_sin_registro_automatico_el_comprobante_queda_para_revision(): void
    {
        $this->publishedFlow($this->receiptFlow(['auto_register' => false]));

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('sin_auto', $report->reason);
        $this->assertEquals(50000, $report->amount, 'La lectura queda lista para quien revise.');
        $this->assertSame([], $this->requestsTo('https://ispwatch.test/'));
        $this->assertSame(['Recibido 🙌 Lo revisa el equipo y te confirmamos.'], $this->whatsappTexts());
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $this->flowRun()->status);
        $this->assertStringContainsString('Queda en «Pagos reportados» para revisión', implode("\n", $this->notes()));
    }

    public function test_un_pago_a_otra_cuenta_no_se_registra(): void
    {
        $this->reading = $this->validReading(['cuenta_destino' => '311 999 8888', 'titular_destino' => 'Otra persona']);
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('verificacion', $report->reason);
        $this->assertFalse(collect($report->checks)->firstWhere('code', 'cuenta')['ok']);
        $this->assertSame([], $this->requestsTo('https://ispwatch.test/'));
        $this->assertSame(['Recibido 🙌 Lo revisa el equipo y te confirmamos.'], $this->whatsappTexts());
        $this->assertStringContainsString('no es ninguna cuenta de la empresa', implode("\n", $this->notes()));
    }

    public function test_un_abono_menor_que_la_factura_no_se_registra_solo(): void
    {
        // ispwatch cerraría la factura, arrastraría el resto y lo reconectaría:
        // con un bot, un abono simbólico escalaría.
        $this->reading = $this->validReading(['valor' => 1000]);
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertFalse(collect($report->checks)->firstWhere('code', 'deuda')['ok']);
        $this->assertSame([], $this->requestsTo('https://ispwatch.test/'));
    }

    public function test_un_telefono_que_no_es_de_un_cliente_queda_para_revision(): void
    {
        $this->customer = null;
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertNull($report->ispwatch_customer_id);
        $this->assertFalse(collect($report->checks)->firstWhere('code', 'cliente')['ok']);
    }

    public function test_una_imagen_que_no_es_comprobante_pide_otra_y_despues_se_rinde(): void
    {
        $this->reading = $this->validReading([
            'es_comprobante' => false, 'motivo_si_no_es' => 'La foto de un router', 'valor' => null,
            'referencia' => null, 'estado_transaccion' => 'desconocido',
        ]);
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $this->assertSame(PaymentReport::STATUS_NOT_RECEIPT, $this->report()->status);
        $this->assertSame(
            ['Esa imagen no parece un comprobante de pago 🤔. ¿Me envías la foto o el pantallazo del comprobante?'],
            $this->whatsappTexts(),
        );
        $this->assertSame(BotFlowRun::STATUS_WAITING_INPUT, $this->flowRun()->status);
        $this->assertStringContainsString('La foto de un router', implode("\n", $this->notes()));

        $this->incoming('ya pagué ayer');

        $this->assertSame('Te paso con un asesor.', last($this->whatsappTexts()));
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $this->flowRun()->status);
    }

    public function test_el_mismo_comprobante_no_se_registra_dos_veces(): void
    {
        $this->publishedFlow($this->receiptFlow());
        $this->sendImage('MEDIA1');
        $this->assertSame(PaymentReport::STATUS_REGISTERED, $this->report()->status);

        // Otra foto del mismo pago (misma referencia), leída desde el chat.
        $this->sendImage('MEDIA2');
        $second = Message::withoutGlobalScopes()->where('media_id', 'MEDIA2')->firstOrFail();

        $this->actingAs($this->userWithRole('asesora@isp.test', 'agent'))
            ->postJson("/chat/messages/{$second->id}/receipt")
            ->assertOk();

        $this->assertSame(2, PaymentReport::withoutGlobalScopes()->count());
        $duplicate = $this->report();
        $this->assertSame($second->id, $duplicate->message_id);
        $this->assertSame(PaymentReport::STATUS_DUPLICATE, $duplicate->status);
        $this->assertSame('duplicado', $duplicate->reason);
        $this->assertSame(PaymentReport::SOURCE_CHAT, $duplicate->source);
        $this->assertCount(1, $this->requestsTo('https://ispwatch.test/'), 'ispwatch recibió UN solo pedido.');
    }

    public function test_un_pago_que_la_cajera_ya_registro_no_se_registra_otra_vez(): void
    {
        $this->existingPayment = ['id' => 8000, 'customer_id' => 501];
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_REGISTERED, $report->status);
        $this->assertSame('ya_registrado', $report->reason);
        $this->assertSame(8000, $report->ispwatch_payment_id);
        $this->assertTrue($report->registered_manually);
        $this->assertSame([], $this->requestsTo('https://ispwatch.test/'));
    }

    public function test_una_referencia_ya_usada_por_otro_cliente_va_a_revision(): void
    {
        $this->existingPayment = ['id' => 8000, 'customer_id' => 999];
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertStringContainsString('OTRO cliente', collect($report->checks)->firstWhere('code', 'ispwatch')['detail']);
        $this->assertSame([], $this->requestsTo('https://ispwatch.test/'));
    }

    public function test_si_la_lectura_no_contesta_a_tiempo_pasa_a_revision(): void
    {
        Queue::fake([ProcessPaymentReport::class]);
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $this->assertSame(BotFlowRun::STATUS_WAITING_TASK, $this->flowRun()->status);
        $this->assertSame(PaymentReport::STATUS_READING, $this->report()->status);
        Queue::assertPushedOn('ai', ProcessPaymentReport::class);

        $this->travel(6)->minutes();
        $this->artisan('flows:tick')->assertSuccessful();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('ia_sin_respuesta', $report->reason);
        $this->assertSame(['Recibido 🙌 Lo revisa el equipo y te confirmamos.'], $this->whatsappTexts());
        $this->assertSame(BotFlowRun::STATUS_HANDED_OFF, $this->flowRun()->status);
    }

    public function test_una_lectura_que_termina_tarde_no_pisa_la_revision_ni_registra(): void
    {
        Queue::fake([ProcessPaymentReport::class]);
        $this->publishedFlow($this->receiptFlow());
        $this->sendImage();

        $this->travel(6)->minutes();
        $this->artisan('flows:tick')->assertSuccessful();
        $this->assertSame('ia_sin_respuesta', $this->report()->reason);

        // La lectura llega después: completa los datos, pero el cliente ya
        // escuchó «lo revisa el equipo» y nada se registra solo.
        (new ProcessPaymentReport($this->tenant->id, $this->report()->id))->handle(app(PaymentReportProcessor::class));

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('ia_sin_respuesta', $report->reason);
        $this->assertEquals(50000, $report->amount, 'Quien revise ya tiene la lectura.');
        $this->assertNotEmpty($report->checks);
        $this->assertSame([], $this->requestsTo('https://ispwatch.test/'));
        $this->assertCount(1, $this->whatsappTexts(), 'Al cliente no se le dice nada más.');
    }

    public function test_un_aviso_viejo_no_vence_la_lectura_antes_de_tiempo(): void
    {
        Queue::fake([ProcessPaymentReport::class]);
        $this->publishedFlow($this->receiptFlow());
        $this->sendImage();
        $run = $this->flowRun();

        // Un despertar por tarea que llega antes del plazo (de una tarea vieja).
        RunBotFlow::dispatch($this->tenant->id, $run->id, RunBotFlow::REASON_TASK);

        $this->assertSame(BotFlowRun::STATUS_WAITING_TASK, $this->flowRun()->status);
        $this->assertSame(PaymentReport::STATUS_READING, $this->report()->status);
        Queue::assertPushed(ProcessPaymentReport::class, 1);
        $this->assertSame([], $this->whatsappTexts());

        // La lectura de verdad termina y despierta al flujo.
        (new ProcessPaymentReport($this->tenant->id, $this->report()->id))->handle(app(PaymentReportProcessor::class));

        $this->assertSame(PaymentReport::STATUS_REGISTERED, $this->report()->status);
        $this->assertSame(BotFlowRun::STATUS_COMPLETED, $this->flowRun()->status);
        $this->assertCount(1, $this->whatsappTexts());
    }

    public function test_si_ispwatch_rechaza_el_pago_queda_para_revision(): void
    {
        $this->ispwatchApi = [422, ['error' => 'validation', 'message' => 'El cliente no pertenece a este operador.']];
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('ispwatch_rechazo', $report->reason);
        $this->assertSame('El cliente no pertenece a este operador.', $report->last_error);
        $this->assertSame(['Recibido 🙌 Lo revisa el equipo y te confirmamos.'], $this->whatsappTexts());
    }

    public function test_si_ispwatch_esta_caido_se_reintenta_con_la_misma_llave_y_sin_volver_a_leer(): void
    {
        Queue::fake([ProcessPaymentReport::class]);
        $this->ispwatchApi = [503, ['message' => 'Mantenimiento']];
        $this->publishedFlow($this->receiptFlow());
        $this->sendImage();

        $job = new ProcessPaymentReport($this->tenant->id, $this->report()->id);

        try {
            $job->handle(app(PaymentReportProcessor::class));
            $this->fail('Un 503 de ispwatch tiene que hacer reintentar el job.');
        } catch (IspwatchUnavailable) {
        }

        $this->assertSame(PaymentReport::STATUS_REGISTERING, $this->report()->status);

        // ispwatch vuelve: reconoce la llave y devuelve el pago que ya había creado.
        $this->ispwatchApi = [200, ['payment' => ['id' => 9001, 'allocations' => []], 'reactivation' => ['was_suspended' => false]]];
        $job->handle(app(PaymentReportProcessor::class));

        $this->assertSame(PaymentReport::STATUS_REGISTERED, $this->report()->status);
        $requests = $this->requestsTo('https://ispwatch.test/');
        $this->assertCount(2, $requests);
        $this->assertSame($requests[0]->header('Idempotency-Key'), $requests[1]->header('Idempotency-Key'));
        $this->assertCount(1, $this->requestsTo('https://api.x.ai/'), 'La lectura no se paga dos veces.');
    }

    public function test_con_el_presupuesto_agotado_no_se_llama_a_la_ia(): void
    {
        $this->tenant->update(['ai_monthly_budget_usd' => 0.01]);
        AiUsageLog::create([
            'tenant_id' => $this->tenant->id, 'purpose' => 'receipt', 'provider' => 'xai', 'model' => 'grok-4.3',
            'status' => 'ok', 'cost_usd' => 0.02, 'created_at' => now(),
        ]);
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('presupuesto_agotado', $report->reason);
        $this->assertSame(501, $report->ispwatch_customer_id, 'Sin IA igual se identifica al cliente.');
        $this->assertSame([], $this->requestsTo('https://api.x.ai/'));
        $this->assertSame(['Recibido 🙌 Lo revisa el equipo y te confirmamos.'], $this->whatsappTexts());
    }

    public function test_con_la_ia_apagada_en_converza_nada_sale_al_proveedor(): void
    {
        config()->set('ai.enabled', false);
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $this->assertSame('ia_apagada_global', $this->report()->reason);
        $this->assertSame([], $this->requestsTo('https://api.x.ai/'));
    }

    public function test_un_error_que_no_se_arregla_reintentando_va_directo_a_revision(): void
    {
        $this->xaiStatus = 400;
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage();

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('ia_error', $report->reason);
        $this->assertCount(1, $this->requestsTo('https://api.x.ai/'));
        $this->assertSame('error', AiUsageLog::firstOrFail()->status);
    }

    public function test_un_pdf_no_se_manda_a_la_ia_y_queda_para_revision(): void
    {
        $this->publishedFlow($this->receiptFlow());

        $this->sendImage('MEDIAPDF1', mime: 'application/pdf');

        $report = $this->report();
        $this->assertSame(PaymentReport::STATUS_PENDING_REVIEW, $report->status);
        $this->assertSame('formato_no_soportado', $report->reason);
        $this->assertSame([], $this->requestsTo('https://api.x.ai/'));
    }

    public function test_el_bot_le_dice_al_cliente_los_medios_de_pago(): void
    {
        $this->publishedFlow((new GraphBuilder())
            ->node('start', 'start', ['trigger' => ['type' => 'conversation_start'], 'timezone' => 'America/Bogota'])
            ->node('medios', 'message', ['text' => "Puedes pagar por:\n{{empresa.medios_pago}}"])
            ->node('fin', 'end')
            ->edge('start', 'next', 'medios')
            ->edge('medios', 'next', 'fin'));

        $this->incoming('hola');

        $this->assertSame(["Puedes pagar por:\n• Nequi: 300 123 4567 a nombre de Fibra Chaguaní SAS"], $this->whatsappTexts());
    }

    public function test_el_validador_advierte_pero_deja_publicar_sin_la_conexion_de_pagos(): void
    {
        $this->tenant->update(['ispwatch_api_token' => null, 'ai_enabled' => false]);

        $result = app(FlowValidator::class)->validate($this->receiptFlow()->toArray(), ValidationContext::forTenant($this->tenant->id));

        $this->assertSame([], $result['errors']);
        $warnings = implode("\n", array_column($result['warnings'], 'message'));
        $this->assertStringContainsString('la lectura con IA está apagada', $warnings);
        $this->assertStringContainsString('conexión de pagos con ispwatch', $warnings);
    }

    public function test_una_salida_de_registrado_sin_registro_automatico_no_valida(): void
    {
        $graph = $this->receiptFlow()->toArray();
        foreach ($graph['nodes'] as &$node) {
            if ($node['id'] === 'comprobante') {
                $node['data']['auto_register'] = false;
            }
        }

        $result = app(FlowValidator::class)->validate($graph, ValidationContext::forTenant($this->tenant->id));

        $this->assertNotSame([], $result['errors'], 'La salida «Pago registrado» no existe sin el registro automático.');
    }
}
