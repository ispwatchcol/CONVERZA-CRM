<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ResolveTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user && $user->tenant_id) {
            $tenant = $user->tenant;
            if ($tenant && $tenant->is_active) {
                app()->instance('tenant', $tenant);
            }
        }

        return $next($request);
    }
}
