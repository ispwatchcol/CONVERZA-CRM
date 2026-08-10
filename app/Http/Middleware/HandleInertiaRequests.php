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
     * Además de la versión de assets, la atamos a la IDENTIDAD de la sesión
     * (user + tenant). El navegador comparte UNA sola cookie de sesión entre
     * todas sus pestañas/ventanas (salvo incógnito): si en ese navegador se
     * cambia de cuenta, una pestaña "vieja" seguiría haciendo polling con la
     * cookie nueva y mezclaría datos de otro tenant. Al incluir la identidad en
     * la versión, cualquier petición Inertia de esa pestaña vieja llega con una
     * versión que ya no coincide; Inertia responde 409 + X-Inertia-Location y el
     * navegador hace una recarga dura, dejando la pestaña consistente con la
     * única cuenta en la que el navegador está logueado ahora.
     *
     * @see https://inertiajs.com/asset-versioning
     */
    public function version(Request $request): ?string
    {
        $assetVersion = (string) parent::version($request);

        $user     = $request->user();
        // tenant_id se toma del propio usuario (no del binding app('tenant'),
        // que ResolveTenant fija DESPUÉS de este middleware).
        $identity = $user
            ? $user->getAuthIdentifier() . ':' . ($user->tenant_id ?? '0')
            : 'guest';

        return hash('xxh128', $assetVersion . '|' . $identity);
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
                            'role'          => $request->user()->staffRole(),
                            'can_brain'     => $request->user()->canAccessBrain(),
                            'internal_role' => $request->user()->canAccessBrain()
                                ? $request->user()->internalRole()
                                : null,
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
            // Solo para usuarios con sesión. En las páginas públicas (/ayuda,
            // /login) un invitado no tiene qué hacer con este estado, y evaluarlo
            // dispararía una llamada al Graph API —con las credenciales del .env—
            // por cada visita anónima.
            'whatsappStatus' => fn() => $request->user()
                ? app(WhatsAppService::class)->checkConnection()
                : null,
        ];
    }
}
