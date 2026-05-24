<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Label;
use Illuminate\Http\Request;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function index(Request $request)
    {
        $query = Contact::with('labels');

        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('phone', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($request->filled('label')) {
            $query->whereHas('labels', fn($q) => $q->where('labels.id', $request->label));
        }

        $contacts = $query->orderBy('updated_at', 'desc')->paginate(20)->withQueryString();
        $labels = Label::where('type', 'contact')->get();

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'labels' => $labels,
            'filters' => $request->only(['search', 'label']),
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'phone' => 'required|string|unique:contacts,phone',
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
            'labels' => 'nullable|array',
            'labels.*' => 'exists:labels,id',
        ]);

        $contact = Contact::create($validated);

        if (!empty($validated['labels'])) {
            $contact->labels()->sync($validated['labels']);
        }

        return back()->with('success', 'Contacto creado exitosamente.');
    }

    public function update(Request $request, Contact $contact)
    {
        $validated = $request->validate([
            'phone' => 'required|string|unique:contacts,phone,' . $contact->id,
            'name' => 'nullable|string|max:255',
            'email' => 'nullable|email|max:255',
            'notes' => 'nullable|string',
            'labels' => 'nullable|array',
            'labels.*' => 'exists:labels,id',
        ]);

        $contact->update($validated);

        if (isset($validated['labels'])) {
            $contact->labels()->sync($validated['labels']);
        }

        return back()->with('success', 'Contacto actualizado exitosamente.');
    }

    public function destroy(Contact $contact)
    {
        $contact->delete();
        return back()->with('success', 'Contacto eliminado exitosamente.');
    }
}
