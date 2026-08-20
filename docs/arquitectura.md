# Arquitectura

> **En una frase:** monolito Laravel 12 + Inertia/Vue 3, multi-tenant por
> `tenant_id` con global scope, que habla con la WhatsApp Cloud API vía webhook
> + colas, y lee (nunca escribe) la base de datos de ispwatch.

**Archivos clave**
- [bootstrap/app.php](../bootstrap/app.php) — pipeline de middleware
- [routes/web.php](../routes/web.php) — todas las rutas HTTP
- [routes/console.php](../routes/console.php) — tareas agendadas
- [app/Models/Concerns/BelongsToTenant.php](../app/Models/Concerns/BelongsToTenant.php) — corazón del aislamiento
- [app/Services/WhatsAppService.php](../app/Services/WhatsAppService.php) — única salida hacia Meta
- [app/Services/Ispwatch/IspwatchRepository.php](../app/Services/Ispwatch/IspwatchRepository.php) — única entrada desde ispwatch

---

## 1. Stack

| Capa | Tecnología | Notas |
|---|---|---|
| Backend | Laravel 12, PHP 8.2 | Monolito, sin API REST pública |
| Frontend | Vue 3 + Inertia.js 2 + Tailwind 4 | SPA sin API separada: el controlador devuelve props |
| Build | Vite 7 | `npm run build` genera `public/build` |
| Rutas en JS | Ziggy | `route('chat.index')` disponible en Vue |
| Base de datos | **PostgreSQL** (Supabase) | Schema dedicado: `converza` (prod) / `converza_dev` (local) |
| Base externa | PostgreSQL de ispwatch, schema `public` | Conexión separada, **read-only** |
| Colas | `database` (tabla `jobs`) | 4 workers vía Supervisor en producción |
| Cache / sesión | Redis en producción, `database` en local | La presencia depende del cache |
| Mensajería | Meta WhatsApp Cloud API (Graph `v20.0` por defecto) | |
| Almacenamiento de medios | Disco local (`storage/app/public`) | S3/Supabase soportado pero **no operativo** hoy |

> ⚠️ El [README.md](../README.md) raíz describía MySQL + SQLite. Eso es
> histórico: la aplicación corre sobre Postgres/Supabase en ambos entornos. Ver
> [.env.example](../.env.example) y [config/database.php](../config/database.php).

---

## 2. Vista de 10.000 pies

```
                       ┌──────────────────────────┐
   Cliente final ────► │  WhatsApp (Meta Cloud)   │
   (WhatsApp)   ◄───── └────────────┬─────────────┘
                                    │ webhook POST /webhook
                                    ▼
   ┌────────────────────────────────────────────────────────────┐
   │  CONVERZA (Laravel + Inertia)                              │
   │                                                            │
   │  WhatsAppController ─► Jobs (cola) ─► Conversación/Mensaje │
   │          │                  │                              │
   │          │                  └─► HandleBotResponse          │
   │          │                  └─► ConversationAssigner       │
   │          │                                                 │
   │  ChatController ◄── polling 5 s ── Vue (Chat/Index.vue)    │
   │          │                                                 │
   │  WhatsAppService ──────────────────► Graph API (salida)    │
   │                                                            │
   │  Scheduler (cada minuto)                                   │
   │    · whatsapp:billing-notify   · campaigns:tick            │
   │    · whatsapp:events-notify                                │
   └───────────┬────────────────────────────────┬───────────────┘
               │ SELECT-only                    │
               ▼                                ▼
   ┌───────────────────────┐        ┌────────────────────────┐
   │ ispwatch (Postgres)   │        │ converza (Postgres)    │
   │ schema public         │        │ schema converza[_dev]  │
   │ clientes, facturas,   │        │ tenants, contacts,     │
   │ pagos, routers        │        │ conversations, ...     │
   └───────────────────────┘        └────────────────────────┘
```

---

## 3. Las tres capas de "cliente"

