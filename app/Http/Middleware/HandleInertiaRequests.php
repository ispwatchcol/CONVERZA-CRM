<?php

namespace App\Http\Middleware;

use App\Services\WhatsAppService;
use Illuminate\Http\Request;
use Inertia\Middleware;

class HandleInertiaRequests extends Middleware
{
    /**
     * The root template that's loaded on the first page visit.
     *
     * @see https://inertiajs.com/server-side-setup#root-template
     *
     * @var string
     */
    protected $rootView = 'app';

    /**
     * Determines the current asset version.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        return parent::version($request);
    }

    /**
     * Define the props that are shared by default.
     *
     * @see https://inertiajs.com/shared-data
     *
     * @return array<string, mixed>
     */
    public function share(Request $request): array
    {
        return [
            ...parent::share($request),
            'auth' => [
                'user' => fn() => $request->user()
                    ? array_merge(
                        $request->user()->only('id', 'name', 'email'),
                        [
                            'is_superadmin' => (bool) $request->user()->is_superadmin,
                            // Rol efectivo en el tenant (viewer < agent < admin) para
                            // que la UI oculte acciones que el backend ya bloquea.
                            'role'          => $request->user()->staffRole(),
                        ]
                    )
                    : null,
            ],
            // Tenant resuelto por ResolveTenant middleware — útil para personalizar
            // UI (saludos de soporte, headers, etc.). Solo expone lo no-sensible.
            'tenant' => fn() => app()->bound('tenant')
                ? ['name' => app('tenant')->name, 'slug' => app('tenant')->slug]
                : null,
            'flash' => [
                'success' => fn() => $request->session()->get('success'),
                'error' => fn() => $request->session()->get('error'),
            ],
            'whatsappStatus' => fn() => app(WhatsAppService::class)->checkConnection(),
        ];
    }
}
