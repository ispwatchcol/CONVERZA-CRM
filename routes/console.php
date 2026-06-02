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

// ── Avisos automáticos por WhatsApp (factura generada + recordatorio) ─────
// Corre a diario a las 9am. Dispara los avisos según las fechas que cada
// router define en ispwatch (billing.create_invoice / billing.payment_reminder),
// solo para routers con notificar_wpp activo. Es idempotente y con catch-up:
// si un día falla, reintenta los siguientes del mismo ciclo sin duplicar.
//
// Reemplaza al antiguo `reminders:send` (basado en vencimiento + notification_type),
// que queda en el repo sin agendar.
Schedule::command('whatsapp:billing-notify')
    ->dailyAt('09:00')
    ->withoutOverlapping()
    ->onOneServer();
