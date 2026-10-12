<?php

namespace App\Services\Brain;

use App\Models\Brain\Account;
use App\Models\Brain\AccountBillingNotice;
use App\Models\Brain\AccountInvoice;
use App\Services\Support\CanalDeConverza;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * El cobro automático del SaaS a los ISP (lo corre `brain:billing-run`).
 *
 * Por cada cuenta con el cobro encendido, en este orden:
 *
 * 1. **Aplica la plata libre** a las facturas abiertas (un pago suelto que llegó
 *    sin factura no puede dejar al cliente cortado).
 * 2. **Factura el período vigente** si no tiene factura: una por moneda, con los
 *    productos mensuales activos. Vence `billing_grace_days` días después de
 *    EMITIDA —no del inicio del período—: aunque el comando haya estado caído,
 *    nadie recibe una factura que ya nace vencida.
 * 3. **Recuerda** el día antes del vencimiento.
 * 4. **Corta** si hay una factura vencida con saldo, y **reactiva** si el corte
 *    fue por cobro y ya no queda nada vencido.
 *
 * Todo es idempotente: el comando corre cada hora y la segunda pasada no hace
 * nada nuevo. La factura del mes la protege el único (cuenta, moneda, mes); los
 * avisos, su bitácora.
 *
 * Cortar = el panel de Converza del ISP muestra "servicio suspendido"
 * (EnsureServicioActivo) y `GET /saas/estado/{id}` se lo informa a ispwatch. Lo
 * de fondo sigue andando —webhooks, bot, avisos a sus clientes—: los clientes
 * finales del ISP no se enteran de que su proveedor nos debe.
 */
class CobroAutomatico
{
    public function __construct(private AvisosDeCobro $avisos) {}

    /** Hoy en la zona horaria del cobro, no en la del servidor (UTC). */
    public static function hoy(): CarbonImmutable
    {
        return CarbonImmutable::now(config('brain.billing.timezone', 'America/Bogota'))->startOfDay();
    }

    /**
     * La fecha como día inmutable. Con un Carbon mutable, `$hoy->addDays()` más
     * abajo correría la fecha de todo lo que viene después en la misma pasada.
     */
    private static function dia(?CarbonInterface $fecha): CarbonImmutable
    {
        return $fecha ? CarbonImmutable::parse($fecha->toDateString()) : self::hoy();
    }

    /**
     * @return array{cuentas: int, facturas: int, recordatorios: int, cortes: int, reactivaciones: int, errores: int}
     */
    public function run(?CarbonInterface $hoy = null, ?int $soloCuenta = null): array
    {
        $hoy = self::dia($hoy);

        $stats = ['cuentas' => 0, 'facturas' => 0, 'recordatorios' => 0, 'cortes' => 0, 'reactivaciones' => 0, 'errores' => 0];

        $cuentas = Account::query()
            ->where('auto_billing_enabled', true)
            ->whereIn('status', ['active', 'past_due', 'suspended'])
            ->when($soloCuenta, fn ($q) => $q->whereKey($soloCuenta))
            ->with('products')
            ->get();

        foreach ($cuentas as $account) {
            $stats['cuentas']++;

            // Una cuenta que falla no frena a las demás.
            try {
                $r = $this->procesar($account, $hoy);
                foreach ($r as $k => $v) {
                    $stats[$k] += $v;
                }
            } catch (\Throwable $e) {
                $stats['errores']++;
                Log::error('Cobro automático: falló una cuenta', [
                    'account' => $account->id, 'error' => $e->getMessage(),
                ]);
            }
        }

        return $stats;
    }

    /** @return array{facturas: int, recordatorios: int, cortes: int, reactivaciones: int} */
    public function procesar(Account $account, CarbonInterface $hoy): array
    {
        $hoy = self::dia($hoy);
        $r = ['facturas' => 0, 'recordatorios' => 0, 'cortes' => 0, 'reactivaciones' => 0];

        $account->allocateCredit();

        $r['facturas'] = count($this->facturarPeriodo($account, $hoy));

        foreach ($this->porRecordar($account, $hoy) as $invoice) {
            $this->avisos->recordatorio($account, $invoice);
            $r['recordatorios']++;
        }

        $this->marcarVencidas($account, $hoy);

        if ($this->cortarSiDebe($account, $hoy)) {
            $r['cortes']++;
        }

        if ($this->reactivarSiQuedoAlDia($account, $hoy)) {
            $r['reactivaciones']++;
        }

        return $r;
    }

