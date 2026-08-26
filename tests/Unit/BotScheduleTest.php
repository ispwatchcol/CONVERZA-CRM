<?php

namespace Tests\Unit;

use App\Models\BotSetting;
use Carbon\Carbon;
use Tests\TestCase;

/**
 * Horario de atención del bot (BotSetting::respondsAt).
 *
 * Sin base de datos: el modelo se instancia en memoria y el "ahora" se inyecta,
 * porque lo único sutil aquí es la aritmética de días, franjas y zona horaria.
 *
 * Referencia de días: 2026-08-24 es lunes (ISO 1) y 2026-08-30 domingo (ISO 7).
 * Bogotá es UTC-5 todo el año (no tiene horario de verano).
 */
class BotScheduleTest extends TestCase
{
    private function settings(array $overrides = []): BotSetting
    {
        return new BotSetting(array_merge([
            'schedule_enabled'  => true,
            'schedule_mode'     => 'inside',
            'schedule_timezone' => 'America/Bogota',
            'schedule_days'     => [1, 2, 3, 4, 5],
            'schedule_start'    => '08:00',
            'schedule_end'      => '18:00',
        ], $overrides));
    }

    private function bogota(string $datetime): Carbon
    {
        return Carbon::parse($datetime, 'America/Bogota');
    }

    public function test_horario_apagado_responde_siempre(): void
    {
        $settings = $this->settings(['schedule_enabled' => false]);

        // Domingo a las 3 de la mañana: fuera de cualquier franja razonable.
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-30 03:00')));
    }

    public function test_dentro_de_la_franja_en_dia_habil(): void
    {
        $this->assertTrue(
            $this->settings()->respondsAt($this->bogota('2026-08-25 10:00'))  // martes
        );
    }

    public function test_fuera_de_la_franja_en_dia_habil(): void
    {
        $this->assertFalse(
            $this->settings()->respondsAt($this->bogota('2026-08-25 19:00'))  // martes, ya cerró
        );
    }

    public function test_el_limite_inferior_entra_y_el_superior_no(): void
    {
        $settings = $this->settings();

        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-25 08:00')));
        $this->assertFalse($settings->respondsAt($this->bogota('2026-08-25 18:00')));
    }

    public function test_dia_no_seleccionado_queda_fuera(): void
    {
        $this->assertFalse(
            $this->settings()->respondsAt($this->bogota('2026-08-30 10:00'))  // domingo
        );
    }

    public function test_modo_outside_invierte_la_franja(): void
    {
        $settings = $this->settings(['schedule_mode' => 'outside']);

        // Martes 19:00: la oficina cerró, el bot cubre.
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-25 19:00')));

        // Martes 10:00: hay gente atendiendo, el bot calla.
        $this->assertFalse($settings->respondsAt($this->bogota('2026-08-25 10:00')));

        // Domingo: día no laborable, el bot cubre todo el día.
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-30 10:00')));
    }

    public function test_franja_nocturna_cuenta_el_dia_en_que_empieza(): void
    {
        // Lunes a viernes de 18:00 a 08:00 del día siguiente.
        $settings = $this->settings([
            'schedule_start' => '18:00',
            'schedule_end'   => '08:00',
        ]);

        // Lunes 20:00: dentro, la ventana abrió ese lunes.
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-24 20:00')));

        // Martes 02:00: dentro — sigue siendo la ventana que abrió el lunes.
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-25 02:00')));

        // Martes 12:00: fuera, la ventana cerró a las 08:00.
        $this->assertFalse($settings->respondsAt($this->bogota('2026-08-25 12:00')));

        // Domingo 02:00: pertenece a la ventana del SÁBADO, que no está marcado.
        $this->assertFalse($settings->respondsAt($this->bogota('2026-08-30 02:00')));

        // Sábado 02:00: pertenece a la ventana del VIERNES, que sí lo está.
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-29 02:00')));
    }

    public function test_la_zona_horaria_manda_sobre_utc(): void
    {
        $settings = $this->settings();

        // 2026-08-26 01:00 UTC son las 20:00 del martes 25 en Bogotá: fuera.
        $this->assertFalse($settings->respondsAt(Carbon::parse('2026-08-26 01:00', 'UTC')));

        // 2026-08-25 15:00 UTC son las 10:00 del martes 25 en Bogotá: dentro.
        $this->assertTrue($settings->respondsAt(Carbon::parse('2026-08-25 15:00', 'UTC')));

        // Medianoche UTC del martes es aún lunes 19:00 en Bogotá: fuera de franja,
        // pero el día que se evalúa debe ser el lunes, no el martes.
        $this->assertFalse($settings->respondsAt(Carbon::parse('2026-08-25 00:00', 'UTC')));
    }

    public function test_sin_dias_seleccionados_el_bot_nunca_esta_dentro(): void
    {
        $settings = $this->settings(['schedule_days' => []]);

        $this->assertFalse($settings->respondsAt($this->bogota('2026-08-25 10:00')));

        // Y en modo outside, la contrapartida: cubre siempre.
        $settings->schedule_mode = 'outside';
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-25 10:00')));
    }

    public function test_zona_horaria_invalida_no_deja_al_bot_mudo(): void
    {
        $settings = $this->settings(['schedule_timezone' => 'Marte/Olympus']);

        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-30 03:00')));
    }

    public function test_franja_de_24_horas_cubre_el_dia_entero(): void
    {
        $settings = $this->settings([
            'schedule_start' => '00:00',
            'schedule_end'   => '00:00',
            'schedule_days'  => [2],
        ]);

        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-25 03:00')));   // martes
        $this->assertTrue($settings->respondsAt($this->bogota('2026-08-25 23:59')));   // martes
        $this->assertFalse($settings->respondsAt($this->bogota('2026-08-26 03:00')));  // miércoles
    }
}
