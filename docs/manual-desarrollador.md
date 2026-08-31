# Manual de desarrollador

> **En una frase:** cómo levantar Converza en tu máquina, dónde tocar cada cosa,
> qué convenciones respetar y cómo depurar lo que se rompe.

Antes de leer esto conviene haber leído [arquitectura.md](arquitectura.md).

---

## 1. Requisitos

| Herramienta | Versión | Notas |
|---|---|---|
| PHP | 8.2+ | Extensiones: `pdo_pgsql`, `gd` (compresión de imágenes), `mbstring`, `openssl` |
| Composer | 2.x | |
| Node | 18+ (CI usa 20) | |
| Git | — | |
| ffmpeg | opcional en local | Necesario solo para **grabar** notas de voz desde el navegador |
| ngrok | opcional | Para recibir webhooks reales en local ([§7](#7-recibir-webhooks-reales-en-local)) |

Verificación rápida:

```powershell
php -v
php -m | Select-String "pdo_pgsql|gd|mbstring"
node -v ; composer -V
```

Si falta `pdo_pgsql` la app no arranca: la conexión por defecto es Postgres.
Si falta `gd`, las imágenes entrantes se guardan sin comprimir (no rompe nada,
solo consume más disco — `WhatsAppService::compressImage` lo detecta y sigue).

---

## 2. Instalación

```powershell
git clone <repo> CONVERZA-CRM
cd CONVERZA-CRM

composer install
npm install

Copy-Item .env.example .env
php artisan key:generate
```

Después edita `.env` con las credenciales reales (ver [§3](#3-configuración-del-entorno))
y corre:

```powershell
php artisan migrate
```

Atajo: `composer setup` hace install + key + migrate + build de una.

### Levantar el entorno

```powershell
npm run dev          # Laravel (:8000) + Vite, vía concurrently
```

Si además necesitas la cola y los logs en vivo (recomendado cuando trabajas con
webhooks, bot o campañas):

```powershell
composer dev         # server + queue:listen + pail (logs) + vite, 4 paneles
```

> Con `npm run dev` **la cola no corre**. Los mensajes entrantes se encolan y no
> se procesan hasta que levantes un worker. Es la causa #1 de "el webhook llega
> pero el chat no muestra nada" en local.

Abre <http://127.0.0.1:8000>.

---

## 3. Configuración del entorno

### 3.1 La advertencia importante

⚠️ **El `.env` local de este proyecto apunta históricamente a la base de datos
de PRODUCCIÓN** (Supabase, schema `converza`) con `APP_DEBUG=true`.

Consecuencias:

- Cualquier `migrate`, `tinker` o borrado que hagas afecta a clientes reales.
- Para reproducir un bug con datos reales, envuélvelo en transacción:

```php
DB::beginTransaction();
// … reproducir …
DB::rollBack();
```

Si quieres un entorno seguro, apunta `DB_SEARCH_PATH=converza_dev` (schema de
desarrollo, mismo servidor) y confirma con:

```powershell
php artisan tinker --execute="echo config('database.connections.pgsql.search_path');"
```

### 3.2 Variables por bloque

**Aplicación**

| Variable | Para qué |
|---|---|
| `APP_URL` | URL local (`http://127.0.0.1:8000`) |
| `APP_PUBLIC_URL` | URL **canónica del SaaS**, igual en todos los entornos. La usa `/settings` para mostrar la "Webhook URL para Meta" |
| `APP_DEBUG` | `true` solo en local |

**Base de datos principal (Converza)**

| Variable | Valor |
|---|---|
| `DB_CONNECTION` | `pgsql` |
| `DB_SEARCH_PATH` | `converza_dev` (local) / `converza` (prod) |
| `DB_SSLMODE` | `require` en Supabase |

**Base de datos externa (ispwatch, read-only)**

`ISPWATCH_DB_HOST`, `_PORT`, `_DATABASE`, `_USERNAME`, `_PASSWORD`, `_SSLMODE`.
El usuario debería ser `converza_reader` (SELECT-only) — ver
[ispwatch-readonly.sql](ispwatch-readonly.sql).

**WhatsApp (solo tenant `default`)**

| Variable | Para qué |
|---|---|
| `WHATSAPP_API_URL` | `https://graph.facebook.com/v20.0/<PHONE_NUMBER_ID>` |
| `WHATSAPP_API_TOKEN` | Token permanente de Meta |
| `WHATSAPP_APP_SECRET` | Secreto de la app: con él se valida `X-Hub-Signature-256` |
| `WHATSAPP_SIGNATURE_MODE` | `off` \| `log` \| `enforce`. Default `log`. **Si recibís webhooks reenviados, dejalo en `log`/`off`**: el reenvío re-serializa el JSON y el HMAC no coincide |
| `WHATSAPP_VERIFY_TOKEN` | Token del handshake del webhook |
| `WHATSAPP_GRAPH_VERSION` | Default `v20.0` |
| `WEBHOOK_FORWARD_URL` | Solo en prod: reenvía webhooks a tu ngrok |

Los demás tenants **no** usan estas variables: sus credenciales viven cifradas en
la tabla `tenants` y se editan desde `/settings`.

**Medios** — `MEDIA_DISK`, `MEDIA_DOWNLOAD_TYPES`, `MEDIA_COMPRESS_IMAGES`,
`MEDIA_MAX_WIDTH`, `MEDIA_JPEG_QUALITY`, `MEDIA_CLEANUP_DAYS`, `FFMPEG_PATH`.
Ver [config/media.php](../config/media.php).

**Avisos automáticos** — todo el bloque `WHATSAPP_BILLING_NOTIFY_*` y
`WHATSAPP_EVENTS_NOTIFY_*` en [config/services.php](../config/services.php).
Detalle en [avisos-automaticos.md](avisos-automaticos.md).

**Campañas** — `CAMPAIGNS_DEFAULT_THROTTLE_PER_MINUTE`,
`CAMPAIGNS_OPT_OUT_KEYWORDS`, `CAMPAIGNS_WARMUP_*`.
Ver [config/campaigns.php](../config/campaigns.php).

---

## 4. Migraciones — la regla que más se olvida

> **Las migraciones deben correrse en LOS DOS schemas de Postgres: `converza` y
> `converza_dev`.**

Laravel guarda la tabla `migrations` **por schema**. Correr `php artisan migrate`
solo aplica al schema del `DB_SEARCH_PATH` activo. Si olvidas el otro, ese
entorno queda con tablas faltantes y errores del tipo
`relation "campaign_steps" does not exist`.

Por eso todas las migraciones nuevas llevan este comentario:

```php
// CORRER EN AMBOS schemas: converza y converza_dev (override DB_SEARCH_PATH).
```

Cómo hacerlo:

```powershell
# schema de desarrollo
$env:DB_SEARCH_PATH="converza_dev"; php artisan migrate

# schema de producción
$env:DB_SEARCH_PATH="converza";     php artisan migrate
```

En producción el deploy corre `php artisan migrate --force` con el
`DB_SEARCH_PATH` del `.env` del droplet (`converza`).

### Cómo escribir una migración aquí

- Nombre: `AAAA_MM_DD_NNNNNN_descripcion.php`.
- Clase anónima (`return new class extends Migration`), estilo Laravel 11+.
- Incluye siempre el comentario de los dos schemas.
- `down()` real, no vacío.
- Si la migración hace **backfill** de datos, que sea **idempotente**: comprobar
  antes de insertar. Ejemplo modelo:
  [2026_06_17_000002_create_campaign_sequences_tables.php](../database/migrations/2026_06_17_000002_create_campaign_sequences_tables.php).
- Cuidado con `enum` en Postgres: alterar un `CHECK` existente es doloroso. Las
  columnas de estado nuevas se declaran como `string(20)` a propósito (ver
  `campaign_recipients.enrollment_status`).

---

## 5. Mapa: "quiero cambiar X, ¿dónde toco?"

| Quiero… | Backend | Frontend |
|---|---|---|
| Cambiar la bandeja de chat | [`ChatController`](../app/Http/Controllers/ChatController.php) | [`Pages/Chat/Index.vue`](../resources/js/Pages/Chat/Index.vue) |
| Cambiar cómo se interpreta un mensaje entrante | [`ProcessIncomingWhatsAppMessage::buildAttributes`](../app/Jobs/ProcessIncomingWhatsAppMessage.php) | — |
| Agregar un tipo de mensaje de WhatsApp | ídem, nuevo `case` en el `switch` | render en `Chat/Index.vue` |
| Cambiar el bot | [`HandleBotResponse`](../app/Jobs/HandleBotResponse.php) + [`IntentDetector`](../app/Services/Bot/IntentDetector.php) · ver [bot.md](bot.md) | [`Pages/Settings/Bot.vue`](../resources/js/Pages/Settings/Bot.vue) |
| Cambiar el horario o los pasos del bot | [`BotSetting`](../app/Models/BotSetting.php) (`respondsAt()`, `defaults()`) + [`BotSettingsController`](../app/Http/Controllers/BotSettingsController.php) | ídem |
| Agregar un aviso automático | [`EventCatalog`](../app/Services/Notifications/EventCatalog.php) + el comando correspondiente | `Settings/Index.vue` (aparece solo) |
| Agregar una variable a un aviso | `EventCatalog::events()` + `resolveValues()` | automático |
| Cambiar plantillas de Meta | [`TemplateController`](../app/Http/Controllers/TemplateController.php) | [`Pages/Templates/Index.vue`](../resources/js/Pages/Templates/Index.vue) |
| Cambiar campañas | [`CampaignController`](../app/Http/Controllers/CampaignController.php), `Services/Campaigns/*` | `Pages/Campaigns/*` |
| Cambiar métricas | [`MetricsController`](../app/Http/Controllers/MetricsController.php) | [`Pages/Metrics/Index.vue`](../resources/js/Pages/Metrics/Index.vue) |
| Cambiar planes comerciales | [`PlanCatalog`](../app/Services/Brain/PlanCatalog.php) | automático |
| Agregar una ruta | [`routes/web.php`](../routes/web.php) | `route('nombre')` disponible vía Ziggy |
| Agregar un ítem al menú | — | `navItems` en [`AppLayout.vue`](../resources/js/Layouts/AppLayout.vue) |
| Cambiar el manual dentro de la app | — | [`Pages/Manual/Index.vue`](../resources/js/Pages/Manual/Index.vue) |

---

## 6. Convenciones del repositorio

### 6.1 Backend

**Controladores delgados, servicios gordos.** La lógica de dominio vive en
`app/Services`. Un controlador valida, delega y devuelve `Inertia::render` o
`back()`.

**Inyección por constructor con promoción:**

```php
public function __construct(
    protected WhatsAppService $whatsappService,
    protected IspwatchRepository $ispwatch,
    protected PresenceService $presence,
) {}
```

**Props pesadas como closures.** Si una prop de Inertia hace una consulta cara y
no se necesita en cada poll, entrégala como `fn () => …`.

```php
return Inertia::render('Chat/Index', [
    'conversations' => $conversations,              // siempre
    'ispwatchCustomer' => fn () => $resolve()[0],   // solo en carga completa
]);
```

**Validación tenant-aware:**

```php
Rule::exists('conversations', 'id')->where('tenant_id', $tenantId)
```

**Route-model binding + guard explícito:**

```php
public function reopen(Conversation $conversation)
{
    abort_if($conversation->tenant_id !== app('tenant')->id, 403);
    …
}
```

**`updateQuietly()` cuando el observer no debe dispararse.** El
`ConversationObserver` apaga el bot al asignar; si el propio bot actualiza la
conversación con `update()` normal entra en bucle.

**Comentarios en español, explicando el *porqué*.** El estilo de este repo no es
comentar lo obvio, es dejar escrito el motivo de una decisión no evidente —
sobre todo si viene de un bug real. Ejemplo del código actual:

```php
// Las reacciones quedan afuera: el webhook las guarda como entrantes
// (status='received'), así que un 👍 del cliente a nuestra propia respuesta
// devolvía la conversación al verde como si tuviera algo pendiente.
```

Ese comentario vale más que el código que documenta. **Mantén ese estándar.**

### 6.2 Frontend

- Vue 3 `<script setup>`, sin Options API.
- Tailwind 4 con tokens semánticos (`bg-sidebar`, `text-accent`, `bg-sidebar-hover`).
- Formularios con `useForm` de Inertia.
- Iconos: SVG inline de Heroicons, no librería externa.
- Los estados de carga se manejan con `form.processing`.
- El tema (claro/oscuro/sistema) vive en `localStorage` bajo `converza-theme` y
  lo aplica `AppLayout.vue` añadiendo la clase `dark` al `<html>`.

### 6.3 Nombres

- Tablas y columnas: `snake_case`, plural para tablas.
- Rutas nombradas: `modulo.accion` (`chat.conversations.assign`).
- Páginas Inertia: `Modulo/Accion` en PascalCase (`Campaigns/Create`).
- Claves de evento de aviso: `snake_case` y **coinciden** con
  `BillingNotificationLog::KIND_*`.

---

## 7. Recibir webhooks reales en local

Meta solo admite **una** URL de webhook por app. El flujo es:

```
Meta ──► Producción ──(reenvía)──► tu ngrok ──► tu localhost:8000
```

**1. Levanta local con cola:**
```powershell
composer dev
```

**2. Expón el puerto:**
```powershell
.\ngrok.exe http 8000
# Copia la URL https://xxxx.ngrok-free.app
```

**3. Activa el forwarder en producción:**
```bash
ssh deploy@159.223.140.27
nano /var/www/converza-crm/.env
# WEBHOOK_FORWARD_URL=https://xxxx.ngrok-free.app/webhook
cd /var/www/converza-crm && php artisan config:cache
```

**4. Prueba** enviando un WhatsApp al número. Deberías ver:

| Dónde | Qué |
|---|---|
| Log crudo de prod | una línea nueva en `storage/logs/webhook-raw-<hoy>.log` |
| Consola de ngrok | `POST /webhook` |
| Log local (pail) | El mismo mensaje procesado |

**5. Apágalo al terminar** (`WEBHOOK_FORWARD_URL=` vacío + `config:cache`).
Si lo dejas encendido, producción sigue gastando 3 s de timeout por webhook
intentando alcanzar una URL muerta.

El reenvío es *best-effort*: `WhatsAppController::forwardWebhook` usa timeout de
3 s y traga la excepción. Nunca puede tumbar producción.

---

## 8. Alta de un tenant nuevo

Proceso completo para incorporar un ISP:

**1. Crear el tenant** — como superadmin, en `/admin/tenants` (o vía tinker).

**2. Crear el usuario admin del tenant**

```php
User::create([
    'tenant_id' => $tenantId,
    'name'      => 'Admin del ISP',
    'email'     => 'admin@isp.com',
    'password'  => 'contraseña',   // el cast 'hashed' la encripta
]);
```

Luego créale su `StaffMember` con `role = 'admin'` (o entra al chat una vez: el
`ChatController` autocrea el staff con rol `agent`, y después lo promueves desde
`/staff`).

**3. Vincular con ispwatch** (si el ISP usa ispwatch):

```bash
php artisan tenant:link --list       # ver estado actual
php artisan tenant:link 1 17         # Converza #1 ↔ ispwatch #17
php artisan tenant:link 1 --unlink   # desvincular
```

El comando valida que el `ispwatch_tenant_id` exista de verdad, avisa si otro
tenant ya lo usa e invalida el cache para que el cambio se vea al instante.

**4. Configurar WhatsApp** — el admin del ISP entra a `/settings` → WhatsApp e
ingresa Phone Number ID, Business Account ID, Access Token, App Secret y Verify
Token. El botón **Probar conexión** valida contra Graph API sin enviar mensajes.

**5. Suscribir el número al webhook en Meta**, apuntando a
`https://converza-crm.duckdns.org/webhook` con el verify token del tenant. Campo
obligatorio: `messages`.

**6. Sincronizar plantillas** desde `/templates` → *Sincronizar*.

**7. Activar avisos** (opcional) en `/settings` → Avisos: asignar plantilla a
cada evento y encenderlo.

---

## 9. Depuración

### 9.1 Logs

```powershell
# local
Get-Content storage\logs\laravel.log -Wait
php artisan pail            # más legible, incluido en `composer dev`
```

```bash
# producción
tail -f /var/www/converza-crm/storage/logs/laravel.log
sudo tail -f /var/log/supervisor/converza-worker.log
```

Cadenas útiles para grepear:

| Cadena | Significa |
|---|---|
| `inbound` en `webhook-raw-*.log` | Llegó un webhook (cuerpo crudo completo). **No está en `laravel.log`**: va a su propio canal, con nivel fijo, para que `LOG_LEVEL=warning` no lo descarte |
| `falló el despacho; el payload quedó en webhook-raw.log` | El webhook entró pero no se pudo procesar (BD o Redis caídos). Se repara solo con `webhooks:reconcile` |
| `Webhook sin identidad de remitente: ni teléfono ni BSUID` | Payload raro: Meta no mandó ni `from` ni `from_user_id`. Es el único caso en que un mensaje se descarta |
| `Contacto duplicado: la misma persona tiene ficha por teléfono y por BSUID` | El cliente escribió antes con el número oculto y ahora con teléfono. Dos fichas, una persona → `conversations:merge-duplicates` |
| `Reconciliación de webhooks: se encontraron mensajes sin guardar` | Hubo huecos y se reprocesaron |
| `Reconciliación de webhooks: no corrió durante un rato largo` | El scheduler estuvo parado ≥15 min: el servidor o la base se cayeron. **Es la alerta de caída**, y salta aunque no se pierda ningún mensaje |
| `Reconciliación de webhooks: la marca de agua excede el tope` | El corte duró más que `--max-horas`; puede quedar un hueco sin reparar antes de ese punto → `webhooks:replay` |
| `ningún tenant coincide con phone_number_id/WABA` | El número no está en ningún tenant activo |
| `WhatsApp API Error` / `sendTemplate Error` | Meta rechazó el envío (el body trae el motivo) |
| `WhatsApp message type without explicit handler` | Tipo de mensaje nuevo sin `case` propio |
| `Duplicate webhook job ignored` | Meta reintentó; idempotencia funcionando |
| `cannot decrypt tenant access token` | `APP_KEY` cambió o el token se guardó en claro |
| `ffmpeg audio transcode failed` | Falta ffmpeg o el audio está corrupto |
| `no se pudo guardar copia local` | Permisos de `storage/app/public` |

### 9.2 Cola

```powershell
php artisan queue:work --once      # procesar un job y ver el error en pantalla
php artisan queue:failed           # jobs fallidos
php artisan queue:retry all
php artisan queue:flush            # limpiar fallidos
```

```php
// ¿cuántos jobs pendientes?
// OJO: en producción la cola es REDIS, así que DB::table('jobs')->count()
// siempre devuelve 0. Queue::size() funciona con cualquier driver.
Illuminate\Support\Facades\Queue::size();
```

### 9.3 Cache de ispwatch

`IspwatchRepository` cachea 60 s las consultas de UI. Si cambiaste datos en
ispwatch y no se reflejan:

```powershell
php artisan cache:clear
```

Las consultas de batch (avisos) **no** están cacheadas: si un aviso usa datos
viejos, el problema no es el cache.

### 9.4 Probar avisos sin enviar nada

Todos los comandos de aviso tienen `--dry-run` y filtros:

```powershell
php artisan whatsapp:billing-notify --dry-run --tenant=1
php artisan whatsapp:billing-notify --dry-run --tenant=1 --customer=1234
php artisan whatsapp:billing-notify --dry-run --date="2026-08-15 09:00"
php artisan whatsapp:events-notify  --dry-run --tenant=1 --event=payment_registered
```

`--date` simula la corrida en la hora local del ISP, lo que permite probar el día
de facturación sin esperar al mes que viene.

### 9.5 Verificar credenciales de WhatsApp desde tinker

```php
$t = App\Models\Tenant::find(1);
$wa = app(App\Services\WhatsAppService::class)->forTenant($t);
$wa->checkConnection();     // ['connected' => true, 'reason' => 'ok']
$wa->accountHealth(true);   // quality_rating, messaging_limit_tier, …
```

### 9.6 «¿Está mal mi `.env` o está caído el servicio?»

```bash
php artisan deploy:verify
```

Comprueba las **dos** conexiones de Postgres (`pgsql` e `ispwatch`), Redis y que
`storage/logs` sea escribible. Imprime el motivo completo de cada falla —con
conexión, host y puerto— y sale con código 1 si algo no responde.

Es el mismo `HealthChecker` que sirve `GET /health`, a propósito: si el deploy
comprobara menos cosas que el monitoreo, volveríamos al caso de la conexión
`ispwatch` con la contraseña vieja pasando desapercibida. La diferencia es que
`/health` **oculta** el detalle de la excepción, porque es una ruta pública y ese
mensaje trae host y usuario; en consola sí se imprime, que es donde hace falta.

Corre solo en cada despliegue y hace fallar el workflow si algo está mal. Para
rotar credenciales a mano, ver
[operaciones.md §6 bis](operaciones.md#6-bis-rotar-credenciales-sin-tumbar-producción).

Para revisar la suscripción del webhook en Meta (solo lectura):

```php
$token = $t->wa_access_token;
Http::withToken($token)
    ->get("https://graph.facebook.com/v20.0/{$t->wa_business_account_id}/subscribed_apps")
    ->json();
```

Si `data` viene vacío, el número no está suscrito y **no llegará ningún mensaje
entrante** aunque los envíos salgan bien.

### 9.6 Reproducir con datos de producción sin romper nada

```php
DB::beginTransaction();
try {
    // … código que quieres probar …
} finally {
    DB::rollBack();
}
```

---

## 10. Testing

**Estado actual: la suite no sirve.**
[`tests/Feature/WhatsAppTest.php`](../tests/Feature/WhatsAppTest.php) prueba una
ruta `POST /messages` y un archivo `messages.json` que no existen desde hace
versiones. `ExampleTest` es el scaffold de Laravel.

```powershell
composer test        # config:clear + artisan test
php artisan test --filter=NombreDelTest
```

Antes de escribir tests nuevos hay que resolver que el `.env` de testing apunte a
un schema desechable. Propuesta concreta y orden sugerido de cobertura en
[mejoras.md](mejoras.md#m-02-la-suite-de-tests-está-muerta).

Mientras tanto, la verificación real es manual:

1. `composer dev`
2. Enviar un WhatsApp al número de pruebas
3. Confirmar que aparece en `/chat`, responder, y verificar que llega

---

## 11. Estilo de commits y ramas

Ramas observadas en el repo: `main` (producción), `pruebas`, y ramas por
funcionalidad (`feature/router-outage`, `feature/document-send-and-preview`,
`chat-filters`).

Formato de commit habitual del proyecto (Conventional Commits, mensaje en
español):

```
feat: catálogo de planes SaaS + corte de servicio + cross-link de cuentas
fix: envío de documentos (PDF) y links clickeables en el chat
add: Columna raw_metadata añadida a la tabla de messages
```

**Flujo:** rama de feature → PR a `main` → merge → GitHub Actions despliega solo.

⚠️ Cada push a `main` **despliega a producción**. No hay ambiente de staging.

---

## 12. Deploy (resumen)

Automático vía [.github/workflows/deploy.yml](../.github/workflows/deploy.yml):

1. El runner de GitHub hace `npm ci && npm run build` (el droplet tiene 1 GB de
   RAM y el OOM-killer mata a Vite).
2. `rsync --delete` sube `public/build/` al droplet.
3. Por SSH: `git reset --hard <sha>`, `composer install --no-dev`,
   `migrate --force`, `config:cache`, `route:cache`, reload de PHP-FPM y restart
   de los workers de Supervisor.

`concurrency: deploy-production` garantiza un deploy a la vez.

Detalle completo, setup inicial del servidor y fallback manual en
[operaciones.md](operaciones.md) y [../deploy/DEPLOY.md](../deploy/DEPLOY.md).

---

## 13. Errores clásicos al desarrollar aquí

| Síntoma | Causa | Solución |
|---|---|---|
| El webhook llega pero nada aparece en el chat | La cola no corre | `composer dev` en vez de `npm run dev` |
| `relation "X" does not exist` | Migración corrida en un solo schema | Correr en `converza` **y** `converza_dev` |
| El chat muestra datos de otro tenant | Job sin rebindar el tenant | Aplicar el patrón forget/bind/finally |
| El sidebar de ispwatch muestra datos viejos | Cache de 60 s | `php artisan cache:clear` |
| Enviar imagen da 500 en producción | `storage/app/public` sin permisos de `www-data` | Ver [operaciones.md](operaciones.md#permisos-de-storage) |
| Grabar audio falla | Falta ffmpeg | `apt install ffmpeg` |
| Cambié `.env` en prod y no surte efecto | Config cacheada | `php artisan config:cache` |
| El bot responde dos veces | Falta el lock por conversación | Ya está en `HandleBotResponse`; no lo quites |
| `route()` no existe en Vue | Ziggy desactualizado | Recargar la página tras cambiar rutas |
| Meta rechaza la plantilla | Categoría o variables mal | Ver [integracion-whatsapp.md](integracion-whatsapp.md#plantillas) |

---

## 14. Comandos de referencia

```powershell
# Desarrollo
npm run dev                          # server + vite
composer dev                         # server + queue + logs + vite
php artisan tinker
php artisan route:list

# Base de datos
php artisan migrate
php artisan migrate:status
php artisan cache:clear

# Multi-tenancy (dueño del SaaS)
php artisan tenant:link --list
php artisan tenant:link 1 17
php artisan tenant:link 1 --unlink

# Avisos automáticos
php artisan whatsapp:billing-notify --dry-run --tenant=1
php artisan whatsapp:events-notify  --dry-run --tenant=1
php artisan reminders:send --dry-run          # legado, sin agendar

# Campañas
php artisan campaigns:tick

# Mantenimiento
php artisan media:clean --dry-run --days=90
php artisan chat:backfill-answered-reads --dry-run

# Colas
php artisan queue:work --once
php artisan queue:failed
php artisan queue:retry all
```
