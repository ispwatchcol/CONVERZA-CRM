<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Cierra la "pestaña vieja" en peticiones que MODIFICAN datos.
 *
 * Inertia ya protege las visitas GET: si la versión del cliente no coincide con
 * la del servidor responde 409 + X-Inertia-Location y el navegador recarga (ver
 * {@see HandleInertiaRequests::version()}, que ata la versión a la identidad de
 * la sesión). Pero esa verificación de versión Inertia SOLO la aplica a GET.
 *
 * Para POST/PATCH/PUT/DELETE lo hacemos nosotros aquí: si una pestaña quedó
 * abierta con la cuenta A y el navegador (cookie compartida) ya cambió a la
 * cuenta B, un envío desde esa pestaña vieja llegaría con la versión de A. Lo
 * detectamos y respondemos 409 + X-Inertia-Location para forzar recarga, en
 * lugar de ejecutar la escritura con el tenant equivocado.
 */
class EnsureConsistentIdentity
{
    public function __construct(private HandleInertiaRequests $inertia)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Solo peticiones Inertia, autenticadas y que escriben. Las GET ya las
        // cubre el core de Inertia; las no-Inertia (webhook, login) no aplican.
        if (
            $request->user()
            && $request->headers->has('X-Inertia')
            && ! $request->isMethod('GET')
        ) {
            $clientVersion  = (string) $request->header('X-Inertia-Version', '');
            $currentVersion = (string) $this->inertia->version($request);

            if ($clientVersion !== '' && ! hash_equals($currentVersion, $clientVersion)) {
                // 409 + X-Inertia-Location → el cliente Inertia hace recarga dura.
                return Inertia::location($request->fullUrl());
            }
        }

        return $next($request);
    }
}
