<?php

namespace App\Services\Assignment;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\StaffMember;
use App\Models\Tenant;

/**
 * Auto-asignación de conversaciones con la estrategia "al menos ocupado":
 * elige al agente activo con MENOS conversaciones abiertas asignadas en ese
 * momento. Empate → menor id de StaffMember (estable), lo que en la práctica
 * reparte como un round-robin a medida que las cargas se igualan.
 *
 * Solo considera staff con rol 'agent' o 'admin' (los 'viewer' no responden).
 */
class ConversationAssigner
{
    /**
     * Asigna la conversación al agente menos ocupado y registra un mensaje de
     * sistema. Devuelve el StaffMember elegido, o null si no hay candidatos.
     */
    public function assignLeastBusy(Conversation $conversation, Tenant $tenant): ?StaffMember
    {
        $eligible = StaffMember::query()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->whereIn('role', ['agent', 'admin'])
            ->with('user:id,name')
            ->orderBy('id')
            ->get();

        if ($eligible->isEmpty()) {
            return null;
        }

        // Carga actual: conversaciones abiertas asignadas a cada staff.
        $loads = Conversation::query()
            ->where('tenant_id', $tenant->id)
            ->where('status', 'open')
            ->whereNotNull('assigned_to')
            ->selectRaw('assigned_to, count(*) as n')
            ->groupBy('assigned_to')
            ->pluck('n', 'assigned_to');

        // sortBy es estable en PHP 8+: a igual carga, gana el de menor id.
        $chosen = $eligible->sortBy(fn (StaffMember $s) => (int) ($loads[$s->id] ?? 0))->first();

        $conversation->update(['assigned_to' => $chosen->id]);

        Message::create([
            'tenant_id'       => $tenant->id,
            'conversation_id' => $conversation->id,
            'contact_id'      => $conversation->contact_id,
            'body'            => '🤖 Asignado automáticamente a ' . ($chosen->user?->name ?? 'un agente') . '.',
            'status'          => 'sent',
            'type'            => 'system',
            'sent_by_user_id' => null,
        ]);

        return $chosen;
    }
}
