<?php

namespace App\Http\Controllers;

use App\Models\Label;
use Illuminate\Http\Request;
use Inertia\Inertia;

class LabelController extends Controller
{
    public function index(Request $request)
    {
        $query = Label::query();

        if ($request->filled('type')) {
            $query->where('type', $request->type);
        }

        $labels = $query->withCount('contacts')->orderBy('name')->get();

        return Inertia::render('Labels/Index', [
            'labels' => $labels,
            'filters' => $request->only(['type']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|max:7',
            'type' => 'required|in:contact,template',
            'description' => 'nullable|string',
        ]);

        Label::create($validated);
        return back()->with('success', 'Etiqueta creada exitosamente.');
    }

    public function update(Request $request, Label $label)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'color' => 'required|string|max:7',
            'type' => 'required|in:contact,template',
            'description' => 'nullable|string',
        ]);

        $label->update($validated);
        return back()->with('success', 'Etiqueta actualizada exitosamente.');
    }

    public function destroy(Label $label)
    {
        $label->delete();
        return back()->with('success', 'Etiqueta eliminada exitosamente.');
    }
}
