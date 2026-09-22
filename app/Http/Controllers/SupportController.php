<?php

namespace App\Http\Controllers;

use App\Models\Brain\Account;
use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Soporte del lado del ISP: abre un requerimiento hacia nosotros y le sigue los
 * avances desde su propio panel, sin preguntar por WhatsApp cómo va (CON-55).
 *
 * Es la ÚNICA puerta de un usuario de tenant hacia las tablas del Core Brain, y
 * está abierta de esta forma a propósito:
 *
 * 1. **No pasa por `internal` ni reutiliza `Brain\TicketController`.** Son dos
 *    modelos de autorización distintos; mezclarlos en condicionales dentro de un
 *    mismo controlador es exactamente donde se cuela el caso que nadie contempló.
 *    Mismo argumento por el que ispwatch mantiene `ApiClientController` y
 *    `TenantApiKeyController` separados.
 * 2. **El `account_id` sale siempre de la sesión**, nunca de la petición:
 *    `app('tenant')` -> `accounts.tenant_id` -> `support_tickets.account_id`.
 * 3. **Un ticket de otro ISP responde 404, no 403.** Un 403 ya confirmaría que
 *    ese ticket existe.
 * 4. **La bitácora se filtra por tipo en el servidor** (`TicketEvent::VISIBLE_AL_ISP`).
 *    Nunca se manda la bitácora completa para que la vista esconda lo que no toca.
 * 5. **El ISP no toca estado, prioridad ni asignación.** No hay ruta para eso y
 *    los campos no se leen de la petición.
 */
class SupportController extends Controller
{
    /** Categorías que el ISP puede elegir al abrir (en el Brain el campo es libre). */
    private const CATEGORIAS = ['technical', 'billing', 'onboarding', 'other'];

    public function index()
    {
        $account = $this->account();

        $tickets = $account
            ? SupportTicket::where('account_id', $account->id)
                // Solo los eventos visibles: el conteo y la fecha de "último
                // movimiento" que ve el ISP no pueden delatar una nota interna.
                ->with(['events' => fn ($q) => $q->visibleAlIsp()
                    ->select('id', 'support_ticket_id', 'type', 'created_at')
                    ->orderBy('created_at')])
                // Lo que está sin resolver primero; dentro de cada grupo, lo último.
                ->orderByRaw("CASE WHEN status IN ('open', 'pending') THEN 0 ELSE 1 END")
                ->orderByDesc('created_at')
                ->get()
                ->map(fn (SupportTicket $t) => [
                    'id'             => $t->id,
                    'subject'        => $t->subject,
                    'status'         => $t->status,
                    'category'       => $t->category,
                    'product'        => $t->product,
                    'created_at'     => $t->created_at?->toIso8601String(),
                    'resolved_at'    => $t->resolved_at?->toIso8601String(),
                    'messages_count' => $t->events->where('type', 'message')->count(),
                    'last_event_at'  => $t->events->last()?->created_at?->toIso8601String(),
                ])
                ->values()
            : collect();

        return Inertia::render('Support/Index', [
            'tickets' => $tickets,
            // Sin cuenta en el Brain no hay a qué colgar el ticket. Pasa de verdad
            // (un tenant recién creado y todavía sin ficha), así que la página lo
            // dice en vez de reventar: ver el estado vacío de Support/Index.vue.
            'account_linked' => $account !== null,
            'categorias'     => self::CATEGORIAS,
        ]);
    }

    public function show(int $ticket)
    {
        $account = $this->accountOrFail();
        $ticket  = $this->ticketOrFail($account, $ticket);

        $ticket->load(['events' => fn ($q) => $q->visibleAlIsp()
            ->with('author:id,name,tenant_id,internal_role,is_superadmin')
            ->orderBy('created_at')]);

        return Inertia::render('Support/Show', [
            'ticket' => [
                'id'          => $ticket->id,
                'subject'     => $ticket->subject,
                'status'      => $ticket->status,
                'category'    => $ticket->category,
                'product'     => $ticket->product,
                'created_at'  => $ticket->created_at?->toIso8601String(),
                'resolved_at' => $ticket->resolved_at?->toIso8601String(),
                'events'      => $ticket->events->map(fn (TicketEvent $e) => $this->eventoParaElIsp($e)),
            ],
        ]);
    }

