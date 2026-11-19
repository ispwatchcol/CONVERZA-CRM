<?php

namespace Tests\Unit\Flows;

use App\Services\Flows\Script\Interpreter;
use App\Services\Flows\Script\ScriptError;
use Tests\TestCase;

/**
 * El lenguaje del bloque «Código»: que haga lo que hace JavaScript en lo que
 * cubre, y que NO pueda salirse de la caja (ni colgar al servidor).
 */
class ScriptInterpreterTest extends TestCase
{
    private function exec(string $code, array $vars = []): \App\Services\Flows\Script\ScriptResult
    {
        return (new Interpreter(fn (string $name) => $vars[$name] ?? null))->run($code);
    }

    private function fails(string $code, string $message, array $vars = []): void
    {
        try {
            $this->exec($code, $vars);
            $this->fail("Tenía que fallar: {$code}");
        } catch (ScriptError $e) {
            $this->assertStringContainsString($message, $e->getMessage());
        }
    }

    public function test_calcula_con_las_variables_del_flujo_y_deja_las_suyas(): void
    {
        $result = $this->exec(<<<'JS'
            // La deuda viene como texto colombiano: "$85.000"
            const deuda = numero(vars["cliente.saldo"])
            let mora = deuda > 50000 ? deuda * 0.05 : 0
            vars.mora = moneda(mora)
            vars.total = moneda(deuda + mora)
            vars.saludo_vip = `Hola ${vars.contacto.primer_nombre.toUpperCase()}, debes ${vars.total}`
            if (deuda + mora > 80000) return "Cobrar"
            return "Normal"
            JS, ['cliente.saldo' => '$85.000', 'contacto.primer_nombre' => 'marta']);

        $this->assertSame(['mora' => '$4.250', 'total' => '$89.250', 'saludo_vip' => 'Hola MARTA, debes $89.250'], $result->writes);
        $this->assertSame('Cobrar', $result->returned);
    }

    public function test_ciclos_listas_objetos_y_metodos_de_texto(): void
    {
        $result = $this->exec(<<<'JS'
            const planes = { "20": 60000, "50": 90000, "100": 120000 }
            const pedido = vars.respuesta.trim().toLowerCase().replace("megas", "").trim()
            let precios = []
            for (const llave of Object.keys(planes)) {
                if (llave === "100") continue
                precios.push(`${llave} megas: ${moneda(planes[llave])}`)
            }
            let suma = 0
            for (let i = 1; i <= 10; i++) { suma += i }
            let n = 0
            while (true) { n++; if (n >= 3) break }
            vars.lista = precios.join("\n")
            vars.precio = planes[pedido] ?? "no existe"
            vars.suma = suma
            vars.n = n
            vars.partes = "a,b,c".split(",").reverse().join("-")
            vars.tilde = sinTildes("Chaguaní").toUpperCase()
            log("pedido", pedido, { ok: true })
            JS, ['respuesta' => '  50 MEGAS ']);

        $this->assertSame("20 megas: $60.000\n50 megas: $90.000", $result->writes['lista']);
        $this->assertSame('90000', $result->writes['precio']);
        $this->assertSame('55', $result->writes['suma']);
        $this->assertSame('3', $result->writes['n']);
        $this->assertSame('c-b-a', $result->writes['partes']);
        $this->assertSame('CHAGUANI', $result->writes['tilde']);
        $this->assertSame(['pedido 50 {"ok":true}'], $result->logs);
        $this->assertNull($result->returned);
    }

    public function test_reglas_de_javascript_que_importan(): void
    {
        $r = $this->exec(<<<'JS'
            vars.a = 0.1 + 0.2 > 0.3
            vars.b = "5" == 5
            vars.c = "5" === 5
            vars.d = 7 / 2
            vars.e = Math.round(2.5) + Math.floor(-1.5)
            vars.f = (12.3456).toFixed(2)
            vars.g = typeof noExiste
            vars.h = [] + ""
            vars.i = "abc"[1] + "102"[1]
            vars.j = vars.vacia?.length ?? "sin dato"
            vars.k = parseInt("42 megas") + Number("8")
            vars.l = isNaN(numero("hola"))
            JS);

        $this->assertSame([
            'a' => 'true', 'b' => 'true', 'c' => 'false', 'd' => '3.5', 'e' => '1', 'f' => '12.35',
            'g' => 'undefined', 'h' => '', 'i' => 'b0', 'j' => 'sin dato', 'k' => '50', 'l' => 'true',
        ], $r->writes);
    }

