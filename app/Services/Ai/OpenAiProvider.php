<?php

namespace App\Services\Ai;

/**
 * OpenAI. Misma Responses API que xAI, con dos diferencias:
 *
 *   - No informa el costo de la llamada: AiGateway lo estima con los precios de
 *     config/ai.php (queda marcado `cost_estimated`). Si OpenAI cambia sus
 *     precios hay que actualizarlos ahí, o el tope mensual mide mal.
 *   - Su modo estricto de JSON schema rechaza con 400 algunas palabras clave
 *     (los límites de tamaño de una lista). Se quitan antes de enviar; el
 *     tamaño igual se acota al sanear la lectura (ReceiptReading).
 */
final class OpenAiProvider extends ResponsesApiProvider
{
    private const UNSUPPORTED_KEYWORDS = ['maxItems', 'minItems'];

    public function name(): string
    {
        return 'openai';
    }

    protected function label(): string
    {
        return 'OpenAI';
    }

    protected function schema(array $schema): array
    {
        foreach ($schema as $key => $value) {
            if (in_array($key, self::UNSUPPORTED_KEYWORDS, true)) {
                unset($schema[$key]);
            } elseif (is_array($value)) {
                $schema[$key] = $this->schema($value);
            }
        }

        return $schema;
    }
}