    public function store(Request $request)
    {
        $account = $this->accountOrFail();

        $validated = $request->validate([
            'subject'  => ['required', 'string', 'max:200'],
            'body'     => ['required', 'string', 'max:5000'],
            'category' => ['nullable', Rule::in(self::CATEGORIAS)],
            'product'  => ['nullable', Rule::in(['ispwatch', 'converza'])],
        ]);

        // Ni `priority` ni `assigned_to` ni `status` salen de la petición: aunque
        // lleguen en el payload se ignoran. Priorizar y asignar es nuestro.
        $ticket = SupportTicket::create([
            'account_id' => $account->id,
            'opened_by'  => Auth::id(),
            'subject'    => $validated['subject'],
            'status'     => 'open',
            'priority'   => 'normal',
            'category'   => $validated['category'] ?? null,
            'product'    => $validated['product'] ?? null,
            'source'     => 'portal',
        ]);

        TicketEvent::create([
            'support_ticket_id' => $ticket->id,
            'author_user_id'    => Auth::id(),
            'type'              => 'message',
            'body'              => $validated['body'],
        ]);

        // `first_response_at` se queda en null a propósito: mide NUESTRA primera
        // respuesta visible, y el mensaje con que el cliente abre el ticket no lo es.
        return redirect()
            ->route('support.show', $ticket->id)
            ->with('success', "Requerimiento #{$ticket->id} enviado. Acá mismo vas viendo sus avances.");
    }

    public function storeMessage(Request $request, int $ticket)
    {
        $account = $this->accountOrFail();
        $ticket  = $this->ticketOrFail($account, $ticket);

        if ($ticket->status === 'closed') {
            return back()->with('error', 'Este requerimiento está cerrado. Abre uno nuevo y lo retomamos desde ahí.');
        }

        $validated = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
        ]);

        TicketEvent::create([
            'support_ticket_id' => $ticket->id,
            'author_user_id'    => Auth::id(),
            'type'              => 'message',
            'body'              => $validated['body'],
        ]);

        // Si el cliente escribe sobre un ticket que dimos por resuelto, vuelve a
        // abrirse. No es el ISP eligiendo un estado —no puede elegir ninguno—: es
        // que un resuelto con una respuesta nueva encima no aparecería en ninguna
        // bandeja nuestra y quedaría esperando a que alguien se acuerde.
        if ($ticket->status === 'resolved') {
            $ticket->update(['status' => 'open', 'resolved_at' => null]);

            TicketEvent::create([
                'support_ticket_id' => $ticket->id,
                'author_user_id'    => Auth::id(),
                'type'              => 'status_change',
                'meta'              => ['from' => 'resolved', 'to' => 'open'],
            ]);
        }

        return back()->with('success', 'Mensaje enviado.');
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /**
     * Cómo se traduce un evento para el ISP.
     *
     * Se arma campo por campo en lugar de mandar el modelo: `meta` no se copia en
     * bruto jamás —el de `assignment` lleva ids de usuarios internos, y un tipo
     * nuevo mañana podría llevar cualquier cosa—, así que de `status_change` solo
     * salen `from` y `to`.
     *
     * @return array<string, mixed>
     */
    private function eventoParaElIsp(TicketEvent $e): array
    {
        $lado = $this->ladoDelAutor($e);

        return [
            'id'          => $e->id,
            'type'        => $e->type,
            'body'        => $e->type === 'message' ? $e->body : null,
            'from'        => $e->type === 'status_change' ? ($e->meta['from'] ?? null) : null,
            'to'          => $e->type === 'status_change' ? ($e->meta['to'] ?? null) : null,
            'author_side' => $lado,
            // Del lado nuestro no se expone quién atendió: para el ISP somos el
            // equipo de soporte, no una lista con los nombres de nuestra gente.
            'author_name' => $lado === 'isp' ? ($e->author?->name ?? 'Tu equipo') : 'Soporte Converza',
            'created_at'  => $e->created_at?->toIso8601String(),
        ];
    }

    /** 'converza' (nosotros), 'isp' (el propio cliente) o 'system'. */
    private function ladoDelAutor(TicketEvent $e): string
    {
        $author = $e->author;

        if (! $author) {
            return 'system';
        }

        if ($author->canAccessBrain()) {
            return 'converza';
        }

        return $author->tenant_id === $this->tenantId() ? 'isp' : 'system';
    }

    /**
     * La cuenta del Brain ligada al tenant de la sesión, o null si todavía no tiene.
     *
     * `accounts.tenant_id` no es único en el esquema (el modal del Brain avisa pero
     * no lo impide), así que se toma la más vieja para que la respuesta sea siempre
     * la misma y no cambie entre peticiones.
     */
    private function account(): ?Account
    {
        $tenantId = $this->tenantId();

        return $tenantId
            ? Account::where('tenant_id', $tenantId)->orderBy('id')->first()
            : null;
    }

    private function accountOrFail(): Account
    {
        $account = $this->account();

        abort_if($account === null, 404);

        return $account;
    }

    /** El ticket, solo si es de esta cuenta. De otro ISP -> 404. */
    private function ticketOrFail(Account $account, int $id): SupportTicket
    {
        return SupportTicket::where('account_id', $account->id)->findOrFail($id);
    }

    /** El tenant lo fija ResolveTenant, que ya descarta los inactivos. */
    private function tenantId(): ?int
    {
        return app()->bound('tenant') ? (int) app('tenant')->id : null;
    }
}
