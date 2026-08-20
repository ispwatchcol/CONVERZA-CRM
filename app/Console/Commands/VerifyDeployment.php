<?php

namespace App\Console\Commands;

use App\Services\HealthChecker;
use Illuminate\Console\Command;

/**
 * Verifica que la configuración desplegada realmente funcione, y falla el
 * despliegue si no.
 *
 * El 20/08/2026 se rotó la contraseña de Postgres en Supabase y el `.env` del
 * droplet quedó con la vieja en sus DOS conexiones. El deploy no lo detectó
 * porque `migrate --force` solo ejerce `pgsql`; la conexión `ispwatch` no la
 * toca nadie hasta que alguien abre el dashboard. Resultado: `/login` respondía
 * 200, el dashboard reventaba, y la caída duró casi diez horas.
 *
 * Esto lo convierte en un despliegue rojo en GitHub Actions en vez de un
 * descubrimiento por accidente.
 *
 * Sale con código 1 si alguna dependencia dura falla, para que el paso del
 * workflow falle. La profundidad de cola no cuenta: un backlog no es motivo
 * para marcar un deploy como fallido.
 */
class VerifyDeployment extends Command
{
    protected $signature = 'deploy:verify';

    protected $description = 'Comprueba que la app pueda hablar con BD, ispwatch, Redis y disco; falla si no';

    public function handle(HealthChecker $checker): int
    {
        $this->line('Verificando dependencias con la configuración desplegada...');
        $this->newLine();

        ['healthy' => $sano, 'checks' => $checks] = $checker->run();

        foreach ($checks as $nombre => $datos) {
            $ok = $datos['ok'] ?? false;

            $this->line(sprintf(
                '  %s  %-14s %s',
                $ok ? '<fg=green>OK  </>' : '<fg=red>FALLA</>',
                $nombre,
                $ok ? "({$datos['ms']} ms)" : ''
            ));

            // El detalle solo se imprime acá, nunca en /health: trae host,
            // usuario y puerto, y en consola es justo lo que hace falta para
            // arreglarlo sin ir a buscar el log.
            if (! $ok) {
                $this->line('        '.($datos['detalle'] ?? $datos['error'] ?? 'sin detalle'));
            }
        }

        $this->newLine();

        if ($sano) {
            $this->info('Todas las dependencias responden con la configuración actual.');

            return self::SUCCESS;
        }

        $this->error('Hay dependencias caídas o mal configuradas.');
        $this->line('Revisá el .env del servidor y acordate de `php artisan config:cache`:');
        $this->line('sin eso, editar el .env no surte efecto porque producción cachea la config.');

        return self::FAILURE;
    }
}
