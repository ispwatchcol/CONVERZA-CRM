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

    // Categoría de plantilla EXIGIDA para campañas masivas. Meta requiere
    // MARKETING (publicidad/ventas) para envío promocional en frío; usar utility
    // o authentication para esto viola la política y arriesga el número. En la BD
    // la categoría se guarda en minúscula (ver TemplateController), por eso 'marketing'.
    'template_category' => env('CAMPAIGNS_TEMPLATE_CATEGORY', 'marketing'),

    // Defaults del "calentamiento" (warm-up) del número: una rampa de volumen
    // diario que sube de a poco para no quemar el quality rating. Estos son solo
    // los valores con que se inicializa cada tenant; cada uno ajusta los suyos
    // desde la UI (CampaignWarmup). Ver App\Services\Campaigns\WarmupBudget.
    //
    // OJO: 'enabled' arranca en false a propósito (opt-in). Encenderlo aquí por
    // defecto caparía de golpe las campañas de TODOS los tenants existentes.
    'warmup' => [
        'enabled' => (bool) env('CAMPAIGNS_WARMUP_ENABLED', false),
        'start_per_day' => (int) env('CAMPAIGNS_WARMUP_START_PER_DAY', 50),
        'daily_increment' => (int) env('CAMPAIGNS_WARMUP_DAILY_INCREMENT', 50),
        'max_per_day' => (int) env('CAMPAIGNS_WARMUP_MAX_PER_DAY', 1000),
    ],

];
