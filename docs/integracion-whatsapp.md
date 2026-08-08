# Integración con WhatsApp Cloud API

> **En una frase:** todo lo que entra pasa por `POST /webhook` y todo lo que sale
> pasa por `WhatsAppService`; en medio están la ventana de 24 h, las plantillas y
> los límites de Meta.

**Archivos clave**
- [app/Http/Controllers/WhatsAppController.php](../app/Http/Controllers/WhatsAppController.php) — webhook
- [app/Services/WhatsAppService.php](../app/Services/WhatsAppService.php) — cliente de Graph API
- [app/Jobs/ProcessIncomingWhatsAppMessage.php](../app/Jobs/ProcessIncomingWhatsAppMessage.php) — traducción de payloads
- [app/Jobs/ProcessWhatsAppStatusUpdate.php](../app/Jobs/ProcessWhatsAppStatusUpdate.php) — acuses de entrega
- [app/Http/Controllers/TemplateController.php](../app/Http/Controllers/TemplateController.php) — plantillas
- [config/services.php](../config/services.php) — configuración

---

## 1. Las reglas de Meta que condicionan el diseño

### 1.1 Ventana de servicio de 24 horas

Tras cada mensaje del cliente se abre una ventana de 24 h en la que puedes
enviar mensajes libres. Fuera de ella, **solo plantillas aprobadas**.

Consecuencia en el código: `sendMessage()` y `sendMedia()` sirven al chat en
vivo; **todo** lo iniciado por el negocio (avisos, campañas) usa `sendTemplate()`.

### 1.2 Niveles de mensajería (*messaging tier*)

Cuántos **destinatarios únicos** puedes contactar por iniciativa propia cada
24 h: 250 → 1.000 → 10.000 → 100.000 → ilimitado. Meta sube el nivel solo, si la
calidad se mantiene.

### 1.3 Calificación de calidad

Verde / amarillo / rojo, basada en bloqueos y reportes. En rojo, Meta baja el
nivel o restringe el número.

Ambos se consultan con `WhatsAppService::accountHealth()` (cache de 10 min) y se
muestran en *Configuración → Salud del número*.

### 1.4 Categorías de plantilla

`marketing` · `utility` · `authentication`. Usar utility para promoción viola la
política. Las campañas de Converza **exigen** `marketing`
([config/campaigns.php](../config/campaigns.php)).

---

## 2. Webhook

### 2.1 URL y verificación

```
GET  https://converza-crm.duckdns.org/webhook   ← handshake
POST https://converza-crm.duckdns.org/webhook   ← eventos
```

Ambas rutas son **públicas** (sin `auth`) y están **exentas de CSRF**
([bootstrap/app.php](../bootstrap/app.php)).

El handshake acepta dos tokens:

1. `WHATSAPP_VERIFY_TOKEN` del `.env` (global, compatibilidad).
2. Cualquier `tenants.wa_verify_token` de un tenant **activo** — así cada cliente
   puede suscribir su número con su propio token.

Comprobación manual:

```bash
curl "https://converza-crm.duckdns.org/webhook?hub.mode=subscribe&hub.verify_token=TU_TOKEN&hub.challenge=TEST123"
# debe responder: TEST123
```

### 2.2 Campos a suscribir en Meta

Obligatorio: **`messages`**. Los demás (`account_alerts`,
`phone_number_quality_update`…) son opcionales y hoy se ignoran.

### 2.3 Resolución del tenant

Meta puede batchear varios mensajes y varias WABAs en un solo webhook. Por cada
`entry.changes.value` se resuelve el dueño así:

| Orden | Criterio |
|---|---|
| 1 | `tenants.wa_phone_number_id == value.metadata.phone_number_id` (activo) |
| 2 | `tenants.wa_business_account_id == entry.id` (activo) |
| 3 | Tenant `default`, **solo si** `WHATSAPP_API_URL` termina en ese `phone_number_id` |

Sin match se registra
`Webhook: ningún tenant coincide con phone_number_id/WABA` y los mensajes se
descartan. Es el primer log a revisar cuando "no llega nada".

### 2.4 Respuesta inmediata

El controlador solo **encola** y responde `{"status":"EVENT_RECEIVED"}` al
instante. Si Meta no recibe un 200 rápido, reintenta y genera duplicados
(absorbidos por el índice único de `wa_message_id`).

### 2.5 Reenvío a desarrollo