    /**
     * Crea las facturas del período vigente que falten (una por moneda).
     *
     * @return list<AccountInvoice>
     */
    public function facturarPeriodo(Account $account, CarbonInterface $hoy): array
    {
        $hoy = self::dia($hoy);
        $inicio = $account->currentPeriodStart($hoy);

        if ($inicio === null) {
            return [];
        }

        // Los meses anteriores a encender el cobro NO se facturan solos: pueden
        // haberse cobrado por fuera, y una factura vieja que aparece sola termina
        // en un corte injusto.
        if ($account->auto_billing_since && $inicio->lt($account->auto_billing_since)) {
            return [];
        }

        $fin     = $account->periodEnd($inicio);
        $periodo = $inicio->format('Y-m');
        $creadas = [];

        foreach ($account->autoBillableProducts() as $moneda => $productos) {
            if ($this->periodoYaFacturado($account, $moneda, $periodo, $inicio, $fin)) {
                continue;
            }

            $invoice = $this->crearFactura($account, $moneda, $productos, $periodo, $inicio, $fin, $hoy);

            if (! $invoice) {
                continue;
            }

            $account->allocateCredit();
            $invoice->refresh()->load('payments');

            // Igual que ispwatch: si el saldo a favor la cubrió entera, no hay nada
            // que cobrar y el aviso sería ruido.
            $invoice->balance_due > 0
                ? $this->avisos->factura($account, $invoice)
                : $this->avisos->omitir($account, $invoice, AccountBillingNotice::KIND_FACTURA, 'inv:' . $invoice->id, 'cubierta_con_saldo');

            $creadas[] = $invoice;
        }

        return $creadas;
    }

    /**
     * ¿El mes ya tiene factura? La automática del mes (aunque esté anulada: si
     * alguien la anuló, fue a propósito y no se rehace), o una manual no anulada
     * cuyo período arranca dentro de este.
     */
    private function periodoYaFacturado(Account $account, string $moneda, string $periodo, Carbon $inicio, Carbon $fin): bool
    {
        return $account->invoices()
            ->where('currency', $moneda)
            ->where(fn ($q) => $q
                ->where('billing_period', $periodo)
                ->orWhere(fn ($q) => $q
                    ->where('status', '!=', 'void')
                    ->whereBetween('period_start', [$inicio->toDateString(), $fin->toDateString()])))
            ->exists();
    }

    private function crearFactura(Account $account, string $moneda, $productos, string $periodo, Carbon $inicio, Carbon $fin, CarbonInterface $hoy): ?AccountInvoice
    {
        $amount = round($productos->sum(fn ($p) => (float) $p->amount), 2);

        $planes = $productos
            ->map(fn ($p) => $p->plan ?: (PlanCatalog::find($p->plan_key)['name'] ?? ucfirst($p->product)))
            ->unique()
            ->implode(' + ');

        $mes = $inicio->copy()->locale('es')->translatedFormat('F Y');

        // El número es único en toda la tabla: si otra corrida lo tomó justo antes,
        // se reintenta con el siguiente. El único (cuenta, moneda, mes) en cambio
        // significa que el mes ya se facturó: ese choque se respeta y se sale.
        for ($intento = 0; $intento < 3; $intento++) {
            try {
                return AccountInvoice::create([
                    'account_id'         => $account->id,
                    'account_product_id' => $productos->first()->id,
                    'created_by'         => null,
                    'number'             => AccountInvoice::nextNumber(),
                    'concept'            => "{$planes} — {$mes}",
                    'amount'             => $amount,
                    'tax'                => 0,
                    'total'              => $amount,
                    'currency'           => $moneda,
                    'status'             => 'sent',
                    'issued_at'          => $hoy->toDateString(),
                    'due_at'             => $hoy->addDays((int) $account->billing_grace_days)->toDateString(),
                    'period_start'       => $inicio->toDateString(),
                    'period_end'         => $fin->toDateString(),
                    'source'             => AccountInvoice::SOURCE_AUTO,
                    'billing_period'     => $periodo,
                ]);
            } catch (QueryException $e) {
                if ($this->periodoYaFacturado($account, $moneda, $periodo, $inicio, $fin)) {
                    return null;
                }
            }
        }

        throw new \RuntimeException("No se pudo numerar la factura de la cuenta {$account->id}");
    }

    /**
     * Facturas a las que hay que recordarles: les falta un día (o vencen hoy) y
     * siguen con saldo. Si se emitió con un día de plazo o menos, el aviso de la
     * factura ya hizo de recordatorio.
     *
     * @return list<AccountInvoice>
     */
    private function porRecordar(Account $account, CarbonInterface $hoy): array
    {
        return $account->invoices()
            ->whereNotIn('status', ['paid', 'void'])
            ->whereNotNull('due_at')
            ->whereDate('due_at', '>=', $hoy->toDateString())
            ->whereDate('due_at', '<=', $hoy->addDay()->toDateString())
            ->with('payments')
            ->get()
            ->filter(fn (AccountInvoice $i) => $i->balance_due > 0
                && $i->issued_at && $i->issued_at->lt($i->due_at->copy()->subDay()))
            ->values()
            ->all();
    }