Este es el concepto que más confusión genera y el que hay que tener claro antes
de tocar nada:

| # | Quién | Dónde vive | Quién lo ve |
|---|---|---|---|
| 1 | **Tú** (dueño del SaaS) y tu equipo interno | `users.is_superadmin`, `users.internal_role` | Consola `/admin` y el Core Brain `/brain` |
| 2 | **Tus clientes: los ISPs** | `tenants` (Converza) + `tenant` (ispwatch) + `accounts` (Brain) | Cada ISP ve solo su workspace |
| 3 | **Los clientes del ISP** (usuarios finales) | `contacts` (Converza) + `customer_profile` (ispwatch) | El equipo del ISP |

Un `Contact` de Converza es una **persona con WhatsApp**. Puede o no
corresponder a un cliente de ispwatch: si el número existe en ispwatch, el
sidebar del chat muestra su servicio, IP, PPPoE y facturas; si no, se trata como
prospecto.

Ver [glosario.md](glosario.md) para el vocabulario completo.

---

## 4. Multi-tenancy

### 4.1 Cómo se resuelve el tenant

[`ResolveTenant`](../app/Http/Middleware/ResolveTenant.php) corre en cada
petición web: toma `auth()->user()->tenant`, verifica que esté activo y lo
enlaza en el contenedor como `app('tenant')`.

### 4.2 Cómo se aplica el aislamiento

El trait [`BelongsToTenant`](../app/Models/Concerns/BelongsToTenant.php) hace dos
cosas en cada modelo que lo usa:

```php
static::addGlobalScope('tenant', fn ($q) => $q->where('…tenant_id', app('tenant')->id));
static::creating(fn ($m) => $m->tenant_id ??= app('tenant')->id);
```

Es decir: **lectura filtrada y escritura auto-etiquetada**, sin que el
controlador tenga que acordarse.

Modelos con el trait: `Contact`, `Conversation`, `Message`, `Label`, `Template`,
`StaffMember`, `Team`, `QuickReply`, `ClosingNote`, `Campaign*`, `BotSetting`,
`BotLog`, `ConversationRead`, `TenantNotificationRoute`, `BillingNotificationLog`.

Modelos **sin** el trait a propósito: todo `App\Models\Brain\*` (el Core Brain es
cross-tenant por diseño) y `App\Models\Ispwatch\*` (base externa).

### 4.3 Defensa en profundidad

El global scope no es la única barrera. El código además:

1. Repite `->where('tenant_id', $tenantId)` explícitamente en queries sensibles
   (`ChatController`, `MetricsController`). Es idempotente y sobrevive si el
   binding no está.
2. Valida pertenencia con `abort_if($model->tenant_id !== $tenantId, 403)` en
   cada acción sobre un modelo recibido por route-model binding.
3. Usa `Rule::exists(...)->where('tenant_id', $tenantId)` en las validaciones,
   para que un ID ajeno ni siquiera pase el `validate()`.

### 4.4 El peligro real: los workers

`queue:work` **mantiene un único contenedor de Laravel vivo entre jobs**. Un
binding `app('tenant')` dejado por un job anterior se filtra al siguiente y
archiva mensajes en el tenant equivocado — una fuga de datos entre clientes.

Por eso todo job que toca datos de tenant sigue este patrón obligatorio
([ProcessIncomingWhatsAppMessage](../app/Jobs/ProcessIncomingWhatsAppMessage.php),
[HandleBotResponse](../app/Jobs/HandleBotResponse.php)):

```php
app()->forgetInstance('tenant');            // 1. descartar herencia
$tenant = Tenant::find($this->tenantId);    // 2. resolver desde el ID explícito
app()->instance('tenant', $tenant);
try { $this->process(...); }
finally { app()->forgetInstance('tenant'); } // 3. limpiar pase lo que pase
```

**Nunca confíes en `app('tenant')` heredado dentro de un job.** El `tenantId`
viaja siempre como parámetro del constructor.

