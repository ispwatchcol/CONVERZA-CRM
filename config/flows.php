<?php

// Workspace de flujos del bot (CON-36). Ver docs/workflows-bot.md.
return [

    // Cola donde corren los jobs del motor (RunBotFlow).
    //
    // Arranca en 'default' A PROPÓSITO: ningún bloque de la v1 llama a un tercero
    // —todo es la base propia, ispwatch (cacheado) o WhatsAppService, lo mismo que
    // ya hacía el bot cableado en esa cola—. La cola propia ('flows') la exige el
    // nodo HTTP (CON-50), que sí mete una dependencia externa dentro del job.
    //
    // Cambiarla a 'flows' SIN agregar el programa de Supervisor que la escuche
    // deja a todos los flujos mudos, en silencio. Ver deploy/supervisor.
    'queue' => env('FLOWS_QUEUE', 'default'),

    // Tope de bloques que un flujo puede ejecutar por cada evento (un mensaje
    // del cliente, el arranque o el fin de una espera). El validador ya impide
    // publicar un ciclo que no espere al cliente; esto es la red por si algo se
    // le escapa: corta el bucle, lo registra y entrega la conversación al equipo
    // en vez de ocupar un worker (hay cuatro).
    'max_steps_per_event' => (int) env('FLOWS_MAX_STEPS_PER_EVENT', 25),

    // Tope de bloques en toda la vida de una ejecución.
    'max_steps_per_run' => (int) env('FLOWS_MAX_STEPS_PER_RUN', 200),

    // Una pregunta sin respuesta caduca a las N horas: la ejecución termina sin
    // mandar nada y el siguiente mensaje del cliente se trata como uno nuevo.
    // Retomar una pregunta de hace tres días confunde más de lo que ayuda.
    'input_timeout_hours' => (int) env('FLOWS_INPUT_TIMEOUT_HOURS', 24),

    // Espera máxima acumulada entre dos mensajes del cliente. La ventana de
    // servicio de WhatsApp dura 24 h desde el último mensaje DEL CLIENTE; con una
    // hora de margen, ningún flujo publicado puede quedar enviando texto libre
    // con la ventana cerrada (Meta lo aceptaría con 200 y lo rechazaría después).
    'max_wait_minutes' => 23 * 60,

    // Límite de bloques por flujo: protege el editor y el validador.
    'max_nodes' => 150,

    // Zona horaria por defecto de los flujos nuevos (condiciones de horario y
    // el saludo según la hora). La app corre en UTC.
    'default_timezone' => env('FLOWS_DEFAULT_TIMEZONE', 'America/Bogota'),
];
