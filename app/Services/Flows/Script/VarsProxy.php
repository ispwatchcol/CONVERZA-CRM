<?php

namespace App\Services\Flows\Script;

/**
 * `vars`: las variables del flujo. Leer `vars.nombre` o `vars["cliente.saldo"]`
 * le pregunta al flujo; escribir `vars.total = 5` deja la variable para los
 * bloques siguientes. Con prefijo es un grupo (`vars.cliente.saldo`) y es de
 * solo lectura.
 */
final class VarsProxy
{
    public function __construct(public readonly string $prefix = '') {}
}
