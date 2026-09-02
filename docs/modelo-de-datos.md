# Modelo de datos

> **En una frase:** un solo esquema Postgres (`converza` / `converza_dev`) con
> aislamiento por columna `tenant_id`, más una conexión externa read-only al
> esquema `public` de ispwatch.

**Archivos clave**
- [database/migrations/](../database/migrations/) — 41 migraciones
- [app/Models/](../app/Models/) — modelos Eloquent
- [converza-schemas.sql](converza-schemas.sql) — creación de los schemas

---

## 1. Convenciones

- Motor: **PostgreSQL** (Supabase). Schema `converza` en producción,
  `converza_dev` en desarrollo.
- Toda tabla de negocio lleva `tenant_id` con FK a `tenants` y borrado en
  cascada. Las excepciones son deliberadas (Core Brain, tablas de sistema).
- IDs `bigint` autoincrementales.
- Timestamps `created_at` / `updated_at` salvo en tablas de solo-inserción.
- Los estados nuevos se declaran como `string` en vez de `enum`: alterar un
  `CHECK` de Postgres a posteriori es costoso.
- **Las migraciones deben correrse en ambos schemas.** Ver
  [manual-desarrollador.md](manual-desarrollador.md#4-migraciones--la-regla-que-más-se-olvida).

---

## 2. Mapa de relaciones

```
tenants ─┬─< users ──< staff_members ──< conversations (assigned_to)
         │                     │
         │                     └──< conversation_reads
         ├─< contacts ─┬──< conversations ──< messages
         │             └──> contact_label >── labels
         ├─< teams ────< staff_members
         ├─< templates ──< tenant_notification_routes
         ├─< quick_replies
         ├─< closing_notes
         ├─< bot_settings (1:1) · bot_logs
         ├─< billing_notification_logs · ispwatch_event_cursors
         └─< campaigns ─┬─< campaign_steps
                        └─< campaign_recipients ──< campaign_sends
             campaign_opt_outs (por tenant + phone)

accounts (Core Brain, SIN tenant_id) ─┬─< account_products
                                      ├─< account_invoices ──< account_payments
                                      ├─< support_tickets ──< ticket_events
                                      └─< account_notes
```

---

## 3. Núcleo multi-tenant

### `tenants`
Un workspace = un ISP cliente del SaaS.

| Columna | Tipo | Notas |
|---|---|---|
| `name`, `slug` | string | `slug` único. El slug `default` es el del dueño del SaaS y es el **único** que puede usar las credenciales del `.env` |
| `ispwatch_tenant_id` | string, único, nullable | FK lógico al `tenant.id` de ispwatch. Se administra con `php artisan tenant:link` |
| `wa_phone_number_id` | string | Identifica al tenant en el webhook entrante |
| `wa_business_account_id` | string | WABA. Fallback de resolución del webhook |
| `wa_access_token` | text | **Cifrado** (`casts: encrypted`) |
| `wa_app_secret` | text | **Cifrado** |
| `wa_verify_token` | string | Aceptado en el handshake del webhook |
| `wa_status` | string(32) | `disconnected` por defecto |
| `wa_invoice_template`, `wa_reminder_template` | string | **Legado**, reemplazados por `tenant_notification_routes` |
| `billing_notify_enabled` | bool | Interruptor de avisos por tenant |
| `auto_assign_enabled` | bool | Auto-asignación al agente menos ocupado |
| `is_active` | bool | Un tenant inactivo no resuelve en `ResolveTenant` ni en el webhook |

> `hasWhatsAppConfigured()` usa `getRawOriginal('wa_access_token')` a propósito:
> si el valor en base no es un payload cifrado válido (texto plano legado o
> `APP_KEY` rotada), el cast lanzaría excepción.

### `users`
Extiende el `users` estándar de Laravel con:

| Columna | Notas |
|---|---|
| `tenant_id` | nullable — un superadmin puede no tener tenant |
| `is_superadmin` | bool. Dueño del SaaS: acceso a `/admin` y trato de `admin` dentro de cualquier tenant |
| `internal_role` | `owner` \| `viewer` \| null. Acceso al Core Brain |

### `staff_members`
La pertenencia de un usuario a un tenant **con rol**.

| Columna | Notas |
|---|---|
| `user_id`, `team_id` | FK |
| `role` | `admin` \| `agent` \| `viewer` |
| `is_active` | Un staff inactivo no recibe auto-asignación ni aparece en selectores |

Se crea automáticamente con rol `agent` la primera vez que un usuario abre el
chat ([`ChatController::getOrCreateStaffForUser`](../app/Http/Controllers/ChatController.php)).

> **Por qué existe `staff_members` además de `users`:** `users` es la identidad
> (login); `staff_members` es el rol operativo dentro de un tenant. Separar
> ambos permite desactivar a alguien de la operación sin borrar su historial de
> mensajes ni sus asignaciones.

---

## 4. Conversaciones

### `contacts`

| Columna | Notas |
|---|---|
| `phone` | Único **por tenant** (`unique(tenant_id, phone)`). Normalizado a 12 dígitos con prefijo país. **Nulable** desde el 02/09/2026 |
| `wa_user_id` | BSUID de Meta (`CO.<digitos>`), único por tenant. Identidad del contacto cuando el cliente ocultó su teléfono con un username de WhatsApp |
| `wa_username` | Username de WhatsApp, solo para mostrar. Es lo único legible que queda cuando no hay teléfono |
| `name`, `email`, `avatar`, `notes` | |
| `external_id` | Indexado junto a `tenant_id`. Para cruces con sistemas externos |

> **El teléfono ya no es la identidad.** Un contacto necesita `phone` **o**
> `wa_user_id`; puede tener los dos. Los NULL no cuentan para un índice único en
> Postgres, así que varios contactos sin teléfono conviven sin chocar.
>
> Para escribirle a un contacto usá `Contact::waDestino()` en vez de `->phone`:
> devuelve el teléfono si lo hay y si no el BSUID, y `WhatsAppService` arma el
> payload que corresponda. Un `->phone` pelado en un camino de envío es un bug
> latente para estos contactos.

### `conversations`

| Columna | Notas |
|---|---|
| `contact_id` | |
| `status` | `open` \| `closed` \| `pending` |
| `assigned_to` | FK a `staff_members`, nullable |
| `team_id` | nullable |
| `bot_active` | El bot tiene el control |
| `bot_step` | `greeting_sent` \| `qualifying_subscribers` \| `qualifying_name` \| `handed_off` |
| `bot_failed_intents` | Contador de "no entendí" |
| `bot_context` | JSON con lo que el bot capturó (intención, nº de suscriptores, nombre) |

La relación `latestMessage()` excluye `type in ('system','note')`: el "último
mensaje" de la lista debe reflejar la conversación **real** con el cliente, no
una nota interna ni una transferencia.

### `messages`

| Columna | Notas |
|---|---|
| `conversation_id`, `contact_id` | |
| `body` | Texto o descripción del contenido |
| `status` | `sent` \| `received` \| `read` \| `delivered` \| `failed` |
| `wa_message_id` | **Único** — la clave de la idempotencia del webhook |
| `type` | `text`, `image`, `audio`, `video`, `document`, `bot`, `note`, `system`, `reaction`, `sticker`, `location`, `contacts`, `order`, `interactive`, `button`, `unsupported`. **NULL = texto saliente legado** |
| `media_id` | ID del medio en Meta |
| `media_path` | Ruta en el disco local |
| `media_mime`, `media_filename`, `caption` | |
| `raw_metadata` | JSON. Payload crudo de Meta para tipos no soportados o inesperados — clave para diagnosticar |
| `sent_by_user_id` | Asesor que lo envió. NULL en entrantes, automáticos y del bot |

> **Los tres tipos "no-mensaje":** `note` (nota interna, nunca sale),
> `system` (transferencias y auto-asignación) y `bot`. Los dos primeros se
> excluyen de `latestMessage()` y de los contadores de "cliente esperando".

### `conversation_reads`
Lectura **por asesor**, no por conversación.

| Columna | Notas |
|---|---|
| `conversation_id`, `staff_member_id` | Único compuesto |
| `last_read_at` | |

Se actualiza en cada carga del chat (incluido el poll de 5 s). El método
`markAnsweredForTeam()` hace un `upsert` masivo cuando un asesor **responde**:
marca el chat como leído para todo el equipo activo, porque alguien ya lo
atendió. **Solo debe llamarse desde respuestas humanas de la UI** — nunca desde
avisos, campañas, el bot ni el mensaje de transferencia.

El cálculo de "no leído" (`ChatController::applyUnreadJoin`) excluye
`type = 'reaction'`: un 👍 sobre un mensaje ya contestado no pide respuesta.

### `closing_notes`, `labels`, `contact_label`, `teams`, `quick_replies`
Tablas directas sin sutilezas. `labels.type` distingue `contact` de `template`.

---

## 5. Plantillas y avisos

### `templates`

| Columna | Notas |
|---|---|
| `name` | Nombre exacto en Meta |
| `category` | `marketing` \| `utility` \| `authentication` (minúscula en base) |
| `language` | Código exacto de Meta (`es_CO`, `es`) |
| `event_key` | Propósito del catálogo de eventos. NULL o `'general'` = propósito libre |
| `body`, `header_text`, `footer_text`, `button_text`, `button_url` | |
| `status` | `pending` \| `approved` \| `rejected` |
| `meta_id` | ID en Meta |
| `is_active`, `last_synced_at` | |

> ⚠️ `event_key` tiene **dos formas** para "general": `NULL` (legado) y el string
> `'general'` (creadas desde el editor). Cualquier filtro debe aceptar ambas —
> ignorar una dejó avisos sin poder asignarse.

### `tenant_notification_routes`
Enrutamiento evento → plantilla, por tenant.

| Columna | Notas |
|---|---|
| `event_key` | Debe existir en [`EventCatalog`](../app/Services/Notifications/EventCatalog.php) |
| `template_id` | |
| `enabled` | **false por defecto**: opt-in explícito |

Reemplaza a las columnas fijas `tenants.wa_invoice_template` / `wa_reminder_template`.

### `billing_notification_logs`
Bitácora e **idempotencia** de todos los avisos automáticos.

| Columna | Notas |
|---|---|
| `kind` | Coincide con la clave del evento |
| `ispwatch_tenant_id`, `ispwatch_customer_id` | A quién |
| `ispwatch_invoice_id`, `invoice_number`, `billing_id`, `router_name` | Contexto |
| `cycle_key` | Ciclo (ej. `2026-08`). **Base de la idempotencia por ciclo** |
| `event_ref` | Referencia del evento de origen (para avisos por evento) |
| `channel`, `template` | |
| `status` | `sent` \| `skipped` \| `failed` |
| `reason` | Por qué se saltó o falló |
| `wa_message_id`, `sent_at` | |

Índices únicos garantizan que un cliente no reciba dos veces el mismo aviso del
mismo ciclo/evento, sin importar cuántas veces corra el comando.

### `ispwatch_event_cursors`
Marca de agua del *polling* incremental sobre ispwatch: por cada
(tenant, evento) guarda el último ID procesado. Como ispwatch no tiene webhooks
y es read-only, esta es la única forma de detectar novedades sin reprocesar todo.

### `bot_settings` (1:1 con tenant) y `bot_logs`
`bot_settings` guarda el interruptor y los 8 textos configurables.
`bot_logs` registra cada interacción: mensaje entrante, intención detectada,
respuesta, si escaló y el contexto capturado.

---

## 6. Campañas

Modelo de **secuencias multi-paso** (estilo Smartlead), migrado desde el modelo
original de "un disparo" sin pérdida de datos.

### `campaigns`

| Columna | Notas |
|---|---|
| `template_id`, `created_by` | |
| `status` | `draft` \| `scheduled` \| `sending` \| `paused` \| `completed` \| `cancelled` \| `failed` |
| `source_type` | `upload` \| `crm` \| `manual` \| `sheet` |
| `source_meta`, `variable_mapping` | JSON |
| `throttle_per_minute` | Mensajes/minuto. Default 20 (config) |
| `scheduled_at`, `started_at`, `completed_at` | |
| `total_recipients`, `sent_count`, `delivered_count`, `read_count`, `failed_count`, `skipped_count`, `replied_count` | Contadores desnormalizados para la UI |
| `deleted_at` | Soft delete |

### `campaign_steps`
La definición de la secuencia.

| Columna | Notas |
|---|---|
| `step_order` | 1 = envío inicial. Único por campaña |
| `template_id`, `variable_mapping` | Propios del paso |
| `delay_hours` | Espera tras el paso anterior |
| `send_condition` | `always` (paso 1) \| `if_not_replied` \| … |

### `campaign_recipients`
La **inscripción** de un contacto en la secuencia.

| Columna | Notas |
|---|---|
| `phone` | Único por campaña |
| `name`, `variables` | JSON con los valores para las plantillas |
| `current_step` | Dónde va |
| `enrollment_status` | `active` \| `sending` \| `completed` \| `replied` \| `opted_out` \| `failed` |
| `next_action_at` | Cuándo toca el próximo paso (indexado con status) |
| `status`, `wa_message_id`, `sent_at`, … | **Legado** del modelo de un disparo; se conservan |

### `campaign_sends`
Un registro por **mensaje real** (paso × destinatario). Aquí viven ahora
`wa_message_id` y los timestamps de estado.

Único `(recipient_id, step_order)` → un envío por paso por destinatario.
Índice `(tenant_id, sent_at)` para el conteo del warm-up; `(campaign_id, step_order)`
para el embudo por paso.

### `campaign_opt_outs`
Único `(tenant_id, phone)`. Una baja aplica a **todas** las campañas futuras del
tenant, no solo a la que la originó.

### `campaign_warmups`
Rampa de volumen diario por tenant: inicio, incremento diario y techo.

---

## 7. Core Brain (sin `tenant_id`)

Estas tablas son del **dueño del SaaS**, no de los clientes. No usan
`BelongsToTenant` a propósito: son cross-tenant por diseño y viven detrás del
middleware `internal`.

### `accounts`
La ficha unificada de cada ISP cliente.

| Columna | Notas |
|---|---|
| `name`, `legal_name`, `slug` | |
| `tenant_id` | nullable — el ISP puede tener ispwatch pero aún no Converza |
| `ispwatch_tenant_id` | nullable — o al revés |
| `status` | `prospect` \| `active` \| `past_due` \| `suspended` \| `churned` |
| `health` | `good` \| `at_risk` \| `critical` |
| `owner_user_id` | Responsable interno |
| `contact_*`, `country` | Datos comerciales |
| `onboarding_at`, `renewal_at`, `billing_day` | |
| `notes`, `deleted_at` | |

> **Por qué `accounts` y no reutilizar `tenants`:** (1) un ISP puede tener un
> solo producto; (2) la información comercial es privada del fundador y no debe
> vivir en una fila que administra el propio ISP; (3) `accounts` es la llave de
> cruce entre ambos productos.

### `account_products`
Qué tiene contratado cada cuenta. Único `(account_id, product)`.

| Columna | Notas |
|---|---|
| `product` | `ispwatch` \| `converza` |
| `plan`, `plan_key` | `plan_key` referencia a [`PlanCatalog`](../app/Services/Brain/PlanCatalog.php) |
| `status`, `billing_cycle` | |
| `amount`, `currency` | Precio real cobrado (puede diferir del catálogo) |
| `started_at`, `renews_at`, `cancelled_at` | |

> Un **combo** se guarda como **dos filas** con el mismo `plan_key` (`combo_*`):
> el precio del combo va en la fila de `converza` y **0** en la de `ispwatch`,
> para que el MRR no se duplique.

### `account_invoices` / `account_payments`
Cobros del SaaS a sus ISPs.

`account_invoices`: `number` (único), `concept`, `amount`, `tax`, `total`,
`currency`, `status` (`draft`/`sent`/`paid`/`partial`/`overdue`/`void`),
`issued_at`, `due_at`, `paid_at`, `period_start`, `period_end`.

`account_payments`: `amount`, `currency`, `method`
(`transfer`/`cash`/`card`/`other`), `reference`, `paid_at`, y un flag de
aplicación de crédito.

> El **saldo a favor** de una cuenta es **derivado**: `Σ pagos − Σ facturas`.
> No se guarda como columna, se calcula y se descuenta solo.

### `support_tickets` / `ticket_events` / `account_notes`
Soporte interno por cuenta: tickets con estado, prioridad, categoría, producto y
origen; una bitácora de eventos (`message`/`note`/`status_change`/`assignment`)
y notas fijables (`pinned`).

---

## 8. Tablas de sistema

`users`, `password_reset_tokens`, `sessions`, `cache`, `cache_locks`, `jobs`,
`job_batches`, `failed_jobs`, `migrations`. Estándar de Laravel.

`jobs` es la cola real en producción: `SELECT count(*) FROM jobs` es el
termómetro de si los workers están al día.

---

## 9. La base externa: ispwatch

Conexión `ispwatch`, schema `public`, **solo SELECT**. Converza no crea ni altera
nada allí. Modelos espejo en `app/Models/Ispwatch/`.

Tablas que Converza lee (vía `IspwatchRepository`):

| Tabla | Para qué |
|---|---|
| `tenant` | Verificar el vínculo y su nombre |
| `users` + `customer_profile` | Buscar cliente por teléfono; estado, PPPoE, IP, dirección, saldo a favor |
| `invoices` | Facturas pendientes, deuda total y arrastre |
| `billing` | Días y horas de generación, recordatorio y corte, y el canal de notificación |
| `user_services` | Activaciones de servicio → aviso de bienvenida |
| `payments` | Pagos registrados → aviso de pago |
| `router_outage_events` | Caídas y restablecimientos de core → aviso de falla masiva |
| `routers` | Fan-out de la falla a los clientes del router |

### Dos detalles que causaron bugs reales

**1. `notification_type`, no `notificar_wpp`.**
El canal de WhatsApp lo gobierna `billing.notification_type` (`whatsapp` o
`both`). La columna `notificar_wpp` es **legada**. Filtrar por la vieja hacía que
tenants bien configurados no recibieran nada.

**2. `balance_due`, no `total`.**
ispwatch aplica el saldo a favor **al crear** la factura, dejando
`balance_due = 0` y `status = paid`. Un aviso que use `total` le cobra al cliente
algo ya saldado. Los avisos deben usar `balance_due` siempre.

### Normalización de teléfonos

Converza guarda `57XXXXXXXXXX`; ispwatch guarda `XXXXXXXXXX`. `IspwatchRepository`
normaliza ambos lados a 10 dígitos antes de comparar. Cuando varios clientes
comparten teléfono (caso real: familiares), desempata puntuando cuántos tokens
del nombre del contacto aparecen en el nombre del cliente; si nadie puntúa, gana
el registro más reciente.

---

## 10. Consultas útiles

```sql
-- Cola de trabajos pendientes
SELECT count(*) FROM jobs;

-- Conversaciones abiertas sin asignar, por tenant
SELECT tenant_id, count(*) FROM conversations
WHERE status = 'open' AND assigned_to IS NULL GROUP BY tenant_id;

-- Avisos enviados hoy por tipo
SELECT kind, status, count(*) FROM billing_notification_logs
WHERE created_at::date = CURRENT_DATE GROUP BY kind, status;

-- Motivos de omisión de avisos (diagnóstico)
SELECT reason, count(*) FROM billing_notification_logs
WHERE status = 'skipped' AND created_at > now() - interval '1 day'
GROUP BY reason ORDER BY 2 DESC;

-- Embudo de una campaña por paso
SELECT step_order, status, count(*) FROM campaign_sends
WHERE campaign_id = ? GROUP BY step_order, status ORDER BY 1, 2;

-- Tenants con WhatsApp configurado
SELECT id, name, slug, wa_phone_number_id IS NOT NULL AS tiene_numero, is_active
FROM tenants ORDER BY id;

-- Mensajes por tipo (detectar tipos nuevos sin handler)
SELECT type, count(*) FROM messages
WHERE created_at > now() - interval '7 days' GROUP BY type ORDER BY 2 DESC;
```