### 4.5 Identidad consistente entre pestañas

El navegador comparte **una** cookie de sesión entre todas sus pestañas. Si el
usuario cambia de cuenta en una pestaña, las demás seguirían haciendo polling con
la cookie nueva y mostrarían datos de otro tenant.

La defensa es doble:

- [`HandleInertiaRequests::version()`](../app/Http/Middleware/HandleInertiaRequests.php)
  ata la versión de assets a la identidad (`userId:tenantId`). Inertia responde
  409 + `X-Inertia-Location` en visitas GET desfasadas → recarga dura.
- [`EnsureConsistentIdentity`](../app/Http/Middleware/EnsureConsistentIdentity.php)
  aplica la misma comprobación a POST/PUT/PATCH/DELETE, que el core de Inertia
  **no** cubre.
- En el frontend, [`AppLayout.vue`](../resources/js/Layouts/AppLayout.vue)
  observa `auth.user.id` con `flush:'sync'` y pinta una pantalla de bloqueo
  antes de que Vue renderice datos del tenant nuevo.

---

## 5. Ciclo de vida de un mensaje entrante

```
Meta ──POST /webhook──► WhatsAppController::handleWebhook
                          │
                          ├─ 1. Log::channel('webhook_raw')  ◄── PRIMERO, siempre
                          │      cuerpo crudo a disco; sobrevive a BD y Redis caídos
                          │
                          ├─ 2. forwardWebhook()  (opcional: reenvía a ngrok local)
                          │
                          ├─ 3. try { WebhookDispatcher::dispatch($payload) }
                          │      │  catch → Log::error, NO propaga
                          │      │
                          │      ├─ por cada entry.changes.value:
                          │      │    resolveTenant(phone_number_id, waba_id)
                          │      │       1. tenants.wa_phone_number_id  (match exacto)
                          │      │       2. tenants.wa_business_account_id
                          │      │       3. tenant 'default' si el .env apunta a ese número
                          │      │
                          │      ├─ messages[] ─► ProcessIncomingWhatsAppMessage::dispatch(...)
                          │      └─ statuses[] ─► ProcessWhatsAppStatusUpdate::dispatch(...)
                          │
                          └─ 4. responde 200 EVENT_RECEIVED SIEMPRE
```

**El orden es la garantía, no un detalle.** Entre recibir los bytes y responder
200 no puede haber nada que dependa de un recurso capaz de fallar. Si el paso 3
revienta porque la BD o Redis están caídos, el mensaje ya está en disco y Meta
recibe su 200; devolver 500 solo conseguiría que Meta desistiera a los 20
segundos y el evento se perdiera para siempre.

`WebhookDispatcher` vive en `app/Services/WhatsApp/` y no dentro del controlador
para que `webhooks:replay` y `webhooks:reconcile` recorran **exactamente** el
mismo camino que un webhook en vivo. Con lógica duplicada divergirían, y lo
descubriríamos justo al reprocesar tras una caída.

Recuperación de lo que no se llegó a procesar:

| Comando | Cuándo | Qué hace |
|---|---|---|
| `webhooks:reconcile` | Automático, cada 5 min | Compara el log crudo contra `messages` en los últimos 60 min y despacha los huecos |
| `webhooks:replay` | Manual, tras un corte largo | Reprocesa un rango explícito del log crudo |

Reprocesar es seguro porque toda la cadena es idempotente: `wa_message_id` tiene
índice único y el job atrapa la violación, el bot solo responde si el mensaje se
guardó de verdad (`$savedMessage`), los acuses de estado nunca retroceden, y las
señales de campaña están protegidas por `whereNull('replied_at')`.

Responder rápido es obligatorio: si Meta no recibe un 200 en pocos segundos,
reintenta el webhook y se generan duplicados.

Dentro de `ProcessIncomingWhatsAppMessage`:

