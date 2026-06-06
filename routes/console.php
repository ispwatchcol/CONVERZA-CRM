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
// Corre cada MINUTO. Dispara los avisos según el DÍA y la HORA local que cada
// router define en ispwatch (billing.create_invoice[_time] / payment_reminder[_time]),
// solo para routers con notificar_wpp activo. Corre cada minuto (no 1×/día)
// para honrar la hora exacta configurada; cuando no hay nada a su hora el comando
// corta temprano sin tocar la lista de clientes, así el tic es barato. Es
// idempotente y con catch-up de N días (WHATSAPP_BILLING_NOTIFY_CATCHUP_DAYS,
// default 2): si una corrida falla —o el sistema estuvo caído un día entero—
// reintenta los minutos/días siguientes sin duplicar, recuperando avisos perdidos
// hasta N días (acotado a fin de mes; nunca cruza al ciclo siguiente). En días de
// catch-up una compuerta local evita el query pesado si ya no queda pendiente.
// withoutOverlapping(10): si una corrida larga (envío masivo) se cuelga, el lock
// expira en 10 min y no bloquea el resto del día.
//
// Reemplaza al antiguo `reminders:send` (basado en vencimiento + notification_type),
// que queda en el repo sin agendar.
Schedule::command('whatsapp:billing-notify')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();
