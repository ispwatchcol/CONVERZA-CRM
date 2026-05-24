<?php

namespace App\Http\Controllers;

use App\Models\ClosingNote;
use App\Models\Conversation;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ClosingNoteController extends Controller
{
    public function index(Request $request)
    {
        $query = ClosingNote::with(['conversation.contact', 'user']);

        if ($request->filled('search')) {
            $query->where('note', 'like', "%{$request->search}%");
        }

        $notes = $query->orderBy('created_at', 'desc')->paginate(20)->withQueryString();

        $notes->getCollection()->transform(fn($note) => [
            'id' => $note->id,
            'note' => $note->note,
            'contact_name' => $note->conversation?->contact?->name ?: $note->conversation?->contact?->phone,
            'user_name' => $note->user?->name,
            'created_at' => $note->created_at->format('Y-m-d H:i'),
        ]);

        return Inertia::render('ClosingNotes/Index', [
            'notes' => $notes,
            'filters' => $request->only(['search']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'conversation_id' => 'required|exists:conversations,id',
            'note' => 'required|string',
        ]);

        ClosingNote::create([
            ...$validated,
            'user_id' => 1, // TODO: auth user
        ]);

        // Close the conversation
        Conversation::where('id', $validated['conversation_id'])->update(['status' => 'closed']);

        return back()->with('success', 'Nota de cierre guardada.');
    }
}