1. Rebinda el tenant (§4.4).
2. Normaliza el teléfono (10 dígitos que empiezan en `3` → prefijo `57`).
3. `Contact::firstOrCreate` por (phone, tenant).
4. Reabre la conversación más reciente si estaba cerrada; si no existe, la crea.
5. `buildAttributes()` traduce el tipo de mensaje de Meta a una fila de
   `messages`. Cubre `text`, `sticker`, `unsupported`, `order`, `system`,
   `reaction`, `location`, `contacts`, media (`image`/`audio`/`video`/`document`),
   `button`, `interactive`, y un *default* que intenta rescatar texto legible y
   loguea un warning para que agreguemos el caso.
6. Guarda el mensaje. `UniqueConstraintViolationException` sobre `wa_message_id`
   = webhook reintentado → se ignora silenciosamente (idempotencia).
7. `handleCampaignSignals()`: detecta opt-out y cierra secuencias de campaña si
   el contacto respondió.
8. Despacha `HandleBotResponse` **antes** de auto-asignar (si auto-asignara
   primero, el observer apagaría el bot y el flujo nunca correría).
9. Auto-asigna al agente menos ocupado si el tenant lo tiene activado.

---

## 6. Ciclo de vida de un mensaje saliente

Hay **cuatro** caminos de salida, todos vía `WhatsAppService`:

| Origen | Método | Cuándo |
|---|---|---|
| Agente escribe en el chat | `sendMessage()` | Dentro de la ventana de 24 h |
| Agente adjunta archivo | `uploadMedia()` + `sendMedia()` | Ídem |
| Bot | `sendMessage()` | Conversación nueva o bot activo |
| Avisos y campañas | `sendTemplate()` | Siempre (fuera de la ventana) |

El texto que el agente envía lleva el prefijo `*Nombre:* ` en el payload de
WhatsApp, pero se guarda **limpio** en la base: `sent_by_user_id` ya identifica
al autor y duplicar el nombre en la UI sería ruido.
Ver [ChatController::sendMessage](../app/Http/Controllers/ChatController.php).

### Credenciales por tenant

`WhatsAppService` resuelve la URL base y el token así:

1. Tenant explícito (`forTenant($t)`) — obligatorio en jobs.
2. `app('tenant')` del contenedor.
3. `null` → cae al `.env`.

El fallback al `.env` **solo aplica al tenant con `slug = 'default'`**. Cualquier
otro tenant sin credenciales propias queda "no configurado": los envíos
devuelven un mock y la UI lo refleja. Esto evita que un cliente nuevo mande
mensajes por el número del dueño del SaaS.

---

## 7. Tiempo real sin WebSockets

El chat **no** usa WebSockets ni Echo. Usa **polling de Inertia cada 5 s** con
recarga parcial (`only: ['conversations', 'activeChat', ...]`).

Consecuencias de diseño que hay que respetar:

- Las props pesadas de `ChatController::index` se entregan como **closures**.
  Inertia solo las evalúa si la respuesta las incluye, así que el poll de 5 s no
  ejecuta las consultas a ispwatch, ni las de etiquetas, ni las de staff.
- El **heartbeat de presencia** (`PresenceService::heartbeat`) se aprovecha del
  mismo poll: cada 5 s registra "estoy en línea" y "estoy viendo la conversación
  X" en cache con TTL de 20 s. De ahí sale el indicador de colisión ("Ana también
  está viendo este chat") sin infraestructura extra.
- El **marcado de leído** también corre en cada poll: mientras el agente tiene la
  conversación abierta, los mensajes que lleguen se marcan leídos solos.
- En recargas parciales está **prohibido** auto-seleccionar la primera
  conversación: sin `?conversation=` la respuesta devolvería mensajes de otro
  hilo. El frontend siempre manda el parámetro; el backend además tiene el guard
  `$isPartialReload`.

