<?php

namespace App\Http\Controllers\Brain;

use App\Http\Controllers\Controller;
use App\Models\Brain\Account;
use App\Models\Brain\AccountInvoice;
use App\Models\Brain\AccountNote;
use App\Models\Brain\AccountProduct;
use App\Models\Brain\SupportTicket;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class AccountController extends Controller
{
    public function __construct(protected IspwatchRepository $ispwatch) {}

    public function index(Request $request)
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->query('status', '');

        $query = Account::query()
            ->with(['products' => fn ($q) => $q->where('status', 'active'), 'ownerUser'])
            ->withCount('products');

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('contact_name', 'like', "%{$search}%")
                  ->orWhere('contact_email', 'like', "%{$search}%");
            });
        }

        if (in_array($status, ['prospect', 'active', 'past_due', 'suspended', 'churned'])) {
            $query->where('status', $status);
        }

        $accounts = $query->orderBy('name')->get()->map(fn ($a) => $this->mapListItem($a));

        $internalUsers = User::whereNotNull('internal_role')
            ->orWhere('is_superadmin', true)
            ->get(['id', 'name']);

        return Inertia::render('Brain/Accounts/Index', [
            'accounts'       => $accounts,
            'filters'        => ['search' => $search, 'status' => $status],
            'internal_users' => $internalUsers,
        ]);
    }

    public function show(Account $account)
    {
        $account->load(['products', 'ownerUser']);

        $converzaLive = null;
        if ($account->tenant_id) {
            $tenant = Tenant::find($account->tenant_id);
            if ($tenant) {
                // Usamos DB::table para evitar que BelongsToTenant scope filtre
                // por el tenant del usuario actual en lugar del de la cuenta.
                $converzaLive = [
                    'name'               => $tenant->name,
                    'wa_status'          => $tenant->wa_status,
                    'conversations_open' => \DB::table('conversations')
                        ->where('tenant_id', $tenant->id)->where('status', 'open')->count(),
                    'contacts_total'     => \DB::table('contacts')
                        ->where('tenant_id', $tenant->id)->count(),
                    'staff_count'        => \DB::table('users')
                        ->where('tenant_id', $tenant->id)->count(),
                ];
            }
        }

        $ispwatchLive = null;
        if ($account->ispwatch_tenant_id) {
            $info   = $this->ispwatch->tenantInfo((int) $account->ispwatch_tenant_id);
            $health = $this->ispwatch->tenantHealth((int) $account->ispwatch_tenant_id);
            if ($info) {
                $ispwatchLive = array_merge($info, $health ?? []);
            }
        }

        $internalUsers = User::where(fn ($q) =>
            $q->whereNotNull('internal_role')->orWhere('is_superadmin', true)
        )->get(['id', 'name']);

        // Cobros
        $invoices = AccountInvoice::where('account_id', $account->id)
            ->with(['payments', 'createdBy:id,name'])
            ->orderByDesc('issued_at')
            ->get()
            ->map(fn ($inv) => [
                'id'                 => $inv->id,
                'number'             => $inv->number,
                'concept'            => $inv->concept,
                'amount'             => (string) $inv->amount,
                'tax'                => (string) $inv->tax,
                'total'              => (string) $inv->total,
                'balance_due'        => (string) $inv->balance_due,
                'currency'           => $inv->currency,
                'status'             => $inv->status,
                'issued_at'          => $inv->issued_at?->toDateString(),
                'due_at'             => $inv->due_at?->toDateString(),
                'paid_at'            => $inv->paid_at?->toDateString(),
                'period_start'       => $inv->period_start?->toDateString(),
                'period_end'         => $inv->period_end?->toDateString(),
                'account_product_id' => $inv->account_product_id,
                'created_by_name'    => $inv->createdBy?->name,
                'payments'           => $inv->payments->map(fn ($p) => [
                    'id'        => $p->id,
                    'amount'    => (string) $p->amount,
                    'currency'  => $p->currency,
                    'method'    => $p->method,
                    'reference' => $p->reference,
                    'paid_at'   => $p->paid_at?->toDateString(),
                ]),
            ]);

        // Tickets
        $tickets = SupportTicket::where('account_id', $account->id)
            ->with(['assignedTo:id,name', 'openedBy:id,name', 'events.author:id,name'])
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => [
                'id'                => $t->id,
                'subject'           => $t->subject,
                'status'            => $t->status,
                'priority'          => $t->priority,
                'category'          => $t->category,
                'product'           => $t->product,
                'source'            => $t->source,
                'assigned_to'       => $t->assignedTo?->only('id', 'name'),
                'opened_by'         => $t->openedBy?->only('id', 'name'),
                'first_response_at' => $t->first_response_at?->toIso8601String(),
                'resolved_at'       => $t->resolved_at?->toIso8601String(),
                'created_at'        => $t->created_at?->toIso8601String(),
                'events'            => $t->events->map(fn ($e) => [
                    'id'         => $e->id,
                    'type'       => $e->type,
                    'body'       => $e->body,
                    'meta'       => $e->meta,
                    'author'     => $e->author?->only('id', 'name'),
                    'created_at' => $e->created_at?->toIso8601String(),
                ]),
            ]);

        // Notas
        $notes = AccountNote::where('account_id', $account->id)
            ->with('author:id,name')
            ->orderByDesc('pinned')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($n) => [
                'id'         => $n->id,
                'body'       => $n->body,
                'pinned'     => $n->pinned,
                'author'     => $n->author?->only('id', 'name'),
                'created_at' => $n->created_at?->toIso8601String(),
                'updated_at' => $n->updated_at?->toIso8601String(),
            ]);

        return Inertia::render('Brain/Accounts/Show', [
            'account'        => $this->mapDetail($account),
            'converza_live'  => $converzaLive,
            'ispwatch_live'  => $ispwatchLive,
            'internal_users' => $internalUsers,
            'invoices'       => $invoices,
            'tickets'        => $tickets,
            'notes'          => $notes,
        ]);
    }

    public function store(Request $request)
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'legal_name'         => ['nullable', 'string', 'max:200'],
            'ispwatch_tenant_id' => ['nullable', 'string', 'max:20'],
            'tenant_id'          => ['nullable', 'integer', Rule::exists('tenants', 'id')],
            'status'             => ['required', Rule::in(['prospect', 'active', 'past_due', 'suspended', 'churned'])],
            'health'             => ['nullable', Rule::in(['good', 'at_risk', 'critical'])],
            'owner_user_id'      => ['nullable', 'integer', Rule::exists('users', 'id')],
            'contact_name'       => ['nullable', 'string', 'max:120'],
            'contact_email'      => ['nullable', 'email', 'max:120'],
            'contact_phone'      => ['nullable', 'string', 'max:30'],
            'country'            => ['nullable', 'string', 'max:5'],
            'onboarding_at'      => ['nullable', 'date'],
            'renewal_at'         => ['nullable', 'date'],
            'notes'              => ['nullable', 'string', 'max:2000'],
            // Productos opcionales
            'products'           => ['nullable', 'array'],
            'products.*.product' => ['required', Rule::in(['ispwatch', 'converza'])],
            'products.*.plan'    => ['nullable', 'string', 'max:60'],
            'products.*.status'  => ['required', Rule::in(['active', 'paused', 'cancelled'])],
            'products.*.billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'products.*.amount'  => ['required', 'numeric', 'min:0'],
            'products.*.currency' => ['required', 'string', 'size:3'],
            'products.*.started_at' => ['nullable', 'date'],
            'products.*.renews_at'  => ['nullable', 'date'],
        ]);

        $account = Account::create([
            ...$validated,
            'slug' => Str::slug($validated['name']) . '-' . Str::lower(Str::random(5)),
        ]);

        foreach ($validated['products'] ?? [] as $p) {
            $account->products()->create($p);
        }

        return back()->with('success', "Cuenta '{$account->name}' creada.");
    }

    public function update(Request $request, Account $account)
    {
        $validated = $request->validate([
            'name'               => ['required', 'string', 'max:120'],
            'legal_name'         => ['nullable', 'string', 'max:200'],
            'ispwatch_tenant_id' => ['nullable', 'string', 'max:20'],
            'tenant_id'          => ['nullable', 'integer', Rule::exists('tenants', 'id')],
            'status'             => ['required', Rule::in(['prospect', 'active', 'past_due', 'suspended', 'churned'])],
            'health'             => ['nullable', Rule::in(['good', 'at_risk', 'critical'])],
            'owner_user_id'      => ['nullable', 'integer', Rule::exists('users', 'id')],
            'contact_name'       => ['nullable', 'string', 'max:120'],
            'contact_email'      => ['nullable', 'email', 'max:120'],
            'contact_phone'      => ['nullable', 'string', 'max:30'],
            'country'            => ['nullable', 'string', 'max:5'],
            'onboarding_at'      => ['nullable', 'date'],
            'renewal_at'         => ['nullable', 'date'],
            'notes'              => ['nullable', 'string', 'max:2000'],
        ]);

        $account->update($validated);

        return back()->with('success', "Cuenta '{$account->name}' actualizada.");
    }

    public function destroy(Account $account)
    {
        $account->delete();

        return to_route('brain.accounts.index')->with('success', "Cuenta '{$account->name}' eliminada.");
    }

    // ── Product sub-actions ───────────────────────────────────────────────────

    public function storeProduct(Request $request, Account $account)
    {
        $validated = $request->validate([
            'product'       => ['required', Rule::in(['ispwatch', 'converza'])],
            'plan'          => ['nullable', 'string', 'max:60'],
            'status'        => ['required', Rule::in(['active', 'paused', 'cancelled'])],
            'billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'amount'        => ['required', 'numeric', 'min:0'],
            'currency'      => ['required', 'string', 'size:3'],
            'started_at'    => ['nullable', 'date'],
            'renews_at'     => ['nullable', 'date'],
        ]);

        $account->products()->updateOrCreate(
            ['product' => $validated['product']],
            $validated,
        );

        return back()->with('success', 'Producto guardado.');
    }

    public function updateProduct(Request $request, Account $account, AccountProduct $product)
    {
        abort_if($product->account_id !== $account->id, 404);

        $validated = $request->validate([
            'plan'          => ['nullable', 'string', 'max:60'],
            'status'        => ['required', Rule::in(['active', 'paused', 'cancelled'])],
            'billing_cycle' => ['required', Rule::in(['monthly', 'quarterly', 'yearly'])],
            'amount'        => ['required', 'numeric', 'min:0'],
            'currency'      => ['required', 'string', 'size:3'],
            'started_at'    => ['nullable', 'date'],
            'renews_at'     => ['nullable', 'date'],
            'cancelled_at'  => ['nullable', 'date'],
        ]);

        $product->update($validated);

        return back()->with('success', 'Producto actualizado.');
    }

    public function destroyProduct(Account $account, AccountProduct $product)
    {
        abort_if($product->account_id !== $account->id, 404);
        $product->delete();

        return back()->with('success', 'Producto eliminado.');
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private function mapListItem(Account $a): array
    {
        return [
            'id'           => $a->id,
            'name'         => $a->name,
            'slug'         => $a->slug,
            'status'       => $a->status,
            'health'       => $a->health,
            'contact_name' => $a->contact_name,
            'country'      => $a->country,
            'renewal_at'   => $a->renewal_at?->toDateString(),
            'onboarding_at'=> $a->onboarding_at?->toDateString(),
            'owner'        => $a->ownerUser?->only('id', 'name'),
            'products'     => $a->products->map(fn ($p) => [
                'product'  => $p->product,
                'plan'     => $p->plan,
                'amount'   => (string) $p->amount,
                'currency' => $p->currency,
                'status'   => $p->status,
            ]),
        ];
    }

    private function mapDetail(Account $a): array
    {
        return [
            'id'                 => $a->id,
            'name'               => $a->name,
            'legal_name'         => $a->legal_name,
            'slug'               => $a->slug,
            'status'             => $a->status,
            'health'             => $a->health,
            'tenant_id'          => $a->tenant_id,
            'ispwatch_tenant_id' => $a->ispwatch_tenant_id,
            'contact_name'       => $a->contact_name,
            'contact_email'      => $a->contact_email,
            'contact_phone'      => $a->contact_phone,
            'country'            => $a->country,
            'onboarding_at'      => $a->onboarding_at?->toDateString(),
            'renewal_at'         => $a->renewal_at?->toDateString(),
            'notes'              => $a->notes,
            'owner'              => $a->ownerUser?->only('id', 'name'),
            'products'           => $a->products->map(fn ($p) => [
                'id'            => $p->id,
                'product'       => $p->product,
                'plan'          => $p->plan,
                'status'        => $p->status,
                'billing_cycle' => $p->billing_cycle,
                'amount'        => (string) $p->amount,
                'currency'      => $p->currency,
                'started_at'    => $p->started_at?->toDateString(),
                'renews_at'     => $p->renews_at?->toDateString(),
                'cancelled_at'  => $p->cancelled_at?->toDateString(),
            ]),
            'created_at' => $a->created_at?->toIso8601String(),
            'updated_at' => $a->updated_at?->toIso8601String(),
        ];
    }
}
