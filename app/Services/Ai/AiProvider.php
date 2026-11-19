<?php

namespace App\Services\Ai;

/**
 * Un proveedor de IA. Las funciones de Converza no lo usan directo: pasan por
 * AiGateway, que aplica los interruptores, el presupuesto y la bitácora.
 */
interface AiProvider
{
    public function name(): string;

    /** ¿Tiene lo mínimo para llamar (llave, URL)? */
    public function isConfigured(): bool;

    /** El modelo que usa si la petición no pide otro. */
    public function model(): ?string;

    /** @throws AiProviderException */
    public function respond(AiRequest $request): AiResponse;

    /**
     * Los modelos de texto que la llave puede usar, según la API del proveedor.
     * Consultarlos no cuesta: sirve también para probar la llave.
     *
     * @return list<array{id: string, label: string}>
     *
     * @throws AiProviderException
     */
    public function models(): array;
}
