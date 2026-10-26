<?php

namespace App\Http\Controllers;

use App\Models\PaymentChannel;
use App\Services\Ispwatch\IspwatchRepository;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Configuración → Medios de pago: dónde le pagan los clientes a la empresa.
 *
 * Cada medio se cuelga de una forma de pago del catálogo de ispwatch (con la
 * que se registra el pago allá) y agrega lo que ispwatch no guarda: el número
 * de cuenta, a nombre de quién y la indicación para el cliente.
 */
class PaymentChannelController extends Controller
{
    public function __construct(private readonly IspwatchRepository $ispwatch) {}

    public function store(Request $request)
    {
        $tenant = app('tenant');
        $data   = $this->validated($request);

        PaymentChannel::create($data + [
            'tenant_id'  => $tenant->id,
            'sort_order' => (int) PaymentChannel::where('tenant_id', $tenant->id)->max('sort_order') + 1,
        ]);

        return back()->with('success', 'Medio de pago agregado.');
    }

    public function update(Request $request, PaymentChannel $channel)
    {
        $this->authorizeChannel($channel);

        $channel->update($this->validated($request));

        return back()->with('success', 'Medio de pago actualizado.');
    }

    public function destroy(PaymentChannel $channel)
    {
        $this->authorizeChannel($channel);

        // Los reportes que apuntaban a él lo pierden (nullOnDelete), pero el
        // pago en ispwatch ya quedó con su forma de pago.
        $channel->delete();

        return back()->with('success', 'Medio de pago eliminado.');
    }

    /**
     * Trae el catálogo de formas de pago de ispwatch como medios de pago, para
     * completarlos con el número de cuenta. No duplica: lo que ya está
     * enlazado no se vuelve a crear.
     */
    public function import()
    {
        $tenant = app('tenant');

        if (! $tenant->ispwatch_tenant_id) {
            return back()->with('error', 'Tu empresa no está vinculada con ispwatch.');
        }

        try {
            $methods = $this->ispwatch->paymentMethodsForTenant((int) $tenant->ispwatch_tenant_id);
        } catch (\Throwable $e) {
            report($e);

            return back()->with('error', 'No se pudo consultar ispwatch. Intenta de nuevo en un momento.');
        }

        $linked = PaymentChannel::where('tenant_id', $tenant->id)->whereNotNull('ispwatch_payment_method_id')->pluck('ispwatch_payment_method_id')->all();
        $order  = (int) PaymentChannel::where('tenant_id', $tenant->id)->max('sort_order');
        $added  = 0;

        foreach ($methods as $method) {
            if (! $method['is_active'] || in_array($method['id'], $linked, true)) {
                continue;
            }

            PaymentChannel::create([
                'tenant_id'                  => $tenant->id,
                'name'                       => mb_substr($method['name'], 0, 80),
                'ispwatch_payment_method_id' => $method['id'],
                'instructions'               => $method['description'] ? mb_substr((string) $method['description'], 0, 500) : null,
                // Sin número de cuenta todavía: no se le muestra al cliente
                // hasta que alguien lo complete.
                'show_in_bot'                => false,
                'is_active'                  => true,
                'sort_order'                 => ++$order,
            ]);
            $added++;
        }

        return back()->with('success', $added > 0
            ? "Se trajeron {$added} formas de pago de ispwatch. Complétales el número de cuenta y márcalas para mostrarlas en el bot."
            : 'No había formas de pago nuevas en ispwatch.');
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $tenant  = app('tenant');
        $methods = [];

        if ($tenant->ispwatch_tenant_id) {
            try {
                $methods = array_column($this->ispwatch->paymentMethodsForTenant((int) $tenant->ispwatch_tenant_id), 'id');
            } catch (\Throwable $e) {
                report($e);
            }
        }

        $data = $request->validate([
            'name'                       => ['required', 'string', 'max:80'],
            // Solo una forma de pago del propio tenant en ispwatch.
            'ispwatch_payment_method_id' => ['nullable', 'integer', Rule::in($methods)],
            'account_number'             => ['nullable', 'string', 'max:60'],
            'account_holder'             => ['nullable', 'string', 'max:120'],
            'instructions'               => ['nullable', 'string', 'max:500'],
            'show_in_bot'                => ['required', 'boolean'],
            'is_active'                  => ['required', 'boolean'],
        ], [
            'ispwatch_payment_method_id.in' => 'Esa forma de pago no existe en ispwatch para tu empresa.',
        ]);

        foreach (['account_number', 'account_holder', 'instructions'] as $field) {
            $data[$field] = filled($data[$field] ?? null) ? trim((string) $data[$field]) : null;
        }

        return $data;
    }

    /** El binding corre antes de ResolveTenant: la pertenencia se mira a mano. */
    private function authorizeChannel(PaymentChannel $channel): void
    {
        abort_if($channel->tenant_id !== app('tenant')->id, 404);
    }
}
