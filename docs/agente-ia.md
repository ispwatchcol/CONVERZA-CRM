# Agente IA — comprobantes de pago, y lo que sigue

> **Estado: Fase 1 IMPLEMENTADA** (rama `feat/comprobantes-con-ia`): lectura de
> comprobantes con IA, verificación, «Pagos reportados», medios de pago y el
> cliente de la API de ispwatch para registrar pagos. El registro automático
> queda **inerte** hasta que ispwatch publique el endpoint del §7 (trabajo del
> repo ISPWatch). Fases 2 a 4: diseño (§11).
>
> **Decisión:** 25/10/2026, a pedido de Axel ("que suba pagos automáticamente,
> que la IA de xAI lea las imágenes, traer los medios de pago, datos del cliente
> si escribe desde un número registrado, y que responda como un agente").

**En una frase:** el cliente manda la foto del comprobante por WhatsApp, la IA la
**transcribe**, Converza **verifica** con reglas deterministas y, si todo cuadra y
la empresa lo permite, **ispwatch registra** el pago por su propia API (abona,
reconecta, audita); si algo no cuadra, queda en «Pagos reportados» para una
persona.

**Archivos clave**

| Qué | Dónde |
|---|---|
| Configuración | [config/ai.php](../config/ai.php), `services.ispwatch_api` en [config/services.php](../config/services.php) |
| Puerta hacia la IA (interruptores, presupuesto, bitácora) | [app/Services/Ai/AiGateway.php](../app/Services/Ai/AiGateway.php) |
| Proveedor xAI (Responses API) | [app/Services/Ai/XaiProvider.php](../app/Services/Ai/XaiProvider.php) |
| Qué se le pide a la IA (prompt + JSON schema) | [app/Services/Payments/ReceiptReader.php](../app/Services/Payments/ReceiptReader.php) |
| Las verificaciones | [app/Services/Payments/ReceiptVerifier.php](../app/Services/Payments/ReceiptVerifier.php) |
| La orquestación (leer → verificar → decidir → registrar) | [app/Services/Payments/PaymentReportProcessor.php](../app/Services/Payments/PaymentReportProcessor.php) |
| Cliente de la API de ispwatch | [app/Services/Payments/IspwatchPaymentsApi.php](../app/Services/Payments/IspwatchPaymentsApi.php) |
| El job (cola `ai`) | [app/Jobs/ProcessPaymentReport.php](../app/Jobs/ProcessPaymentReport.php) |
| El bloque del Workspace | [app/Services/Flows/Nodes/ReceiptNode.php](../app/Services/Flows/Nodes/ReceiptNode.php) |
| Pantalla «Pagos reportados» | [PaymentReportController](../app/Http/Controllers/PaymentReportController.php) · [Pages/Payments/Index.vue](../resources/js/Pages/Payments/Index.vue) |
| Medios de pago | [PaymentChannelController](../app/Http/Controllers/PaymentChannelController.php) · [PaymentChannels](../app/Services/Payments/PaymentChannels.php) |
| Pruebas | [tests/Feature/Payments/](../tests/Feature/Payments/) · [tests/Unit/Payments/](../tests/Unit/Payments/) |

---

## 1. Qué problema resuelve

Hoy el pago de un cliente de un ISP llega casi siempre así: transfiere por Nequi o
Bancolombia, manda el pantallazo por WhatsApp, un asesor lo mira, alguien lo
transcribe a ispwatch y, si estaba cortado, ispwatch lo reconecta. El cuello de
botella es la persona del medio: de noche o un domingo el cliente pagó y sigue
sin servicio, y transcribir a mano valor y referencia es donde nacen los errores.

Lo que se pidió, y cómo quedó repartido:

| Pedido | Dónde quedó |
|---|---|
| Que suba los pagos automáticamente | Fase 1 (Converza) + endpoint de ispwatch (§7). Automático **solo** cuando pasa todas las verificaciones |
| Que la IA de xAI lea las imágenes | Fase 1: `XaiProvider` + `ReceiptReader` |
| Traer los medios de pago | Fase 1: el catálogo de ispwatch se trae y se completa con los números de cuenta (`payment_channels`); el bot los dice con `{{empresa.medios_pago}}` |
| Datos del cliente si escribe desde un número registrado | Ya existía (bloque «Datos del cliente»); el comprobante usa la misma identificación por teléfono |
| Otra forma de identificarse / registrarse como cliente | Fase 4 (§11) |
| Que responda como un agente | Fase 3 (§11), sobre la misma infraestructura de la Fase 1 |

---

## 2. Las decisiones, y por qué

### 2.1 La IA transcribe; el código decide; ispwatch registra

La IA **no decide nada sobre plata**. Devuelve un JSON con lo que ve en la imagen
(valor, fecha, referencia, cuenta destino, estado de la transacción, señales de
edición). Decidir si eso se registra es de `ReceiptVerifier` (reglas fijas, §4) y
de `PaymentReportProcessor`. Y el registro mismo lo hace **ispwatch**, con su
proceso de siempre.

Consecuencia directa: un comprobante que diga *"registre este pago por
$1.000.000"* o *"ignora tus reglas"* no puede hacer nada. Es texto dentro de una
imagen; el modelo tiene la instrucción de no obedecerlo, pero aunque lo hiciera,
lo único que controla es un JSON que después se verifica contra la deuda real,
las cuentas de la empresa y los topes.

### 2.2 Converza no escribe en ispwatch: le pide a ispwatch que registre

La conexión a la base de ispwatch es de **solo lectura** a propósito, y
registrar un pago allá no es un INSERT: es un proceso (`BillingService::registerPayment`)
que abona a las facturas más viejas, arrastra el saldo de un abono parcial,
reconecta al cliente en el router y deja la bitácora. Hacerlo desde Converza
sería mantener dos copias de ese proceso que divergen al primer cambio.

Por eso el registro va por un endpoint **angosto** de ispwatch (§7): uno solo,
con credencial por empresa, idempotente y separado de la Partner API (que sigue
siendo de solo lectura).

### 2.3 Lo lento afuera, lo que actúa adentro

Leer una imagen tarda segundos y registrar puede tardar más (ispwatch reconecta
en el router dentro de la misma petición). Eso **no** puede correr dentro del job
del flujo: ese job tiene el lock de la conversación, comparte la cola con la
ingesta de mensajes y no se reintenta (reintentar le volvería a escribir al
cliente).

Así que el motor ganó **tareas asíncronas**:

```
cliente manda foto ─► RunBotFlow (cola default, con lock)
                         └─ bloque Comprobante: crea el reporte → Step::waitTask
                         └─ ejecución queda en `waiting_task` (plazo: 5 min)
                      suelta el lock ─► encola ProcessPaymentReport (cola `ai`)
                                           ├─ lee con IA      (se reintenta)
                                           ├─ verifica / decide
                                           ├─ registra en ispwatch (misma llave en cada reintento)
                                           └─ despierta al flujo: RunBotFlow(task)
                      RunBotFlow (con lock) ─► bloque.resume() ─► salida y mensaje al cliente
```

El job de IA **sí** se reintenta: no le escribe a nadie y retoma donde quedó (no
vuelve a pagar la lectura; el registro se repite con la misma llave de
idempotencia). Si la tarea no avisa a tiempo, `flows:tick` despierta igual a la
ejecución al vencer el plazo y el comprobante pasa a revisión: el cliente nunca
queda esperando una respuesta que no llega.

### 2.4 La identidad sale del servidor, nunca de la imagen ni del cliente

A quién se le abona un pago lo decide **el teléfono del chat**, cruzado con
ispwatch (`IspwatchRepository::customerByPhone`). Ni el nombre del pagador en el
comprobante ni lo que diga el cliente cambian eso. Si el teléfono no cruza o
cruza con varios clientes, el registro automático no ocurre y una persona elige
al cliente a mano en «Pagos reportados».

### 2.5 Todo entra por donde ya se trabaja

- **En un flujo**: el bloque «Comprobante de pago» (§5). Hereda gratis lo que el
  Workspace ya garantiza: ventana de 24 h, los humanos mandan, lock por
  conversación, traza, simulador.
- **En el chat**: el botón «Leer comprobante» en las imágenes del cliente. Es lo
  que más se va a usar al principio: la mayoría de los ISPs no tiene flujos
  encendidos y los comprobantes llegan a la bandeja. Desde el chat **nunca** se
  registra solo.

### 2.6 Opt-in, medido y con tope

- Interruptor general `AI_ENABLED` (apagado por defecto) y otro por empresa
  (`tenants.ai_enabled`, apagado por defecto: la foto sale a un tercero).
- Cada llamada queda en `ai_usage_logs` con tokens, latencia y el **costo real**
  que informa xAI (`usage.cost_in_usd_ticks`). Nada del contenido.
- Tope de gasto mensual por empresa (USD 5 por defecto). Alcanzado, los
  comprobantes llegan igual, sin leer.

### 2.7 Proveedor intercambiable; xAI hoy

Las funciones hablan con `AiGateway`, que habla con un `AiProvider`. Hoy el único
es `XaiProvider` contra la **Responses API** (`POST /v1/responses`):

| Elección | Por qué |
|---|---|
| `grok-4.3` | Lee imágenes, soporta JSON schema estricto y es el más barato de los que hacen las dos cosas (USD 1,25 / 2,50 por millón de tokens, oct/2026) |
| `store: false` | Con `true`, xAI guarda entrada y respuesta 30 días. Aquí la entrada es un comprobante con nombres y cuentas, y no hay nada que retomar |
| Imagen en base64 (data URL) | Los medios viven en el disco del droplet detrás de sesión; no hay URL pública que xAI pueda bajar |
| `text.format` = `json_schema` con `strict: true` | La salida siempre trae todas las llaves; igual se sanea (`ReceiptReading`) porque estricto no es correcto |
| `reasoning.effort = low` | Grok razona siempre y por defecto a fondo. Transcribir no lo necesita; baja la espera del cliente y el costo. Configurable (`XAI_REASONING_EFFORT`) |

### 2.8 Datos personales

La foto de un comprobante tiene nombres, números de cuenta y valores. Lo que sale
a xAI es solo esa imagen, para transcribirla, sin retención (`store: false`).
En Converza quedan la imagen (ya estaba: es el chat) y lo transcrito. En la
bitácora de IA, nada del contenido.

El ISP es el responsable del tratamiento de los datos de sus clientes (Ley 1581
de 2012); Converza es su encargado y xAI un subencargado en el exterior. Por eso
la función es **opt-in por empresa** y la pantalla de Configuración lo dice al
encenderla. Si un ISP no lo tiene cubierto en su política de datos, no la
enciende y todo funciona igual, sin leer.

---

## 3. Qué pasa con un comprobante, de punta a punta

```
                 ┌──────────────────────────────────────────────────────────┐
 foto del cliente│ PaymentReportProcessor::start()                          │
 (flujo o chat) ─►  ¿hay imagen? ¿jpg/png? ¿IA disponible (interruptores,   │
                 │  llave, presupuesto)?                                    │
                 └───────┬──────────────────────────────┬───────────────────┘
                     sí  │                           no │ (PDF, IA apagada, sin presupuesto…)
                         ▼                              ▼
                   status = reading              status = pending_review
                         │                       (se identifica al cliente igual)
             ProcessPaymentReport (cola ai)
                         │
              ReceiptReader (xAI) ── no es un comprobante ──► not_receipt
                         │
              identificar por teléfono, buscar duplicados, verificar (§4)
                         │
       ┌─────────────────┼───────────────────┬──────────────────────┐
  repetido        ya registrado en      no pidió auto / sin       todo pasa y
  (referencia o   ispwatch (mismo       conexión / algo no        el bloque pidió
  imagen)         cliente)              cuadra                    registrar solo
       │                 │                   │                      │
   duplicate        registered          pending_review          registering
                    (ya_registrado)                                 │
                                                       IspwatchPaymentsApi::register
                                                        ├─ 201/200 ─► registered
                                                        ├─ 409/422/401… ─► pending_review
                                                        └─ caída/5xx ─► reintento (misma llave)
```

Cada paso deja una **nota interna** en el chat (`🧾 …` / `✅ Pago registrado…`),
así el asesor ve en contexto qué pasó sin abrir otra pantalla.

---

## 4. Las verificaciones y la política de registro automático

`ReceiptVerifier` produce una lista de verificaciones. Cada una da `true`
(pasó), `false` (no pasó) o `null` (no se pudo mirar). **El registro automático
exige todas en `true`**: un `null` también manda a revisión, porque "no lo pude
verificar" no es "está bien".

| Verificación | Pasa si | Qué fraude o error ataja |
|---|---|---|
| `comprobante` | La imagen dice pago **exitoso** y la lectura es segura (≥ 80 %) | Pantallazo de una transferencia pendiente o rechazada |
| `valor` | Se leyó el valor | — |
| `fecha` | Ni futura ni más vieja que 7 días | Reenviar un comprobante viejo |
| `referencia` | Trae número de referencia | Sin referencia no hay forma de detectar repetidos |
| `repetido` | Ningún otro reporte vivo tiene esa referencia o esa misma imagen | Mandar dos veces la misma foto |
| `ispwatch` | ispwatch no tiene ya un pago con esa referencia | Que la cajera ya lo haya registrado; o el **mismo comprobante usado por otro cliente** |
| `cuenta` | La cuenta destino es de la empresa (compara el final del número; los bancos lo ocultan) | Pantallazo de un pago a otra cuenta |
| `cliente` | El teléfono del chat es de **un** cliente | Abonarle a quien no es |
| `deuda` | El valor cubre al menos su factura más vieja y no supera lo que debe | Abono simbólico: ispwatch cierra la factura, arrastra el resto **y reconecta** (decisión del 31/07/2026, sin mínimo). Con un bot eso escala |
| `tope` | ≤ tope por comprobante (300.000 por defecto, configurable) | Que un error caro pase sin ojos humanos |
| `frecuencia` | < 2 pagos automáticos hoy para ese cliente | Abuso repetido |
| `alertas` | El modelo no vio señales de edición | Pantallazos editados burdamente |

**Lo que estas reglas NO atajan**, y hay que decirlo: un comprobante falsificado
con cuidado (cuenta, valor, referencia y fecha creíbles). Eso solo lo ve la
conciliación contra el extracto del banco. Mitigaciones: el pago queda en ispwatch
con `source = converza_whatsapp` y la nota del comprobante (se puede conciliar y
reversar), los topes limitan el daño, y la pasarela de pagos (§11) lo elimina de
raíz.

**Quién decide que sea automático:** el bloque «Comprobante de pago», con su
casilla «Registrar el pago en ispwatch sin revisión». Desde el chat nunca. Y aunque
la casilla esté marcada, sin la conexión de pagos con ispwatch o sin números de
cuenta en los medios de pago, nada se registra solo (el validador lo advierte al
publicar).

---

## 5. Qué ve cada quién

### El cliente (por WhatsApp)

Con un flujo que use el bloque: si abrió el chat mandando la foto, el bot la usa
sin volver a pedirla; si no, se la pide. Según el resultado:

| Salida del bloque | Ejemplo de lo que el ISP le hace decir |
|---|---|
| **Pago registrado** (solo con registro automático) | «✅ Registramos tu pago de {{pago.valor}}. {{pago.servicio}}» (`pago.servicio` dice si quedó reconectado) |
| **Para revisión del equipo** | «Recibido 🙌 Lo revisa el equipo y te confirmamos» → Pasar a un asesor |
| **No mandó un comprobante** | Agotó los reintentos: mandó texto o fotos que no son comprobantes |

El cliente **nunca** recibe el motivo de una revisión: decirle qué verificación
falló le enseña a un estafador qué corregir. Solo `pago.estado` distingue
`registrado`, `en_revision`, `repetido` y `no_valido`, para que el ISP pueda
ramificar con una Condición.

### El asesor (en el chat)

- «🧾 Leer comprobante» en cada imagen (y PDF) del cliente, si la empresa encendió
  la IA. El resultado llega como nota interna; la imagen queda marcada con su
  estado («Comprobante #12 · por revisar»).
- Las notas del bot cuando el comprobante vino por un flujo.

### El admin (en «Pagos reportados»)

Pestañas *Por revisar · Registrados · Descartados · Todos*. En el detalle: la
imagen al lado de lo transcrito, cada verificación con su ✓ ✗ ?, el cliente y su
deuda actual en ispwatch, y tres acciones:

- **Registrar en ispwatch** — con los datos corregidos si hace falta (valor,
  fecha, referencia, medio). Va por la misma API y la misma llave.
- **Ya lo registré en ispwatch** — solo deja constancia (opcional: el número del pago).
- **Descartar** — con motivo obligatorio.

Además, **Cambiar cliente**: búsqueda en ispwatch por nombre, cédula o teléfono.

Es solo admin: muestra comprobantes de todas las conversaciones (los asesores solo
ven las suyas) y desde ahí se mueve plata.

### El ISP (en Configuración)

- **Inteligencia artificial**: encenderla, el consumo del mes contra el tope y el
  tope para registrar sin revisión.
- **Medios de pago**: «Traer de ispwatch» crea uno por cada forma de pago activa
  del catálogo de ispwatch (sin duplicar); se completan con número de cuenta,
  titular e indicación. Un medio sin número no se muestra en el bot hasta que
  alguien lo complete.

---

## 6. Modelo de datos

Detalle de columnas en [modelo-de-datos.md](modelo-de-datos.md).

| Tabla | Qué guarda |
|---|---|
| `payment_reports` | Un comprobante, de la lectura al registro: qué dice la imagen, las verificaciones, el cliente, la decisión (`status` + `reason`), y la respuesta de ispwatch. UN reporte por imagen (`message_id` único) |
| `payment_channels` | Los medios de pago de la empresa, cada uno colgado de una forma de pago de ispwatch |
| `ai_usage_logs` | Cada llamada a la IA: tokens, costo, latencia, error. Sin contenido |
| `tenants` (+4 columnas) | `ai_enabled`, `ai_monthly_budget_usd`, `receipts_auto_max_amount`, `ispwatch_api_token` (cifrada) |

`bot_flow_runs.status` suma el estado vivo `waiting_task` (sin migración: la
columna es texto). El reporte guarda la evidencia aunque se borre el chat
(`nullOnDelete`, no cascada).

---

## 7. Contrato con ispwatch: el endpoint de registro (pendiente, repo ISPWatch)

Converza ya lo consume (`IspwatchPaymentsApi`); ispwatch lo tiene que publicar.
Hasta entonces `canRegisterPayments()` da falso y todo queda para revisión.

```
POST {ISPWATCH_API_URL}/api/v1/integrations/payments
Authorization: Bearer <credencial de UNA empresa, emitida por ispwatch>
Idempotency-Key: converza-payment-report-<id>
Content-Type: application/json

{
  "customer_id": 501,                 // users.id del cliente
  "amount": 50000,
  "payment_date": "2026-10-24",
  "payment_method_id": 4,             // del catálogo payment_methods del tenant (o "method" en texto)
  "reference": "M1234567",
  "notes": "Reportado por WhatsApp y registrado desde Converza (comprobante #12)",
  "source": "converza_whatsapp",
  "external_id": "converza-payment-report-12"
}
```

| Respuesta | Cuándo | Converza hace |
|---|---|---|
| `201` `{payment: {id, allocations[]}, reactivation: {was_suspended, reactivated, router_ok, …}}` | Pago creado | `registered` |
| `200` (mismo cuerpo) | La `Idempotency-Key` ya se había usado: devuelve el pago que creó | `registered` |
| `409` `{error: "duplicate_reference", payment_id}` | Ya hay un pago con esa referencia | revisión |
| `422` `{message}` | Dato inválido (cliente de otro tenant, valor…) | revisión, con el mensaje |
| `401` / `403` / `404` | Credencial inválida o revocada, sin permiso, endpoint no publicado | revisión, con el motivo |
| `429` / `5xx` / caída | Pasajero | reintento con la **misma** llave (hasta 3) |

**Lo que ispwatch tiene que garantizar del otro lado** (son sus reglas, no las de
Converza; ver los principios del producto ISPWatch: seguridad de la información
primero, superficie de escritura mínima):

1. **El tenant sale de la credencial**, nunca del cuerpo. Reutilizar `api_clients`
   + Sanctum con una ability nueva `write:payments`, **solo emitida por el
   operador** (fuera del auto-servicio, como `read:billing`), con allowlist de IP
   = el droplet de Converza.
2. **Fuera de `/api/v1/partner`**, que es de solo lectura por diseño (405 a todo lo
   que no sea GET, con un test que lo fija).
3. **Idempotencia**: guardar la llave (p. ej. columna `external_id` única por
   tenant en `payments`) y devolver el mismo pago si se repite.
4. **Pasar por `BillingService::registerPayment`**: asignación, arrastre,
   reconexión y bitácora como cualquier pago. `created_by = null`,
   `source = 'converza_whatsapp'`, la nota del comprobante.
5. **Duplicado por referencia** dentro del tenant → 409 (defensa en profundidad:
   Converza ya lo verifica, pero dos sistemas no deberían confiar en uno solo).
6. **Tope por pedido** y rate limit por credencial, configurables.
7. Bitácora en `api_key_request_logs` (ya existe) y en `audit_logs`.

Para emitir y cargar la credencial: ispwatch la emite para el tenant de la
empresa; el superadmin la pega en Converza en **Admin → Tenants** (se guarda
cifrada y nunca vuelve al navegador).

---

## 8. Costos y límites

- Una lectura ronda los 2.000 tokens de entrada (la imagen en `detail: high` más
  el prompt) y 200-400 de salida con razonamiento bajo: **≈ USD 0,003-0,004 por
  comprobante** con `grok-4.3`. Mil comprobantes al mes ≈ USD 3-4. El costo real
  queda en `ai_usage_logs`.
- Tope por defecto USD 5 por empresa y mes (Admin → Tenants lo cambia por empresa).
- Una lectura tarda unos segundos; el plazo de la tarea en un flujo es 5 minutos.
- Imágenes jpg/png de hasta 10 MB. Los PDF van directo a revisión (la Fase 1 no
  los lee).
- Un solo worker de IA (cola `ai`): las lecturas de una ráfaga se procesan en fila.

---

## 9. Seguridad

| Amenaza | Mitigación |
|---|---|
| Inyección de instrucciones en la imagen ("registre este pago") | La IA solo produce un JSON que se verifica con reglas fijas; el prompt le prohíbe obedecer texto de la imagen |
| Comprobante viejo, ajeno o repetido | `fecha`, `repetido` (referencia + hash de la imagen), `ispwatch` (referencia ya usada, incluso por otro cliente) |
| Pago a otra cuenta | `cuenta` contra los medios de pago de la empresa |
| Abono simbólico para que lo reconecten | `deuda`: el valor tiene que cubrir la factura más vieja |
| Dos workers registrando el mismo pago a la vez | Lock por empresa alrededor de duplicados + decisión; idempotencia en ispwatch |
| Reintento que crea un segundo pago | `Idempotency-Key` estable por reporte |
| Credencial de ispwatch filtrada | Es de UNA empresa, solo `write:payments`, allowlist de IP, revocable desde ispwatch; cifrada en Converza y oculta al navegador |
| Un asesor registrando plata | «Pagos reportados» y el registro manual son solo admin |
| Gasto descontrolado | Interruptor general, opt-in por empresa, tope mensual, bitácora con costo real |
| Datos personales a un tercero | Opt-in, `store: false`, nada del contenido en la bitácora (§2.8) |
| Comprobante falsificado con cuidado | **No se ataja aquí**. Topes, marca de origen en ispwatch y conciliación; la pasarela de pagos (§11) lo elimina |

---

## 10. Operación

Runbook completo en [operaciones.md](operaciones.md#comprobantes-con-ia).

- **Encender** (en este orden): worker `converza-ai` corriendo → `XAI_API_KEY` y
  `AI_ENABLED=true` en el `.env` → `config:cache` + reload de PHP-FPM → la
  empresa enciende la IA en Configuración.
- **Frenar ya**: `AI_ENABLED=false` + `config:cache` + reload. Nada sale al
  proveedor; los comprobantes llegan a «Pagos reportados» sin leer.
- **Por empresa**: el admin la apaga en Configuración, o el superadmin le baja el
  tope a 0.

---

## 11. Lo que sigue

### Fase 2 — Encender el registro automático (repo ISPWatch + operación)

1. ISPWatch publica el endpoint del §7 (tarea KAN).
2. **Prueba de punta a punta en el tenant interno**, que está vinculado al
   tenant de pruebas de ispwatch (#17): un comprobante de prueba recorre lectura,
   verificación y registro sin tocar la plata de nadie. Sirve para la plomería,
   **no** como piloto: a nuestro número le escriben ISPs (sus pagos van al Core
   Brain, no a ispwatch), no suscriptores con comprobantes.
3. **Piloto real en un ISP** con clientes (Chaguaní o Tocaima): IA encendida y
   registro automático **apagado** dos semanas, midiendo en «Pagos reportados»
   cuántos habrían pasado todas las verificaciones y cuántos de esos estaban
   bien. Emitir su credencial en ispwatch y cargarla en Admin → Tenants.
4. Recién con esa medición, encender el registro automático en ese ISP.

> El 25/10 este plan decía "piloto con el tenant interno". Estaba mal por lo del
> punto 2: ahí no llegan comprobantes de suscriptores, así que dos semanas de
> piloto no habrían medido nada.

Mejora natural de esta fase: avisarle al cliente cuando una persona registra su
pago desde «Pagos reportados» (hoy se le escribe desde el chat).

### Fase 3 — El agente que conversa

Un bloque «Agente IA» del Workspace, no un bot aparte, para heredar las mismas
garantías. Diseño:

- **Recibe** el mensaje del cliente y responde con un LLM (misma `AiGateway`,
  misma cola `ai`, misma tarea asíncrona del motor), con un prompt que arma el
  ISP: quién es, horarios, planes, qué NO prometer.
- **Herramientas de solo lectura**, ejecutadas por Converza con la identidad del
  servidor (sin parámetros de cliente que el modelo pueda inventar):
  `mis_facturas`, `estado_del_servicio`, `medios_de_pago`, `buscar_en_la_faq`.
- **Las acciones no las ejecuta el modelo**: devuelve una intención
  (`pasar_a_asesor`, `reportar_pago`, `terminar`) y el bloque sale por la salida
  correspondiente. Así un traspaso o un comprobante siguen el camino que ya tiene
  garantías.
- Topes: turnos por conversación, tokens por día, y salida a un asesor ante
  quejas, temas legales, visitas técnicas o lo que el modelo no sepa.
- Prerrequisito: la FAQ en base de datos (CON-62) para que el agente responda con
  lo que el ISP escribió, no con lo que el modelo imagina.

### Fase 4 — Identidad sin número registrado, y prospectos

- **Identificación por cédula** cuando el teléfono no cruza: el bot pregunta la
  cédula, la busca en ispwatch (solo lectura) y la ata a la conversación con un
  **nivel de confianza** menor que el teléfono. Con ese nivel se aceptan
  comprobantes (siempre a revisión) pero **no** se muestran datos (deuda,
  dirección): saber una cédula no da derecho a verlos.
- **Registro de prospectos**: hoy ya se puede con bloques existentes (Preguntas →
  Etiquetar → Pasar a un asesor). Crearlos directamente en `prospects` de
  ispwatch exige otro endpoint de escritura del mismo estilo que el §7.

### Más adelante — conciliación

Lo único que ataja el comprobante falsificado con cuidado es saber que la plata
llegó. Opciones: pasarela (Wompi, Bold) con webhook, o importar el extracto del
banco y conciliar contra los pagos con `source = converza_whatsapp`.

---

## 12. Límites conocidos de la Fase 1

1. **Sin el endpoint de ispwatch no se registra nada solo** (§7).
2. **PDF**: no se leen; van directo a revisión.
3. **Una imagen por vez en el bloque**: si el cliente manda dos fotos seguidas, el
   flujo lee la primera; la segunda queda en el chat (se puede leer con el botón).
4. **Lo que el cliente escribe mientras se lee el comprobante** queda en el chat,
   pero el flujo no lo lee (igual que durante una Espera).
5. **El comprobante falsificado con cuidado** no se detecta (§4).
6. **Al registrar desde «Pagos reportados» no se le avisa al cliente** (Fase 2).
7. **La cola `ai` exige su programa de Supervisor**: sin él, cada comprobante de un
   flujo espera 5 minutos y va a revisión (§10).

---

## 13. Pendientes que no son código

Van como tareas de Jira (CON para Converza, KAN para ISPWatch). Estado al
26/10/2026:

1. **KAN** — Endpoint `POST /api/v1/integrations/payments` según el §7.
   *Pendiente.*
2. **CON** — Migración en `converza_dev`. *Hecho el 26/10/2026* (en `converza`
   la corrió el deploy del PR #95).
3. **CON** — Droplet. *Hecho:* bloque de IA en el `.env` (`AI_ENABLED=false`,
   `ISPWATCH_API_URL`), con `config:cache`, reload de PHP-FPM y reinicio de
   workers. *Pendiente, requiere root* (el usuario `deploy` solo tiene sudo sin
   contraseña para reiniciar `converza-worker` y recargar PHP-FPM): instalar
   `converza-ai.conf` (ya está copiado en `/home/deploy/converza-ai.conf`) y la
   línea de sudoers de `converza-ai`. *Pendiente, requiere la llave:*
   `XAI_API_KEY` y recién entonces `AI_ENABLED=true`.
4. **CON** — Fase 2 (§11): prueba de punta a punta en el tenant interno (contra
   el tenant de pruebas de ispwatch) y piloto real de dos semanas en un ISP con
   el registro automático apagado. *Acordado; falta elegir el ISP.*
5. **CON** — Fase 3 (agente) y Fase 4 (cédula y prospectos), con la FAQ en base
   (CON-62) como prerrequisito de la 3.