**Trade-off aceptado:** ~12 peticiones/minuto por agente con el chat abierto. Con
decenas de agentes es barato; con miles habría que migrar a WebSockets. Ver
[mejoras.md](mejoras.md#m-06-el-polling-de-5-s-es-el-techo-de-escalado-del-chat).

---

## 8. Integración con ispwatch

- Conexión `ispwatch` declarada en [config/database.php](../config/database.php)
  (driver `pgsql`, schema `public`, variables `ISPWATCH_DB_*`).
- **Todo** el acceso pasa por
  [`IspwatchRepository`](../app/Services/Ispwatch/IspwatchRepository.php). Si el
  esquema de ispwatch cambia, ese es el único archivo a tocar.
- Los modelos espejo en `app/Models/Ispwatch/*` declaran
  `protected $connection = 'ispwatch'`.
- **Solo lectura, sin excepciones.** En producción debería usarse el rol
  `converza_reader` (SELECT-only) — ver [ispwatch-readonly.sql](ispwatch-readonly.sql).
- Las consultas de UI (sidebar del chat, estado en Settings) están **cacheadas
  60 s**. Las de batch (avisos automáticos) **no** se cachean: la frescura importa.

> 🐛 Si cambias datos en ispwatch y el sidebar del chat sigue mostrando lo
> anterior: `php artisan cache:clear`.

El vínculo entre un tenant de Converza y uno de ispwatch se guarda en
`tenants.ispwatch_tenant_id` y **no es editable desde la UI**: lo administra el
dueño del SaaS con `php artisan tenant:link`.

---

## 9. Trabajos en segundo plano

### Jobs (cola `default`)

| Job | Disparado por | Qué hace |
|---|---|---|
| [`ProcessIncomingWhatsAppMessage`](../app/Jobs/ProcessIncomingWhatsAppMessage.php) | Webhook | Persiste el mensaje entrante, descarga medios, señales de campaña |
| [`ProcessWhatsAppStatusUpdate`](../app/Jobs/ProcessWhatsAppStatusUpdate.php) | Webhook | Actualiza `sent/delivered/read/failed` en mensajes y campañas |
| [`HandleBotResponse`](../app/Jobs/HandleBotResponse.php) | Job anterior | Ejecuta la máquina de estados del bot |
| [`SendCampaignMessageJob`](../app/Jobs/SendCampaignMessageJob.php) | `campaigns:tick` | Envía un mensaje de campaña |

`tries = 3`, `backoff = 5s` en el job de entrada; `tries = 2`, `backoff = 3s` en
el bot. En producción corren 4 workers de Supervisor con `--max-time=3600`
(reinicio horario contra fugas de memoria).

### Scheduler ([routes/console.php](../routes/console.php))

| Comando | Frecuencia | Propósito |
|---|---|---|
| `whatsapp:billing-notify` | cada minuto | Factura generada / recordatorio / suspensión |
| `whatsapp:events-notify` | cada minuto | Bienvenida / pago / falla masiva de router |
| `campaigns:tick` | cada minuto | Promueve campañas agendadas y despacha lotes |
| `media:clean` | domingos 03:00 | Borra medios de más de `MEDIA_CLEANUP_DAYS` días |

Los tres primeros corren **cada minuto** y no una vez al día porque tienen que
honrar la hora exacta que cada ISP configuró en ispwatch. Cuando no hay nada que
hacer cortan temprano con una consulta barata (un `MAX(id)` o una comprobación de
hora), así que el tic en reposo es prácticamente gratis.

Todos usan `withoutOverlapping()` y `onOneServer()`.

---

## 10. Decisiones de diseño y por qué

| Decisión | Alternativa descartada | Razón |
|---|---|---|
| Global scope por `tenant_id` | Base de datos por tenant | Un solo esquema, migraciones únicas, joins baratos; el volumen no justifica sharding |
| Polling de 5 s | WebSockets / Laravel Echo | Sin infraestructura extra (Reverb/Pusher), sin estado de conexión; suficiente para la escala actual |
| Cola `database` | Redis como cola | Redis ya se usa para cache/sesión; la cola en base facilita depurar (`SELECT * FROM jobs`) y sobrevive reinicios |
| ispwatch read-only | API entre ambos productos | ispwatch es un Laravel separado sin API; leer su Postgres es lo más simple y no requiere cambios de su lado |
| `Message.type = 'note'` para notas internas | Tabla aparte | Reutiliza el timeline y el polling del chat sin duplicar lógica; `latestMessage()` las excluye |
| `Message.type = 'system'` para transferencias | Tabla de auditoría | Ídem: el evento se ve en el hilo, donde el agente lo necesita |
| Avisos con `tries` de catch-up | Reintento inmediato | Un ISP caído un día entero recupera sus avisos; la idempotencia por ciclo impide duplicar |
| Build de assets en el runner de CI | `npm run build` en el droplet | El droplet tiene 1 GB de RAM y el OOM-killer mata a Vite |

---

## 11. Estructura del repositorio

```
app/
├── Console/Commands/       # 7 comandos artisan (avisos, campañas, media, tenant:link)
├── Http/
│   ├── Controllers/        # 1 controlador por módulo + Admin/ y Brain/
│   ├── Middleware/         # ResolveTenant, EnsureStaffRole, EnsureSuperadmin, …
│   └── Requests/
├── Jobs/                   # 4 jobs de cola
├── Models/
│   ├── Brain/              # Core Brain (sin BelongsToTenant)
│   ├── Ispwatch/           # espejos read-only
│   └── Concerns/           # BelongsToTenant
├── Observers/              # ConversationObserver (apaga el bot al asignar)
├── Providers/
└── Services/
    ├── Assignment/         # ConversationAssigner (least-busy)
    ├── Bot/                # IntentDetector
    ├── Brain/              # PlanCatalog
    ├── Campaigns/          # AudienceBuilder, CampaignMessageBuilder, WarmupBudget
    ├── Ispwatch/           # IspwatchRepository  ← única puerta a ispwatch
    ├── Notifications/      # EventCatalog        ← única fuente de eventos
    ├── Presence/           # PresenceService
    ├── Templates/          # TemplateRenderer
    └── WhatsAppService.php ← única puerta a Meta

resources/js/
├── Components/             # LinkedText, Logo, MediaViewerModal
├── Layouts/AppLayout.vue   # sidebar, tema, presencia de sesión, FAB de soporte
└── Pages/                  # una carpeta por módulo Inertia

database/migrations/        # 41 migraciones
deploy/                     # DEPLOY.md + config de Supervisor
docs/                       # esta documentación
```

### Los cuatro "cuellos de botella" del diseño

Cuatro archivos concentran, a propósito, todo el acoplamiento externo. Tocar
cualquier integración empieza por uno de ellos:

1. `Services/WhatsAppService.php` — todo lo que sale hacia Meta.
2. `Services/Ispwatch/IspwatchRepository.php` — todo lo que entra desde ispwatch.
3. `Services/Notifications/EventCatalog.php` — qué avisos existen y qué variables tienen.
4. `Services/Brain/PlanCatalog.php` — qué planes comerciales existen y qué límites imponen.

---

## 12. Qué NO tiene el sistema

Saber esto ahorra búsquedas inútiles:

- **No hay API REST pública.** Todo es Inertia (HTML + props JSON con sesión).
- **No hay WebSockets.** Ver §7.
- **No hay verificación de firma del webhook.** `X-Hub-Signature-256` se reenvía
  al forwarder pero no se valida. Ver [mejoras.md](mejoras.md#m-01-el-webhook-no-verifica-la-firma-de-meta).
- **No hay tests significativos.** `tests/Feature/WhatsAppTest.php` prueba rutas
  que ya no existen. Ver [mejoras.md](mejoras.md#m-02-la-suite-de-tests-está-muerta).
- **No hay rate limiting** en las rutas de escritura.
- **No hay soft-deletes** en `conversations` / `messages`: `ChatController::destroy`
  borra de verdad.
