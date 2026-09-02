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

// ── Avisos por EVENTO de ispwatch (bienvenida + pago registrado) ──────────
// Corre cada MINUTO. Polling incremental (marca de agua por tenant+evento en
// ispwatch_event_cursors) sobre user_services / payments: ispwatch es solo
// lectura y no hay webhooks. En reposo el tic es barato: un MAX(id) por
// tenant/evento activo y corta si no hay nada nuevo. Apagado por defecto: solo
// procesa eventos con ruta activa (Settings → Avisos). Salvaguardas anti-baneo:
// cold-start (siembra el cursor sin enviar histórico), ventana de frescura,
// supresión de carga masiva por densidad (solo bienvenida a altas manuales),
// idempotencia por (tenant,kind,ref) y pacing (tope + micro-espaciado).
Schedule::command('whatsapp:events-notify')
    ->everyMinute()
    ->withoutOverlapping(10)
    ->onOneServer();

// ── Campañas masivas de WhatsApp ──────────────────────────────────────────
// Corre cada minuto: promueve campañas agendadas cuya hora llegó y despacha
// el siguiente lote (throttle_per_minute) de cada campaña en envío. Barato
// cuando no hay campañas activas (un par de queries cortas). withoutOverlapping
// corto porque cada tick solo encola, no envía sincrónicamente.
Schedule::command('campaigns:tick')
    ->everyMinute()
    ->withoutOverlapping(2)
    ->onOneServer();

// ── Cierre automático de chats sin respuesta del cliente ──────────────────
// Cada 5 minutos: cierra los chats abiertos donde el equipo ya respondió y el
// cliente no volvió a escribir en N horas (default 2). Opt-in por tenant
// (Configuración → Cierre automático); si nadie lo activó, el tic es una sola
// consulta a `tenants` y sale. La granularidad de 5 min es suficiente: el
// umbral se mide en horas y evita 288 corridas diarias de más.
Schedule::command('chats:auto-close')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();

// ── Reconciliación de mensajes entrantes ──────────────────────────────────
// Cada 5 minutos compara el log crudo de webhooks contra la tabla `messages` y
// reprocesa lo que llegó al servidor pero no acabó guardado. Es la red que
// convierte `webhooks:replay` (manual, hay que acordarse) en automático: tras
// una caída de base, los mensajes entran solos cuando el sistema se recupera.
//
// Sin `--minutos`: la ventana arranca en la marca de agua que dejó la última
// corrida con éxito, así que se estira sola tanto como haya durado el corte
// (hasta el tope de --max-horas, 24 por defecto). Antes era una ventana fija de
// 60 min y un corte más largo dejaba un hueco permanente y silencioso: al
// recuperarse, la ventana ya no alcanzaba el principio del corte. Ver el
// comentario de cabecera de ReconcileWebhooks para el caso que lo destapó.
//
// Barato cuando no hay nada que hacer: lee el log de la ventana y hace UNA
// consulta con whereIn para todos los ids.
Schedule::command('webhooks:reconcile')
    ->everyFiveMinutes()
    ->withoutOverlapping(10)
    ->onOneServer();
