<?php

namespace App\Services\Payments;

use App\Jobs\ProcessPaymentReport;
use App\Models\Contact;
use App\Models\Message;
use App\Models\PaymentReport;
use App\Models\Tenant;
use App\Services\Ai\AiGateway;
use App\Services\Ai\AiProviderException;
use App\Services\Ai\AiUnavailable;
use App\Services\Ispwatch\IspwatchRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Lleva un comprobante de la imagen al pago:
 *
 *   start()   crea el reporte. Si la IA lo puede leer queda en `reading`; si no
 *             (PDF, IA apagada, sin presupuesto) queda directo para revisión.
 *   process() lo que hace el job ProcessPaymentReport: lee con IA, verifica,
 *             decide y, si toca, le pide el registro a ispwatch.
 *
 * Cada paso mira el estado antes de actuar, así que el job se puede reintentar
 * sin miedo: si la lectura ya está, no se vuelve a pagar; si el registro quedó
 * a medias, se repite con la MISMA llave de idempotencia y ispwatch devuelve
 * el pago que ya creó.
 *
 * La decisión de registrar sin revisión es de este código, nunca de la IA: la IA
 * solo transcribe.
 */
final class PaymentReportProcessor
{
    public function __construct(
        private readonly ReceiptReader $reader,
        private readonly ReceiptVerifier $verifier,
        private readonly PaymentChannels $channels,
        private readonly IspwatchPaymentsApi $api,
        private readonly IspwatchRepository $ispwatch,
        private readonly AiGateway $ai,
    ) {}

