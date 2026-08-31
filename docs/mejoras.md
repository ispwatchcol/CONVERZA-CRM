# Mejoras y deuda técnica

> **En una frase:** hallazgos concretos sobre el código actual, priorizados por
> riesgo × esfuerzo, con la corrección propuesta para cada uno.

Este documento **no es una lista de deseos**. Cada punto apunta a un archivo y
una línea de comportamiento real. Cuando uno se resuelva, bórralo de aquí.

**Última revisión:** rama `feature/document-send-and-preview`.

---

## Resumen priorizado

| # | Mejora | Riesgo | Esfuerzo | Prioridad |
|---|---|:---:|:---:|:---:|
| [M-01](#m-01-el-webhook-no-verifica-la-firma-de-meta) | Verificar la firma del webhook | Alto | Bajo | 🔴 **1** |
| [M-04](#m-04-el-chat-carga-todo-sin-paginar) | Paginar chat y conversaciones | Alto | Medio | 🔴 **2** |
| [M-02](#m-02-la-suite-de-tests-está-muerta) | Resucitar los tests | Alto | Alto | 🔴 **3** |
| [M-07](#m-07-no-hay-rollback-ni-staging) | Staging + rollback | Medio | Medio | 🟠 **4** |
| [M-09](#m-09-no-hay-backup-propio-de-la-base) | Backup propio de la base | Alto | Bajo | 🟠 **5** |
| [M-06](#m-06-el-polling-de-5-s-es-el-techo-de-escalado-del-chat) | Migrar el chat a WebSockets | Medio | Alto | 🟡 **6** |
| [M-11](#m-11-la-normalización-de-teléfonos-está-duplicada-y-es-solo-colombiana) | Normalización de teléfonos | Medio | Medio | 🟡 **7** |
| [M-08](#m-08-código-legado-sin-retirar) | Retirar código legado | Bajo | Bajo | 🟡 **8** |
| [M-13](#m-13-el-almacenamiento-remoto-de-medios-no-funciona) | Arreglar disco remoto | Medio | Medio | 🟢 9 |
| [M-14](#m-14-métricas-que-procesan-en-php) | Métricas en SQL | Bajo | Medio | 🟢 10 |
| [M-15](#m-15-el-manual-in-app-está-incompleto) | Completar el manual in-app | Bajo | Bajo | 🟢 11 |
| [M-16](#m-16-sin-observabilidad) | Observabilidad | Medio | Medio | 🟢 12 |

---

## 🔴 Críticas

### M-01 · El webhook no verifica la firma de Meta

**Dónde:** [`WhatsAppController::handleWebhook`](../app/Http/Controllers/WhatsAppController.php)

**Qué pasa:** la cabecera `X-Hub-Signature-256` se **reenvía** al forwarder pero
nunca se valida contra `WHATSAPP_APP_SECRET`. La ruta es pública y está exenta de
CSRF.

**Consecuencia:** cualquiera que conozca la URL puede publicar un payload con un
`phone_number_id` válido (que además es descubrible) e inyectar mensajes falsos
en el chat de un tenant, disparar respuestas del bot, activar opt-outs y
contaminar métricas.

**Mitigación parcial existente:** los payloads sin tenant coincidente se
descartan y `wa_message_id` es único.

**Corrección:**

```php
// Middleware nuevo, aplicado a POST /webhook
$signature = $request->header('X-Hub-Signature-256', '');
$secret    = $tenant?->wa_app_secret ?? config('services.whatsapp.app_secret');
$expected  = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);

abort_unless(hash_equals($expected, $signature), 403);
```

**Complicación real:** el secreto es **por tenant**, pero el tenant se resuelve
*dentro* del payload. Opciones:

1. Validar contra el `WHATSAPP_APP_SECRET` global si todos los tenants comparten
   la misma app de Meta (caso más probable hoy).
2. Validar por tenant después de resolverlo, antes de encolar.

**Despliegue seguro:** loguear los fallos de firma sin rechazar durante unos días
para confirmar que no hay falsos negativos, y luego activar el `abort`.

---

### M-04 · El chat carga todo sin paginar

**Dónde:** [`ChatController::index`](../app/Http/Controllers/ChatController.php)

Dos consultas sin límite:

```php
$conversationModels = $conversationsQuery->orderByDesc('updated_at')->get();   // TODAS
$activeChat = Message::where('conversation_id', …)->orderBy('created_at')->get(); // TODOS
```

**Consecuencia:** un tenant con 5.000 conversaciones serializa las 5.000 en cada
carga, **y de nuevo en cada poll de 5 s**. Una conversación de dos años con
10.000 mensajes se envía entera cada vez. Es el límite de escalado más cercano
del sistema y se manifiesta como lentitud creciente, no como un error.

**Corrección:**

1. **Conversaciones:** límite de ~50 con scroll infinito o paginación por cursor
   sobre `updated_at`. El polling solo debería traer las que cambiaron desde el
   último `updated_at` conocido.
2. **Mensajes:** cargar los últimos ~50 y paginar hacia arriba. El polling solo
   debería traer `id > lastMessageId`.

Lo segundo es además la mejora de rendimiento percibido más grande disponible:
hoy cada poll re-serializa el hilo completo.

---

### M-02 · La suite de tests está muerta

**Dónde:** [`tests/Feature/WhatsAppTest.php`](../tests/Feature/WhatsAppTest.php)

Prueba `POST /messages` y un archivo `messages.json` que **no existen** desde
hace versiones. `ExampleTest` es el scaffold de Laravel. Es decir: **cero
cobertura real** sobre un sistema con aislamiento multi-tenant, dinero de por
medio y envíos que pueden banear un número.

**Corrección — orden sugerido por valor:**

**Paso 0.** `.env.testing` apuntando a un schema desechable (`converza_test`), con
`RefreshDatabase`. Sin esto no se puede hacer nada.

**Paso 1 — aislamiento multi-tenant** (lo que más duele si falla):
- Un usuario del tenant A no ve conversaciones del tenant B.
- Un job con `tenantId = B` no escribe en A aunque el contenedor tenga A bindeado.
- `Rule::exists` rechaza IDs de otro tenant.

**Paso 2 — webhook:**
- Resolución de tenant por `phone_number_id`, por WABA y sin match.
- Idempotencia: el mismo `wa_message_id` dos veces crea **un** mensaje.
- Cada tipo de mensaje del `switch` produce el `body` esperado.

**Paso 3 — avisos:**
- Idempotencia por ciclo.
- Se omite al cliente con `balance_due = 0`.
- Conversión de zona horaria.
- Arranque en frío no envía histórico.

**Paso 4 — campañas:** condición del paso, opt-out, tope de warm-up.

Usa `Http::fake()` para Graph API (ya está en el test viejo) y factories para
tenants y contactos.

---

## 🟠 Importantes

### M-07 · No hay rollback ni staging

**Hoy:** cada push a `main` despliega **directo a producción**. No hay ambiente
intermedio, ni rollback automatizado, ni reversión de migraciones.

**Consecuencia:** un bug llega a los clientes al instante, y volver atrás es un
procedimiento manual que **no restaura los assets ni el esquema**.

**Corrección incremental:**

1. Que el workflow guarde la build anterior de `public/build` antes del rsync.
2. Un workflow `rollback.yml` con `workflow_dispatch` que reciba un SHA,
   reconstruya sus assets y los despliegue.
3. A medio plazo: un droplet o subdominio de staging que despliegue desde
   `pruebas`.

---

### M-09 · No hay backup propio de la base

Toda la persistencia depende de la política de retención de Supabase. No hay
`pg_dump` programado ni copia fuera de ese proveedor.

**Corrección:** cron diario con `pg_dump` del schema `converza` a almacenamiento
externo, con retención de 30 días y **una prueba de restauración**. Un backup no
probado no es un backup.

---

## 🟡 Deseables

### M-06 · El polling de 5 s es el techo de escalado del chat

Cada agente con el chat abierto genera ~12 peticiones/minuto. Cada una ejecuta
`ChatController::index` completo: consultas de conversaciones, conteo de no
leídos, heartbeat de presencia y upsert de lectura.

Con 20 agentes son ~240 req/min solo de polling. En un droplet de 1 GB, eso es
carga real.

**Corrección:** Laravel Reverb (WebSockets) con eventos `MessageReceived` y
`ConversationAssigned`, y polling como respaldo.

**Coste:** un proceso más en el droplet y estado de conexión que hoy no existe.
No hacerlo antes de resolver [M-04](#m-04-el-chat-carga-todo-sin-paginar) — la
paginación da más alivio con mucho menos riesgo.

---

### M-11 · La normalización de teléfonos está duplicada y es solo colombiana

**Dónde:** tres implementaciones con la misma lógica:

- [`Contact::normalizePhone`](../app/Models/Contact.php)
- [`ProcessIncomingWhatsAppMessage::normalizePhone`](../app/Jobs/ProcessIncomingWhatsAppMessage.php) (privada)
- `IspwatchRepository` (normaliza a 10 dígitos para comparar)

Todas asumen Colombia: *10 dígitos que empiezan en 3 → prefijo 57*.

**Consecuencias:** (1) un cambio hay que hacerlo en tres sitios y es fácil olvidar
uno, provocando contactos duplicados; (2) un ISP de otro país no está soportado.

**Corrección:** un único `PhoneNormalizer` con el prefijo por defecto
configurable por tenant (`tenants.country_code`, default `57`). Migrar los tres
llamadores y añadir un test de la tabla de casos.

---

### M-08 · Código legado sin retirar

| Qué | Dónde | Estado |
|---|---|---|
| `reminders:send` | [`SendPaymentReminders.php`](../app/Console/Commands/SendPaymentReminders.php) | Reemplazado por `whatsapp:billing-notify`. **Sin agendar** |
| `tenants.wa_invoice_template` / `wa_reminder_template` | `tenants` | Reemplazadas por `tenant_notification_routes` |
| Columnas de envío en `campaign_recipients` | Migración de secuencias | Sustituidas por `campaign_sends` |
| Conexiones `mysql`, `mariadb`, `sqlsrv`, `sqlite` | [`config/database.php`](../config/database.php) | Sin uso |
| `messages.type = NULL` | Datos | Texto saliente legado; obliga a `whereNull('type') or …` en varias consultas |

**Corrección:** retirar el comando y las columnas en una migración de limpieza;
backfillear `type = 'text'` donde sea `NULL` y simplificar las consultas.

**Cuidado:** el `type = NULL` aparece en `latestMessage()` y en
`applyUnreadJoin()`. Migrar los datos **antes** de tocar esas consultas.

---

## 🟢 Menores

### M-13 · El almacenamiento remoto de medios no funciona

`MEDIA_DISK` soporta `supabase` y `s3`, pero el disco remoto **no está operativo
en el droplet**. El código intenta el remoto, falla, loguea y cae al local — un
intento fallido por cada medio.

**Consecuencia:** todo el almacenamiento vive en un droplet de 1 GB.
`media:clean` a 90 días es lo único que evita que se llene.

**Corrección:** o se arregla el disco remoto (S3/R2/Supabase) y se migra el
histórico, o se elimina el código muerto y se documenta que el almacenamiento es
local. Lo que no conviene es dejar la ambigüedad actual.

---

### M-14 · Métricas que procesan en PHP

**Dónde:** [`MetricsController::responseTime`](../app/Http/Controllers/MetricsController.php)

Calcula el tiempo de respuesta trayendo mensajes a PHP y emparejándolos en
memoria. El propio comentario del código dice que se hizo por portabilidad
pgsql ↔ mysql y que "si el dataset crece mucho, reemplazar por window function".

**Ya no hace falta esa portabilidad:** producción es Postgres. Con `LAG()` /
`LEAD()` esto es una sola consulta.

**Además:** `DashboardController::recentConversations` hace un lookup a ispwatch
**por cada** una de las 8 conversaciones (N+1 contra una base externa, mitigado
por el cache de 60 s). `IspwatchRepository` ya tiene `tenantInfoBatch()`; falta
el equivalente para clientes.

---

### M-15 · El manual in-app está incompleto

**Dónde:** [`Pages/Manual/Index.vue`](../resources/js/Pages/Manual/Index.vue)

Cubre 14 secciones pero **le faltan tres módulos completos** que sí están en
producción: **Campañas**, **Notificaciones automáticas** y **Bot**. También
describe funciones del chat que ya evolucionaron (no menciona notas internas,
grabación de audio, presencia ni filtros).

**Corrección:** portar las secciones correspondientes de
[manual-usuario.md](manual-usuario.md).

---

### M-16 · Sin observabilidad — *parcialmente resuelto (20/08/2026)*

Este punto dejó de ser teórico: el 20/08/2026 producción estuvo **9 h 54 m** sin
poder consultar la base —se rotó la contraseña de Supabase y el `.env` del
droplet quedó con la vieja— y se descubrió cuando alguien intentó entrar. Se
perdieron 18 mensajes entrantes.

**Ya hecho:**
1. ✅ `GET /health` profundo: ambas conexiones de BD, Redis y `storage/logs`
   escribible. Contrato estricto — `"status":"ok"` ⟺ 200 ⟺ todo verde.
2. ✅ Centinela externo (UptimeRobot cada 5 min, push a dos teléfonos).
3. ✅ Log crudo de webhooks + `webhooks:replay` y `webhooks:reconcile`, para que
   una caída no cueste mensajes.
   ✅ *(02/09/2026)* Mensajes de clientes con **username de WhatsApp**. Meta manda
   un BSUID en `from_user_id` y ningún `from`; el job los descartaba en silencio y
   se perdieron 20 mensajes de 5 clientes en dos semanas. El log crudo fue lo
   único que permitió reconstruirlo. Ver CON-68 y
   [integracion-whatsapp.md §2.4b](integracion-whatsapp.md).
   ✅ *(02/09/2026)* La reconciliación arranca en una **marca de agua** y no en
   una ventana fija de 60 min. La primera versión solo tapaba cortes de menos de
   una hora: en uno más largo, al recuperarse la ventana ya no alcanzaba el
   principio del corte y esos mensajes quedaban en el log crudo para siempre, sin
   que saltara ninguna alerta. Se destapó auditando un mensaje que nunca llegó al
   panel de Chaguani (CON-66/CON-67).
4. ✅ `deploy:verify` en el despliegue: falla el workflow si la config publicada
   no puede hablar con alguna dependencia. Cierra el hueco por el que
   `ISPWATCH_DB_PASSWORD` quedó vieja sin que nadie lo notara.
5. ✅ La contraseña de Postgres vive en un único sitio (secret `DB_PASSWORD` del
   repo) y `deploy/sync-secrets.php` la propaga a las dos variables del `.env` en
   cada despliegue. Rotar pasa a ser «cambiar un secreto y desplegar».

> ⚠️ La recomendación original de este punto —*«un healthcheck externo sobre
> `/up`»*— **era incorrecta y conviene no repetirla.** `/up` solo confirma que
> PHP responde: habría devuelto 200 durante las diez horas de caída. Igual que
> `/login`, que fue precisamente lo que enmascaró el incidente. Un health check
> que no toca las dependencias no es un health check.

**Lo que sigue faltando:**
1. **Sentry** (plan gratuito) para excepciones — sin esto, un 500 aislado que no
   tumba `/health` sigue siendo invisible.
2. **Que las alertas de la app lleguen a una persona.** `webhooks:reconcile`
   escribe `Log::error` cuando encuentra huecos, cuando detecta que no corrió en
   ≥15 min (señal de caída) y cuando la marca de agua excede el tope —pero nadie
   vigila ese archivo. Los huecos se reparan solos, así que la urgencia es menor;
   el que importa es el tercero, porque marca el tramo que **no** se reparó solo.
3. **Alerta por profundidad de cola.** `/health` reporta `Queue::size()` pero es
   informativo a propósito: un backlog no debería despertar a nadie de
   madrugada. Merece su propio umbral, aparte del health check.

---

## Ideas de producto (fuera del alcance técnico)

Sin priorizar; van aquí para no perderlas.

- **Bot con IA** en lugar de palabras clave: hoy `IntentDetector` es una lista
  fija muy acoplada al pitch de ISPWatch. Un LLM daría intención real y
  respuestas contextuales.
- **Plantillas de cierre configurables** por tenant, para estandarizar las
  métricas de cierre.
- **Programar mensajes individuales**, no solo campañas.
- **Multi-número por tenant:** hoy es un `phone_number_id` por workspace.
- **Exportar conversaciones** (PDF/CSV) para soporte y cumplimiento.
- **Encuestas de satisfacción** tras cerrar una conversación.
- **App móvil o PWA:** los asesores viven en el móvil.
- **Panel del cliente final** para consultar factura y estado sin escribir.
- **Automatización de cobros del SaaS** en el Core Brain (hoy todo manual, tal
  como se diseñó: "manual primero, automático después").
