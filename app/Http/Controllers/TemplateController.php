<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TemplateController extends Controller
{
    public function index(Request $request)
    {
        $query = Template::query();

        if ($request->filled('status')) {
            $query->where('status', $request->status);
        }

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('body', 'like', "%{$search}%");
            });
        }

        $templates = $query->orderBy('updated_at', 'desc')->get();

        return Inertia::render('Templates/Index', [
            'templates' => $templates,
            'filters' => $request->only(['status', 'search']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:marketing,utility,authentication',
            'body' => 'required|string',
            'team_label' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        Template::create($validated);
        return back()->with('success', 'Plantilla creada exitosamente.');
    }

    public function update(Request $request, Template $template)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'category' => 'required|in:marketing,utility,authentication',
            'body' => 'required|string',
            'status' => 'nullable|in:pending,approved,rejected',
            'team_label' => 'nullable|string|max:100',
            'is_active' => 'boolean',
        ]);

        $template->update($validated);
        return back()->with('success', 'Plantilla actualizada exitosamente.');
    }

    public function toggleActive(Template $template)
    {
        $template->update(['is_active' => !$template->is_active]);
        return back()->with('success', 'Estado de la plantilla actualizado.');
    }

    public function destroy(Template $template)
    {
        $template->delete();
        return back()->with('success', 'Plantilla eliminada exitosamente.');
    }
}
