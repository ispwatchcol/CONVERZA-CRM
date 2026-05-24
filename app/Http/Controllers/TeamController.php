<?php

namespace App\Http\Controllers;

use App\Models\Team;
use Illuminate\Http\Request;
use Inertia\Inertia;

class TeamController extends Controller
{
    public function index()
    {
        $teams = Team::withCount('staffMembers')->orderBy('name')->get();

        return Inertia::render('Teams/Index', [
            'teams' => $teams,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'required|string|max:7',
        ]);

        Team::create($validated);
        return back()->with('success', 'Equipo creado exitosamente.');
    }

    public function update(Request $request, Team $team)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'color' => 'required|string|max:7',
        ]);

        $team->update($validated);
        return back()->with('success', 'Equipo actualizado exitosamente.');
    }

    public function destroy(Team $team)
    {
        $team->delete();
        return back()->with('success', 'Equipo eliminado exitosamente.');
    }
}