Si `WEBHOOK_FORWARD_URL` está definida, producción reenvía el payload íntegro a
esa URL (típicamente tu ngrok) con timeout de 3 s y excepción tragada. Nunca
puede tumbar producción. Ver
[manual-desarrollador.md §7](manual-desarrollador.md#7-recibir-webhooks-reales-en-local).

### 2.6 ⚠️ La firma NO se verifica

`X-Hub-Signature-256` se **reenvía** al forwarder pero **no se valida** contra
`WHATSAPP_APP_SECRET`. Cualquiera que conozca la URL puede inyectar mensajes
falsos. Ver [mejoras.md](mejoras.md#m-01-el-webhook-no-verifica-la-firma-de-meta).

---

## 3. Tipos de mensaje entrante

`ProcessIncomingWhatsAppMessage::buildAttributes()` traduce el payload de Meta a
una fila de `messages`:

| `type` | Qué se guarda en `body` |
|---|---|
| `text` | El texto |
| `image`/`audio`/`video`/`document` | El caption, o `📷 Imagen` / `🎤 Audio` / `🎥 Video` / `📎 Documento` |
| `sticker` | `[Sticker]` (no se descarga: irrelevante en un CRM) |
| `location` | `📍 Ubicación: lat, lng` (el chat la enlaza a Google Maps) |
| `contacts` | `👤 Contacto: nombres` |
| `reaction` | `Reaccionó con X` o `Quitó su reacción` |
| `button` | El texto visible del botón |
| `interactive` | Título de la respuesta de botón o lista |
| `order` | `🛒 Pedido: N productos` + payload en `raw_metadata` |
| `system` | El texto de Meta (ej. cambio de número) |
| `unsupported` | Texto amigable + error de Meta en `raw_metadata` |
| *(cualquier otro)* | Rescate best-effort de texto legible + `raw_metadata` + **warning en el log** |

> **`unsupported`** es lo que Meta manda por encuestas, mensajes que se
> autodestruyen, tarjetas de contacto y —caso frecuente— los códigos de
> verificación de Meta con botón "Copiar código". **El contenido no viaja en el
> webhook**: el código real no es recuperable desde el chat. Por eso se muestra
> un texto explicativo al agente y el detalle técnico queda en `raw_metadata`.

> **Agregar un tipo nuevo:** el `default` loguea
> `WhatsApp message type without explicit handler`. Si ese warning aparece
> seguido, agrega un `case` propio en el `switch`.

---

## 4. Medios

### 4.1 Entrada

Tipos descargados: `MEDIA_DOWNLOAD_TYPES` (default `image,audio,video,document`).
Para los no descargados solo se guarda metadata.

Flujo (`WhatsAppService::downloadMedia`):

1. `GET graph.facebook.com/{version}/{media_id}` → URL temporal y MIME.
2. Descarga del binario con el token del tenant.
3. Si es imagen → compresión con GD: redimensiona a `MEDIA_MAX_WIDTH` (1200 px),
   fondo blanco para PNG con transparencia, salida JPEG al `MEDIA_JPEG_QUALITY`
   (75). Solo se usa la comprimida si de verdad es más pequeña.
4. Guardado en `whatsapp-media/{media_id}.{ext}`.

Sin extensión GD la compresión se salta con un log informativo — no rompe nada.

### 4.2 Salida

`uploadMedia()` sube al endpoint del **propio número**
(`{phone_number_id}/media`) y devuelve un `media_id`; `sendMedia()` lo envía.

> Cuidado con la asimetría: para **descargar** se consulta
> `graph.facebook.com/{version}/{media_id}` (sin el phone id), pero para **subir**
> el endpoint sí está scopeado al `phone_number_id`.

Para `type = 'document'` se envía además `filename`: es el título que ve el
destinatario. Sin él, WhatsApp muestra el `media_id` como nombre del archivo.

### 4.3 Límites y validación

| Regla | Valor |
|---|---|
| Tamaño máximo aceptado por Converza | **16 MB** |
| Tipos permitidos al enviar | jpeg, jpg, png, webp, gif · ogg, oga, mp3, m4a, aac, amr, webm, weba · mp4, 3gp · pdf, doc, docx, xls, xlsx, ppt, pptx, txt, csv |

El tipo de mensaje se deriva del **MIME real detectado**, no de la extensión:
`image/*` → image, `video/*` → video, `audio/*` → audio, **todo lo demás** →
document.

> Este `match` arregló un bug real: antes se asumía audio para cualquier cosa que
> no fuera imagen, así que un PDF entraba al transcodificador de ffmpeg y el
> envío fallaba.

### 4.4 Notas de voz y ffmpeg

Los navegadores graban en `webm/opus`, que WhatsApp **no acepta**. Converza
transcodifica a OGG/opus con ffmpeg:

```
ffmpeg -y -i in.webm -vn -c:a libopus -b:a 32k -ar 48000 -ac 1 out.ogg
```

Sin ffmpeg el envío falla con *"No se pudo procesar el audio grabado"*.
Instalación: `apt install ffmpeg`. Ruta configurable con `FFMPEG_PATH`.
Subir un `.mp3`/`.ogg`/`.m4a` ya listo **no** requiere ffmpeg.

> **Notas de voz mudas:** si una nota sale sin audio, la causa es casi siempre la
> **captura del navegador/micrófono**, no el servidor. Por eso el chat muestra un
> medidor de nivel en vivo y **bloquea el envío** si no detecta señal. Para
> verificar un archivo: `ffprobe` + filtro `volumedetect`.

### 4.5 Almacenamiento

`MEDIA_DISK` admite `public` (local), `supabase` o `s3`. **Hoy solo el local está
operativo**: el disco `supabase` no funciona en el droplet. El código intenta el
disco remoto y **siempre** guarda una copia local como respaldo.

El guardado local está envuelto en try/catch: si `storage/app/public` no es
escribible por `www-data`, el mensaje **igual se registra** (ya se envió a
WhatsApp); solo la miniatura queda rota. Ver
[operaciones.md](operaciones.md#permisos-de-storage).

Los archivos se sirven por `GET /media/{path}`
([`MediaController`](../app/Http/Controllers/MediaController.php)), que **requiere
autenticación** y evita depender del symlink de storage. Con `?download=1&name=…`
fuerza `Content-Disposition: attachment` con el nombre original.

`media:clean` borra semanalmente los archivos de más de `MEDIA_CLEANUP_DAYS`
(90) días. **La metadata en base se conserva.**

---

## 5. Plantillas

### 5.1 Sincronización

`POST /templates/sync` consulta la WABA del tenant y trae nombre, categoría,
idioma, cuerpo y estado de cada plantilla aprobada.

### 5.2 Creación y envío a aprobación

Se pueden redactar en Converza y enviar a Meta con `POST /templates/{id}/submit`.
Elementos soportados: header de texto, body, footer y botón con URL.

### 5.3 Variables: nombradas vs. posicionales

```php
Template::namedVariablesIn($body);       // {{nombre_cliente}} → ['nombre_cliente']
Template::positionalVariablesIn($body);  // {{1}} → [1]
```

**Las nuevas usan variables nombradas.** `WhatsAppService::sendTemplate()` decide
el formato según el tipo de array recibido:

```php
// lista → posicional {{1}}, {{2}}
['Juan', '$45.000']
// mapa → named params {{nombre_cliente}}, {{monto}}
['nombre_cliente' => 'Juan', 'monto' => '$45.000']
```

Ventaja de las nombradas: el cliente nunca ve un `{{1}}` pelado y **el orden en
que las escriba no importa**.

### 5.4 Idioma exacto

El `language` debe coincidir **exactamente** con el de la plantilla en Meta
(`es_CO` ≠ `es`). Un desajuste devuelve error 132001.

### 5.5 Restricciones al rellenar parámetros

- **Nada de saltos de línea ni tabuladores** dentro de un parámetro: Meta los
  rechaza. Por eso `detalle_pendientes` sale en una sola línea separada por comas.
- Hay un límite práctico de longitud por parámetro. Por eso ese mismo campo lista
  **máximo 6 facturas** y resume el resto como "y N más".

---

## 6. Acuses de estado

`ProcessWhatsAppStatusUpdate` procesa `value.statuses[]` y actualiza:

- `messages.status` → `sent` / `delivered` / `read` / `failed`
- `campaign_sends` y `campaign_recipients` (embudo de campañas)

El cruce es por `wa_message_id`.

---

## 7. Errores comunes de la Graph API

| Síntoma | Causa habitual |
|---|---|
| `(#131030) Recipient phone number not in allowed list` | Número de prueba: hay que registrar destinatarios en la app de Meta |
| `(#131047) Re-engagement message` | Fuera de la ventana de 24 h → usar plantilla |
| `(#132001) Template name does not exist` | Nombre o **idioma** incorrectos |
| `(#132000) Number of parameters does not match` | Faltan o sobran variables |
| `(#100) Invalid parameter` | Salto de línea o parámetro demasiado largo |
| `(#190) Access token expired` | Token temporal en vez de permanente |
| Envíos `sent` que nunca llegan | El número **no está suscrito** a la app |

### Verificar la suscripción (read-only)

```php
Http::withToken($tenant->wa_access_token)
    ->get("https://graph.facebook.com/v20.0/{$tenant->wa_business_account_id}/subscribed_apps")
    ->json();
```

Si `data` viene vacío, no llegará ningún mensaje entrante aunque los envíos
salgan sin error. **Es la causa #1 de "los salientes salen pero no entra nada".**

---

## 8. Modo mock

Sin credenciales (`baseUrl` vacía), `WhatsAppService` **no falla**: loguea el
envío y devuelve `['success' => true, 'mock' => true]`. Permite desarrollar el
flujo completo sin cuenta de Meta.

`ChatController::sendMedia` genera un `mock-{uuid}` como media_id en ese caso.

> Recuerda: el fallback al `.env` **solo** aplica al tenant `default`. Otro
> tenant sin credenciales queda en modo mock — y eso está bien, porque evita que
> mande mensajes por el número del dueño del SaaS.