    /**
     * Crea el reporte del comprobante que trae `$message`, o devuelve el que ya
     * existe (un reporte por imagen). NO encola nada: eso es queue(), que el
     * motor de flujos llama recién cuando soltó el lock de la conversación.
     *
     * @param 'flow'|'chat' $source
     */
    public function start(
        Tenant $tenant,
        Message $message,
        string $source,
        ?int $runId = null,
        bool $autoRequested = false,
        ?int $requestedBy = null,
    ): PaymentReport {
        $existing = $this->forMessage($tenant, $message->id);

        if ($existing) {
            // La imagen ya se estaba leyendo (p. ej. un asesor tocó «Leer
            // comprobante» antes): el flujo se cuelga de ese mismo reporte.
            if ($runId !== null && $existing->bot_flow_run_id === null) {
                $existing->forceFill(['bot_flow_run_id' => $runId])->save();
            }

            return $existing;
        }

        $reason = match (true) {
            blank($message->media_path)                       => 'imagen_no_disponible',
            ! ReceiptReader::supports($message->media_mime)   => 'formato_no_soportado',
            default                                           => $this->ai->unavailableReason($tenant),
        };

        try {
            $report = PaymentReport::create([
                'tenant_id'       => $tenant->id,
                'conversation_id' => $message->conversation_id,
                'contact_id'      => $message->contact_id,
                'message_id'      => $message->id,
                'bot_flow_run_id' => $runId,
                'source'          => $source,
                'requested_by'    => $requestedBy,
                'status'          => $reason === null ? PaymentReport::STATUS_READING : PaymentReport::STATUS_PENDING_REVIEW,
                'auto_requested'  => $autoRequested,
                'reason'          => $reason,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Dos pedidos sobre la misma imagen en el mismo instante.
            return $this->forMessage($tenant, $message->id) ?? throw new \RuntimeException('Reporte de pago perdido.');
        }

        if ($reason !== null) {
            // Sin lectura no hay nada más que esperar: se identifica al cliente
            // para que el equipo lo tenga a mano, y queda para revisión.
            $this->identify($tenant, $report);
            $report->save();
            $this->note($report, '🧾 Comprobante recibido. Queda en «Pagos reportados» para revisión: '
                . mb_strtolower(PaymentReport::REASONS[$reason] ?? $reason) . '.');
        }

        return $report;
    }

    /** Encola el trabajo pendiente del reporte, si tiene. */
    public function queue(PaymentReport $report): void
    {
        if (in_array($report->status, [PaymentReport::STATUS_READING, PaymentReport::STATUS_REGISTERING], true)) {
            ProcessPaymentReport::dispatch($report->tenant_id, $report->id);
        }
    }

    /**
     * El trabajo del job. Puede lanzar AiProviderException o IspwatchUnavailable
     * reintentables: el job reintenta y este método retoma donde quedó.
     */
    public function process(Tenant $tenant, PaymentReport $report): void
    {
        // Una lectura que llega tarde (el flujo se cansó de esperar y ya lo
        // mandó a revisión) igual se hace: completa los datos para quien
        // revise. readAndDecide() no cambia la decisión ya tomada.
        $late = $report->status === PaymentReport::STATUS_PENDING_REVIEW
            && $report->reason === 'ia_sin_respuesta'
            && $report->extraction === null;

        if ($report->status === PaymentReport::STATUS_READING || $late) {
            $this->readAndDecide($tenant, $report);
        }

        if ($report->status === PaymentReport::STATUS_REGISTERING) {
            $this->register($tenant, $report);
        }
    }

    /**
     * Desde «Pagos reportados»: una persona revisó el comprobante (y quizá
     * corrigió valor, fecha, referencia o cliente) y pide registrarlo en
     * ispwatch. Queda en `registering` y el job hace el resto.
     *
     * @param array{amount?: float, paid_on?: string, reference?: ?string, payment_channel_id?: ?int} $fields
     */
    public function approve(Tenant $tenant, PaymentReport $report, int $userId, array $fields): void
    {
        $report->forceFill(array_filter([
            'amount'             => $fields['amount'] ?? null,
            'paid_on'            => $fields['paid_on'] ?? null,
            'reference'          => array_key_exists('reference', $fields) ? $fields['reference'] : null,
            'payment_channel_id' => $fields['payment_channel_id'] ?? null,
        ], fn ($v) => $v !== null));

        $report->forceFill([
            'reference_key'  => PaymentReport::referenceKey($report->reference),
            'status'         => PaymentReport::STATUS_REGISTERING,
            'reviewed_by'    => $userId,
            'reviewed_at'    => now(),
            'registered_by'  => $userId,
            'reason'         => null,
            'last_error'     => null,
        ])->save();

        $this->queue($report);
    }

    /** Alguien lo registró directamente en ispwatch: solo se deja constancia. */
    public function markRegistered(PaymentReport $report, int $userId, ?int $ispwatchPaymentId, ?string $note): void
    {
        $report->forceFill([
            'status'              => PaymentReport::STATUS_REGISTERED,
            'registered_manually' => true,
            'registered_at'       => now(),
            'registered_by'       => $userId,
            'reviewed_by'         => $userId,
            'reviewed_at'         => now(),
            'review_note'         => $note,
            'ispwatch_payment_id' => $ispwatchPaymentId ?? $report->ispwatch_payment_id,
            'reason'              => null,
        ])->save();
    }

    public function reject(PaymentReport $report, int $userId, string $note): void
    {
        $report->forceFill([
            'status'      => PaymentReport::STATUS_REJECTED,
            'reviewed_by' => $userId,
            'reviewed_at' => now(),
            'review_note' => $note,
        ])->save();
    }

    /** El equipo eligió a mano de qué cliente es el comprobante. */
    public function assignCustomer(Tenant $tenant, PaymentReport $report, int $customerId): bool
    {
        $customer = $tenant->ispwatch_tenant_id
            ? $this->ispwatch->customerById((int) $tenant->ispwatch_tenant_id, $customerId)
            : null;

        if ($customer === null) {
            return false;
        }

        $report->forceFill([
            'ispwatch_customer_id' => $customer['user_id'],
            'customer_name'        => mb_substr((string) $customer['name'], 0, 160),
            'customer_match'       => 'manual',
        ])->save();

        return true;
    }

    /**
     * El job se rindió (reintentos agotados). Lo que quedó a medias pasa a una
     * persona; un pago nunca queda colgado en `reading` o `registering`.
     */
    public function giveUp(PaymentReport $report, \Throwable $e): void
    {
        $reason = match ($report->status) {
            PaymentReport::STATUS_READING     => 'ia_error',
            PaymentReport::STATUS_REGISTERING => 'ispwatch_error',
            default                           => null,
        };

        if ($reason === null) {
            return;
        }

        $report->forceFill([
            'status'     => PaymentReport::STATUS_PENDING_REVIEW,
            'reason'     => $reason,
            'last_error' => mb_substr($e->getMessage(), 0, 500),
        ])->save();

        $this->note($report, '🧾 No se pudo procesar el comprobante automáticamente ('
            . mb_strtolower(PaymentReport::REASONS[$reason]) . '). Revísalo en «Pagos reportados».');
    }

    /**
     * El flujo se cansó de esperar la lectura (flows:tick). Solo si sigue en
     * `reading`: si el job terminó en el medio, gana lo que el job decidió.
     */
    public function expire(PaymentReport $report): void
    {
        $updated = PaymentReport::withoutGlobalScopes()
            ->whereKey($report->id)
            ->where('status', PaymentReport::STATUS_READING)
            ->update([
                'status'     => PaymentReport::STATUS_PENDING_REVIEW,
                'reason'     => 'ia_sin_respuesta',
                'updated_at' => now(),
            ]);

        $report->refresh();

        if ($updated) {
            $this->note($report, '🧾 La lectura del comprobante tardó demasiado. Queda en «Pagos reportados» para revisión.');
        }
    }

    // ─────────────────────────────────────────────────────────────────────────

    private function readAndDecide(Tenant $tenant, PaymentReport $report): void
    {
        $binary = $this->loadImage($report);

        if ($binary === null) {
            $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, 'imagen_no_disponible');
            $this->identify($tenant, $report);
            $report->save();
            $this->note($report, '🧾 No se pudo abrir la imagen del comprobante. Revísalo en «Pagos reportados».');

            return;
        }

        $report->image_hash = hash('sha256', $binary);

        try {
            $reading = $this->reader->read($tenant, $binary, (string) $report->message?->media_mime, $report);
        } catch (AiUnavailable $e) {
            $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, $e->reason);
            $this->identify($tenant, $report);
            $report->save();
            $this->note($report, '🧾 Comprobante recibido. Queda para revisión: ' . mb_strtolower(PaymentReport::REASONS[$e->reason] ?? $e->reason) . '.');

            return;
        } catch (AiProviderException $e) {
            if ($e->retryable) {
                $report->forceFill(['attempts' => $report->attempts + 1, 'last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

                throw $e;
            }

            $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, 'ia_error', $e->getMessage());
            $this->identify($tenant, $report);
            $report->save();
            $this->note($report, '🧾 La IA no pudo leer el comprobante. Revísalo en «Pagos reportados».');

            return;
        }

        $report->forceFill([
            'ai_confidence'       => $reading->confidence,
            'amount'              => $reading->amount,
            'currency'            => $reading->currency,
            'paid_on'             => $reading->date?->format('Y-m-d'),
            'reference'           => $reading->reference,
            'reference_key'       => PaymentReport::referenceKey($reading->reference),
            'channel'             => $reading->channel,
            'destination_account' => $reading->destinationAccount,
            'destination_holder'  => $reading->destinationHolder,
            'payer_name'          => $reading->payerName,
            'extraction'          => $reading->raw,
        ]);

        $identity = $this->identify($tenant, $report);

        if (! $reading->isReceipt) {
            if ($this->keepIfExpired($report)) {
                return;
            }

            $this->settle($report, PaymentReport::STATUS_NOT_RECEIPT, 'no_es_comprobante');
            $report->save();
            $this->note($report, '🧾 La IA revisó la imagen y no parece un comprobante de pago'
                . ($reading->notReceiptReason ? " ({$reading->notReceiptReason})." : '.'));

            return;
        }

        $decided = false;

        // Duplicados y decisión bajo un lock por empresa: dos fotos del mismo
        // pago leídas a la vez por dos workers no pueden pasar las dos.
        Cache::lock("payment-reports:t{$tenant->id}", 20)->block(10, function () use ($tenant, $report, $reading, $identity, &$decided) {
            $existing = $this->ispwatchPayment($tenant, $report, $identity['customer']);
            $channels = $this->channels->active($tenant);
            $match    = $this->channels->matchDestination($channels, $reading->destinationAccount);

            $checks = $this->verifier->verify($reading, [
                'today'            => CarbonImmutable::now((string) config('flows.default_timezone', 'America/Bogota'))->startOfDay(),
                'min_confidence'   => (float) config('ai.receipts.min_confidence', 0.8),
                'max_age_days'     => (int) config('ai.receipts.max_age_days', 7),
                'duplicate_of'     => $this->duplicateOf($tenant, $report),
                'ispwatch_payment' => $existing,
                'destination'      => $match,
                'customer_status'  => $identity['status'],
                'pending'          => $identity['pending'],
                'auto_max'         => (float) ($tenant->receipts_auto_max_amount ?? config('ai.receipts.default_auto_max_amount', 300000)),
                'auto_today'       => $this->autoRegisteredToday($tenant, $report->ispwatch_customer_id),
                'auto_per_day'     => (int) config('ai.receipts.max_auto_per_customer_per_day', 2),
            ]);

            $report->checks             = $checks;
            $report->payment_channel_id = $match['channel']?->id;

            if ($this->keepIfExpired($report)) {
                return;
            }

            $decided   = true;
            $duplicate = collect($checks)->firstWhere('code', 'repetido');

            match (true) {
                ($duplicate['ok'] ?? true) === false
                    => $this->settle($report, PaymentReport::STATUS_DUPLICATE, 'duplicado'),
                is_array($existing) && $existing['same_customer']
                    => $this->settleAlreadyRegistered($report, $existing['id']),
                ! $report->auto_requested
                    => $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, 'sin_auto'),
                ! $tenant->canRegisterPayments()
                    => $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, 'sin_conexion'),
                ! ReceiptVerifier::allPassed($checks)
                    => $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, 'verificacion'),
                default
                    => $this->settle($report, PaymentReport::STATUS_REGISTERING, null),
            };

            $report->save();
        });

        if ($decided && $report->status !== PaymentReport::STATUS_REGISTERING) {
            $this->note($report, $this->decisionNote($report));
        }
    }

    /**
     * Si mientras la IA leía el flujo se cansó de esperar (expire()), el
     * reporte ya está en revisión y el cliente ya escuchó «lo revisa el
     * equipo»: la lectura solo completa los datos para quien revise, sin
     * cambiar la decisión ni registrar nada.
     *
     * @return bool true = estaba vencido y solo se guardaron los datos.
     */
    private function keepIfExpired(PaymentReport $report): bool
    {
        $current = PaymentReport::withoutGlobalScopes()->whereKey($report->id)->first(['status', 'reason']);

        if ($current === null || $current->status === PaymentReport::STATUS_READING) {
            return false;
        }

        $report->status = $current->status;
        $report->reason = $current->reason;
        $report->save();

        return true;
    }

    /** @throws IspwatchUnavailable */
    private function register(Tenant $tenant, PaymentReport $report): void
    {
        if ($report->ispwatch_customer_id === null || $report->amount === null || $report->paid_on === null) {
            $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, 'verificacion', 'Falta el cliente, el valor o la fecha.');
            $report->save();

            return;
        }

        $channel = $report->channelRecord;
        $report->forceFill(['attempts' => $report->attempts + 1])->save();

        try {
            $result = $this->api->register(
                $tenant,
                $report,
                $channel?->ispwatch_payment_method_id,
                (string) ($channel?->name ?: $report->channel ?: 'Transferencia'),
            );
        } catch (IspwatchUnavailable $e) {
            $report->forceFill(['last_error' => mb_substr($e->getMessage(), 0, 500)])->save();

            throw $e;
        }

        if ($result->status === 'registered') {
            $report->forceFill([
                'status'              => PaymentReport::STATUS_REGISTERED,
                'reason'              => null,
                'ispwatch_payment_id' => $result->paymentId,
                'registration'        => $result->details,
                'registered_at'       => now(),
                'last_error'          => null,
            ])->save();

            $this->note($report, $this->registeredNote($report));

            return;
        }

        $this->settle($report, PaymentReport::STATUS_PENDING_REVIEW, 'ispwatch_rechazo', $result->message);
        $report->save();
        $this->note($report, '🧾 ispwatch no aceptó el pago: ' . $result->message . ' Revísalo en «Pagos reportados».');
    }

    /**
     * De quién es el comprobante. Por el teléfono del chat, salvo que alguien
     * ya lo haya elegido a mano: nada de lo que diga la imagen decide a quién se
     * le abona.
     *
     * @return array{status: string, customer: ?array, pending: list<array>}
     */
    private function identify(Tenant $tenant, PaymentReport $report): array
    {
        $none = fn (string $status) => ['status' => $status, 'customer' => null, 'pending' => []];

        if (! $tenant->ispwatch_tenant_id) {
            return $none('unavailable');
        }

        $ispwatchTenant = (int) $tenant->ispwatch_tenant_id;

        try {
            if ($report->customer_match === 'manual' && $report->ispwatch_customer_id) {
                $customer = $this->ispwatch->customerById($ispwatchTenant, (int) $report->ispwatch_customer_id);
            } else {
                $contact = $report->contact_id ? Contact::withoutGlobalScopes()->find($report->contact_id) : null;

                if (! $contact?->phone) {
                    return $none('unavailable');
                }

                $customer = $this->ispwatch->customerByPhone($ispwatchTenant, $contact->phone, $contact->name);
            }

            if ($customer === null) {
                return $none('not_found');
            }

            $pending = $this->ispwatch->pendingInvoicesForCustomer($ispwatchTenant, (int) $customer['user_id']);
        } catch (\Throwable $e) {
            Log::warning('Comprobante: no se pudo consultar ispwatch', ['report_id' => $report->id, 'error' => $e->getMessage()]);

            return $none('unavailable');
        }

        $ambiguous = (bool) ($customer['ambiguous'] ?? false) && $report->customer_match !== 'manual';

        $report->forceFill([
            'ispwatch_customer_id' => (int) $customer['user_id'],
            'customer_name'        => mb_substr((string) $customer['name'], 0, 160),
            'customer_match'       => $report->customer_match === 'manual' ? 'manual' : ($ambiguous ? 'telefono_ambiguo' : 'telefono'),
        ]);

        return ['status' => $ambiguous ? 'ambiguous' : 'found', 'customer' => $customer, 'pending' => $pending];
    }

    /** @return array{id: int, same_customer: bool}|false|null */
    private function ispwatchPayment(Tenant $tenant, PaymentReport $report, ?array $customer): array|false|null
    {
        if (! $tenant->ispwatch_tenant_id || $report->reference_key === null) {
            return null;
        }

        try {
            $payment = $this->ispwatch->paymentWithReference((int) $tenant->ispwatch_tenant_id, $report->reference_key);
        } catch (\Throwable $e) {
            Log::warning('Comprobante: no se pudo buscar la referencia en ispwatch', ['report_id' => $report->id, 'error' => $e->getMessage()]);

            return false;
        }

        return $payment === null ? null : [
            'id'            => $payment['id'],
            'same_customer' => $customer !== null && $payment['customer_id'] === (int) $customer['user_id'],
        ];
    }

    /** Otro reporte vivo con la misma referencia o la misma imagen. */
    private function duplicateOf(Tenant $tenant, PaymentReport $report): ?int
    {
        if ($report->reference_key === null && $report->image_hash === null) {
            return null;
        }

        $id = PaymentReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('id', '<', $report->id)
            ->whereNotIn('status', [PaymentReport::STATUS_REJECTED, PaymentReport::STATUS_NOT_RECEIPT, PaymentReport::STATUS_DUPLICATE])
            ->where(function ($q) use ($report) {
                if ($report->reference_key !== null) {
                    $q->orWhere('reference_key', $report->reference_key);
                }
                if ($report->image_hash !== null) {
                    $q->orWhere('image_hash', $report->image_hash);
                }
            })
            ->orderBy('id')
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    /** Pagos que el bot registró solo, hoy, para este cliente. */
    private function autoRegisteredToday(Tenant $tenant, ?int $customerId): int
    {
        if ($customerId === null) {
            return 0;
        }

        $since = CarbonImmutable::now((string) config('flows.default_timezone', 'America/Bogota'))->startOfDay()->utc();

        return PaymentReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('ispwatch_customer_id', $customerId)
            ->where('status', PaymentReport::STATUS_REGISTERED)
            ->whereNull('registered_by')
            ->where('registered_manually', false)
            ->where('registered_at', '>=', $since)
            ->count();
    }

    private function settle(PaymentReport $report, string $status, ?string $reason, ?string $error = null): void
    {
        $report->forceFill([
            'status'     => $status,
            'reason'     => $reason,
            'last_error' => $error !== null ? mb_substr($error, 0, 500) : $report->last_error,
        ]);
    }

    private function settleAlreadyRegistered(PaymentReport $report, int $paymentId): void
    {
        $report->forceFill([
            'status'              => PaymentReport::STATUS_REGISTERED,
            'reason'              => 'ya_registrado',
            'ispwatch_payment_id' => $paymentId,
            'registered_manually' => true,
            'registered_at'       => now(),
        ]);
    }

    private function loadImage(PaymentReport $report): ?string
    {
        $path = $report->message?->media_path;

        if (blank($path)) {
            return null;
        }

        // Igual que MediaController: el disco configurado y, de respaldo, el
        // local (los medios viven en el droplet).
        foreach (array_unique([(string) config('filesystems.media_disk', 'public'), 'public']) as $disk) {
            try {
                $storage = Storage::disk($disk);

                if ($storage->exists($path)) {
                    $size = $storage->size($path);

                    return $size > 0 && $size <= (int) config('ai.receipts.max_image_bytes', 10 * 1024 * 1024)
                        ? $storage->get($path)
                        : null;
                }
            } catch (\Throwable $e) {
                Log::warning('Comprobante: no se pudo leer la imagen', ['report_id' => $report->id, 'disk' => $disk, 'error' => $e->getMessage()]);
            }
        }

        return null;
    }

    /** Nota interna en el chat: el asesor ve en contexto qué pasó con el comprobante. */
    private function note(PaymentReport $report, string $body): void
    {
        if (! $report->conversation_id) {
            return;
        }

        try {
            Message::create([
                'tenant_id'       => $report->tenant_id,
                'conversation_id' => $report->conversation_id,
                'contact_id'      => $report->contact_id,
                'body'            => mb_substr($body, 0, 4000),
                'status'          => 'sent',
                'type'            => 'note',
                'sent_by_user_id' => null,
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function decisionNote(PaymentReport $report): string
    {
        $summary = self::summary($report);

        return match ($report->status) {
            PaymentReport::STATUS_DUPLICATE  => "🧾 Comprobante repetido ({$summary}): ya se había reportado.",
            PaymentReport::STATUS_REGISTERED => "🧾 Comprobante leído ({$summary}): el pago ya estaba registrado en ispwatch (pago #{$report->ispwatch_payment_id}).",
            default                          => "🧾 Comprobante leído ({$summary}). Queda en «Pagos reportados» para revisión: "
                . mb_strtolower(PaymentReport::REASONS[$report->reason] ?? (string) $report->reason)
                . $this->failedChecks($report) . '.',
        };
    }

    private function registeredNote(PaymentReport $report): string
    {
        $note = '✅ Pago registrado en ispwatch por el bot: ' . self::summary($report) . " (pago #{$report->ispwatch_payment_id}).";

        $reactivation = (array) ($report->registration['reactivation'] ?? []);
        if (! empty($reactivation['was_suspended'])) {
            $note .= ! empty($reactivation['router_ok'])
                ? ' El cliente estaba cortado y quedó reconectado.'
                : ' ⚠️ El cliente estaba cortado y el router NO confirmó la reconexión: revisa su servicio en ispwatch.';
        }

        return $note;
    }

    private function failedChecks(PaymentReport $report): string
    {
        $failed = collect((array) $report->checks)
            ->filter(fn ($c) => ($c['ok'] ?? null) !== true)
            ->map(fn ($c) => $c['detail'] ?? (ReceiptVerifier::LABELS[$c['code']] ?? $c['code']))
            ->filter()
            ->take(3)
            ->implode(' · ');

        return $failed !== '' && $report->reason === 'verificacion' ? " ({$failed})" : '';
    }

    /** «$50.000 · Nequi · ref M123 · 24/10/2026», con lo que haya. */
    public static function summary(PaymentReport $report): string
    {
        return collect([
            $report->amount !== null ? '$' . number_format((float) $report->amount, 0, ',', '.') : null,
            $report->channel,
            $report->reference ? 'ref ' . $report->reference : null,
            $report->paid_on?->format('d/m/Y'),
        ])->filter()->implode(' · ') ?: 'sin datos legibles';
    }

    private function forMessage(Tenant $tenant, int $messageId): ?PaymentReport
    {
        return PaymentReport::withoutGlobalScopes()
            ->where('tenant_id', $tenant->id)
            ->where('message_id', $messageId)
            ->first();
    }
}