    public function test_numeros_al_estilo_colombiano(): void
    {
        $r = $this->exec('vars.a = numero("$50.000"); vars.b = numero("1.234,5"); vars.c = numero("12.5"); vars.d = numero(""); vars.e = moneda(-1500.6)');

        $this->assertSame(['a' => '50000', 'b' => '1234.5', 'c' => '12.5', 'd' => '0', 'e' => '-$1.501'], $r->writes);
    }

    public function test_no_hay_forma_de_salir_de_la_caja(): void
    {
        $this->fails('function f() { return 1 }', 'No se pueden declarar funciones');
        $this->fails('const f = x => x', 'funciones flecha');
        $this->fails('new Date()', '«new» no está disponible');
        $this->fails('import fs from "fs"', 'No se puede importar');
        $this->fails('await fetch("https://x.test")', 'No hay código asíncrono');
        $this->fails('fetch("https://x.test")', '«fetch» no existe');
        $this->fails('require("child_process")', '«require» no existe');
        $this->fails('process.exit()', '«process» no existe');
        $this->fails('eval("1")', '«eval» no está disponible');
        $this->fails('globalThis.x = 1', '«globalThis» no existe');
        $this->fails('Math.round = 5', 'no se puede');
        $this->fails('Math = 1', 'parte del lenguaje');
        // El escape clásico de los sandboxes de JS: aquí no hay prototipos ni constructores.
        $this->fails('"x".constructor.constructor("return 1")()', 'valor vacío');
        $this->fails('"x".constructor("return 1")', 'no es una función');
        $this->fails('vars.__proto__.x = 1', 'no se puede modificar');
        $this->fails('vars.__proto__ = 1', 'no sirve como nombre');
        $this->fails('vars["cliente.saldo"] = 1', 'no sirve como nombre');
        $this->fails('vars.respuesta = "x"', 'variable del sistema');
        $this->fails('vars.cliente.saldo = 1', 'no se pueden cambiar');
    }

    public function test_no_puede_colgar_al_servidor(): void
    {
        $this->fails('while (true) {}', 'demasiados pasos');
        $this->fails('for (;;) { }', 'demasiados pasos');
        $this->fails('let s = "x"; while (true) { s = s + s }', 'demasiado largo');
        $this->fails('"x".repeat(1000000)', 'demasiado largo');
        $this->fails('let l = []; for (let i = 0; i < 5000; i++) l.push(i)', 'demasiado grande');
        $this->fails(str_repeat('(', 200) . '1' . str_repeat(')', 200), 'demasiado anidado');
        $this->fails(str_repeat('x', Interpreter::MAX_CODE + 1), 'demasiado largo');
        $this->fails('for (let i = 0; i < 30; i++) { vars["v" + i] = i }', 'demasiadas variables');
    }

    public function test_los_errores_dicen_la_linea_y_que_pasa(): void
    {
        $this->fails("let a = 1\nlet b = \nc", 'Línea 3');
        $this->fails('total = 5', 'no está declarada');
        $this->fails("const a = 1\na = 2", 'es constante');
        $this->fails('let x = null; x.length', 'valor vacío');
        $this->fails('let a = "sin cerrar', 'texto sin cerrar');
        $this->fails('let año = 1', 'Los nombres van sin tildes');
        $this->assertNull(Interpreter::check('let a = 1; vars.b = a + 1'));
        $this->assertNotNull(Interpreter::check('let = 1'));
    }

    public function test_encuentra_las_variables_que_escribe(): void
    {
        $this->assertSame(['total', 'mora', 'extra', 'contador'], Interpreter::writtenNames(<<<'JS'
            vars.total = 1
            vars["mora"] += 2
            vars . extra = 3
            vars.contador++
            if (vars.leida == 1) {}
            JS));
    }
}
