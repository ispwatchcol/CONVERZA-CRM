<?php

namespace App\Services\Flows\IO;

use App\Models\PaymentReport;
use App\Models\Tenant;
use App\Services\Flows\CustomerLookup;
use App\Services\Flows\ReceiptOutcome;
use App\Services\Flows\SendResult;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\Payments\PaymentChannels;
use Carbon\CarbonImmutable;

/**
 * El FlowIO del simulador: no envía nada por WhatsApp ni escribe en la base.
 * Anota lo que el bot habría mandado (`outbox`) y lo que habría hecho
 * (`effects`: etiquetas, traspasos, datos guardados en la ficha).
 *
 * Lo único real son las lecturas de solo lectura: la búsqueda en ispwatch (con
 * un teléfono de prueba, el admin ve su flujo con los datos de un cliente de
 * verdad de SU empresa) y los medios de pago de la empresa.
 *
 * Los comprobantes NO se leen con IA: el admin elige en el simulador qué
 * resultado probar («válido», «con problemas», «no es un comprobante»). Leer
 * una imagen de prueba costaría plata y no probaría el flujo mejor.
 */
final class SimulatedFlowIO implements FlowIO
{
    public const RECEIPT_OUTCOMES = ['valid', 'review', 'not_receipt'];

    /** @var list<array<string, mixed>> */
    public array $outbox = [];

    /** @var list<array<string, mixed>> */
    public array $effects = [];

    private ?ReceiptOutcome $lastReceipt = null;

    /**
     * @param array{name: ?string, phone: ?string} $contact
     * @param list<int>          $labels     Etiquetas que tiene el contacto simulado.
     * @param array<int, string> $labelNames Etiquetas del tenant (id => nombre).
     * @param array<int, string> $teamNames  Equipos del tenant (id => nombre).
     * @param string|null        $receipt    Si el mensaje simulado es un comprobante, qué resultado
     *                                       simular (uno de RECEIPT_OUTCOMES).
     */
    public function __construct(
        private readonly Tenant $tenant,
        private array $contact,
        private array $labels,
        private readonly bool $windowOpen,
        private readonly array $labelNames,
        private readonly array $teamNames,
        private readonly IspwatchRepository $ispwatch,
        private readonly ?string $receipt = null,
    ) {}

    public function sendText(string $text): SendResult
    {
        if (! $this->windowOpen) {
            return SendResult::skipped('fuera_de_ventana');
        }

        $this->outbox[] = ['kind' => 'text', 'text' => $text];

        return SendResult::sent(null);
    }

    public function sendButtons(string $body, array $buttons): SendResult
    {
        if (! $this->windowOpen) {
            return SendResult::skipped('fuera_de_ventana');
        }

        $this->outbox[] = ['kind' => 'buttons', 'text' => $body, 'options' => $buttons];

        return SendResult::sent(null);
    }

    public function sendList(string $body, string $buttonLabel, array $rows): SendResult
    {
        if (! $this->windowOpen) {
            return SendResult::skipped('fuera_de_ventana');
        }

        $this->outbox[] = ['kind' => 'list', 'text' => $body, 'button' => $buttonLabel, 'options' => $rows];

        return SendResult::sent(null);
    }

    public function contact(): array
    {
        return [
            'name'  => $this->contact['name'] ?? null,
            'phone' => $this->contact['phone'] ?? null,
        ];
    }

    public function tenantName(): string
    {
        return (string) $this->tenant->name;
    }

    public function now(): CarbonImmutable
    {
        return CarbonImmutable::now();
    }

    public function contactHasLabel(int $labelId): bool
    {
        return in_array($labelId, $this->labels, true);
    }

    public function addLabel(int $labelId): ?string
    {
        $name = $this->labelNames[$labelId] ?? null;

        if ($name !== null) {
            $this->labels = array_values(array_unique([...$this->labels, $labelId]));
            $this->effects[] = ['kind' => 'label', 'action' => 'add', 'label' => $name];
        }

        return $name;
    }

    public function removeLabel(int $labelId): ?string
    {
        $name = $this->labelNames[$labelId] ?? null;

        if ($name !== null) {
            $this->labels = array_values(array_filter($this->labels, fn (int $id) => $id !== $labelId));
            $this->effects[] = ['kind' => 'label', 'action' => 'remove', 'label' => $name];
        }

        return $name;
    }

