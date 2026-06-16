<?php

return [

    // Cuántos mensajes por minuto despacha campaigns:tick por defecto cuando el
    // usuario no especifica un throttle propio al crear la campaña. Conservador
    // a propósito: protege el quality rating del número en cuentas nuevas/sin
    // verificar (tier 250/24h de Meta). El usuario puede subirlo en el wizard.
    'default_throttle_per_minute' => (int) env('CAMPAIGNS_DEFAULT_THROTTLE_PER_MINUTE', 20),

    // Umbrales de los messaging tiers de WhatsApp Cloud API (destinatarios
    // ÚNICOS por 24h). Se usan solo para advertir en la UI antes de lanzar,
    // no se hace cumplir automáticamente (Meta es quien decide el tier real).
    'tier_warn_thresholds' => [250, 1000, 10000, 100000],

    // Palabras clave (insensibles a mayúsculas/acentos) que, si un contacto las
    // envía, lo agregan a campaign_opt_outs y lo excluyen de futuras campañas.
    'opt_out_keywords' => array_filter(array_map(
        'trim',
        explode(',', env('CAMPAIGNS_OPT_OUT_KEYWORDS', 'STOP,BAJA,CANCELAR,NO MAS,NO MÁS,UNSUBSCRIBE')),
    )),

];
