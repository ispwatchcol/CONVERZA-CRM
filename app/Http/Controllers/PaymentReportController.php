<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\PaymentChannel;
use App\Models\PaymentReport;
use App\Services\Ai\AiGateway;
use App\Services\Ispwatch\IspwatchRepository;
use App\Services\Payments\PaymentReportProcessor;
use App\Services\Payments\ReceiptReader;
use App\Services\Payments\ReceiptVerifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

/**
 * «Pagos reportados»: los comprobantes que mandaron los clientes, leídos con
 * IA, para que alguien los revise y los registre en ispwatch.
 *
 * Solo admin: muestra comprobantes de TODAS las conversaciones (los asesores
 * solo ven las suyas) y desde aquí se registra plata en ispwatch. El botón
 * «Leer comprobante» del chat sí es de cualquier asesor.
 */
class PaymentReportController extends Controller
{
    private const FILTERS = [
        'revision'    => [PaymentReport::STATUS_READING, PaymentReport::STATUS_PENDING_REVIEW, PaymentReport::STATUS_REGISTERING],
        'registrados' => [PaymentReport::STATUS_REGISTERED],
        'descartados' => [PaymentReport::STATUS_REJECTED, PaymentReport::STATUS_DUPLICATE, PaymentReport::STATUS_NOT_RECEIPT],
        'todos'       => null,
    ];

    public function __construct(
        private readonly PaymentReportProcessor $processor,
        private readonly IspwatchRepository $ispwatch,
        private readonly AiGateway $ai,
    ) {}

    public function index(Request $request): Response
    {
        $tenant = app('tenant');
        $filter = array_key_exists((string) $request->query('estado'), self::FILTERS) ? (string) $request->query('estado') : 'revision';

        $reports = PaymentReport::where('tenant_id', $tenant->id)
            ->when(self::FILTERS[$filter], fn ($q, $statuses) => $q->whereIn('status', $statuses))
            ->with(['contact:id,name,phone,wa_username', 'message:id,media_path,media_mime', 'channelRecord:id,name', 'registrar:id,name', 'reviewer:id,name'])
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString()
            ->through(fn (PaymentReport $r) => $this->payload($r));

        $counts = PaymentReport::where('tenant_id', $tenant->id)
            ->selectRaw('status, count(*) as n')
            ->groupBy('status')
            ->pluck('n', 'status');

        return Inertia::render('Payments/Index', [
            'reports' => $reports,
            'filter'  => $filter,
            'counts'  => collect(self::FILTERS)->map(fn ($statuses) => (int) ($statuses === null
                ? $counts->sum()
                : collect($statuses)->sum(fn ($s) => $counts[$s] ?? 0)))->all(),
            'channels'    => PaymentChannel::where('tenant_id', $tenant->id)->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']),
            'canRegister' => $tenant->canRegisterPayments(),
            'aiReason'    => $this->ai->unavailableReason($tenant),
            'reasons'     => PaymentReport::REASONS,
            'checkLabels' => ReceiptVerifier::LABELS,
        ]);
    }

    /** El reporte con la deuda actual del cliente, para revisar con contexto. */
    public function show(PaymentReport $report): JsonResponse
    {
        $this->authorizeReport($report);
        $tenant = app('tenant');

        // null = no se pudo mirar (sin vínculo o ispwatch caído), que NO es lo
        // mismo que "no debe nada": la pantalla tiene que decir cuál de las dos.
        $pending = null;
        if ($tenant->ispwatch_tenant_id && $report->ispwatch_customer_id) {
            try {
                $pending = $this->ispwatch->pendingInvoicesForCustomer((int) $tenant->ispwatch_tenant_id, (int) $report->ispwatch_customer_id);
            } catch (\Throwable $e) {
                report($e);
            }
        }

        return response()->json([
            'report'           => $this->payload($report->load(['contact:id,name,phone,wa_username', 'message:id,media_path,media_mime', 'channelRecord:id,name', 'registrar:id,name', 'reviewer:id,name'])),
            'pending_invoices' => $pending,
        ]);
    }

