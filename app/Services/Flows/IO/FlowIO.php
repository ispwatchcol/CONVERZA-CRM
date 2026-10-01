<?php

namespace App\Services\Flows\IO;

use App\Services\Flows\CustomerLookup;
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

    /**
     * Entrega la conversación al equipo.
     *
     * @param bool        $assign Usar la auto-asignación del tenant, si la tiene activa.
     * @param string|null $note   Nota interna para el asesor.
     * @return array<string, mixed> Qué se hizo, para la traza.
     */
    public function handoff(?int $teamId, bool $assign, ?string $note): array;
}
