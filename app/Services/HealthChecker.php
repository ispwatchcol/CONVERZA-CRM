<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Comprueba que la app pueda hablar con todo aquello sin lo cual no funciona.
 *
 * Lo consumen dos sitios que deben coincidir siempre:
 *   - HealthController  → GET /health, para el centinela externo.
 *   - `deploy:verify`   → paso del despliegue, para no publicar una config rota.
 *
 * Vive aparte para que no puedan divergir: si el deploy comprobara menos cosas
 * que el health check, volveríamos al caso del 20/08/2026 —la conexión
 * `ispwatch` quedó con la contraseña vieja y nadie se enteró, porque el deploy
 * solo tocaba `pgsql` al correr las migraciones—.
 */
class HealthChecker
{
    /**
     * @return array{healthy: bool, checks: array<string, array<string, mixed>>}
     */
    public function run(): array
    {
        $checks = [
            'db'          => $this->timed(fn () => DB::connection('pgsql')->select('select 1')),
            'db_ispwatch' => $this->timed(fn () => DB::connection('ispwatch')->select('select 1')),
            'redis'       => $this->timed(fn () => Redis::connection()->ping()),

            // El log crudo del webhook es la única copia de un mensaje hasta que
            // el worker lo persiste. Si el disco dejó de ser escribible, la red de
            // seguridad desapareció sin avisar — eso es una falla dura.
            'webhook_log' => $this->timed(function () {
                if (! is_writable(storage_path('logs'))) {
                    throw new \RuntimeException('storage/logs no es escribible');
                }
            }),
        ];

        // La cola se separa en dos cosas distintas a propósito:
        //
        //  - CONECTIVIDAD (que se pueda consultar): dependencia caída, cuenta
        //    para el estado general igual que la BD o Redis.
        //  - PROFUNDIDAD (cuántos esperan): informativa. Un backlog sigue siendo
        //    un sistema que funciona y no debe despertar a nadie.
        $pendientes = null;
        $checks['queue'] = $this->timed(function () use (&$pendientes) {
            $pendientes = Queue::size();
        });

        if ($pendientes !== null) {
            $checks['queue']['pendientes'] = $pendientes;
        }

        return [
            'healthy' => ! in_array(false, array_column($checks, 'ok'), true),
            'checks'  => $checks,
        ];
    }

    /**
     * Corre una comprobación midiendo cuánto tarda. Nunca deja escapar la
     * excepción: el objetivo es reportar el estado, no fallar al reportarlo.
     */
    private function timed(callable $probe): array
    {
        $inicio = microtime(true);

        try {
            $probe();

            return ['ok' => true, 'ms' => (int) round((microtime(true) - $inicio) * 1000)];
        } catch (\Throwable $e) {
            return [
                'ok'    => false,
                'ms'    => (int) round((microtime(true) - $inicio) * 1000),
                'error' => class_basename($e),
                // El mensaje solo se usa en consola (deploy:verify), nunca en la
                // respuesta HTTP: puede traer host, usuario o puerto.
                'detalle' => $e->getMessage(),
            ];
        }
    }
}
