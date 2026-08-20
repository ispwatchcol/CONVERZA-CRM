<?php

namespace App\Http\Controllers;

use App\Services\HealthChecker;
use Illuminate\Http\JsonResponse;

/**
 * Health check profundo, para monitoreo externo (UptimeRobot y similares).
 *
 * Existe porque el 20/08/2026 producción estuvo 9 h 54 m sin poder consultar la
 * base y NADIE se enteró: /login y /ayuda seguían devolviendo 200 porque no la
 * tocan, así que un monitor convencional reportaba el sistema como sano. Se
 * descubrió cuando una persona intentó entrar.
 *
 * Los chequeos viven en HealthChecker, compartidos con `deploy:verify`, para que
 * el despliegue no pueda comprobar menos cosas que el monitoreo.
 */
class HealthController extends Controller
{
    public function check(HealthChecker $checker): JsonResponse
    {
        ['healthy' => $healthy, 'checks' => $checks] = $checker->run();

        // Contrato para el centinela externo: "status":"ok" ⟺ HTTP 200 ⟺ todos
        // los checks en ok:true. No existe ningún caso de 200 con otro status.
        //
        // El plan gratuito del monitor solo permite HEAD, sin poder mirar el
        // cuerpo, así que el código HTTP es la ÚNICA señal que llega. Mantener
        // esa equivalencia no es opcional.
        return response()->json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $this->publico($checks),
            'time'   => now()->toIso8601String(),
        ], $healthy ? 200 : 503);
    }

    /**
     * Quita el detalle de la excepción antes de responder: es una ruta pública
     * (el monitor no puede autenticarse) y ese mensaje trae host, usuario y
     * puerto de la conexión que falló. El nombre de la clase basta para saber
     * qué pasó; el detalle queda para `deploy:verify`, que corre en consola.
     */
    private function publico(array $checks): array
    {
        foreach ($checks as $nombre => $datos) {
            unset($datos['detalle']);
            $checks[$nombre] = $datos;
        }

        return $checks;
    }
}
