<?php

// Inteligencia artificial en Converza: lectura de comprobantes de pago (Fase 1)
// y el agente conversacional (bloque «Agente IA»). Ver docs/agente-ia.md.
return [

    // Interruptor general. En false, NINGUNA función llama al proveedor aunque
    // la empresa tenga la IA encendida: los comprobantes llegan igual a «Pagos
    // reportados», sin leer, para que alguien los revise a mano. Es lo primero
    // que se apaga si la IA se porta mal; no hace falta desplegar nada.
    'enabled' => (bool) env('AI_ENABLED', false),

    // Proveedores. NO hay llave de plataforma: cada empresa carga la SUYA en
    // Configuración (tenants.ai_api_key, cifrada), elige su proveedor
    // (tenants.ai_provider) y el modelo (tenants.ai_model, de la lista que le da
    // la API del proveedor con su llave). Aquí van, por proveedor:
    //
    //   label     nombre en pantalla
    //   api       cómo se le habla: 'responses' (Responses API: OpenAI, xAI),
    //             'anthropic' (Messages API de Claude) o 'chat' (Chat Completions,
    //             el estándar «compatible con OpenAI» de casi todos los demás)
    //   base_url  la URL de la API ('custom' la pone la empresa: tenants.ai_base_url)
    //   model     el modelo por defecto si la empresa no eligió uno
    //   vision    ¿el modelo por defecto lee imágenes? (comprobantes)
    //   key_url / key_placeholder  ayuda para encontrar y pegar la llave
    //   price_*   USD por millón de tokens, para ESTIMAR el gasto cuando el
    //             proveedor no informa el costo. Son los del modelo por defecto:
    //             con otro modelo, el gasto de Configuración es aproximado.
    //
    // Las funciones hablan con AiGateway, no con el proveedor.
    'providers' => ['openai', 'xai', 'anthropic', 'gemini', 'deepseek', 'groq', 'openrouter', 'mistral', 'custom'],

    // Cola de las tareas de IA. NO es 'default' a propósito: leer una imagen
    // tarda segundos y no puede quedar delante de la ingesta de mensajes ni de
    // los envíos. Exige su propio programa de Supervisor (converza-ai, en
    // deploy/supervisor/converza-ai.conf): sin él las lecturas no corren y
    // `flows:tick` manda a revisión humana lo que lleve más de
    // `receipts.task_timeout_minutes` esperando.
    'queue' => env('AI_QUEUE', 'ai'),

    // Tope de gasto por empresa y mes calendario (hora de Colombia), en USD,
    // cuando la empresa no se puso uno (tenants.ai_monthly_budget_usd). 0 = sin
    // tope: el gasto es de la empresa (su llave), no de Converza. Alcanzado un
    // tope, los comprobantes quedan para revisión humana.
    'default_monthly_budget_usd' => (float) env('AI_DEFAULT_MONTHLY_BUDGET_USD', 0),

    'xai' => [
        'label'    => 'xAI (Grok)',
        'api'      => 'responses',
        'vision'   => true,
        'key_url'  => 'console.x.ai → API keys',
        'key_placeholder' => 'xai-…',
        'base_url' => env('XAI_BASE_URL', 'https://api.x.ai/v1'),
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

    'openai' => [
        'label'    => 'OpenAI (ChatGPT)',
        'api'      => 'responses',
        'vision'   => true,
        'key_url'  => 'platform.openai.com → API keys',
        'key_placeholder' => 'sk-proj-…',
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        // gpt-5-mini: lee imágenes y soporta JSON schema estricto (nov/2026).
        'model'    => env('OPENAI_MODEL', 'gpt-5-mini'),
        // Igual que con Grok: transcribir no necesita razonar a fondo. Vacío =
        // no se envía (para un modelo que no razone y rechace el parámetro).
        'reasoning_effort' => env('OPENAI_REASONING_EFFORT', 'low'),
        'timeout'  => (int) env('OPENAI_TIMEOUT', 60),
        // USD por millón de tokens. OpenAI NO informa el costo de cada llamada,
        // así que el gasto del mes (y el tope) sale SIEMPRE de estos dos
        // números: si cambian de modelo o de precios, actualizarlos.
        'price_input_per_million'  => (float) env('OPENAI_PRICE_INPUT', 0.25),
        'price_output_per_million' => (float) env('OPENAI_PRICE_OUTPUT', 2.00),
    ],

    // Claude. Messages API propia (no la de OpenAI): llave en `x-api-key` y la
    // versión de la API en `anthropic-version`. No informa el costo: se estima.
    'anthropic' => [
        'label'    => 'Anthropic (Claude)',
        'api'      => 'anthropic',
        'vision'   => true,
        'key_url'  => 'console.anthropic.com → API Keys',
        'key_placeholder' => 'sk-ant-…',
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'version'  => env('ANTHROPIC_VERSION', '2026-10-21'),
        'model'    => env('ANTHROPIC_MODEL', 'claude-opus-5-5'),
        // output_config.effort. Los modelos viejos que no lo aceptan responden
        // 400 y el proveedor reintenta sin él.
        'reasoning_effort' => env('ANTHROPIC_REASONING_EFFORT', 'low'),
        'timeout'  => (int) env('ANTHROPIC_TIMEOUT', 60),
        'price_input_per_million'  => (float) env('ANTHROPIC_PRICE_INPUT', 4.00),
        'price_output_per_million' => (float) env('ANTHROPIC_PRICE_OUTPUT', 20.00),
    ],

    // Los demás hablan Chat Completions («compatible con OpenAI»).
    'gemini' => [
        'label'    => 'Google (Gemini)',
        'api'      => 'chat',
        'vision'   => true,
        'key_url'  => 'aistudio.google.com → Get API key',
        'key_placeholder' => 'AIza…',
        'base_url' => env('GEMINI_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta/openai'),
        'model'    => env('GEMINI_MODEL', 'gemini-2.5-flash'),
        'reasoning_effort' => env('GEMINI_REASONING_EFFORT', 'low'),
        'timeout'  => (int) env('GEMINI_TIMEOUT', 60),
        'price_input_per_million'  => (float) env('GEMINI_PRICE_INPUT', 0.30),
        'price_output_per_million' => (float) env('GEMINI_PRICE_OUTPUT', 2.50),
    ],

    'deepseek' => [
        'label'    => 'DeepSeek',
        'api'      => 'chat',
        // deepseek-chat no lee imágenes: sirve para el agente, no para comprobantes.
        'vision'   => false,
        'key_url'  => 'platform.deepseek.com → API keys',
        'key_placeholder' => 'sk-…',
        'base_url' => env('DEEPSEEK_BASE_URL', 'https://api.deepseek.com/v1'),
        'model'    => env('DEEPSEEK_MODEL', 'deepseek-chat'),
        'reasoning_effort' => '',
        'timeout'  => (int) env('DEEPSEEK_TIMEOUT', 60),
        'price_input_per_million'  => (float) env('DEEPSEEK_PRICE_INPUT', 0.28),
        'price_output_per_million' => (float) env('DEEPSEEK_PRICE_OUTPUT', 0.42),
    ],

    'groq' => [
        'label'    => 'Groq',
        'api'      => 'chat',
        'vision'   => false,
        'key_url'  => 'console.groq.com → API Keys',
        'key_placeholder' => 'gsk_…',
        'base_url' => env('GROQ_BASE_URL', 'https://api.groq.com/openai/v1'),
        'model'    => env('GROQ_MODEL', 'llama-3.3-70b-versatile'),
        'reasoning_effort' => '',
        'timeout'  => (int) env('GROQ_TIMEOUT', 60),
        'price_input_per_million'  => (float) env('GROQ_PRICE_INPUT', 0.59),
        'price_output_per_million' => (float) env('GROQ_PRICE_OUTPUT', 0.79),
    ],

    // Un intermediario con cientos de modelos de todos los proveedores, una sola llave.
    'openrouter' => [
        'label'    => 'OpenRouter (muchos modelos)',
        'api'      => 'chat',
        'vision'   => true,
        'key_url'  => 'openrouter.ai → Keys',
        'key_placeholder' => 'sk-or-…',
        'base_url' => env('OPENROUTER_BASE_URL', 'https://openrouter.ai/api/v1'),
        'model'    => env('OPENROUTER_MODEL', 'openai/gpt-5-mini'),
        'reasoning_effort' => '',
        'timeout'  => (int) env('OPENROUTER_TIMEOUT', 60),
        'price_input_per_million'  => (float) env('OPENROUTER_PRICE_INPUT', 0.25),
        'price_output_per_million' => (float) env('OPENROUTER_PRICE_OUTPUT', 2.00),
    ],

    'mistral' => [
        'label'    => 'Mistral',
        'api'      => 'chat',
        'vision'   => true,
        'key_url'  => 'console.mistral.ai → API Keys',
        'key_placeholder' => '',
        'base_url' => env('MISTRAL_BASE_URL', 'https://api.mistral.ai/v1'),
        'model'    => env('MISTRAL_MODEL', 'mistral-small-latest'),
        'reasoning_effort' => '',
        'timeout'  => (int) env('MISTRAL_TIMEOUT', 60),
        'price_input_per_million'  => (float) env('MISTRAL_PRICE_INPUT', 0.10),
        'price_output_per_million' => (float) env('MISTRAL_PRICE_OUTPUT', 0.30),
    ],

    // Cualquier otra API compatible con OpenAI (un Ollama publicado, Together,
    // Fireworks, Azure…): la empresa pone la URL (tenants.ai_base_url) y el
    // modelo. La URL tiene que ser https y resolver a una IP pública: si no, la
    // empresa podría hacer que el servidor le pegue a la red interna (SSRF).
    'custom' => [
        'label'    => 'Otro compatible con OpenAI',
        'api'      => 'chat',
        'vision'   => true,
        'key_url'  => 'la consola de tu proveedor',
        'key_placeholder' => '',
        'base_url' => null,
        'model'    => null,
        'reasoning_effort' => '',
        'timeout'  => (int) env('CUSTOM_AI_TIMEOUT', 60),
        'price_input_per_million'  => 0.0,
        'price_output_per_million' => 0.0,
    ],

    // Agente IA (bloque «Agente IA» del Workspace y pantalla «Agentes IA»).
    'agents' => [
        // Cuántos mensajes de la conversación con el agente viajan en cada
        // llamada (los más recientes). Más = más contexto y más tokens.
        'history_messages' => (int) env('AI_AGENT_HISTORY', 20),

        // Tope de respuestas del agente por pasada por el bloque, si el bloque no
        // fija otro. Alcanzado, la conversación pasa a un asesor.
        'default_max_turns' => (int) env('AI_AGENT_MAX_TURNS', 10),
        'max_turns_limit'   => 30,

        // Si la respuesta no llega en este tiempo (cola caída, proveedor colgado),
        // el bloque sale por «IA no disponible».
        'task_timeout_minutes' => (int) env('AI_AGENT_TASK_TIMEOUT', 3),

        // Tokens de salida por respuesta. Los modelos que razonan gastan parte en
        // pensar: muy poco y la respuesta se corta.
        'max_output_tokens' => (int) env('AI_AGENT_MAX_OUTPUT_TOKENS', 2000),

        // Largo máximo del prompt que escribe la empresa.
        'max_instructions_chars' => 12000,
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
