<?php

namespace App\Http\Controllers;

use App\Models\StaffMember;
use App\Models\Team;
use App\Models\User;
use Illuminate\Http\Request;
use Inertia\Inertia;

class StaffController extends Controller
{
    public function index()
    {
        $staff = StaffMember::with(['user', 'team'])->orderBy('created_at', 'desc')->get()
            ->map(fn($s) => [
                'id' => $s->id,
                'user_id' => $s->user_id,
                'name' => $s->user->name,
                'email' => $s->user->email,
                'team' => $s->team?->name,
                'team_id' => $s->team_id,
                'team_color' => $s->team?->color,
                'role' => $s->role,
                'is_active' => $s->is_active,
                'created_at' => $s->created_at->format('Y-m-d'),
            ]);

        $teams = Team::orderBy('name')->get(['id', 'name', 'color']);
        $users = User::orderBy('name')->get(['id', 'name', 'email']);

        return Inertia::render('Staff/Index', [
            'staff' => $staff,
            'teams' => $teams,
            'users' => $users,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'user_id' => 'required|exists:users,id|unique:staff_members,user_id',
            'team_id' => 'nullable|exists:teams,id',
            'role' => 'required|in:admin,agent,viewer',
            'is_active' => 'boolean',
        ]);

        StaffMember::create($validated);
        return back()->with('success', 'Miembro del staff agregado exitosamente.');
    }

    public function update(Request $request, StaffMember $staff)
    {
        $validated = $request->validate([
            'team_id' => 'nullable|exists:teams,id',
            'role' => 'required|in:admin,agent,viewer',
            'is_active' => 'boolean',
        ]);

        $staff->update($validated);
        return back()->with('success', 'Miembro del staff actualizado exitosamente.');
    }

    public function destroy(StaffMember $staff)
    {
        $staff->delete();
        return back()->with('success', 'Miembro del staff eliminado exitosamente.');
    }
}
