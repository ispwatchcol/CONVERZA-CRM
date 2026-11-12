<?php

// Inteligencia artificial en Converza: lectura de comprobantes de pago (Fase 1)
// y, más adelante, el agente conversacional. Ver docs/agente-ia.md.
return [

    // Interruptor general. En false, NINGUNA función llama al proveedor aunque
    // la empresa tenga la IA encendida: los comprobantes llegan igual a «Pagos
    // reportados», sin leer, para que alguien los revise a mano. Es lo primero
    // que se apaga si la IA se porta mal; no hace falta desplegar nada.
    'enabled' => (bool) env('AI_ENABLED', false),

    // Proveedor. Hoy solo 'xai' (Grok). Las funciones hablan con AiGateway, no
    // con el proveedor, así que cambiarlo no las toca.
    'provider' => env('AI_PROVIDER', 'xai'),

    // Cola de las tareas de IA. NO es 'default' a propósito: leer una imagen
    // tarda segundos y no puede quedar delante de la ingesta de mensajes ni de
    // los envíos. Exige su propio programa de Supervisor (converza-ai, en
    // deploy/supervisor/converza-ai.conf): sin él las lecturas no corren y
    // `flows:tick` manda a revisión humana lo que lleve más de
    // `receipts.task_timeout_minutes` esperando.
    'queue' => env('AI_QUEUE', 'ai'),

    // Tope de gasto por empresa y mes calendario (hora de Colombia), en USD,
    // cuando la empresa no tiene uno propio (tenants.ai_monthly_budget_usd).
    // Alcanzado el tope, los comprobantes quedan para revisión humana.
    'default_monthly_budget_usd' => (float) env('AI_DEFAULT_MONTHLY_BUDGET_USD', 5),

    'xai' => [
        'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
        'api_key'  => env('XAI_API_KEY'),
        // grok-4.3: lee imágenes, soporta salida con JSON schema estricto y es
        // el más barato de los que hacen las dos cosas (oct/2026).
        'model'    => env('XAI_MODEL', 'grok-4.3'),
        // Los modelos de Grok razonan siempre y por defecto a fondo ("high").
        // Para transcribir un comprobante sobra con 'low': menos espera para el
        // cliente y menos tokens. Si el modelo elegido rechaza el parámetro
        // (error 400 en ai_usage_logs), dejar la variable vacía para no enviarlo.
        'reasoning_effort' => env('XAI_REASONING_EFFORT', 'low'),
        'timeout'  => (int) env('XAI_TIMEOUT', 60),
        // USD por millón de tokens. Solo para estimar el costo cuando la
        // respuesta no trae el exacto (`usage.cost_in_usd_ticks`).
        'price_input_per_million'  => (float) env('XAI_PRICE_INPUT', 1.25),
        'price_output_per_million' => (float) env('XAI_PRICE_OUTPUT', 2.50),
    ],

    'receipts' => [
        // Por debajo de esta confianza la lectura no cuenta como comprobante.
        'min_confidence' => (float) env('AI_RECEIPT_MIN_CONFIDENCE', 0.8),

        // Un comprobante más viejo que esto no se registra solo: reenviar uno
        // viejo es la forma más simple de cobrar dos veces el mismo pago.
        'max_age_days' => (int) env('AI_RECEIPT_MAX_AGE_DAYS', 7),

        // xAI acepta hasta 20 MiB; WhatsApp ya comprime las fotos a ~1200 px.
        'max_image_bytes' => 10 * 1024 * 1024,

        // El bloque «Comprobante de pago» usa sin volver a pedirla una imagen
        // que el cliente haya mandado en estos últimos minutos (lo normal es
        // que abra el chat mandando la foto).
        'recent_image_minutes' => 30,

        // Si la tarea de un flujo no contesta en este tiempo (cola caída,
        // proveedor colgado), el comprobante pasa a revisión humana y el flujo
        // sigue por «Para revisión».
        'task_timeout_minutes' => (int) env('AI_RECEIPT_TASK_TIMEOUT', 5),

        // Registro automático (sin revisión): tope por comprobante cuando la
        // empresa no fijó uno (tenants.receipts_auto_max_amount)…
        'default_auto_max_amount' => (float) env('AI_RECEIPT_AUTO_MAX', 300000),

        // …y cuántos pagos automáticos se le aceptan a un mismo cliente por día.
        'max_auto_per_customer_per_day' => (int) env('AI_RECEIPT_AUTO_PER_DAY', 2),
    ],
];
