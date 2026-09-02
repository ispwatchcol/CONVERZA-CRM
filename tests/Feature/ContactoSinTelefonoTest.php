<?php

namespace Tests\Feature;

use App\Models\Contact;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Tests\TestCase;

/**
 * Reglas de validación para contactos sin teléfono.
 *
 * Se escaparon del arreglo original (CON-68) y bloquearon a un asesor en
 * producción: no podía mandar una plantilla —la única salida fuera de la
 * ventana de 24 h— ni guardar la ficha del contacto.
 *
 * Se prueban las reglas en sí y no el controlador completo porque montar la
 * sesión, el tenant y el staff_member exige media base de datos; lo que falló
 * fue exactamente esto: `required` donde debía ser condicional.
 */
class ContactoSinTelefonoTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::dropIfExists('contacts');
        Schema::create('contacts', function (Blueprint $t) {
            $t->id();
            $t->string('phone')->nullable();
            $t->string('wa_user_id')->nullable();
            $t->string('wa_username')->nullable();
            $t->string('name')->nullable();
            $t->unsignedBigInteger('tenant_id')->nullable();
            $t->timestamps();
        });
    }

    /** La regla que usan sendMessage, sendMedia y sendTemplate. */
    private function reglaEnvio(): array
    {
        return ['phone' => 'nullable|string|required_without:conversation_id'];
    }

    public function test_se_puede_enviar_sin_telefono_si_va_la_conversacion(): void
    {
        $v = Validator::make(['conversation_id' => 42], $this->reglaEnvio());

        $this->assertFalse($v->fails(), 'Un chat sin teléfono debe poder recibir mensajes y plantillas.');
    }

    public function test_se_puede_enviar_con_telefono_y_sin_conversacion(): void
    {
        $v = Validator::make(['phone' => '573133799933'], $this->reglaEnvio());

        $this->assertFalse($v->fails(), 'Escribirle a un número desde otra pantalla sigue funcionando.');
    }

    public function test_sin_telefono_ni_conversacion_se_rechaza(): void
    {
        $v = Validator::make([], $this->reglaEnvio());

        $this->assertTrue($v->fails(), 'Sin ninguna de las dos no hay a quién escribirle.');
    }

    /** La regla de ContactController::validateData. */
    private function reglaFicha(?Contact $contacto): array
    {
        $exigir = ! ($contacto && $contacto->wa_user_id);

        return ['phone' => [
            $exigir ? 'required' : 'nullable',
            'string', 'max:20',
            Rule::unique('contacts', 'phone')->where('tenant_id', 1)->ignore($contacto?->id),
        ]];
    }

    public function test_la_ficha_de_un_contacto_sin_telefono_se_puede_guardar(): void
    {
        $contacto = Contact::create(['tenant_id' => 1, 'wa_user_id' => 'CO.1', 'name' => 'FGC']);

        $v = Validator::make(['phone' => null], $this->reglaFicha($contacto));

        $this->assertFalse($v->fails(), 'Sin esto no se le puede ni poner nombre ni etiquetas.');
    }

    public function test_a_un_contacto_sin_telefono_se_le_puede_anadir_uno(): void
    {
        $contacto = Contact::create(['tenant_id' => 1, 'wa_user_id' => 'CO.1', 'name' => 'FGC']);

        $v = Validator::make(['phone' => '573133799933'], $this->reglaFicha($contacto));

        $this->assertFalse($v->fails(), 'El asesor se lo pregunta por el chat y lo guarda: reactiva el cruce con ispwatch.');
    }

    public function test_crear_un_contacto_desde_cero_sigue_exigiendo_telefono(): void
    {
        $v = Validator::make(['phone' => null], $this->reglaFicha(null));

        $this->assertTrue($v->fails(), 'Un BSUID no se puede teclear: solo llega por webhook.');
    }

    public function test_no_se_puede_duplicar_un_telefono_existente(): void
    {
        Contact::create(['tenant_id' => 1, 'phone' => '573133799933', 'name' => 'Ya existe']);
        $contacto = Contact::create(['tenant_id' => 1, 'wa_user_id' => 'CO.1', 'name' => 'FGC']);

        $v = Validator::make(['phone' => '573133799933'], $this->reglaFicha($contacto));

        $this->assertTrue($v->fails(), 'Si el número ya es de otra ficha hay que avisar, no crear un duplicado.');
    }
}