    /** El botón «Leer comprobante» de una imagen del chat. */
    public function readFromChat(Request $request, Message $message): JsonResponse
    {
        $tenant = app('tenant');
        abort_if($message->tenant_id !== $tenant->id, 404);

        $isReceiptLike = $message->status === 'received'
            && ($message->type === 'image' || ($message->type === 'document' && $message->media_mime === 'application/pdf'));

        if (! $isReceiptLike) {
            return response()->json(['message' => 'Solo se leen imágenes o PDF que mandó el cliente.'], 422);
        }

        $report = $this->processor->start($tenant, $message, PaymentReport::SOURCE_CHAT, null, false, $request->user()->id);
        $this->processor->queue($report);

        return response()->json([
            'report'  => ['id' => $report->id, 'status' => $report->status],
            'message' => $report->status === PaymentReport::STATUS_READING
                ? 'Leyendo el comprobante… el resultado aparece como nota interna en este chat.'
                : 'Quedó en «Pagos reportados» para revisión: ' . mb_strtolower(PaymentReport::REASONS[$report->reason] ?? 'revisión manual') . '.',
        ]);
    }

    /** Registrar en ispwatch, con lo que la persona revisó (y quizá corrigió). */
    public function register(Request $request, PaymentReport $report)
    {
        $this->authorizeReport($report);
        $tenant = app('tenant');

        $data = $request->validate([
            'amount'             => ['required', 'numeric', 'min:1', 'max:100000000'],
            'paid_on'            => ['required', 'date', 'before_or_equal:tomorrow'],
            'reference'          => ['nullable', 'string', 'max:80'],
            'payment_channel_id' => ['nullable', 'integer', Rule::exists('payment_channels', 'id')->where('tenant_id', $tenant->id)],
        ]);

        if (! $tenant->canRegisterPayments()) {
            return back()->with('error', 'Tu empresa todavía no tiene la conexión de pagos con ispwatch. Regístralo allá y márcalo aquí como registrado.');
        }

        if (in_array($report->status, [PaymentReport::STATUS_READING, PaymentReport::STATUS_REGISTERING, PaymentReport::STATUS_REGISTERED], true)) {
            return back()->with('error', 'Este comprobante no se puede registrar ahora (' . $report->status . ').');
        }

        if (! $report->ispwatch_customer_id) {
            return back()->with('error', 'Primero elige de qué cliente es el pago.');
        }

        $this->processor->approve($tenant, $report, $request->user()->id, [
            'amount'             => (float) $data['amount'],
            'paid_on'            => $data['paid_on'],
            'reference'          => $data['reference'] ?? null,
            'payment_channel_id' => $data['payment_channel_id'] ?? null,
        ]);

        return back()->with('success', 'Pidiéndole el registro a ispwatch. En unos segundos aparece como registrado.');
    }

    /** Alguien ya lo registró directamente en ispwatch. */
    public function markRegistered(Request $request, PaymentReport $report)
    {
        $this->authorizeReport($report);

        $data = $request->validate([
            'ispwatch_payment_id' => ['nullable', 'integer', 'min:1'],
            'note'                => ['nullable', 'string', 'max:500'],
        ]);

        if (in_array($report->status, [PaymentReport::STATUS_READING, PaymentReport::STATUS_REGISTERING, PaymentReport::STATUS_REGISTERED], true)) {
            return back()->with('error', 'Este comprobante no se puede marcar ahora (' . $report->status . ').');
        }

        $this->processor->markRegistered($report, $request->user()->id, $data['ispwatch_payment_id'] ?? null, $data['note'] ?? null);

        return back()->with('success', 'Marcado como registrado.');
    }

    public function reject(Request $request, PaymentReport $report)
    {
        $this->authorizeReport($report);

        $data = $request->validate(['note' => ['required', 'string', 'min:3', 'max:500']]);

        if (in_array($report->status, [PaymentReport::STATUS_REGISTERING, PaymentReport::STATUS_REGISTERED], true)) {
            return back()->with('error', 'Un pago registrado (o registrándose) no se descarta aquí: si hay que reversarlo, se hace en ispwatch.');
        }

        $this->processor->reject($report, $request->user()->id, $data['note']);

        return back()->with('success', 'Comprobante descartado.');
    }

