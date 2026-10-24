<?php

namespace Tests\Unit;

use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Schedule as ScheduleFacade;
use Tests\TestCase;

/**
 * El cobro automático solo corre de 8 a. m. a 8 p. m. de Colombia, porque manda
 * WhatsApp al ISP (routes/console.php).
 *
 * `between()` arma la ventana con la zona horaria que el evento tiene EN ESE
 * MOMENTO: con `->timezone('America/Bogota')` escrito después, la ventana quedó
 * de 8 a 20 UTC y el corte de Chaguani del 24/10/2026 salió a las 3 a. m.
 *
 * El servidor corre en UTC (Bogotá = UTC−5) y `between()` también fija el
 * "ahora" al definir el evento, así que el schedule se vuelve a definir en cada
 * hora probada, como hace `schedule:run` en cada minuto.
 */
class HorarioDelCobroTest extends TestCase
{
    private function correriaA(string $utc): bool
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

        $schedule = new Schedule(config('app.schedule_timezone') ?? 'UTC');
        ScheduleFacade::swap($schedule);
        require base_path('routes/console.php');

        /** @var Event $evento */
        $evento = collect($schedule->events())
            ->first(fn (Event $e) => str_contains((string) $e->command, 'brain:billing-run'));

        $this->assertNotNull($evento, 'brain:billing-run no está programado');

        return $evento->filtersPass($this->app);
    }

    public function test_no_corre_de_madrugada_en_colombia(): void
    {
        // 08:00 UTC = 03:00 en Bogotá: la hora a la que salió el aviso de corte.
        $this->assertFalse($this->correriaA('2026-10-24 08:00:00'));
        $this->assertFalse($this->correriaA('2026-10-24 12:00:00'));
    }

    public function test_corre_en_horario_de_oficina_de_colombia(): void
    {
        $this->assertTrue($this->correriaA('2026-10-24 13:00:00'));  // 08:00 Bogotá
        $this->assertTrue($this->correriaA('2026-10-24 23:00:00'));  // 18:00 Bogotá
    }

    public function test_no_corre_de_noche_en_colombia(): void
    {
        $this->assertFalse($this->correriaA('2026-10-25 02:00:00')); // 21:00 Bogotá
    }
}
