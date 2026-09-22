<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TicketEvent extends Model
{
    /**
     * La frontera de visibilidad del ticket, en un solo lugar (CON-56).
     *
     * Desde que el ISP puede leer la bitácora de su ticket, cada cosa escrita ahí
     * tiene dos audiencias posibles. Estos son los tipos que el cliente SÍ puede
     * leer:
     *
     *   message       → la conversación con el cliente
     *   status_change → su ticket avanzó; se le muestra en forma legible
     *
     * Los que se quedan adentro y nunca salen:
     *
     *   note       → nota interna del equipo ("está en mora, aguantarlo")
     *   assignment → a quién se lo asignamos es asunto nuestro
     *
     * Si algún día se agrega un tipo nuevo, queda FUERA por omisión: la lista es
     * de lo que se muestra, no de lo que se esconde. Ese es el sentido de que sea
     * una allowlist.
     *
     * @var list<string>
     */
    public const VISIBLE_AL_ISP = ['message', 'status_change'];

    public $timestamps = false;

    protected $fillable = ['support_ticket_id', 'author_user_id', 'type', 'body', 'meta'];

    protected function casts(): array
    {
        return [
            'meta'       => 'array',
            'created_at' => 'datetime',
        ];
    }

    /** Solo los eventos que el ISP puede leer en su portal. Se filtra en el SERVIDOR. */
    public function scopeVisibleAlIsp(Builder $query): Builder
    {
        return $query->whereIn('type', self::VISIBLE_AL_ISP);
    }

    public function ticket(): BelongsTo { return $this->belongsTo(SupportTicket::class, 'support_ticket_id'); }
    public function author(): BelongsTo { return $this->belongsTo(User::class, 'author_user_id'); }
}
