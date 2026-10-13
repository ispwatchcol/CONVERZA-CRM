<?php

namespace App\Http\Controllers;

use App\Models\Brain\Account;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `GET /saas/estado/{ispwatch_tenant_id}` — si un ISP tiene el servicio cortado
 * por nosotros, para que ispwatch le cierre el panel.
 *
 * Existe porque el corte no se puede hacer al revés: Converza lee la BD de
 * ispwatch en solo lectura (principio no negociable del Brain), así que no puede
 * marcar nada allá. Publica el estado y ispwatch decide.
 *
 * Contrato pensado para que ispwatch falle ABIERTO: si esta ruta no responde, no
 * hay token o la cuenta no existe, el ISP sigue trabajando. Un corte de nuestro
 * servidor no puede convertirse en un corte del ISP que sí pagó.
 */
class SaasStatusController extends Controller
{
    public function show(Request $request, string $ispwatchTenantId): JsonResponse
    {
        $token = (string) config('brain.status_api_token');

        // Sin token configurado la ruta no existe: nada que adivinar desde afuera.
        abort_if($token === '', 404);
        abort_unless(hash_equals($token, (string) $request->bearerToken()), 401);

        $account = Account::where('ispwatch_tenant_id', $ispwatchTenantId)->first();

        if (! $account) {
            // No es cliente nuestro (o no está vinculado): no hay nada que cortar.
            return response()->json(['suspended' => false, 'known' => false]);
        }

        return response()->json([
            'suspended' => $account->isSuspended(),
            'known'     => true,
            'reason'    => $account->suspension_reason,
            'since'     => $account->suspended_at?->toIso8601String(),
            'message'   => match (true) {
                ! $account->isSuspended() => null,
                $account->suspension_reason === Account::SUSPENSION_BILLING
                    => 'El servicio está suspendido por una factura vencida. Se reactiva solo al registrar el pago.',
                default => 'El servicio está suspendido. Comunícate con soporte de Converza.',
            },
            'payment_instructions' => (string) config('brain.billing.payment_instructions') ?: null,
        ]);
    }
}
