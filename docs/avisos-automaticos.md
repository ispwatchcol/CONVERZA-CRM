# Avisos automáticos

> **En una frase:** dos comandos que corren cada minuto leen ispwatch y envían
> plantillas de WhatsApp cuando corresponde, con idempotencia, catch-up y pacing
> anti-baneo.

**Archivos clave**
- [app/Console/Commands/SendBillingNotifications.php](../app/Console/Commands/SendBillingNotifications.php) — avisos por **fecha**
- [app/Console/Commands/SendEventNotifications.php](../app/Console/Commands/SendEventNotifications.php) — avisos por **evento**
- [app/Services/Notifications/EventCatalog.php](../app/Services/Notifications/EventCatalog.php) — catálogo y variables
- [app/Services/Templates/TemplateRenderer.php](../app/Services/Templates/TemplateRenderer.php) — render de la plantilla
- [config/services.php](../config/services.php) — toda la configuración

---

## 1. Los dos motores

| | `whatsapp:billing-notify` | `whatsapp:events-notify` |
|---|---|---|
| **Disparador** | Una **fecha y hora** del ciclo de facturación | Un **registro nuevo** en ispwatch |
| **Detección** | Comparar día/hora local con `billing.*` | Polling incremental por `MAX(id)` |
| **Eventos** | `invoice_created`, `payment_reminder`, `service_suspension` | `service_activated`, `payment_registered`, `router_outage_start`, `router_outage_resolved` |
| **Idempotencia** | Por **ciclo** (mes) | Por **referencia** del evento |
| **Frecuencia** | Cada minuto | Cada minuto |

Ambos comparten: el catálogo de eventos, el enrutamiento
(`tenant_notification_routes`), la bitácora (`billing_notification_logs`), el
interruptor maestro y las reglas de pacing.

---

## 2. Los siete eventos

Definidos en `EventCatalog::events()`. La **clave** del evento coincide con
`BillingNotificationLog::KIND_*`.

| Clave | Nombre | Cuándo |
|---|---|---|
| `invoice_created` | Factura generada | El día `billing.create_invoice`, a quien queda debiendo algo |
| `payment_reminder` | Recordatorio de pago | El día `billing.payment_reminder` |
| `service_suspension` | Aviso de suspensión | El día de corte (`cut_day`), a quien tenga saldo |
| `service_activated` | Bienvenida | Al activarse el servicio (solo altas **manuales**) |
| `payment_registered` | Pago registrado | Al registrarse un pago |
| `router_outage_start` | Falla masiva — inicio | Cuando ispwatch reporta caída de un core |
| `router_outage_resolved` | Falla masiva — restablecido | Cuando el core vuelve |
| `general` | *(no automático)* | Propósito libre para plantillas de envío manual |

`EventCatalog::autoKeys()` devuelve solo los automáticos (lo que muestra
*Settings → Avisos*); `general` queda fuera porque no se dispara solo.

---

## 3. Configuración por tenant

Un aviso sale **solo si se cumple todo esto**:

1. `services.whatsapp.billing_notify_enabled` = true (interruptor maestro global,
   `WHATSAPP_BILLING_NOTIFY_ENABLED`). **Default: false.**
2. `tenants.billing_notify_enabled` = true.
3. Existe una fila en `tenant_notification_routes` para ese evento con
   `enabled = true` y una `template_id` **aprobada**.
4. Para los avisos de facturación: el `billing` del router tiene
   `notification_type` en `whatsapp` o `both`.

> ⚠️ **`notification_type`, no `notificar_wpp`.** El canal lo gobierna
> `billing.notification_type` — el selector "Tipo de Aviso al Crear Facturas" del
> core de ispwatch. `notificar_wpp` es una columna **legada** que se acepta por
> compatibilidad. Filtrar solo por la vieja hizo que tenants bien configurados no
> recibieran nada.

---

## 4. `whatsapp:billing-notify` — avisos por fecha

### 4.1 Zona horaria

