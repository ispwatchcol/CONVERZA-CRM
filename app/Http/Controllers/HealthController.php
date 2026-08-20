<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

/**
 * Health check profundo, para monitoreo externo (UptimeRobot y similares).
 *
 * Existe porque el 20/08/2026 producción estuvo 9 h 54 m sin poder consultar la
 * base y NADIE se enteró: /login y /ayuda seguían devolviendo 200 porque no la
 * tocan, así que un monitor convencional reportaba el sistema como sano. Se
 * descubrió cuando una persona intentó entrar.
 *
 * Verifica cada dependencia que puede tumbar el sistema en silencio. Devuelve
 * 503 si alguna falla, que es lo que dispara la alerta.
 *
 * Deliberadamente NO revela hosts, usuarios ni credenciales: es una ruta pública
 * (el monitor no puede autenticarse), así que solo dice qué falló, nunca dónde
 * ni con qué.
 */
class HealthController extends Controller
{
    public function check(): JsonResponse
    {
        $checks = [
            'db'          => $this->timed(fn () => DB::connection('pgsql')->select('select 1')),
            'db_ispwatch' => $this->timed(fn () => DB::connection('ispwatch')->select('select 1')),
            'redis'       => $this->timed(fn () => Redis::connection()->ping()),

            // El log crudo del webhook es nuestra única copia de un mensaje hasta
            // que el worker lo persiste. Si el disco dejó de ser escribible, la red
            // de seguridad desapareció sin avisar — eso es una falla dura.
            'webhook_log' => $this->timed(function () {
                if (! is_writable(storage_path('logs'))) {
                    throw new \RuntimeException('storage/logs no es escribible');
                }
            }),
        ];

        $healthy = ! in_array(false, array_column($checks, 'ok'), true);

        // Informativo, no tumba el health check: una cola con backlog sigue siendo
        // un sistema que funciona, y no queremos despertar a nadie por eso.
        try {
            $checks['queue'] = ['ok' => true, 'pendientes' => Queue::size()];
        } catch (\Throwable $e) {
            $checks['queue'] = ['ok' => false, 'error' => 'no se pudo consultar'];
        }

        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
            'time'   => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
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
            ];
        }
    }
}
