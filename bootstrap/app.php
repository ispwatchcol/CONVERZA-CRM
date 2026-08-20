<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        commands: __DIR__ . '/../routes/console.php',
        health: '/up',
        // /health se registra acá, y no en routes/web.php, para que quede FUERA
        // del grupo `web`: sin sesión, sin CSRF, sin Inertia, sin ResolveTenant.
        // Esos middlewares dependen de Redis y de la BD —justo lo que /health
        // tiene que poder diagnosticar—, así que dentro del grupo un Redis caído
        // reventaría la ruta antes de llegar a comprobar nada.
        //
        // /up (el health de Laravel) se queda: es superficial y solo confirma que
        // PHP responde. /health es el que mira las dependencias.
        then: function () {
            Route::get('/health', [\App\Http\Controllers\HealthController::class, 'check'])
                ->name('health');
        },
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
