<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cobro automático del SaaS a los ISP (Core Brain)
    |--------------------------------------------------------------------------
    |
    | El Brain factura solo cada mes a las cuentas que lo tengan encendido, le
    | avisa al ISP por WhatsApp desde el número de Converza (tenant `default`) y
    | le corta el panel si la factura vence sin pagar. Ver docs/core-brain.md §8.
    |
    */

    'billing' => [
        // Zona horaria de las fechas de cobro. El servidor corre en UTC: sin esto,
        // la factura "del 1" saldría a las 7 p. m. del 30 en Colombia.
        'timezone' => env('BRAIN_BILLING_TZ', 'America/Bogota'),

        // Cómo se paga. Va al final de cada aviso que sale como texto libre
        // (dentro de la ventana de 24 h) y en la pantalla de servicio suspendido.
        // Las plantillas de Meta llevan su propio texto fijo: hay que escribir
        // ahí estos mismos datos al crearlas.
        'payment_instructions' => env('BRAIN_BILLING_PAY_INSTRUCTIONS', ''),

        'language' => env('BRAIN_BILLING_NOTIFY_LANG', 'es_CO'),

        /*
        | Plantillas para avisar FUERA de la ventana de 24 h. Sin plantilla, el
        | aviso se registra como `skipped` y no se intenta: Meta acepta el texto
        | libre con 200 y lo rechaza después, gastando quality rating del número.
        |
        | Variables nombradas que recibe cada una (tienen que coincidir EXACTO con
        | las de la plantilla aprobada, o Meta la rechaza con #132000):
        |
        |   factura       {{cliente}} {{numero}} {{total}} {{vence}}
        |   recordatorio  {{cliente}} {{numero}} {{total}} {{vence}}
        |   suspension    {{cliente}} {{total}}
        |   reactivacion  {{cliente}}
        */
        'templates' => [
            'factura'      => env('BRAIN_BILLING_TPL_FACTURA'),
            'recordatorio' => env('BRAIN_BILLING_TPL_RECORDATORIO'),
            'suspension'   => env('BRAIN_BILLING_TPL_SUSPENSION'),
            'reactivacion' => env('BRAIN_BILLING_TPL_REACTIVACION'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Estado del servicio para ispwatch
    |--------------------------------------------------------------------------
    |
    | Converza lee ispwatch en solo lectura, así que no puede cortarle el panel
    | a un ISP allá. Lo que hace es publicar el estado: ispwatch pregunta
    | `GET /saas/estado/{ispwatch_tenant_id}` con este token y decide qué
    | mostrar. Sin token configurado la ruta responde 404.
    |
    */

    'status_api_token' => env('BRAIN_SAAS_STATUS_TOKEN'),

];
