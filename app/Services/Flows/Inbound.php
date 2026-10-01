<?php

namespace App\Services\Flows;

/**
 * Un mensaje del cliente, tal como lo ve un bloque que espera respuesta.
 *
 * `$replyId` viene cuando el cliente tocó un botón o eligió de una lista: es
 * el id que le pusimos a la opción al enviarla, y gana sobre el texto porque
 * el texto visible puede repetirse entre opciones o cambiar entre versiones.
 */
final class Inbound
{
    public function __construct(
        public readonly string $text,
        public readonly ?string $replyId = null,
        public readonly ?int $messageId = null,
    ) {}
}
