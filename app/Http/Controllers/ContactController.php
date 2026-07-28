<?php

namespace App\Http\Controllers;

use App\Models\Contact;
use App\Models\Conversation;
use App\Models\Label;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class ContactController extends Controller
{
    public function __construct(protected IspwatchRepository $ispwatch) {}

    public function index(Request $request)
    {
        $tenant = app('tenant');
        $tenantId = $tenant->id;
        $ispwatchTenantId = $tenant->ispwatch_tenant_id ? (int) $tenant->ispwatch_tenant_id : null;

        $search   = trim((string) $request->query('search', ''));
        $labelId  = $request->query('label');
        $ispwatchFilter = $request->query('ispwatch', 'all'); // all | customers | prospects

        $query = Contact::query()
            ->where('tenant_id', $tenantId)
            ->with(['labels' => fn ($q) => $q->where('labels.tenant_id', $tenantId)])
            ->withCount(['conversations', 'messages']);

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        if ($labelId) {
            $query->whereHas('labels', fn ($q) => $q->where('labels.id', $labelId)->where('labels.tenant_id', $tenantId));
        }

        $contacts = $query->orderByDesc('updated_at')->paginate(20)->withQueryString();

        // ── Enriquecimiento ISPWatch + post-filtro por estado ────────────────
        // Batch: UNA sola query a ispwatch para las 20 filas de la página, en vez
        // de una llamada por contacto (ver IspwatchRepository::customersByPhonesBatch).
        $pageIspwatchData = [];
        if ($ispwatchTenantId) {
            $forBatch = $contacts->getCollection()
                ->mapWithKeys(fn (Contact $c) => [$c->id => ['phone' => $c->phone, 'name' => $c->name]])
                ->all();
            $pageIspwatchData = $this->ispwatch->customersByPhonesBatch($ispwatchTenantId, $forBatch);
        }

        $contacts->getCollection()->transform(function (Contact $c) use ($pageIspwatchData) {
            $ispwatchCustomer = null;
            $cust = $pageIspwatchData[$c->id] ?? null;
            if ($cust) {
                $ispwatchCustomer = [
                    'name'           => $cust['name'],
                    'service_status' => $cust['service_status'],
                    'pppoe_username' => $cust['pppoe_username'],
                    'ip_user'        => $cust['ip_user'],
                    'credit_balance' => $cust['credit_balance'],
                ];
            }

            return [
                'id'                  => $c->id,
                'phone'               => $c->phone,
                'name'                => $c->name,
                'email'               => $c->email,
                'notes'               => $c->notes,
                'labels'              => $c->labels->map(fn ($l) => ['id' => $l->id, 'name' => $l->name, 'color' => $l->color])->all(),
                'conversations_count' => (int) $c->conversations_count,
                'messages_count'      => (int) $c->messages_count,
                'created_at'          => $c->created_at?->toIso8601String(),
                'updated_at'          => $c->updated_at?->toIso8601String(),
                'ispwatch_customer'   => $ispwatchCustomer,
            ];
        });

        // El filtro ISPWatch se aplica DESPUÉS porque depende del enriquecimiento.
        // Como solo afecta a la página actual, no rompe la paginación visible.
        if ($ispwatchFilter === 'customers' || $ispwatchFilter === 'prospects') {
            $isCustomer = $ispwatchFilter === 'customers';
            $filtered = $contacts->getCollection()->filter(
                fn ($c) => $isCustomer ? $c['ispwatch_customer'] !== null : $c['ispwatch_customer'] === null
            )->values();
            $contacts->setCollection($filtered);
        }

        $labels = Label::query()
            ->where('tenant_id', $tenantId)
            ->where('type', 'contact')
            ->orderBy('name')
            ->get(['id', 'name', 'color']);

        return Inertia::render('Contacts/Index', [
            'contacts' => $contacts,
            'labels'   => $labels,
            'filters'  => ['search' => $search, 'label' => $labelId, 'ispwatch' => $ispwatchFilter],
            'stats'    => $this->stats($tenantId, $ispwatchTenantId),
            'hasIspwatch' => $ispwatchTenantId !== null,
        ]);
    }

    public function store(Request $request)
    {
        $tenantId = app('tenant')->id;

        $request->merge(['phone' => Contact::normalizePhone($request->input('phone'))]);

        $validated = $this->validateData($request, $tenantId);

        $contact = Contact::create([...collect($validated)->except('labels')->all(), 'tenant_id' => $tenantId]);

        if (! empty($validated['labels'])) {
            $contact->labels()->sync($validated['labels']);
        }

        return back()->with('success', 'Contacto creado.');
    }

    public function update(Request $request, Contact $contact)
    {
        $this->authorizeTenantOwnership($contact);

        $request->merge(['phone' => Contact::normalizePhone($request->input('phone'))]);

        $validated = $this->validateData($request, $contact->tenant_id, ignoreId: $contact->id);

        $contact->update(collect($validated)->except('labels')->all());

        if (isset($validated['labels'])) {
            $contact->labels()->sync($validated['labels']);
        }

        return back()->with('success', 'Contacto actualizado.');
    }

    public function destroy(Contact $contact)
    {
        $this->authorizeTenantOwnership($contact);
        $contact->delete();

        return back()->with('success', 'Contacto eliminado.');
    }

    /**
     * Va al chat de este contacto: encuentra la conversación más reciente
     * (o la abierta si hay) y redirige. Si no tiene ninguna, manda a /chat sin id.
     */
    public function chat(Contact $contact)
    {
        $this->authorizeTenantOwnership($contact);

        $conv = Conversation::query()
            ->where('tenant_id', $contact->tenant_id)
            ->where('contact_id', $contact->id)
            ->orderByRaw("CASE WHEN status = 'open' THEN 0 ELSE 1 END")
            ->orderByDesc('updated_at')
            ->first();

        if ($conv) {
            return redirect()->route('chat.index', ['conversation' => $conv->id]);
        }

        // Sin conversación previa: ir al chat con el modal "Nuevo chat" precargado
        // (teléfono + nombre), para no dejar al usuario en una pantalla vacía sin
        // explicación de por qué no se abrió ningún hilo.
        return redirect()->route('chat.index', [
            'new_phone' => $contact->phone,
            'new_name'  => $contact->name,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function authorizeTenantOwnership(Contact $contact): void
    {
        abort_if($contact->tenant_id !== app('tenant')->id, 403, 'No autorizado para este tenant.');
    }

    private function validateData(Request $request, int $tenantId, ?int $ignoreId = null): array
    {
        return $request->validate([
            'phone' => [
                'required', 'string', 'max:20',
                Rule::unique('contacts', 'phone')
                    ->where('tenant_id', $tenantId)
                    ->ignore($ignoreId),
            ],
            'name'      => ['nullable', 'string', 'max:120'],
            'email'     => ['nullable', 'email', 'max:120'],
            'notes'     => ['nullable', 'string', 'max:2000'],
            'labels'    => ['nullable', 'array'],
            'labels.*'  => [
                Rule::exists('labels', 'id')->where('tenant_id', $tenantId),
            ],
        ], [
            'phone.unique' => 'Ya existe un contacto con este teléfono en tu workspace.',
        ]);
    }

    /**
     * @return array<string, int>
     */
    private function stats(int $tenantId, ?int $ispwatchTenantId): array
    {
        $base = Contact::query()->where('tenant_id', $tenantId);

        $stats = [
            'total'        => (clone $base)->count(),
            'this_month'   => (clone $base)->where('created_at', '>=', Carbon::now()->startOfMonth())->count(),
            'with_labels'  => (clone $base)->has('labels')->count(),
            'customers'    => 0,
            'prospects'    => 0,
        ];

        // Conteo de "clientes ISP" vs "prospectos": una sola query batch a ispwatch
        // (ver IspwatchRepository::countCustomersAmongPhones) en vez de una por contacto.
        if ($ispwatchTenantId !== null) {
            $phones = (clone $base)->pluck('phone')->all();
            $customersCount = $this->ispwatch->countCustomersAmongPhones($ispwatchTenantId, $phones);
            $stats['customers'] = $customersCount;
            $stats['prospects'] = $stats['total'] - $customersCount;
        } else {
            $stats['prospects'] = $stats['total'];
        }

        return $stats;
    }
}
