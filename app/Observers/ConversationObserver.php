<?php

namespace App\Observers;

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
     */
    public function updated(Conversation $conversation): void
    {
        if ($conversation->wasChanged('assigned_to') && ! is_null($conversation->assigned_to)) {
            $conversation->updateQuietly(['bot_active' => false]);
        }
    }
}
