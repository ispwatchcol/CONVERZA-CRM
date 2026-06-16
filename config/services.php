<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'whatsapp' => [
        'url' => env('WHATSAPP_API_URL', ''),
        'token' => env('WHATSAPP_API_TOKEN', ''),
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN', 'ispwatch-token'),
        'forward_url' => env('WEBHOOK_FORWARD_URL'),
        'graph_version' => env('WHATSAPP_GRAPH_VERSION', 'v20.0'),
        // Nombre de la plantilla aprobada en Meta usada para recordatorios de pago.
        'reminder_template' => env('WHATSAPP_REMINDER_TEMPLATE', 'payment_reminder'),
        // Interruptor maestro del envío automático masivo (whatsapp:billing-notify).
        // Default false: la tarea agendada NO envía nada hasta activarlo. Las pruebas
        // dirigidas (--customer) y los --dry-run siguen funcionando con esto en false.
        'billing_notify_enabled' => env('WHATSAPP_BILLING_NOTIFY_ENABLED', false),
        // Zona horaria en la que el admin del ISP escribe los días/horas de
        // ispwatch (billing.create_invoice_time / payment_reminder_time son
        // `time without time zone`, hora de pared local). La app corre en UTC,
        // así que el comando convierte "ahora" a esta zona antes de comparar.
        // Cámbiala por env si el ISP no es de Colombia.
        'billing_notify_timezone' => env('WHATSAPP_BILLING_NOTIFY_TIMEZONE', 'America/Bogota'),
        // Días de catch-up: tras el día agendado, el aviso se reintenta durante N
        // días más (acotado a fin de mes) para recuperar caídas prolongadas
        // (WhatsApp/ispwatch/servidor caído un día completo). Default 2 ≈ hasta 48h.
        // 0 = solo el día agendado (comportamiento anterior). La idempotencia por
        // ciclo impide reenviar a quien ya recibió, sin importar la fecha.
        'billing_notify_catchup_days' => (int) env('WHATSAPP_BILLING_NOTIFY_CATCHUP_DAYS', 2),
        // Pacing del envío masivo: máximo de avisos a ENVIAR por tenant en cada
        // corrida (la tarea corre cada minuto), para no salir en ráfaga y proteger
        // la calidad/tier del número en Meta. Con 40 y la idempotencia + catch-up,
        // 200 avisos se drenan solos en ~5 min sin perder a nadie. 0 = sin tope
        // (comportamiento antiguo de ráfaga). El flag --limit lo sobreescribe.
        'billing_notify_per_minute' => (int) env('WHATSAPP_BILLING_NOTIFY_PER_MINUTE', 40),
        // Micro-espaciado en milisegundos entre cada envío real dentro de una
        // corrida, para que ni siquiera el lote de un minuto salga en el mismo
        // segundo. 250ms × 40 ≈ 10s, holgado dentro del lock withoutOverlapping(10).
        // 0 = sin pausa entre mensajes.
        'billing_notify_gap_ms' => (int) env('WHATSAPP_BILLING_NOTIFY_GAP_MS', 250),
    ],
];
