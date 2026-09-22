<?php

namespace App\Models\Brain;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class SupportTicket extends Model
{
    /** Estados en los que el ticket sigue siendo trabajo pendiente nuestro. */
    public const ABIERTOS = ['open', 'pending'];

    protected $fillable = [
        'account_id', 'assigned_to', 'opened_by',
        'subject', 'status', 'priority', 'category', 'product', 'source',
        'first_response_at', 'resolved_at',
    ];

    protected function casts(): array
    {
        return [
            'first_response_at' => 'datetime',
            'resolved_at'       => 'datetime',
        ];
    }

    public function account(): BelongsTo    { return $this->belongsTo(Account::class); }
    public function assignedTo(): BelongsTo { return $this->belongsTo(User::class, 'assigned_to'); }
    public function openedBy(): BelongsTo   { return $this->belongsTo(User::class, 'opened_by'); }
    public function events(): HasMany       { return $this->hasMany(TicketEvent::class); }

    /** El último mensaje del hilo, para la vista previa de la bandeja. */
    public function ultimoMensaje(): HasOne
    {
        return $this->hasOne(TicketEvent::class)->where('type', 'message')->latestOfMany('created_at');
    }

    public function scopeAbiertos(Builder $query): Builder
    {
        return $query->whereIn('status', self::ABIERTOS);
    }

    /**
     * Fecha del último mensaje escrito por el CLIENTE (o por NOSOTROS, con
     * `$nuestros`), como subconsulta correlacionada.
     *
     * "Nuestro" = el autor entra al Brain (`internal_role` o superadmin), que es la
     * misma definición que usa `User::canAccessBrain()`. No se usan bindings a
     * propósito: `internal_role IS NULL` y la columna booleana suelta funcionan
     * igual en Postgres (producción) y en sqlite (las pruebas).
     */
    private static function sqlUltimoMensaje(bool $nuestros): string
    {
        $quien = $nuestros
            ? '(u.internal_role IS NOT NULL OR u.is_superadmin)'
            : '(u.internal_role IS NULL AND NOT u.is_superadmin)';

        return "(SELECT max(e.created_at) FROM ticket_events e
                   JOIN users u ON u.id = e.author_user_id
                  WHERE e.support_ticket_id = support_tickets.id
                    AND e.type = 'message'
                    AND {$quien})";
    }

    /**
     * Los que nos toca a nosotros mover: nunca les contestamos, o el cliente
     * escribió después de nuestra última respuesta.
     *
     * Es LA definición de "pendiente" del producto — la usan la bandeja interna, sus
     * contadores y el badge de la navegación. Si vive en un solo sitio, las tres
     * cuentan lo mismo; copiada, se desincronizan y el badge deja de significar nada.
     */
    public function scopeEsperandoNuestraRespuesta(Builder $query): Builder
    {
        $cliente = self::sqlUltimoMensaje(false);
        $nuestro = self::sqlUltimoMensaje(true);

        return $query->abiertos()->where(function (Builder $q) use ($cliente, $nuestro) {
            $q->whereNull('first_response_at')
              ->orWhereRaw("{$cliente} IS NOT NULL AND ({$nuestro} IS NULL OR {$cliente} > {$nuestro})");
        });
    }

    /** Arriba lo que lleva más tiempo esperándonos. */
    public function scopeOrdenPorEspera(Builder $query): Builder
    {
        $cliente = self::sqlUltimoMensaje(false);
        $nuestro = self::sqlUltimoMensaje(true);

        return $query
            ->orderByRaw("CASE WHEN (first_response_at IS NULL
                                     OR ({$cliente} IS NOT NULL AND ({$nuestro} IS NULL OR {$cliente} > {$nuestro})))
                               THEN 0 ELSE 1 END")
            ->orderByRaw("COALESCE({$cliente}, support_tickets.created_at) ASC");
    }

    /**
     * Cambia el estado dejando la bitácora y `resolved_at` consistentes.
     *
     * Vive en el modelo porque hay tres sitios que mueven estados —la ficha de la
     * cuenta, la bandeja interna y el portal del ISP cuando el cliente responde
     * sobre un resuelto— y la regla tiene que ser una sola. Cuando estaba copiada,
     * `resolved_at` se quedaba con la fecha de la PRIMERA vez que se resolvió:
     * reabrir y volver a resolver dejaba el ticket mintiendo sobre cuándo se cerró.
     *
     * Devuelve false si no había nada que cambiar (y entonces no escribe evento).
     */
    public function cambiarEstadoA(string $nuevo, ?int $autorId): bool
    {
        if ($this->status === $nuevo) {
            return false;
        }

        $anterior = $this->status;

        $this->status = $nuevo;
        // Al reabrir se limpia: si no, el ticket sigue diciendo que se resolvió en
        // una fecha en la que evidentemente no quedó resuelto.
        $this->resolved_at = in_array($nuevo, ['resolved', 'closed'], true)
            ? ($this->resolved_at ?? now())
            : null;
        $this->save();

        TicketEvent::create([
            'support_ticket_id' => $this->id,
            'author_user_id'    => $autorId,
            'type'              => 'status_change',
            'meta'              => ['from' => $anterior, 'to' => $nuevo],
        ]);

        return true;
    }

    /**
     * Marca nuestra primera respuesta visible, si es que todavía no había ninguna.
     *
     * `$cuando` llega null cuando el evento se acaba de crear: `ticket_events.created_at`
     * lo pone la BD (`useCurrent`) y el modelo recién creado no lo sabe. Guardarlo
     * tal cual era lo que dejaba la columna vacía para siempre.
     */
    public function marcarPrimeraRespuesta(?\DateTimeInterface $cuando = null): void
    {
        if ($this->first_response_at) {
            return;
        }

        $this->update(['first_response_at' => $cuando ?? now()]);
    }
}
