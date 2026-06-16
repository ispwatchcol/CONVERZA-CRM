<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\Account;
use App\Models\Brain\AccountNote;
use App\Models\Brain\SupportTicket;
use App\Models\Brain\TicketEvent;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

class TicketController extends Controller
{
    // ── Tickets ───────────────────────────────────────────────────────────────

    public function storeTicket(Request $request, Account $account)
    {
        $validated = $request->validate([
            'subject'     => ['required', 'string', 'max:200'],
            'priority'    => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'category'    => ['nullable', 'string', 'max:40'],
            'product'     => ['nullable', Rule::in(['ispwatch', 'converza'])],
            'source'      => ['required', Rule::in(['manual', 'whatsapp', 'email'])],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
            'body'        => ['nullable', 'string', 'max:5000'],
        ]);

        $ticket = SupportTicket::create([
            'account_id'  => $account->id,
            'opened_by'   => Auth::id(),
            'status'      => 'open',
            'subject'     => $validated['subject'],
            'priority'    => $validated['priority'],
            'category'    => $validated['category'] ?? null,
            'product'     => $validated['product'] ?? null,
            'source'      => $validated['source'],
            'assigned_to' => $validated['assigned_to'] ?? null,
        ]);

        if (! empty($validated['body'])) {
            TicketEvent::create([
                'support_ticket_id' => $ticket->id,
                'author_user_id'    => Auth::id(),
                'type'              => 'message',
                'body'              => $validated['body'],
            ]);
        }

        return back()->with('success', 'Ticket creado.');
    }

    public function updateTicket(Request $request, Account $account, SupportTicket $ticket)
    {
        abort_if($ticket->account_id !== $account->id, 404);

        $validated = $request->validate([
            'subject'     => ['required', 'string', 'max:200'],
            'status'      => ['required', Rule::in(['open', 'pending', 'resolved', 'closed'])],
            'priority'    => ['required', Rule::in(['low', 'normal', 'high', 'urgent'])],
            'category'    => ['nullable', 'string', 'max:40'],
            'product'     => ['nullable', Rule::in(['ispwatch', 'converza'])],
            'assigned_to' => ['nullable', 'integer', Rule::exists('users', 'id')],
        ]);

        $oldStatus     = $ticket->status;
        $oldAssignedTo = $ticket->assigned_to;

        $ticket->update($validated);

        // Registrar eventos de cambio automáticamente
        if ($oldStatus !== $validated['status']) {
            TicketEvent::create([
                'support_ticket_id' => $ticket->id,
                'author_user_id'    => Auth::id(),
                'type'              => 'status_change',
                'meta'              => ['from' => $oldStatus, 'to' => $validated['status']],
            ]);

            if (in_array($validated['status'], ['resolved', 'closed']) && ! $ticket->resolved_at) {
                $ticket->update(['resolved_at' => now()]);
            }
        }

        if ($oldAssignedTo !== $validated['assigned_to']) {
            TicketEvent::create([
                'support_ticket_id' => $ticket->id,
                'author_user_id'    => Auth::id(),
                'type'              => 'assignment',
                'meta'              => ['to' => $validated['assigned_to']],
            ]);
        }

        return back()->with('success', 'Ticket actualizado.');
    }

    public function addEvent(Request $request, Account $account, SupportTicket $ticket)
    {
        abort_if($ticket->account_id !== $account->id, 404);

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

        // Primer evento en un ticket abierto → marcar first_response_at
        if (! $ticket->first_response_at) {
            $ticket->update(['first_response_at' => $event->created_at]);
        }

        return back()->with('success', 'Mensaje añadido.');
    }

    public function destroyTicket(Account $account, SupportTicket $ticket)
    {
        abort_if($ticket->account_id !== $account->id, 404);
        $ticket->delete();

        return back()->with('success', 'Ticket eliminado.');
    }

    // ── Notas ─────────────────────────────────────────────────────────────────

    public function storeNote(Request $request, Account $account)
    {
        $validated = $request->validate([
            'body'   => ['required', 'string', 'max:3000'],
            'pinned' => ['boolean'],
        ]);

        AccountNote::create([
            'account_id'     => $account->id,
            'author_user_id' => Auth::id(),
            'body'           => $validated['body'],
            'pinned'         => $validated['pinned'] ?? false,
        ]);

        return back()->with('success', 'Nota añadida.');
    }

    public function updateNote(Request $request, Account $account, AccountNote $note)
    {
        abort_if($note->account_id !== $account->id, 404);

        $validated = $request->validate([
            'body'   => ['required', 'string', 'max:3000'],
            'pinned' => ['boolean'],
        ]);

        $note->update($validated);

        return back()->with('success', 'Nota actualizada.');
    }

    public function destroyNote(Account $account, AccountNote $note)
    {
        abort_if($note->account_id !== $account->id, 404);
        $note->delete();

        return back()->with('success', 'Nota eliminada.');
    }

    public function pinNote(Account $account, AccountNote $note)
    {
        abort_if($note->account_id !== $account->id, 404);
        $note->update(['pinned' => ! $note->pinned]);

        return back()->with('success', $note->pinned ? 'Nota fijada.' : 'Nota desfijada.');
    }
}
