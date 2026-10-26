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

    /** @throws AiProviderException */
    public function respond(AiRequest $request): AiResponse;
}
