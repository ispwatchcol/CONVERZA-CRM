<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ── Limpieza automática de medios ───────────────────────────────────────
// Ejecuta media:clean cada domingo a las 3am para liberar espacio en disco.
// Configurar en .env: MEDIA_CLEANUP_DAYS=90 (default)
Schedule::command('media:clean')->weekly()->sundays()->at('03:00');
