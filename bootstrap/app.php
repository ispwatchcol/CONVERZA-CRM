<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->trustProxies(at: '*');
        $middleware->web(append: [
            \App\Http\Middleware\HandleInertiaRequests::class,
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\EnsureConsistentIdentity::class,
        ]);
        $middleware->alias([
            'superadmin' => \App\Http\Middleware\EnsureSuperadmin::class,
            'role'       => \App\Http\Middleware\EnsureStaffRole::class,
            'internal'   => \App\Http\Middleware\EnsureInternalAccess::class,
        ]);
        $middleware->validateCsrfTokens(except: [
            'webhook',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();
