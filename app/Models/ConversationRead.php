<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ConversationRead extends Model
{
    use BelongsToTenant;

    protected $fillable = ['tenant_id', 'conversation_id', 'staff_member_id', 'last_read_at'];

    protected $casts = [
        'last_read_at' => 'datetime',
    ];

    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }

    public function staffMember(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class);
    }

    /**
     * Marca la conversación como leída para TODO el equipo activo del tenant.
     *
     * El "no leído" es por agente: cada uno tiene su propia fila y solo se
     * actualiza cuando ÉL abre el chat. Eso hacía que un chat ya respondido por
     * un compañero siguiera con el badge verde para el resto del equipo — para
     * siempre, porque el único modo de bajarlo era abrirlo uno por uno. Y como
     * el badge se ve igual que el de WhatsApp, se lee como "sin responder".
     *
     * Por eso, cuando un agente responde al cliente desde el chat, la damos por
     * leída para todos: alguien ya la atendió. Los chats sin responder siguen
     * marcándose por agente, que es donde ese seguimiento sí aporta.
     *
     * Solo para respuestas HUMANAS desde la UI. Los envíos automáticos (avisos
     * de facturación/eventos, campañas, bot, mensaje de transferencia) NO deben
     * llamar acá: nadie leyó nada, y taparían mensajes entrantes reales.
     */
    public static function markAnsweredForTeam(int $tenantId, int $conversationId): void
    {
        $now = now();

        $rows = StaffMember::where('tenant_id', $tenantId)
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (int $staffId) => [
                'tenant_id'       => $tenantId,
                'conversation_id' => $conversationId,
                'staff_member_id' => $staffId,
                'last_read_at'    => $now,
                'created_at'      => $now,
                'updated_at'      => $now,
            ])
            ->all();

        if ($rows === []) {
            return;
        }

        // Único índice (conversation_id, staff_member_id) → upsert en una query.
        static::upsert($rows, ['conversation_id', 'staff_member_id'], ['last_read_at', 'updated_at']);
    }
}