Las horas de ispwatch (`create_invoice_time`, `payment_reminder_time`) son
`time without time zone`: **hora de pared local** que escribió el admin del ISP.
La app corre en UTC.

El comando convierte "ahora" a `services.whatsapp.billing_notify_timezone`
(default `America/Bogota`) **antes** de comparar día y hora. Sin esa conversión,
todo saldría 5 horas tarde.

### 4.2 Por qué corre cada minuto

Para honrar la hora exacta configurada. Cuando no hay nada a su hora, el comando
**corta temprano** sin tocar la lista de clientes: el tic en reposo es barato.

### 4.3 Catch-up

`billing_notify_catchup_days` (default **2**). El aviso se dispara desde la hora
agendada del día agendado hasta el fin del día `agendado + N`, **acotado a fin de
mes** — nunca cruza al ciclo siguiente.

Si una corrida falla, o el sistema estuvo caído un día entero, los minutos y días
siguientes lo reintentan sin duplicar. En días de catch-up una compuerta local
barata evita el query pesado si ya no queda nada pendiente.

**La idempotencia está llaveada al MES, no a la fecha exacta.** Cambiar la
fecha u hora del router **no** reenvía a quien ya recibió.

### 4.4 Pacing anti-baneo

| Parámetro | Default | Qué hace |
|---|---|---|
| `billing_notify_per_minute` | 40 | Máximo de envíos por tenant y corrida |
| `billing_notify_gap_ms` | 250 | Espera entre envíos reales |

Los que no salen por el tope **no se registran**, así que el minuto siguiente los
reintenta. 200 avisos se drenan solos en ~5 minutos sin ráfaga y sin perder a
nadie.

> En producción estos valores se bajaron a **6/min y 4 s** de espacio en un
> número sin calentar. Ajústalos según la salud del número.

`--limit` sobreescribe el tope. El día agendado siempre drena: la compuerta de
recursos solo aplica en días de catch-up.

### 4.5 Saldo a favor

ispwatch aplica el `credit_balance` del cliente **en el mismo segundo** en que
crea la factura, dejándola en `balance_due = 0` y `status = paid`.

Por eso los tres avisos de facturación:

- **omiten** al cliente que no quedó debiendo nada, y
- usan **siempre `balance_due`**, nunca `total`, como monto.

Cobrarle el bruto por WhatsApp es cobrarle algo que su saldo ya cubrió. Quien
necesite el bruto tiene la variable `monto_total`.

### 4.6 Uso

```bash
php artisan whatsapp:billing-notify
php artisan whatsapp:billing-notify --tenant=2
php artisan whatsapp:billing-notify --customer=1234        # un cliente de ispwatch
php artisan whatsapp:billing-notify --date="2026-08-15 09:00"
php artisan whatsapp:billing-notify --dry-run
php artisan whatsapp:billing-notify --limit=1
```

`--date` simula la corrida en hora local del ISP: permite probar el día de
facturación sin esperar al mes siguiente.

---

## 5. `whatsapp:events-notify` — avisos por evento

### 5.1 Polling incremental

ispwatch es **read-only**: no hay webhooks ni triggers. La detección es una marca
de agua por `(tenant, evento)` en `ispwatch_event_cursors`.

Cada corrida trae solo `id > cursor`. **Compuerta barata:** si `MAX(id)` no supera
al cursor, corta sin tocar joins. El tic en reposo es una sola consulta por
tenant/evento activo.

### 5.2 Las cinco salvaguardas

**1. Arranque en frío.** La primera corrida de un evento **siembra** el cursor en
`MAX(id)` y **no envía nada**. Solo reciben aviso las altas y pagos posteriores a
activarlo — nunca la base histórica completa.

**2. Ventana de frescura.** `events_notify_freshness_days` (default 2): no se
envía por registros más viejos. El cursor **igual avanza** sobre ellos, así que no
se reintentan. Para outages la ventana es de **horas**
(`outage_freshness_hours`, default 6): avisar de una caída de hace días no tiene
sentido.

