<?php

namespace App\Services\Brain;

use App\Models\Brain\Account;
use App\Models\Brain\AccountBillingNotice;
use App\Models\Brain\AccountInvoice;
use App\Services\Support\CanalDeConverza;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;

/**
 * Los avisos del cobro automático al ISP: factura nueva, recordatorio, corte y
 * reactivación. Salen del número de Converza, igual que los de tickets.
 *
 * Las mismas reglas que AvisosDeTicket, por las mismas razones:
 *
 * 1. **Nunca texto libre fuera de la ventana de 24 h.** Meta lo acepta con 200 y
 *    lo rechaza después; cada rechazo gasta quality rating del número. Sin
 *    plantilla configurada, el aviso queda `skipped` y no se intenta.
 * 2. **Idempotente.** La fila de bitácora se crea ANTES del envío y
 *    (cuenta, tipo, ref) es único: el comando corre cada hora y un aviso que ya
 *    salió (o que ya se decidió no mandar) choca contra la BD.
 *
 * El aviso que no sale NO frena el cobro: la factura se crea y el corte se
 * aplica igual. El aviso es cortesía; la fecha de vencimiento es el contrato.
 */
class AvisosDeCobro
{
    /**
     * Copia del texto de las plantillas aprobadas en Meta (UTILITY, es_CO), solo
     * para anotar en el chat lo que el ISP leyó de verdad. Meta no devuelve el
     * texto renderizado: si se edita una plantilla en Meta, se edita también acá.
     */
    private const CIERRE = 'Para recibir los datos de pago o enviar tu comprobante, responde a este mensaje.';

    private const PLANTILLAS = [
        AccountBillingNotice::KIND_FACTURA => "Hola, {{cliente}}. Se generó la factura {{numero}} de tu suscripción por {{total}}, con vencimiento el {{vence}}.\n\n"
            . 'Si el pago no se registra antes de esa fecha, el acceso al panel queda suspendido hasta que se registre. ' . self::CIERRE,
        AccountBillingNotice::KIND_RECORDATORIO => "Hola, {{cliente}}. Te recordamos que la factura {{numero}} de tu suscripción por {{total}} vence el {{vence}}.\n\n"
            . 'Si ya realizaste el pago, puedes ignorar este mensaje. ' . self::CIERRE,
        AccountBillingNotice::KIND_SUSPENSION => "Hola, {{cliente}}. El acceso al panel de tu suscripción quedó suspendido porque la cuenta tiene un saldo vencido de {{total}}.\n\n"
            . 'La información de tu cuenta se conserva y el acceso se reactiva automáticamente cuando se registre el pago. ' . self::CIERRE,
        AccountBillingNotice::KIND_REACTIVACION => 'Hola, {{cliente}}. Registramos el pago de tu suscripción y el acceso al panel quedó reactivado. Ya puedes ingresar con normalidad.',
    ];

    public function __construct(private CanalDeConverza $canal) {}

    /**
     * Lo que el ISP vio de un aviso que salió por plantilla, con las variables
     * puestas. `public static` para poder anotar en el chat avisos que salieron
     * antes de que existiera el registro.
     *
     * @param  array<string, string>  $params
     */
    public static function textoDePlantilla(string $kind, array $params): ?string
    {
        $texto = self::PLANTILLAS[$kind] ?? null;

        if ($texto === null) {
            return null;
        }

        foreach ($params as $nombre => $valor) {
            $texto = str_replace('{{' . $nombre . '}}', $valor, $texto);
        }

        return $texto;
    }

    public function factura(Account $account, AccountInvoice $invoice): void
    {
        $this->enviar($account, $invoice, AccountBillingNotice::KIND_FACTURA, 'inv:' . $invoice->id,
            "Hola, {$account->name}. Ya está tu factura {$invoice->number} de {$invoice->concept}: "
            . Dinero::formato($invoice->balance_due, $invoice->currency)
            . ', vence el ' . $this->fecha($invoice) . '. '
            . 'Si vence sin pago, el servicio se suspende hasta que se registre el pago.',
            [
                'cliente' => $account->name,
                'numero'  => $invoice->number,
                'total'   => Dinero::formato($invoice->balance_due, $invoice->currency),
                'vence'   => $this->fecha($invoice),
            ],
        );
    }

    public function recordatorio(Account $account, AccountInvoice $invoice): void
    {
        $this->enviar($account, $invoice, AccountBillingNotice::KIND_RECORDATORIO, 'inv:' . $invoice->id,
            "Hola, {$account->name}. Te recordamos que la factura {$invoice->number} por "
            . Dinero::formato($invoice->balance_due, $invoice->currency)
            . ' vence el ' . $this->fecha($invoice) . '.',
            [
                'cliente' => $account->name,
                'numero'  => $invoice->number,
                'total'   => Dinero::formato($invoice->balance_due, $invoice->currency),
                'vence'   => $this->fecha($invoice),
            ],
        );
    }

