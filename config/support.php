<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Avisos al ISP sobre sus tickets (CON-57)
    |--------------------------------------------------------------------------
    |
    | Los mandamos NOSOTROS, desde el número de Converza (el tenant `default`),
    | al admin del ISP. No tienen nada que ver con los avisos que cada ISP le
    | manda a sus propios suscriptores: esos viven en EventCatalog y salen del
    | número del ISP.
    |
    | Fuera de la ventana de 24 h de Meta, texto libre no se entrega: hace falta
    | plantilla aprobada. Un acuse de ticket casi nunca cae dentro de la ventana,
    | así que sin plantilla configurada el aviso se registra como `skipped` y no
    | se intenta — antes que mandar algo que Meta va a rechazar en silencio,
    | gastando quality rating del número.
    |
    | Las plantillas esperan DOS variables nombradas: {{numero}} y {{asunto}}.
    |
    */

    'notify' => [
        /*
        | A dónde nos llega el aviso cuando un ISP abre un ticket por el portal.
        |
        | Va por correo y no por WhatsApp: el aviso al cliente tropieza con la
        | ventana de 24 h y con las plantillas de Meta, y para avisarnos a nosotros
        | mismos nada de eso hace falta.
        |
        | Ojo: necesita un transporte de correo de verdad (MAIL_MAILER=smtp y sus
        | credenciales). Con el mailer en `log` el aviso se registra como omitido
        | en vez de fingir que salió.
        */
        'internal_email' => env('SUPPORT_INTERNAL_EMAIL', 'ispwatchcol@gmail.com'),

        'language' => env('SUPPORT_NOTIFY_LANG', 'es_CO'),

        'templates' => [
            'acuse'     => env('SUPPORT_NOTIFY_TPL_ACUSE'),
            'respuesta' => env('SUPPORT_NOTIFY_TPL_RESPUESTA'),
            'resuelto'  => env('SUPPORT_NOTIFY_TPL_RESUELTO'),
        ],
    ],

];