**3. Detección de carga masiva** *(solo bienvenida)*. Una importación crea
decenas o cientos de activaciones en el mismo minuto. Se detecta por **densidad
temporal**: si ≥ `events_notify_bulk_threshold` (default 5) activaciones del
mismo tenant caen dentro de `events_notify_bulk_window_seconds` (default 120),
se tratan como importación y **no** reciben bienvenida. Las altas manuales
observadas van a 1-4 por minuto.

**4. Idempotencia.** Fila única en `billing_notification_logs` por
`(tenant, kind, ref)`: bienvenida una vez por cliente, confirmación una vez por
pago.

**5. Pacing.** `events_notify_per_minute` (30) y `events_notify_gap_ms` (350).
El cursor avanza **solo sobre lo procesado**, así que una ráfaga se drena sola.

### 5.3 Falla masiva de router

Caso especial: es un **fan-out**. ispwatch inserta una fila en
`router_outage_events` cuando un core cae (`type = 'outage'`) o se restablece
(`type = 'restored'`). Converza busca **todos los clientes activos de ese router**
y les envía el aviso.

- Los valores de `type` son configurables
  (`WHATSAPP_OUTAGE_TYPE_START` / `_RESOLVED`), confirmados con ispwatch en
  2026-07-04.
- Solo se procesa el evento **más reciente** del router: si ya hay uno posterior,
  el anterior se descarta.
- El envío es paceado **con reanudación**: un fan-out grande se reparte entre
  corridas.

### 5.4 Uso

```bash
php artisan whatsapp:events-notify
php artisan whatsapp:events-notify --tenant=2 --event=payment_registered
php artisan whatsapp:events-notify --customer=123 --force   # prueba dirigida, no mueve el cursor
php artisan whatsapp:events-notify --dry-run
php artisan whatsapp:events-notify --reset-cursor           # re-siembra sin enviar histórico
```

> `--reset-cursor` es la salida segura si el cursor quedó desalineado: re-siembra
> en `MAX(id)` **sin** disparar el histórico.

---

## 6. Anatomía de un envío

```
1. ¿Interruptor maestro + tenant + ruta activa + plantilla aprobada?  → si no, fin
2. Traer las filas candidatas de ispwatch (IspwatchRepository, sin cache)
3. Por cada cliente:
   a. ¿Ya hay log para (tenant, kind, ciclo|ref)?     → skipped: 'ya enviado'
   b. ¿Tiene teléfono válido?                          → skipped: 'sin teléfono'
   c. ¿Tiene saldo pendiente? (avisos de facturación)  → skipped: 'sin saldo'
   d. ¿Está dentro de la ventana de frescura?          → skipped: 'rancio'
   e. EventCatalog::resolveValues(kind, row, tenant)   → { variable => valor }
   f. TemplateRenderer → parámetros de la plantilla
   g. WhatsAppService::sendTemplate(...)
   h. Registrar Message + Conversation (aparece en el chat)
   i. Registrar en billing_notification_logs (sent | failed + motivo)
   j. Esperar gap_ms
4. Al llegar al tope por minuto: cortar. El próximo minuto sigue.
```

**Todo intento queda registrado** — `sent`, `skipped` con motivo, o `failed` con
el error. Esa bitácora es `/notifications` en la UI y la primera parada del
diagnóstico.

Los avisos también crean `Message` y `Conversation`, así que el agente ve en el
chat lo que el sistema le mandó al cliente. **No** llaman a
`ConversationRead::markAnsweredForTeam()`: nadie leyó nada.

---

## 7. Variables

`EventCatalog` es la **única fuente de verdad** de qué variables existen. Lo leen
el editor de plantillas (paleta), Settings (lista de eventos) y los comandos
(valores reales).

### Variables de deuda (los tres avisos de facturación)

