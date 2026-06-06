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
    ],
];
