<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

/**
 * Bandeja de tickets de TODAS las cuentas (CON-58).
 *
 * La ficha de la cuenta sirve mientras los tickets los abrimos nosotros y ya
 * sabemos de qué cuenta hablamos. Desde que los abre el cliente por su portal
 * hace falta el otro eje: qué entró, qué nadie ha atendido y desde cuándo. Sin
 * esta vista el portal es PEOR que el WhatsApp de siempre, porque el cliente
 * cree que ya lo recibimos.
 *
 * El orden por defecto es el que importa: arriba lo que lleva más tiempo
 * esperándonos, según `SupportTicket::scopeEsperandoNuestraRespuesta` — la misma
 * definición que cuenta el badge de la navegación.
 */
class TicketInboxController extends Controller
{
    private const POR_PAGINA = 25;

    public function index(Request $request)
    {
        $filtros = [
            'estado'    => $request->string('estado')->toString() ?: 'abiertos',
            'prioridad' => $request->string('prioridad')->toString(),
            'producto'  => $request->string('producto')->toString(),
            'origen'    => $request->string('origen')->toString(),
            'asignado'  => $request->string('asignado')->toString(),
            'buscar'    => $request->string('buscar')->toString(),
            'orden'     => $request->string('orden')->toString() ?: 'espera',
        ];

        $query = SupportTicket::query()
            ->with([
                'account:id,name,slug',
                'assignedTo:id,name',
                // Solo el último mensaje: la bandeja muestra una vista previa, no
                // el hilo entero de cada ticket.
                'ultimoMensaje.author:id,name,internal_role,is_superadmin',
            ])
            ->withCount(['events as mensajes_count' => fn ($q) => $q->where('type', 'message')]);

        $this->aplicarFiltros($query, $filtros);

        $filtros['orden'] === 'recientes'
            ? $query->orderByDesc('created_at')
            : $query->ordenPorEspera();

        $tickets = $query->paginate(self::POR_PAGINA)->withQueryString();

        return Inertia::render('Brain/Tickets/Index', [
            'tickets'   => $tickets->through(fn (SupportTicket $t) => $this->fila($t)),
            'filtros'   => $filtros,
            'contadores' => $this->contadores(),
            'internos'  => User::where(fn ($q) => $q->whereNotNull('internal_role')->orWhere('is_superadmin', true))
                ->orderBy('name')
                ->get(['id', 'name']),
        ]);
    }

    /**
     * Cambiar el estado desde la bandeja, sin abrir la ficha de la cuenta.
     *
     * Es su propia ruta y no `updateTicket`: aquel pide asunto, prioridad,
     * categoría y asignación completas —tiene sentido en un formulario de edición,
     * no en un botón de "resuelto"—, y mandar el resto de campos desde acá sería
     * pisarlos con lo que la bandeja tenga cargado.
     */
    public function updateStatus(Request $request, SupportTicket $ticket)
    {
        $validated = $request->validate([
            'status' => ['required', Rule::in(['open', 'pending', 'resolved', 'closed'])],
        ]);

        $ticket->cambiarEstadoA($validated['status'], Auth::id());

        return back()->with('success', 'Estado actualizado.');
    }

    /**
     * Responder (o anotar) desde la bandeja.
     *
     * Misma regla que en la ficha: por defecto la UI manda `note`, y solo un
     * `message` cuenta como primera respuesta, porque es lo único que el cliente lee.
     */
    public function storeEvent(Request $request, SupportTicket $ticket)
    {
        $validated = $request->validate([
            'type' => ['required', Rule::in(['message', 'note'])],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $event = TicketEvent::create([
            'support_ticket_id' => $ticket->id,
            'author_user_id'    => Auth::id(),
            'type'              => $validated['type'],
            'body'              => $validated['body'],
        ]);

        if ($validated['type'] === 'message') {
            $ticket->marcarPrimeraRespuesta($event->created_at);
        }

        return back()->with('success', $validated['type'] === 'message' ? 'Respuesta enviada.' : 'Nota guardada.');
    }

    // ── Interno ──────────────────────────────────────────────────────────────

    /** @param  \Illuminate\Database\Eloquent\Builder<SupportTicket>  $query */
    private function aplicarFiltros($query, array $filtros): void
    {
        match ($filtros['estado']) {
            'todos'     => null,
            'esperando' => $query->esperandoNuestraRespuesta(),
            'abiertos'  => $query->abiertos(),
            default     => $query->where('status', $filtros['estado']),
        };

        if ($filtros['prioridad']) {
            $query->where('priority', $filtros['prioridad']);
        }

        if ($filtros['producto']) {
            $query->where('product', $filtros['producto']);
        }

        if ($filtros['origen']) {
            $query->where('source', $filtros['origen']);
        }

        if ($filtros['asignado'] === 'nadie') {
            $query->whereNull('assigned_to');
        } elseif ($filtros['asignado'] === 'mi') {
            $query->where('assigned_to', Auth::id());
        } elseif (is_numeric($filtros['asignado'])) {
            $query->where('assigned_to', (int) $filtros['asignado']);
        }

        if ($filtros['buscar'] !== '') {
            // `ilike` es de Postgres, que es lo que corre en producción (mismo uso
            // que en ChatController). Las pruebas montan sqlite, así que este ramal
            // no está cubierto por ellas.
            $texto = '%' . str_replace('%', '\%', $filtros['buscar']) . '%';
            $query->where(function ($q) use ($texto) {
                $q->where('subject', 'ilike', $texto)
                  ->orWhereHas('account', fn ($a) => $a->where('name', 'ilike', $texto));
            });
        }
    }

    /**
     * Los números de arriba. Se cuentan sobre TODAS las cuentas y sin los filtros
     * de la vista: son el estado del mundo, no el de la consulta actual.
     *
     * @return array<string, int>
     */
    private function contadores(): array
    {
        return [
            'esperando' => SupportTicket::esperandoNuestraRespuesta()->count(),
            'abiertos'  => SupportTicket::abiertos()->count(),
            'portal'    => SupportTicket::abiertos()->where('source', 'portal')->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function fila(SupportTicket $t): array
    {
        $ultimo      = $t->ultimoMensaje;
        $delCliente  = $ultimo?->author !== null && ! $ultimo->author->canAccessBrain();

        // Desde cuándo nos está esperando: el mensaje del cliente que no hemos
        // contestado o, si nunca contestamos, desde que se abrió.
        $esperandoDesde = match (true) {
            $delCliente                     => $ultimo->created_at,
            $t->first_response_at === null  => $t->created_at,
            default                         => null,
        };

        return [
            'id'                => $t->id,
            'subject'           => $t->subject,
            'status'            => $t->status,
            'priority'          => $t->priority,
            'category'          => $t->category,
            'product'           => $t->product,
            'source'            => $t->source,
            'created_at'        => $t->created_at?->toIso8601String(),
            'first_response_at' => $t->first_response_at?->toIso8601String(),
            'esperando_desde'   => $esperandoDesde?->toIso8601String(),
            'mensajes_count'    => (int) $t->mensajes_count,
            'account'           => $t->account?->only('id', 'name'),
            'assigned_to'       => $t->assignedTo?->only('id', 'name'),
            'ultimo_mensaje'    => $ultimo ? [
                'body'        => Str::limit((string) $ultimo->body, 240),
                'from_client' => $delCliente,
                'author'      => $ultimo->author?->name,
                'created_at'  => $ultimo->created_at?->toIso8601String(),
            ] : null,
        ];
    }
}
