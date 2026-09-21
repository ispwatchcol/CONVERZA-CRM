<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

class Conversation extends Model
{
    use BelongsToTenant;

    protected $fillable = [
        'tenant_id', 'contact_id', 'status', 'assigned_to', 'team_id',
        'bot_active', 'bot_step', 'bot_failed_intents', 'bot_context',
    ];

    protected $casts = [
        'bot_active'         => 'boolean',
        'bot_context'        => 'array',
        'bot_failed_intents' => 'integer',
    ];

    /**
     * Devuelve LA conversación del contacto: una sola por contacto y tenant.
     *
     * Todo lo que escribe en el chat (webhook entrante, envío del agente, avisos
     * automáticos, campañas) debe pasar por acá. Antes cada punto de envío hacía
     * su propio `firstOrCreate(['contact_id' => …, 'status' => 'open'])`, que con
     * el chat CERRADO no encontraba nada y abría una conversación nueva: el mismo
     * cliente terminaba con 3 hilos en la lista y el historial partido en pedazos.
     *
     * Se reutiliza la más reciente sin importar el estado. $reopen decide si un
     * hilo cerrado vuelve a 'open' (true para mensajes reales; false cuando el
     * llamador quiere validar el estado antes de escribir).
     *
     * `wasRecentlyCreated` en el modelo devuelto distingue "hilo nuevo" de
     * "hilo reutilizado" — lo usa el webhook para saber si el bot debe saludar.
     */
    public static function resolveForContact(?int $tenantId, int $contactId, bool $reopen = true): self
    {
        $conversation = static::query()
            ->where('tenant_id', $tenantId)
            ->where('contact_id', $contactId)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if (! $conversation) {
            return static::create(['contact_id' => $contactId, 'tenant_id' => $tenantId]);
        }

        if ($reopen && $conversation->status !== 'open') {
            $conversation->update(['status' => 'open']);
        }

        return $conversation;
    }

    public function contact(): BelongsTo
    {
        return $this->belongsTo(Contact::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(Message::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(StaffMember::class, 'assigned_to');
    }

    public function closingNotes(): HasMany
    {
        return $this->hasMany(ClosingNote::class);
    }

    public function reads(): HasMany
    {
        return $this->hasMany(ConversationRead::class);
    }

    /**
     * Fin de la ventana de servicio de 24 h, o null si nunca hubo entrante.
     *
     * ÚNICO sitio donde se calcula. Antes vivía suelto en ChatController::index()
     * y alimentaba solo al navegador; el servidor enviaba sin mirarlo, así que
     * las dos mitades podían discrepar y de hecho lo hacían: el navegador
     * preguntaba y el servidor no preguntaba nada (CON-77).
     *
     * Se mide desde el último mensaje con status 'received' —lo que el CLIENTE
     * nos escribió—, porque es lo que reabre la ventana para WhatsApp.
     */
    public function serviceWindowExpiresAt(): ?Carbon
    {
        // tenant_id explícito y no solo el global scope: este método se llama
        // desde el chat, pero puede terminar llamándose desde un worker, donde
        // el tenant del container puede ser el del job anterior.
        $lastInboundAt = Message::query()
            ->where('tenant_id', $this->tenant_id)
            ->where('conversation_id', $this->id)
            ->where('status', 'received')
            ->max('created_at');

        return $lastInboundAt ? Carbon::parse($lastInboundAt)->addDay() : null;
    }

    /**
     * ¿WhatsApp va a entregar texto libre en este hilo ahora mismo?
     *
     * Ojo con la respuesta negativa: significa "según los mensajes que NOSOTROS
     * guardamos". Meta es la autoridad y un webhook entrante que se perdiera nos
     * haría creer cerrada una ventana abierta (pasó de verdad: CON-68). Por eso
     * quien la consulta avisa o pide confirmación, pero nunca bloquea a secas.
     */
    public function serviceWindowIsOpen(): bool
    {
        return $this->serviceWindowExpiresAt()?->isFuture() ?? false;
    }

    /**
     * El "último mensaje" de la lista de chats y el cálculo de "cliente
     * esperando" del dashboard deben reflejar la conversación real con el
     * cliente, NO las notas internas ni los eventos de sistema (transferencias).
     * type NULL = mensaje de texto saliente antiguo, por eso se incluye.
     */
    public function latestMessage(): HasOne
    {
        return $this->hasOne(Message::class)->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where(function ($q) {
                $q->whereNull('type')->orWhereNotIn('type', ['system', 'note']);
            }),
        );
    }
}