    /** @param  array<string, float>  $deuda  saldo vencido por moneda */
    public function suspension(Account $account, ?AccountInvoice $invoice, array $deuda): void
    {
        $total = Dinero::lista($deuda);

        $this->enviar($account, $invoice, AccountBillingNotice::KIND_SUSPENSION, $this->episodio($account),
            "Hola, {$account->name}. Suspendimos el servicio por la factura vencida ({$total} pendiente). "
            . 'Se reactiva solo en cuanto se registre el pago.',
            ['cliente' => $account->name, 'total' => $total],
        );
    }

    /** `$episodio` es el del corte que se levanta: se calcula antes de borrar `suspended_at`. */
    public function reactivacion(Account $account, string $episodio): void
    {
        $this->enviar($account, null, AccountBillingNotice::KIND_REACTIVACION, $episodio,
            "Hola, {$account->name}. Recibimos el pago y el servicio ya está activo de nuevo. ¡Gracias!",
            ['cliente' => $account->name],
            conInstrucciones: false,
        );
    }

    /** Identifica un episodio de corte: el aviso de corte y el de reactivación comparten ref. */
    public function episodio(Account $account): string
    {
        return 'susp:' . ($account->suspended_at?->getTimestamp() ?? 0);
    }

    // ── El camino común ──────────────────────────────────────────────────────

    /** @param  array<string, string>  $params  variables nombradas de la plantilla */
    private function enviar(
        Account $account,
        ?AccountInvoice $invoice,
        string $kind,
        string $ref,
        string $texto,
        array $params,
        bool $conInstrucciones = true,
    ): void {
        $log = $this->abrirBitacora($account, $invoice, $kind, $ref);

        if (! $log) {
            return;
        }

        $telefono = CanalDeConverza::normalizarTelefono($account->contact_phone);

        if (! $telefono) {
            $this->cerrar($log, 'skipped', 'sin_telefono');

            return;
        }

        $log->phone = $telefono;

        $instrucciones = trim((string) config('brain.billing.payment_instructions'));

        if ($conInstrucciones && $instrucciones !== '') {
            $texto .= "\n\n" . $instrucciones;
        }

        // Dentro de la ventana se escribe como una persona; fuera, solo plantilla.
        if ($this->canal->ventanaAbierta($telefono)) {
            $resultado = $this->canal->whatsapp()->sendMessage($telefono, $texto);
            $log->channel = 'whatsapp_text';
            $enChat       = $texto;
        } else {
            $plantilla = config("brain.billing.templates.{$kind}");

            if (! $plantilla) {
                $this->cerrar($log, 'skipped', 'sin_plantilla');

                return;
            }

            $resultado = $this->canal->whatsapp()->sendTemplate(
                $telefono,
                $plantilla,
                (string) config('brain.billing.language', 'es_CO'),
                $params,
            );
            $log->channel  = 'whatsapp_template';
            $log->template = $plantilla;
            $enChat        = self::textoDePlantilla($kind, $params) ?? $texto;
        }

        if ($resultado['success'] ?? false) {
            $log->wa_message_id = $resultado['data']['messages'][0]['id'] ?? null;
            $this->cerrar($log, 'sent', null);

            $this->canal->registrarEnChat(
                $telefono,
                $account->name,
                $enChat,
                $log->channel === 'whatsapp_template' ? 'template' : 'text',
                $log->wa_message_id,
            );

            return;
        }

        $this->cerrar($log, 'failed', substr((string) ($resultado['error'] ?? 'error'), 0, 200));
    }

    /**
     * Registra un aviso que se decidió no mandar (p. ej. la factura quedó cubierta
     * con saldo a favor). Queda en la bitácora para que se vea por qué no salió.
     */
    public function omitir(Account $account, ?AccountInvoice $invoice, string $kind, string $ref, string $reason): void
    {
        if ($log = $this->abrirBitacora($account, $invoice, $kind, $ref)) {
            $this->cerrar($log, 'skipped', $reason);
        }
    }

    private function abrirBitacora(Account $account, ?AccountInvoice $invoice, string $kind, string $ref): ?AccountBillingNotice
    {
        try {
            return AccountBillingNotice::create([
                'account_id'         => $account->id,
                'account_invoice_id' => $invoice?->id,
                'kind'               => $kind,
                'ref'                => $ref,
                'status'             => 'skipped',
                'channel'            => 'none',
            ]);
        } catch (QueryException) {
            Log::info('Aviso de cobro ya registrado, no se reenvía', [
                'account' => $account->id, 'kind' => $kind, 'ref' => $ref,
            ]);

            return null;
        }
    }

    private function cerrar(AccountBillingNotice $log, string $status, ?string $reason): void
    {
        $log->status = $status;
        $log->reason = $reason;
        $log->save();
    }

    private function fecha(AccountInvoice $invoice): string
    {
        return $invoice->due_at?->format('d/m/Y') ?? '—';
    }
}