    public function saveContactField(string $field, string $value): void
    {
        $value = trim($value);

        if ($field === 'name' && $value !== '') {
            $this->contact['name'] = mb_substr($value, 0, 120);
            $this->effects[] = ['kind' => 'contact', 'field' => 'nombre', 'value' => $this->contact['name']];
        }

        if ($field === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL)) {
            $this->effects[] = ['kind' => 'contact', 'field' => 'correo', 'value' => mb_strtolower($value)];
        }
    }

    public function lookupCustomer(): CustomerLookup
    {
        if (! $this->tenant->ispwatch_tenant_id) {
            return CustomerLookup::unavailable('sin_vinculo');
        }

        if (empty($this->contact['phone'])) {
            return CustomerLookup::unavailable('sin_telefono');
        }

        try {
            $customer = $this->ispwatch->customerByPhone(
                (int) $this->tenant->ispwatch_tenant_id,
                $this->contact['phone'],
                $this->contact['name'] ?? null,
            );

            return $customer === null
                ? CustomerLookup::notFound()
                : CustomerLookup::found(
                    $customer,
                    $this->ispwatch->pendingInvoicesForCustomer((int) $this->tenant->ispwatch_tenant_id, (int) $customer['user_id']),
                );
        } catch (\Throwable) {
            return CustomerLookup::unavailable('error');
        }
    }

    public function paymentMethodsText(): string
    {
        try {
            return app(PaymentChannels::class)->textFor($this->tenant);
        } catch (\Throwable) {
            return '';
        }
    }

    /** En el simulador, la "imagen previa" es el comprobante con que arrancó la prueba. */
    public function recentReceiptImage(): ?int
    {
        return $this->receipt !== null ? -1 : null;
    }

    public function startReceipt(int $messageId, bool $autoRegister): ReceiptOutcome
    {
        $kind = in_array($this->receipt, self::RECEIPT_OUTCOMES, true) ? $this->receipt : 'valid';

        [$status, $reason] = match ($kind) {
            'not_receipt' => [PaymentReport::STATUS_NOT_RECEIPT, 'no_es_comprobante'],
            'review'      => [PaymentReport::STATUS_PENDING_REVIEW, 'verificacion'],
            default       => $autoRegister
                ? [PaymentReport::STATUS_REGISTERED, null]
                : [PaymentReport::STATUS_PENDING_REVIEW, 'sin_auto'],
        };

        $this->lastReceipt = new ReceiptOutcome(
            reportId: -1,
            status: $status,
            amount: $kind === 'not_receipt' ? null : 50000.0,
            date: $kind === 'not_receipt' ? null : $this->now()->format('d/m/Y'),
            reference: $kind === 'not_receipt' ? null : 'M1234567',
            channel: $kind === 'not_receipt' ? null : 'Nequi',
            reason: $reason,
        );

        $this->effects[] = [
            'kind'   => 'receipt',
            'status' => $status,
            'reason' => $reason !== null ? (PaymentReport::REASONS[$reason] ?? $reason) : null,
        ];

        return $this->lastReceipt;
    }

    public function receipt(int $reportId, bool $giveUp = false): ?ReceiptOutcome
    {
        return $this->lastReceipt;
    }

    public function handoff(?int $teamId, bool $assign, ?string $note): array
    {
        $effect = [
            'kind'   => 'handoff',
            'team'   => $teamId !== null ? ($this->teamNames[$teamId] ?? null) : null,
            // En la vida real solo se asigna si el tenant tiene la auto-asignación activa.
            'assign' => $assign && (bool) $this->tenant->auto_assign_enabled,
            'note'   => $note,
        ];

        $this->effects[] = $effect;

        return array_filter(['equipo' => $effect['team'], 'nota' => $note !== null, 'simulado' => true]);
    }

    /** @return list<int> */
    public function labels(): array
    {
        return $this->labels;
    }

    /** @return array{name: ?string, phone: ?string} */
    public function contactState(): array
    {
        return $this->contact();
    }
}
