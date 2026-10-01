<?php

namespace Tests\Unit\Flows;

use App\Models\Tenant;
use App\Services\Flows\FlowTemplates;
use App\Services\Flows\FlowValidator;
use App\Services\Flows\GraphBuilder;
use App\Services\Flows\Validation\ValidationContext;
use Tests\TestCase;

/**
 * El validador es la compuerta de publicación: un flujo roto no puede llegar a
 * los clientes, y cada error tiene que señalar el bloque exacto (CON-48).
 */
class FlowValidatorTest extends TestCase
{
    private function validate(GraphBuilder|array $graph, ?ValidationContext $ctx = null): array
    {
        return app(FlowValidator::class)->validate(
            $graph instanceof GraphBuilder ? $graph->toArray() : $graph,
            $ctx ?? new ValidationContext(labels: [7 => 'VIP'], teams: [3 => 'Soporte']),
        );
    }

    private function start(): GraphBuilder
    {
        return (new GraphBuilder())->node('start', 'start', ['trigger' => ['type' => 'conversation_start']]);
    }

    private function errorsFor(array $result, string $nodeId): array
    {
        return array_values(array_filter($result['errors'], fn ($e) => $e['node_id'] === $nodeId));
    }

    public function test_un_flujo_minimo_bien_armado_no_tiene_errores(): void
    {
        $result = $this->validate($this->start()
            ->node('hola', 'message', ['text' => 'Hola {{contacto.nombre}}'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'hola')
            ->edge('hola', 'next', 'fin'));

        $this->assertSame([], $result['errors']);
        $this->assertSame([], $result['warnings']);
    }

    public function test_sin_bloque_inicio_no_se_publica(): void
    {
        $result = $this->validate((new GraphBuilder())->node('fin', 'end'));

        $this->assertStringContainsString('Falta el bloque Inicio', $result['errors'][0]['message']);
    }

    public function test_una_opcion_del_menu_sin_conectar_senala_el_menu_y_la_opcion(): void
    {
        $result = $this->validate($this->start()
            ->node('menu', 'menu', ['text' => '¿Qué necesitas?', 'options' => [
                ['id' => 'a', 'label' => 'Soporte'],
                ['id' => 'b', 'label' => 'Ventas'],
            ]])
            ->node('fin', 'end')
            ->edge('start', 'next', 'menu')
            ->edge('menu', 'opt_a', 'fin')
            ->edge('menu', 'no_match', 'fin'));

        $errors = $this->errorsFor($result, 'menu');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('«Ventas»', $errors[0]['message']);
    }

    public function test_un_ciclo_que_nunca_espera_al_cliente_es_un_error(): void
    {
        $result = $this->validate($this->start()
            ->node('a', 'message', ['text' => 'uno'])
            ->node('b', 'message', ['text' => 'dos'])
            ->edge('start', 'next', 'a')
            ->edge('a', 'next', 'b')
            ->edge('b', 'next', 'a'));

        $this->assertNotEmpty($this->errorsFor($result, 'a'));
        $this->assertNotEmpty($this->errorsFor($result, 'b'));
        $this->assertStringContainsString('ciclo', $this->errorsFor($result, 'a')[0]['message']);
    }

    public function test_un_ciclo_con_una_espera_pero_sin_pregunta_tambien_es_un_error(): void
    {
        // Mandaría un mensaje cada minuto para siempre: más despacio, igual de mal.
        $result = $this->validate($this->start()
            ->node('a', 'message', ['text' => '¿Sigues ahí?'])
            ->node('w', 'wait', ['minutes' => 1])
            ->edge('start', 'next', 'a')
            ->edge('a', 'next', 'w')
            ->edge('w', 'next', 'a'));

        $this->assertNotEmpty($this->errorsFor($result, 'w'));
    }

    public function test_un_ciclo_que_pasa_por_una_pregunta_es_valido(): void
    {
        // "Volver al menú" es el ciclo más común de un bot, y es legítimo.
        $result = $this->validate($this->start()
            ->node('menu', 'menu', ['text' => 'Elige', 'options' => [['id' => 'otra', 'label' => 'Otra consulta'], ['id' => 'salir', 'label' => 'Salir']]])
            ->node('info', 'message', ['text' => 'Información…'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'menu')
            ->edge('menu', 'opt_otra', 'info')
            ->edge('info', 'next', 'menu')
            ->edge('menu', 'opt_salir', 'fin')
            ->edge('menu', 'no_match', 'fin'));

        $this->assertSame([], $result['errors']);
    }

    public function test_esperas_que_suman_mas_de_23_horas_antes_de_un_mensaje_cierran_la_ventana(): void
    {
        $result = $this->validate($this->start()
            ->node('w1', 'wait', ['minutes' => 720])
            ->node('w2', 'wait', ['minutes' => 720])
            ->node('seguimiento', 'message', ['text' => '¿Pudiste resolver?'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'w1')
            ->edge('w1', 'next', 'w2')
            ->edge('w2', 'next', 'seguimiento')
            ->edge('seguimiento', 'next', 'fin'));

        $errors = $this->errorsFor($result, 'seguimiento');
        $this->assertCount(1, $errors);
        $this->assertStringContainsString('ventana de 24 h', $errors[0]['message']);
        $this->assertStringContainsString('24', $errors[0]['message']);
    }

    public function test_una_pregunta_en_el_medio_renueva_la_ventana(): void
    {
        $result = $this->validate($this->start()
            ->node('w1', 'wait', ['minutes' => 720])
            ->node('q', 'question', ['text' => '¿Seguimos?', 'save_as' => 'seguir'])
            ->node('w2', 'wait', ['minutes' => 720])
            ->node('seguimiento', 'message', ['text' => 'Listo'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'w1')
            ->edge('w1', 'next', 'q')
            ->edge('q', 'next', 'w2')
            ->edge('w2', 'next', 'seguimiento')
            ->edge('seguimiento', 'next', 'fin'));

        $this->assertSame([], $result['errors']);
    }

    public function test_una_espera_de_mas_de_23_horas_se_rechaza_en_el_propio_bloque(): void
    {
        $result = $this->validate($this->start()
            ->node('w', 'wait', ['minutes' => 24 * 60])
            ->node('fin', 'end')
            ->edge('start', 'next', 'w')
            ->edge('w', 'next', 'fin'));

        $this->assertNotEmpty($this->errorsFor($result, 'w'));
    }

    public function test_una_variable_mal_escrita_es_un_error_y_una_definida_no(): void
    {
        $result = $this->validate($this->start()
            ->node('q', 'question', ['text' => '¿Tu ciudad?', 'save_as' => 'ciudad'])
            ->node('ok', 'message', ['text' => 'Genial, {{ciudad}}. {{saludo}}, {{contacto.primer_nombre}}'])
            ->node('typo', 'message', ['text' => 'Hola {{ciudda}}'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'q')
            ->edge('q', 'next', 'ok')
            ->edge('ok', 'next', 'typo')
            ->edge('typo', 'next', 'fin'));

        $this->assertSame([], $this->errorsFor($result, 'ok'), 'ciudad la define la pregunta; saludo y contacto.* existen siempre.');
        $this->assertStringContainsString('{{ciudda}}', $this->errorsFor($result, 'typo')[0]['message']);
    }

    public function test_los_datos_de_ispwatch_exigen_el_bloque_que_los_trae(): void
    {
        // Sin «Datos del cliente», {{cliente.nombre}} saldría vacío SIEMPRE.
        $sinBloque = $this->validate($this->start()
            ->node('m', 'message', ['text' => 'Hola {{cliente.nombre}}'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'm')
            ->edge('m', 'next', 'fin'));

        $this->assertStringContainsString('Datos del cliente', $this->errorsFor($sinBloque, 'm')[0]['message']);

        $conBloque = $this->validate($this->start()
            ->node('buscar', 'ispwatch')
            ->node('m', 'message', ['text' => 'Hola {{cliente.nombre}}, debes {{cliente.total_pendiente}}'])
            ->node('fin', 'end')
            ->edge('start', 'next', 'buscar')
            ->edge('buscar', 'found', 'm')
            ->edge('buscar', 'not_found', 'fin')
            ->edge('m', 'next', 'fin'));

        $this->assertSame([], $conBloque['errors']);
    }

    public function test_una_etiqueta_de_otro_tenant_o_borrada_no_se_acepta(): void
    {
        $result = $this->validate($this->start()
            ->node('tag', 'tag', ['label_id' => 99])
            ->node('fin', 'end')
            ->edge('start', 'next', 'tag')
            ->edge('tag', 'next', 'fin'));

        $this->assertNotEmpty($this->errorsFor($result, 'tag'));
    }

    public function test_los_botones_respetan_los_limites_de_whatsapp(): void
    {
        $result = $this->validate($this->start()
            ->node('menu', 'menu', ['text' => 'Elige', 'style' => 'buttons', 'options' => [
                ['id' => 'a', 'label' => 'Uno'],
                ['id' => 'b', 'label' => 'Dos'],
                ['id' => 'c', 'label' => 'Tres'],
                ['id' => 'd', 'label' => 'Un título demasiado largo para un botón'],
            ]])
            ->node('fin', 'end')
            ->edge('start', 'next', 'menu')
            ->edge('menu', 'opt_a', 'fin')->edge('menu', 'opt_b', 'fin')
            ->edge('menu', 'opt_c', 'fin')->edge('menu', 'opt_d', 'fin')
            ->edge('menu', 'no_match', 'fin'));

        $messages = implode(' | ', array_column($this->errorsFor($result, 'menu'), 'message'));
        $this->assertStringContainsString('hasta 3 botones', $messages);
        $this->assertStringContainsString('20 caracteres', $messages);
    }

    public function test_nada_vuelve_al_inicio_y_una_salida_no_tiene_dos_conexiones(): void
    {
        $result = $this->validate($this->start()
            ->node('a', 'message', ['text' => 'uno'])
            ->node('b', 'end')
            ->node('c', 'end')
            ->edge('start', 'next', 'a')
            ->edge('a', 'next', 'b')
            ->edge('a', 'next', 'c')
            ->edge('b', 'next', 'start'));

        $messages = implode(' | ', array_column($result['errors'], 'message'));
        $this->assertStringContainsString('dos conexiones', $messages);
        $this->assertStringContainsString('Inicio', $messages);
    }

    public function test_un_bloque_suelto_es_advertencia_no_error(): void
    {
        $result = $this->validate($this->start()
            ->node('fin', 'end')
            ->node('suelto', 'end')
            ->edge('start', 'next', 'fin'));

        $this->assertSame([], $result['errors']);
        $this->assertSame('suelto', $result['warnings'][0]['node_id']);
    }

    public function test_las_plantillas_de_arranque_se_pueden_publicar_tal_cual(): void
    {
        $tenant = new Tenant(['name' => 'ISP']);
        $tenant->id = 1;

        // 'legacy' sin fila en bot_settings cae a los defaults: el bot de fábrica.
        foreach (['support', 'blank'] as $key) {
            $result = $this->validate(app(FlowTemplates::class)->build($key, $tenant));
            $this->assertSame([], $result['errors'], "La plantilla «{$key}» no valida: " . json_encode($result['errors'], JSON_UNESCAPED_UNICODE));
        }
    }
}