| Variable | Qué trae |
|---|---|
| `saldo_total` | Suma de **todas** las facturas pendientes |
| `saldo_anterior` | Solo el arrastre de meses anteriores |
| `facturas_pendientes` | Cuántas facturas sin pagar |
| `detalle_pendientes` | Lista con número, valor y vencimiento |
| `saldo_favor` | Saldo a favor restante |

Son **opcionales**: existen porque el aviso solo habla del mes en curso y un
cliente con mora arrastrada no tenía cómo verla.

`detalle_pendientes` sale en **una sola línea separada por comas** (Meta rechaza
saltos de línea en un parámetro) y lista **máximo 6 facturas**, resumiendo el
resto como "y N más" para no exceder el largo aceptado.

### Agregar una variable nueva

1. Declararla en `EventCatalog::events()` bajo el evento, con `label`,
   `description` y `sample`.
2. Calcular su valor en `EventCatalog::resolveValues()`.
3. Si el dato no está en la fila que devuelve `IspwatchRepository`, agregarlo
   allí primero.

El editor de plantillas y Settings la muestran **automáticamente**. No hay que
tocar frontend.

---

## 8. Diagnóstico: cuando no sale un aviso

Recorre esta lista en orden. Casi siempre falla en los tres primeros puntos.

```bash
# 1. ¿Qué haría ahora mismo?
php artisan whatsapp:billing-notify --dry-run --tenant=1

# 2. ¿Qué haría en la fecha/hora del aviso?
php artisan whatsapp:billing-notify --dry-run --tenant=1 --date="2026-08-15 09:00"

# 3. ¿Y para un cliente concreto?
php artisan whatsapp:billing-notify --dry-run --tenant=1 --customer=1234
```

| # | Comprobar | Cómo |
|---|---|---|
| 1 | Interruptor maestro | `WHATSAPP_BILLING_NOTIFY_ENABLED=true` en el `.env` de prod (+ `config:cache`) |
| 2 | Tenant habilitado | `tenants.billing_notify_enabled` |
| 3 | Ruta del evento | Fila en `tenant_notification_routes` con `enabled = true` y plantilla **aprobada** |
| 4 | Canal en ispwatch | `billing.notification_type` en `whatsapp` o `both` |
| 5 | Fecha y hora | Día del mes y hora local del router; ¿la zona es la correcta? |
| 6 | Motivo de omisión | `SELECT reason, count(*) FROM billing_notification_logs WHERE status='skipped' AND created_at > now() - interval '1 day' GROUP BY 1 ORDER BY 2 DESC;` |
| 7 | Ya enviado | Un log `sent` de ese ciclo bloquea el reenvío. Es lo correcto |
| 8 | Sin saldo | El saldo a favor cubrió la factura: `balance_due = 0` → se omite a propósito |
| 9 | Scheduler vivo | ¿Corre el cron de `schedule:run` en el droplet? |
| 10 | Cola viva | `sudo supervisorctl status converza-worker:*` |

### Errores frecuentes

| Síntoma | Causa |
|---|---|
| Sale 5 h tarde | Zona horaria mal configurada |
| No sale nada para un tenant que sí está bien | Se filtró por `notificar_wpp` en vez de `notification_type` |
| El cliente recibe un monto mayor al que debe | Se usó `total` en vez de `balance_due` |
| Se enviaron 500 bienvenidas de golpe | Faltó la detección de carga masiva, o el umbral está muy alto |
| Nadie recibe nada tras activar un evento | Correcto: el arranque en frío no dispara el histórico |
| La plantilla "General" no aparece en Settings | `event_key` tiene dos formas (`NULL` y `'general'`); el filtro debe aceptar ambas |

---

## 9. El comando legado

`reminders:send` es la implementación **anterior** (basada en vencimiento +
`notification_type`). Sigue en el repo pero **no está agendada** y no se usa.
`whatsapp:billing-notify` la reemplaza. Ver
[mejoras.md](mejoras.md#m-08-código-legado-sin-retirar).