    private function marcarVencidas(Account $account, CarbonInterface $hoy): void
    {
        foreach ($account->overdueInvoices($hoy) as $invoice) {
            if (in_array($invoice->status, ['draft', 'sent'], true)) {
                $invoice->update(['status' => 'overdue']);
            }
        }
    }

    private function cortarSiDebe(Account $account, CarbonInterface $hoy): bool
    {
        if ($account->isSuspended()) {
            return false;
        }

        $vencidas = $account->overdueInvoices($hoy);

        if ($vencidas->isEmpty()) {
            return false;
        }

        if (! $account->suspend(Account::SUSPENSION_BILLING)) {
            return false;
        }

        $deuda = [];
        foreach ($vencidas as $invoice) {
            $deuda[$invoice->currency] = ($deuda[$invoice->currency] ?? 0) + $invoice->balance_due;
        }

        Log::info('Cobro automático: cuenta suspendida por factura vencida', [
            'account' => $account->id, 'facturas' => $vencidas->pluck('number')->all(),
        ]);

        $this->avisos->suspension($account, $vencidas->first(), $deuda);

        return true;
    }

    /**
     * Levanta un corte POR COBRO si ya no queda nada vencido. Un corte manual no se
     * toca: lo puso una persona por una razón que este código no conoce.
     *
     * Público porque también lo llama BillingController al registrar un pago o
     * anular una factura: quien paga no espera a la próxima pasada del comando.
     */
    public function reactivarSiQuedoAlDia(Account $account, ?CarbonInterface $hoy = null): bool
    {
        $hoy = self::dia($hoy);

        if (! $account->isSuspended() || $account->suspension_reason !== Account::SUSPENSION_BILLING) {
            return false;
        }

        if ($account->overdueInvoices($hoy)->isNotEmpty()) {
            return false;
        }

        $episodio = $this->avisos->episodio($account);
        $account->reactivate();

        Log::info('Cobro automático: cuenta reactivada al quedar al día', ['account' => $account->id]);

        $this->avisos->reactivacion($account, $episodio);

        return true;
    }

    /**
     * Lo que va a pasar con esta cuenta, para la ficha: próxima fecha de cobro,
     * cuánto y qué productos quedan afuera.
     *
     * @return array<string, mixed>
     */
    public function vistaPrevia(Account $account): array
    {
        $hoy    = self::hoy();
        $actual = $account->currentPeriodStart($hoy);

        // La próxima factura es la del período vigente si todavía no existe (y le
        // toca), o la del siguiente.
        $proxima = null;
        if ($account->billing_day !== null) {
            $siguiente = $account->periodEnd($actual ?? $hoy)->addDay();
            $proxima   = $siguiente;

            // Apagado, se muestra lo que pasaría al encenderlo hoy: el período
            // vigente solo le toca si arrancó hoy.
            $desde = $account->auto_billing_enabled ? $account->auto_billing_since : $hoy;

            $vigenteLeToca = $actual && (! $desde || $actual->gte($desde));

            if ($vigenteLeToca) {
                foreach (array_keys($account->autoBillableProducts()) as $moneda) {
                    if (! $this->periodoYaFacturado($account, $moneda, $actual->format('Y-m'), $actual, $account->periodEnd($actual))) {
                        $proxima = $hoy;
                        break;
                    }
                }
            }
        }

        $montos = [];
        foreach ($account->autoBillableProducts() as $moneda => $productos) {
            $montos[$moneda] = number_format($productos->sum(fn ($p) => (float) $p->amount), 2, '.', '');
        }

        $excluidos = $account->products
            ->where('status', 'active')
            ->filter(fn ($p) => $p->billing_cycle !== 'monthly' && (float) $p->amount > 0)
            ->map(fn ($p) => $p->plan ?: $p->product)
            ->values()
            ->all();

        return [
            'enabled'      => (bool) $account->auto_billing_enabled,
            'since'        => $account->auto_billing_since?->toDateString(),
            'grace_days'   => (int) $account->billing_grace_days,
            'billing_day'  => $account->billing_day,
            'next_date'    => $proxima?->toDateString(),
            'amounts'      => $montos,
            'excluded'     => $excluidos,
            'has_phone'    => CanalDeConverza::normalizarTelefono($account->contact_phone) !== null,
        ];
    }
}
