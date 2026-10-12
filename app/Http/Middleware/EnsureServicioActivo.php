<?php

namespace App\Http\Middleware;

use App\Models\Brain\Account;
use App\Services\Brain\CobroAutomatico;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * El corte del servicio de Converza: si la cuenta del Brain de este tenant está
 * suspendida, el panel muestra "servicio suspendido" en vez de la bandeja.
 *
 * Corta el PANEL, nada más. Los webhooks no pasan por acá (no hay usuario), así
 * que los mensajes de los clientes del ISP se siguen guardando, y el bot y los
 * avisos automáticos siguen andando: los clientes finales no se enteran de que
 * su proveedor nos debe, y al pagar el ISP encuentra todo donde lo dejó.
 *
 * Quedan abiertos: cerrar sesión, el manual y el portal de soporte —para hablar
 * con nosotros justamente de esto—.
 */
class EnsureServicioActivo
{
    private const SIEMPRE_ABIERTAS = ['logout', 'support.*', 'manual.*'];

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || ! $user->tenant_id || $user->canAccessBrain()) {
            return $next($request);
        }

        if ($request->routeIs(...self::SIEMPRE_ABIERTAS)) {
            return $next($request);
        }

        if (! $this->suspendida((int) $user->tenant_id)) {
            return $next($request);
        }

        // Las llamadas de fondo (sondeo del chat, contadores) no son una pantalla:
        // reciben un 423 con el motivo en vez de una página que nadie va a ver.
        if ($request->expectsJson() && ! $request->header('X-Inertia')) {
            return response()->json([
                'message' => 'El servicio está suspendido por falta de pago.',
                'code'    => 'servicio_suspendido',
            ], 423);
        }

        return Inertia::render('ServicioSuspendido', $this->datos($request, (int) $user->tenant_id))
            ->toResponse($request);
    }

    /**
     * Cacheado: corre en cada petición del panel. Account lo olvida al guardar,
     * así que un pago registrado reabre el panel en el acto.
     *
     * Falla ABIERTO: si la consulta revienta, el panel sigue funcionando. Un error
     * nuestro al preguntar no puede dejar sin servicio a un ISP que pagó. El error
     * no se cachea (la excepción sale de remember() sin guardar nada), así que la
     * siguiente petición vuelve a preguntar.
     */
    private function suspendida(int $tenantId): bool
    {
        try {
            return Cache::remember(
                Account::suspendedCacheKey($tenantId),
                60,
                fn () => Account::where('tenant_id', $tenantId)->where('status', 'suspended')->exists(),
            );
        } catch (QueryException $e) {
            Log::warning('No se pudo verificar si el tenant está suspendido; se deja pasar', [
                'tenant' => $tenantId, 'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    /** @return array<string, mixed> */
    private function datos(Request $request, int $tenantId): array
    {
        $account = Account::where('tenant_id', $tenantId)->where('status', 'suspended')->first();

        $pendientes = $account
            ? $account->invoices()
                ->whereNotIn('status', ['paid', 'void'])
                ->with('payments')
                ->orderBy('due_at')
                ->get()
                ->filter(fn ($i) => $i->balance_due > 0)
                ->map(fn ($i) => [
                    'number'      => $i->number,
                    'concept'     => $i->concept,
                    'balance_due' => number_format($i->balance_due, 2, '.', ''),
                    'currency'    => $i->currency,
                    'due_at'      => $i->due_at?->toDateString(),
                    'overdue'     => $i->due_at !== null && $i->due_at->lt(CobroAutomatico::hoy()),
                ])
                ->values()
            : collect();

        return [
            'motivo'        => $account?->suspension_reason,
            'desde'         => $account?->suspended_at?->toIso8601String(),
            'facturas'      => $pendientes,
            'instrucciones' => (string) config('brain.billing.payment_instructions'),
            // El portal de soporte es solo para el admin del ISP (role:admin).
            'puede_abrir_ticket' => $request->user()->hasStaffRole('admin'),
        ];
    }
}
