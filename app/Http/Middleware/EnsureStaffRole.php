<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Aplica los roles de staff DENTRO de un tenant: viewer < agent < admin.
 *
 * Uso en rutas:
 *   ->middleware('role:agent')  => agent o admin (bloquea a los viewers de escribir).
 *   ->middleware('role:admin')  => solo admin (gestión de staff/equipos, borrar chats).
 *
 * El superadmin del SaaS siempre pasa (User::staffRole() lo trata como admin).
 * NO confundir con el middleware 'superadmin', que protege la consola
 * cross-tenant del dueño del SaaS.
 */
class EnsureStaffRole
{
    public function handle(Request $request, Closure $next, string $minRole): Response
    {
        $user = $request->user();

        if (! $user || ! $user->hasStaffRole($minRole)) {
            abort(403, 'No tienes permiso para realizar esta acción.');
        }

        return $next($request);
    }
}
