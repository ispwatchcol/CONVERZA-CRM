<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege rutas que solo el dueño del SaaS Converza puede usar.
 * Distinto del role 'admin' de staff_members (que aplica DENTRO de un tenant).
 *
 * Devuelve 403 si el user no tiene is_superadmin = true.
 */
class EnsureSuperadmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->is_superadmin) {
            abort(403, 'Solo el equipo de Converza puede acceder a esta sección.');
        }

        return $next($request);
    }
}
