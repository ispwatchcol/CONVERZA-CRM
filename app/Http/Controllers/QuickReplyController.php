<?php

namespace App\Http\Controllers;

use App\Models\QuickReply;
use Illuminate\Http\Request;
use Inertia\Inertia;

class QuickReplyController extends Controller
{
    public function index(Request $request)
    {
        $query = QuickReply::query();

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%")
                    ->orWhere('shortcut', 'like', "%{$search}%");
            });
        }

        if ($request->filled('category')) {
            $query->where('category', $request->category);
        }

        $quickReplies = $query->orderBy('title')->get();

        return Inertia::render('QuickReplies/Index', [
            'quickReplies' => $quickReplies,
            'filters' => $request->only(['search', 'category']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'shortcut' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        QuickReply::create($validated);
        return back()->with('success', 'Respuesta rápida creada exitosamente.');
    }

    public function update(Request $request, QuickReply $quickReply)
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'body' => 'required|string',
            'shortcut' => 'nullable|string|max:50',
            'category' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $quickReply->update($validated);
        return back()->with('success', 'Respuesta rápida actualizada exitosamente.');
    }

    public function destroy(QuickReply $quickReply)
    {
        $quickReply->delete();
        return back()->with('success', 'Respuesta rápida eliminada exitosamente.');
    }
}
