<?php

namespace App\Services\Flows\Nodes;

use App\Models\PaymentReport;
use App\Services\Flows\Execution;
use App\Services\Flows\Inbound;
use App\Services\Flows\ReceiptOutcome;
use App\Services\Flows\Step;
use App\Services\Flows\Validation\Issue;
use App\Services\Flows\Validation\ValidationContext;
use Carbon\CarbonImmutable;

/**
 * Pide el comprobante de pago, lo hace leer con IA y sigue según lo que pasó:
 *
 *   Pago registrado         → ispwatch ya tiene el pago (solo con «registrar
 *                             automáticamente» encendido y si pasó todas las
 *                             verificaciones).
 *   Para revisión del equipo → quedó en «Pagos reportados» para una persona.
 *   No mandó un comprobante → agotó los reintentos sin mandar una imagen que
 *                             sea un comprobante.
 *
 * La lectura NO corre dentro del job del flujo: el bloque pide una tarea
 * (Step::waitTask), la ejecución queda en `waiting_task` y el job de la cola de
 * IA la despierta al terminar. Si el plazo vence (cola caída, proveedor
 * colgado), flows:tick la despierta igual y el comprobante pasa a revisión.
 */
final class ReceiptNode extends NodeType
{
    /** Tipo de tarea que encola FlowRunner. */
    public const TASK = 'receipt';

    public const MAX_RETRIES = 3;

    public const VARIABLES = [
        'pago.valor'      => 'Valor del comprobante ($50.000)',
        'pago.fecha'      => 'Fecha del comprobante (dd/mm/aaaa)',
        'pago.referencia' => 'Número de referencia del comprobante',
        'pago.medio'      => 'Por dónde pagó (Nequi, Bancolombia…)',
        'pago.estado'     => 'registrado, en_revision, repetido o no_valido',
        'pago.servicio'   => 'Si estaba cortado, que su servicio ya quedó activo',
    ];

    private const DEFAULT_RETRY = 'Para registrar tu pago necesito la foto o el pantallazo del comprobante 📸';

    private const DEFAULT_NOT_RECEIPT = 'Esa imagen no parece un comprobante de pago 🤔. ¿Me envías la foto o el pantallazo del comprobante?';

    public function type(): string
    {
        return 'receipt';
    }

    public function label(): string
    {
        return 'Comprobante de pago';
    }

    public function waitsForInput(): bool
    {
        return true;
    }

    public function sendsMessage(array $data): bool
    {
        return true;
    }

    public function outputs(array $data): array
    {
        return $this->bool($data, 'auto_register') ? ['registered', 'review', 'invalid'] : ['review', 'invalid'];
    }

    public function outputLabel(string $handle, array $data): string
    {
        return match ($handle) {
            'registered' => 'Pago registrado',
            'invalid'    => 'No mandó un comprobante',
            default      => 'Para revisión del equipo',
        };
    }

    public function definesVariables(array $data): array
    {
        return array_keys(self::VARIABLES);
    }

    public function texts(array $data): array
    {
        return [$this->str($data, 'text'), $this->str($data, 'retry_text')];
    }

    public function validate(array $node, ValidationContext $ctx): array
    {
        $data   = $node['data'];
        $issues = $this->requireText($node, 'text', 'el mensaje que pide el comprobante');

        $retries = $data['max_retries'] ?? 1;
        if (! is_numeric($retries) || (int) $retries < 0 || (int) $retries > self::MAX_RETRIES) {
            $issues[] = Issue::error('Comprobante de pago: los reintentos van de 0 a ' . self::MAX_RETRIES . '.', $node['id']);
        }

        if (mb_strlen($this->str($data, 'retry_text')) > self::MAX_TEXT) {
            $issues[] = Issue::error('Comprobante de pago: el texto de reintento es demasiado largo.', $node['id']);
        }

        // Advertencias, no errores: el bloque funciona igual, solo que todo
        // termina en «Para revisión». Bloquear la publicación por una
        // configuración de la empresa obligaría a rehacer el flujo después.
        if (! $ctx->aiEnabled) {
            $issues[] = Issue::warning('Comprobante de pago: la lectura con IA está apagada para tu empresa (Configuración → Inteligencia artificial). Los comprobantes van a quedar para revisión del equipo sin leer.', $node['id']);
        }

        if ($this->bool($data, 'auto_register')) {
            if (! $ctx->canRegisterPayments) {
                $issues[] = Issue::warning('Comprobante de pago: tu empresa todavía no tiene la conexión de pagos con ispwatch. Hasta que la tenga, ningún pago se registra solo: todos salen por «Para revisión del equipo».', $node['id']);
            }

            if (! $ctx->hasPaymentAccounts) {
                $issues[] = Issue::warning('Comprobante de pago: no tienes números de cuenta en tus medios de pago. Sin ellos no se puede comprobar a qué cuenta se pagó, y ningún pago se registra solo.', $node['id']);
            }
        }

        return $issues;
    }

