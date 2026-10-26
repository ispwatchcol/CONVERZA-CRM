<?php

namespace App\Services\Payments;

use App\Models\PaymentChannel;
use App\Models\Tenant;
use Illuminate\Support\Collection;

/**
 * Los medios de pago de una empresa (payment_channels): lo que el bot le dice
 * al cliente y contra lo que se compara la cuenta destino de un comprobante.
 */
final class PaymentChannels
{
    /** Caracteres con que los bancos ocultan parte de un número. */
    private const MASK = '/[*xX•·…]/u';

    /** @return Collection<int, PaymentChannel> Los activos, en el orden que eligió la empresa. */
    public function active(Tenant $tenant): Collection
    {
        return PaymentChannel::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * El texto de {{empresa.medios_pago}}: un medio por línea, con su cuenta, a
     * nombre de quién y la indicación que haya. Vacío si la empresa no tiene
     * ninguno marcado para mostrar en el bot.
     */
    public function textFor(Tenant $tenant): string
    {
        return $this->active($tenant)
            ->filter(fn (PaymentChannel $c) => $c->show_in_bot)
            ->map(function (PaymentChannel $c) {
                $line = '• ' . $c->name;

                if (filled($c->account_number)) {
                    $line .= ': ' . $c->account_number;
                }
                if (filled($c->account_holder)) {
                    $line .= ' a nombre de ' . $c->account_holder;
                }
                if (filled($c->instructions)) {
                    $line .= "\n  " . trim((string) $c->instructions);
                }

                return $line;
            })
            ->implode("\n");
    }

    /**
     * ¿A cuál de las cuentas de la empresa fue el pago?
     *
     * Los comprobantes rara vez muestran el número completo: Bancolombia pone
     * «*4567», Nequi a veces «300 *** 4567». Por eso se compara por el final:
     *
     *   - número completo (con o sin el 57 delante) → tiene que coincidir;
     *   - número oculto → bastan los últimos 4 dígitos visibles.
     *
     * @param Collection<int, PaymentChannel> $channels
     * @return array{status: 'match'|'no_match'|'unknown', channel: ?PaymentChannel, detail: string}
     */
    public function matchDestination(Collection $channels, ?string $destination): array
    {
        $withAccount = $channels->filter(fn (PaymentChannel $c) => strlen($c->accountDigits()) >= 4);

        if ($withAccount->isEmpty()) {
            return ['status' => 'unknown', 'channel' => null, 'detail' => 'La empresa no tiene números de cuenta en sus medios de pago para comparar.'];
        }

        $shown  = (string) preg_replace('/\D/', '', (string) $destination);
        $masked = (bool) preg_match(self::MASK, (string) $destination);

        if (strlen($shown) < 4) {
            return ['status' => 'unknown', 'channel' => null, 'detail' => 'El comprobante no muestra la cuenta que recibió el pago.'];
        }

        foreach ($withAccount as $channel) {
            $digits = $channel->accountDigits();

            $full = str_ends_with($digits, $shown) && strlen($shown) >= 7
                || str_ends_with($shown, $digits);
            $tail = $masked && substr($digits, -4) === substr($shown, -4);

            if ($full || $tail) {
                return ['status' => 'match', 'channel' => $channel, 'detail' => "Pagado a {$channel->name}."];
            }
        }

        return ['status' => 'no_match', 'channel' => null, 'detail' => "Pagado a «{$destination}», que no es ninguna cuenta de la empresa."];
    }
}
