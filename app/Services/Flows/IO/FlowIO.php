<?php

namespace App\Services\Flows\IO;

use App\Services\Flows\AgentOutcome;
use App\Services\Flows\CustomerLookup;
use App\Services\Flows\ReceiptOutcome;
use App\Services\Flows\SendResult;
use Carbon\CarbonImmutable;

/**
 * Todo lo que un bloque puede hacerle al mundo.
 *
 * Hay dos implementaciones y el motor no sabe cuál tiene enfrente:
 *
 *   - LiveFlowIO: la de verdad. Envía por WhatsAppService (nunca HTTP a Meta
 *     desde el motor), deja el mensaje en el chat, etiqueta, asigna.
 *   - SimulatedFlowIO: la del simulador del editor. No envía NADA a WhatsApp ni
 *     escribe en la base; anota lo que habría pasado. Probar un flujo
 *     escribiéndole a un número real quema calidad del número.
 */
interface FlowIO
{
    public function sendText(string $text): SendResult;

    /** @param list<array{id: string, title: string}> $buttons Hasta 3. */
    public function sendButtons(string $body, array $buttons): SendResult;

    /** @param list<array{id: string, title: string, description?: string}> $rows Hasta 10. */
    public function sendList(string $body, string $buttonLabel, array $rows): SendResult;

    /** @return array{name: ?string, phone: ?string} */
    public function contact(): array;

    public function tenantName(): string;

    public function now(): CarbonImmutable;

    public function contactHasLabel(int $labelId): bool;

    /** @return string|null Nombre de la etiqueta, o null si no existe en el tenant. */
    public function addLabel(int $labelId): ?string;

    /** @return string|null Nombre de la etiqueta, o null si no existe en el tenant. */
    public function removeLabel(int $labelId): ?string;

    /** @param 'name'|'email' $field */
    public function saveContactField(string $field, string $value): void;

    public function lookupCustomer(): CustomerLookup;

    /** {{empresa.medios_pago}}: los medios de pago que la empresa muestra en el bot. */
    public function paymentMethodsText(): string;

    /**
     * Una imagen (o PDF) que el cliente mandó hace poco y que todavía nadie
     * leyó como comprobante: el id del mensaje, o null.
     */
    public function recentReceiptImage(): ?int;

    /**
     * Crea el reporte del comprobante que trae el mensaje. Si la IA lo puede
     * leer vuelve `pending()`: el bloque espera la tarea (Step::waitTask).
     *
     * @param bool $autoRegister Registrar en ispwatch sin revisión si pasa todo.
     */
    public function startReceipt(int $messageId, bool $autoRegister): ReceiptOutcome;

    /**
     * En qué va un comprobante. Con `$giveUp`, si todavía se está leyendo pasa
     * a revisión humana (se venció el plazo de la tarea).
     */
    public function receipt(int $reportId, bool $giveUp = false): ?ReceiptOutcome;

    /**
     * Un agente IA de la empresa (Agentes IA), o null si no existe.
     *
     * @return array{id: int, name: string, instructions: string, model: ?string, active: bool}|null
     */
    public function agent(int $agentId): ?array;

    /**
     * Pide la siguiente respuesta del agente. Si la IA va a contestar en la cola
     * vuelve `pending()`: el bloque espera la tarea (Step::waitTask).
     *
     * @param string $instructions El prompt completo, ya armado (AgentResponder::instructions).
     * @param list<array{role: string, content: string}> $history
     */
    public function startAgentTurn(int $agentId, string $instructions, array $history, ?string $model): AgentOutcome;

    /** En qué va una respuesta. Con `$giveUp`, si sigue pendiente se da por fallida (plazo vencido). */
    public function agentTurn(int $turnId, bool $giveUp = false): ?AgentOutcome;

    /** El id del último mensaje que mandó el cliente, o null. */
    public function latestInboundId(): ?int;

    /**
     * Lo que el cliente escribió después de ese mensaje (mientras el agente
     * pensaba la respuesta anterior), en orden.
     *
     * @return list<array{id: int, text: string, type: string}>
     */
    public function inboundAfter(int $messageId): array;

    /**
     * Entrega la conversación al equipo.
     *
     * @param bool        $assign Usar la auto-asignación del tenant, si la tiene activa.
     * @param string|null $note   Nota interna para el asesor.
     * @return array<string, mixed> Qué se hizo, para la traza.
     */
    public function handoff(?int $teamId, bool $assign, ?string $note): array;
}