    public function assignCustomer(Request $request, PaymentReport $report)
    {
        $this->authorizeReport($report);

        $data = $request->validate(['customer_id' => ['required', 'integer', 'min:1']]);

        if (in_array($report->status, [PaymentReport::STATUS_REGISTERING, PaymentReport::STATUS_REGISTERED], true)) {
            return back()->with('error', 'Ese pago ya está registrado: el cliente no se cambia aquí.');
        }

        return $this->processor->assignCustomer(app('tenant'), $report, (int) $data['customer_id'])
            ? back()->with('success', 'Cliente asignado.')
            : back()->with('error', 'Ese cliente no existe en ispwatch para tu empresa.');
    }

    /** Clientes de ispwatch por nombre, cédula o teléfono (solo lectura). */
    public function searchCustomers(Request $request): JsonResponse
    {
        $tenant = app('tenant');
        $term   = mb_substr(trim((string) $request->query('q', '')), 0, 60);

        if (! $tenant->ispwatch_tenant_id) {
            return response()->json(['customers' => []]);
        }

        try {
            $customers = $this->ispwatch->searchCustomers((int) $tenant->ispwatch_tenant_id, $term);
        } catch (\Throwable $e) {
            report($e);

            return response()->json(['customers' => [], 'message' => 'No se pudo consultar ispwatch.'], 503);
        }

        return response()->json(['customers' => $customers]);
    }

    // ─────────────────────────────────────────────────────────────────────────

    /** El binding corre antes de ResolveTenant: la pertenencia se mira a mano. */
    private function authorizeReport(PaymentReport $report): void
    {
        abort_if($report->tenant_id !== app('tenant')->id, 404);
    }

    private function payload(PaymentReport $r): array
    {
        $path = $r->message?->media_path;

        return [
            'id'                  => $r->id,
            'status'              => $r->status,
            'reason'              => $r->reason,
            'source'              => $r->source,
            'auto_requested'      => $r->auto_requested,
            'amount'              => $r->amount !== null ? (float) $r->amount : null,
            'currency'            => $r->currency,
            'paid_on'             => $r->paid_on?->format('Y-m-d'),
            'reference'           => $r->reference,
            'channel'             => $r->channel,
            'destination_account' => $r->destination_account,
            'destination_holder'  => $r->destination_holder,
            'payer_name'          => $r->payer_name,
            'ai_confidence'       => $r->ai_confidence,
            'alerts'              => array_values((array) ($r->extraction['alertas'] ?? [])),
            'checks'              => array_values((array) ($r->checks ?? [])),
            'customer'            => $r->ispwatch_customer_id ? [
                'id'    => $r->ispwatch_customer_id,
                'name'  => $r->customer_name,
                'match' => $r->customer_match,
            ] : null,
            'payment_channel'     => $r->channelRecord ? ['id' => $r->channelRecord->id, 'name' => $r->channelRecord->name] : null,
            'ispwatch_payment_id' => $r->ispwatch_payment_id,
            'registration'        => $r->registration,
            'registered_at'       => $r->registered_at?->toIso8601String(),
            'registered_by'       => $r->status === PaymentReport::STATUS_REGISTERED
                ? ($r->registrar?->name ?? ($r->registered_manually ? 'Fuera de Converza' : 'El bot'))
                : null,
            'registered_manually' => $r->registered_manually,
            'reviewed_by'         => $r->reviewer?->name,
            'reviewed_at'         => $r->reviewed_at?->toIso8601String(),
            'review_note'         => $r->review_note,
            'last_error'          => $r->last_error,
            'contact'             => $r->contact ? [
                'id'    => $r->contact->id,
                'name'  => $r->contact->display_name,
                'phone' => $r->contact->phone,
            ] : null,
            'conversation_id'     => $r->conversation_id,
            'image_url'           => $path ? route('media.serve', ['path' => $path]) : null,
            'is_pdf'              => $r->message?->media_mime === 'application/pdf',
            'readable_by_ai'      => ReceiptReader::supports($r->message?->media_mime),
            'created_at'          => $r->created_at?->toIso8601String(),
        ];
    }
}
