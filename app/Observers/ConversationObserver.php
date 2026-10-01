<?php

namespace App\Observers;

use App\Models\BotFlowRun;
use App\Models\Conversation;

class ConversationObserver
{
    /**
     * Cuando un agente toma la conversación (assigned_to cambia a un valor no-null),
     * desactivamos el bot inmediatamente con updateQuietly para no disparar este
     * mismo observer de nuevo (loop: updated → updateQuietly → sin evento → fin).
     *
     * Cubre los tres escenarios de asignación:
     *  1. Agente se auto-asigna desde la UI del chat.
     *  2. Auto-asignación automática del sistema (ConversationAssigner).
     *  3. Admin asigna a otro agente desde el panel de staff.
     *
     * Vale para los dos bots: el clásico mira bot_active, y la ejecución de un
     * flujo del Workspace que estuviera atendiendo el hilo se cierra acá mismo,
     * sin esperar al próximo mensaje del cliente.
     */
    public function updated(Conversation $conversation): void
    {
        if ($conversation->wasChanged('assigned_to') && ! is_null($conversation->assigned_to)) {
            $conversation->updateQuietly(['bot_active' => false]);

            // Aislado: esto corre en CADA asignación (la del asesor desde el chat
            // y la automática). Si la tabla de flujos no está (un esquema sin
            // migrar), asignar tiene que seguir funcionando; la ejecución, si la
            // hubiera, la cierra el motor cuando vea al asesor asignado.
            try {
                BotFlowRun::withoutGlobalScopes()
                    ->where('tenant_id', $conversation->tenant_id)
                    ->where('live_conversation_id', $conversation->id)
                    ->update([
                        'status'               => BotFlowRun::STATUS_CANCELLED,
                        'ended_reason'         => 'assigned',
                        'ended_at'             => now(),
                        'live_conversation_id' => null,
                        'resume_at'            => null,
                        'updated_at'           => now(),
                    ]);
            } catch (\Throwable $e) {
                report($e);
            }
        }
    }
}