    public function enter(array $node, Execution $ex): Step
    {
        foreach (array_keys(self::VARIABLES) as $name) {
            $ex->set($name, '');
        }

        // Lo más común: el cliente abre el chat mandando la foto. Esa imagen es
        // la que hay que leer, no pedirle otra.
        if ($this->bool($node['data'], 'use_recent_image', true) && ($messageId = $ex->io->recentReceiptImage()) !== null) {
            $ex->note(detail: ['imagen_previa' => true]);

            return $this->start($node, $ex, $messageId);
        }

        $text = trim($ex->render($this->str($node['data'], 'text')));

        return $this->afterSend($ex, $ex->io->sendText($text), $text) ?? Step::waitInput();
    }

    public function receive(array $node, Execution $ex, Inbound $input): Step
    {
        if ($input->isMedia() && $input->messageId !== null) {
            $ex->note(input: $input->text);

            return $this->start($node, $ex, $input->messageId);
        }

        $ex->note(input: $input->text);

        return $this->retry($node, $ex, self::DEFAULT_RETRY);
    }

    public function resume(array $node, Execution $ex): Step
    {
        $task = $ex->state['receipt'][$node['id']] ?? null;

        if (! is_array($task) || ! isset($task['report'])) {
            // Estado que no debería existir: que lo mire una persona.
            return $this->conclude($node, $ex, null);
        }

        $reportId = (int) $task['report'];
        $until    = CarbonImmutable::parse((string) ($task['until'] ?? 'now'));
        $outcome  = $ex->io->receipt($reportId);

        if ($outcome !== null && $outcome->pending()) {
            if ($ex->io->now()->lt($until)) {
                // Lo despertó un aviso viejo y la lectura sigue en curso: a
                // seguir esperando, sin volver a encolarla.
                return Step::waitTask(self::TASK, $reportId, $until, dispatch: false);
            }

            $outcome = $ex->io->receipt($reportId, giveUp: true) ?? $outcome;
            $ex->note(detail: ['plazo_vencido' => true]);
        }

        unset($ex->state['receipt'][$node['id']]);

        return $this->conclude($node, $ex, $outcome);
    }

    private function start(array $node, Execution $ex, int $messageId): Step
    {
        $outcome = $ex->io->startReceipt($messageId, $this->bool($node['data'], 'auto_register'));
        $ex->note(detail: ['comprobante' => $outcome->reportId, 'estado' => $outcome->status]);

        if (! $outcome->pending()) {
            return $this->conclude($node, $ex, $outcome);
        }

        $until = $ex->io->now()->addMinutes(max(1, (int) config('ai.receipts.task_timeout_minutes', 5)));
        $ex->state['receipt'][$node['id']] = ['report' => $outcome->reportId, 'until' => $until->toIso8601String()];

        return Step::waitTask(self::TASK, $outcome->reportId, $until);
    }

    private function conclude(array $node, Execution $ex, ?ReceiptOutcome $outcome): Step
    {
        $status = $outcome?->status;

        // La imagen no era un comprobante: lo normal es que se equivocó de
        // foto. Se le pide otra antes de rendirse.
        if ($status === PaymentReport::STATUS_NOT_RECEIPT) {
            $ex->set('pago.estado', 'no_valido');

            return $this->retry($node, $ex, self::DEFAULT_NOT_RECEIPT);
        }

        $ex->resetRetries($node['id']);
        $ex->set('pago.valor', $outcome?->amount !== null ? '$' . number_format($outcome->amount, 0, ',', '.') : '');
        $ex->set('pago.fecha', $outcome?->date ?? '');
        $ex->set('pago.referencia', $outcome?->reference ?? '');
        $ex->set('pago.medio', $outcome?->channel ?? '');
        $ex->set('pago.servicio', $outcome?->service ?? '');
        $ex->set('pago.estado', match ($status) {
            PaymentReport::STATUS_REGISTERED => 'registrado',
            PaymentReport::STATUS_DUPLICATE  => 'repetido',
            default                          => 'en_revision',
        });

        $registered = $status === PaymentReport::STATUS_REGISTERED;
        $ex->note(detail: array_filter(['resultado' => $status, 'motivo' => $outcome?->reason]));

        return Step::next($registered && in_array('registered', $this->outputs($node['data']), true) ? 'registered' : 'review');
    }

    private function retry(array $node, Execution $ex, string $default): Step
    {
        $maxRetries = max(0, min(self::MAX_RETRIES, $this->int($node['data'], 'max_retries', 1)));

        if ($ex->bumpRetries($node['id']) > $maxRetries) {
            $ex->resetRetries($node['id']);
            $ex->set('pago.estado', 'no_valido');
            $ex->note(detail: ['sin_comprobante' => true]);

            return Step::next('invalid');
        }

        $text = trim($ex->render($this->str($node['data'], 'retry_text'))) ?: $default;

        return $this->afterSend($ex, $ex->io->sendText($text), $text) ?? Step::waitInput();
    }
}
