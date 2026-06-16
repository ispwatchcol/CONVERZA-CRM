<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Protege el área del Core Brain.
 * Solo pasan: superadmins del SaaS o users con internal_role asignado.
 * Usuarios normales de tenants reciben 403 sin excepción.
 */
class EnsureInternalAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->canAccessBrain()) {
            abort(403, 'Acceso restringido al equipo interno de Converza.');
        }

        return $next($request);
    }
}
