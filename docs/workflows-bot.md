# Workflows del bot — el Workspace de flujos

> **Estado: DECIDIDO e IMPLEMENTADO (v1).** Motor propio dentro de Converza, con
> editor visual, librería de bloques v1, simulador y versionado. El nodo HTTP
> (escape hatch hacia n8n/Make) queda para CON-50.
>
> **Decisión:** 30/09/2026, Axel, sobre la propuesta del 21/09. **Épica:** CON-36 ·
> **Spike:** CON-46 (cerrado) · **Construido en:** CON-47 (motor), CON-48 (editor),
> CON-49 (bloques + simulador).

Guía del día a día para el ISP: *Manual → Flujos del bot*. Este documento es el
porqué y el cómo por dentro.

---

## 1. Qué problema resuelve, y para quién

**Para el ISP (y para nuestro propio número):** cambiar cómo atiende su bot
—menús, preguntas, condiciones, consultas a ispwatch, entrega a un asesor— sin
pedirle nada al equipo de Converza.

**Antes no se podía.** `HandleBotResponse` es una máquina de estados cableada con
un menú fijo de cinco opciones y `IntentDetector` con palabras clave en código.
`BotSetting` deja editar **el texto** de esos mensajes, no la forma del flujo.
Agregarle una sexta rama a un cliente era código y despliegue.

### Cómo se decidió construirlo sin la medición previa

La propuesta del 21/09 condicionaba la obra a CON-78: encender el bot cableado en
un ISP real y medir dos semanas, porque la evidencia decía que nadie lo usaba:

| Hecho (medido el 12 y el 21/09/2026) | Dato |
|---|---|
| Empresas con bot configurado | **1 de 3** — sólo el tenant interno |
| Chaguaní (836 conversaciones) y Tocaima (216) | nunca se les encendió |
| Conversaciones que el bot tocó en su vida | 16, todas internas |
| Mensajes automáticos enviados | 15, todos en mayo de 2026 |

El 30/09 Axel decidió construir ya. El primer usuario real es **nuestro propio
número** (el tenant interno ya choca con el techo del bot cableado) y el segundo
son los ISPs. El riesgo de construir algo que ningún ISP use sigue ahí; por eso el
Workspace **nace midiendo** (§8) y la medición de CON-78 se hace sobre él, en el
primer ISP que publique un flujo.

---

## 2. La decisión: motor propio + un nodo HTTP

Se eligió la **C, híbrida**: un motor de flujos dentro de Converza, más **un** nodo
de `HTTP request / webhook` (CON-50, pendiente) como escape hatch hacia n8n, Make o
la API del cliente.

**Por qué no n8n como núcleo:**

- **Recursos.** El droplet es de 1 GB y ya carga Postgres, Redis y los workers.
  n8n necesita otro host: costo nuevo y un dominio de fallo nuevo.
- **Aislamiento.** n8n no separa por tenant sin Enterprise.
- **Superficie.** Habría que construir una API pública, y aun así el pacing
  anti-baneo, la ventana de 24 h y las plantillas tendrían que aplicarse acá.
- **Audiencia.** Los usuarios son ISPs, no ingenieros de automatización.

**Por qué sí motor propio:** el runtime conversacional queda en el mismo sistema
que es dueño del número de WhatsApp, y hereda gratis las colas, el binding de
tenant, `WhatsAppService`, la ventana de 24 h y el manejo de BSUID.

---

## 3. La restricción que manda sobre todo lo demás: la ventana de 24 h

Fuera de las 24 h posteriores al último mensaje *del cliente*, WhatsApp no entrega
texto libre. Y no lo rechaza en el momento: acepta con 200 y un `wamid`, y el
rechazo llega después por webhook. Entre el 11 y el 20/09/2026, dos asesores
generaron así **120 mensajes rechazados** (27,5 % de lo que escribieron).

Cómo lo resuelve la v1, en tres capas:

1. **Los flujos son conversacionales por construcción.** Siempre arrancan con un
   mensaje del cliente, así que nacen con la ventana abierta, y cada Pregunta o
   Menú la renueva.
2. **El validador lo dice al diseñar, no al fallar.** Suma las Esperas de cada
   camino entre dos mensajes del cliente; si algún bloque que envía quedaría a más
   de **23 h** (`flows.max_wait_minutes`, una hora de margen), el flujo **no se
   publica** y el error señala ese bloque.
3. **El motor lo mira antes de llamar a Meta.** Si aun así la ventana está cerrada
   (p. ej. el `flows:tick` estuvo caído), no envía: la ejecución termina con
   `window_closed`, la conversación pasa al equipo y queda una nota interna que
   dice "usa una plantilla aprobada". Nada sale a gastar quality rating.

> Desviación consciente de la propuesta: el bloque Mensaje de la v1 **no** manda
> plantillas. Con las tres capas de arriba, un flujo publicado no puede llegar a
> enviar fuera de ventana, así que la plantilla de respaldo no tiene caso de uso
> todavía. El contacto saliente fuera de ventana sigue siendo de Campañas y
> Avisos automáticos (§7).

---

## 4. Qué ve el cliente

Una sección **Flujos del bot** (solo admin) con la lista de flujos y, por cada uno,
sus métricas de 7 días. El editor es un lienzo (Vue Flow) con una paleta de
bloques, un panel de propiedades y cuatro pestañas: *Bloque*, *Revisión*, *Probar*
(el simulador) y *Versiones*.

**Borrador y publicación separados.** Se edita y guarda el **borrador**; el motor
solo ejecuta **versiones publicadas**. *Publicar* valida y congela una versión
nueva; *Restaurar* copia una versión vieja al borrador (y la publicación siguiente
lo anota: "Restaurada desde la v2").

**Plantillas de arranque:** "Atención a clientes de un ISP" (saldo con ispwatch,
fallas, pagos, asesor), "Tu bot actual, editable" (el bot clásico de ESE tenant
con sus textos y switches — el flujo semilla de CON-51) y "Empezar casi de cero".

### Los bloques de la v1

| Bloque | Qué hace | Salidas |
|---|---|---|
| **Inicio** | El disparador (§5) y la zona horaria del flujo | Siguiente |
| **Mensaje** | Envía un texto con `{{variables}}` | Siguiente |
| **Pregunta** | Pregunta, espera, valida (texto, número, correo, teléfono) y guarda en una variable; opcional: copiarla al nombre o correo de la ficha | Respondió · Sin respuesta válida |
| **Menú** | Opciones numeradas, botones (≤ 3) o lista (≤ 10); reintento si no entiende | una por opción · No entendió |
| **Condición** | Reglas sobre variables, etiquetas del contacto u horario; todas o al menos una | Sí · No |
| **Datos del cliente** | Busca al contacto en ispwatch por teléfono (solo lectura) y llena `{{cliente.*}}` | Encontrado · No encontrado |
| **Etiquetar** | Pone o quita una etiqueta | Siguiente |
| **Espera** | Pausa N minutos (máx. 23 h) | Siguiente |
| **Pasar a un asesor** | Mensaje opcional, equipo opcional, auto-asignación y nota interna con lo que respondió el cliente | — |
| **Fin** | Termina: el bot resolvió | — |

**Cómo elige una opción el Menú**, en este orden: el botón o fila que tocó (por su
id, no por el texto), el número o su emoji, el nombre exacto de la opción, una
palabra clave, y por último el nombre de la opción dentro de la frase. Las
palabras se comparan sin tildes ni mayúsculas y **como palabra completa** — el bot
cableado usa `str_contains` y por eso "ver" caía en "verificar".

**Variables** (`{{nombre}}`): siempre existen `contacto.*`, `empresa.nombre`,
`saludo`, `fecha.*`, `mensaje_inicial`, `respuesta` y `opcion`; las de una
Pregunta existen con el nombre que se les dé; las `cliente.*`, solo si el flujo
tiene un bloque *Datos del cliente*. El validador rechaza una variable que no
existe en ningún lado (casi siempre es un error de tipeo).

**La clasificación con IA** (CON-25) entra como variante del bloque Menú.

### El simulador

Un chat dentro del editor que conversa con el flujo **del lienzo** (aunque no esté
guardado) usando el MISMO motor, con un `SimulatedFlowIO` que no envía nada ni
escribe en la base. Muestra los efectos (etiquetas, traspaso con su nota interna),
resalta en el lienzo los bloques recorridos y permite saltar las Esperas, probar
con la ventana cerrada y, con el teléfono de un cliente real, ver sus datos de
ispwatch (solo lectura).

---

## 5. Cómo se enruta un mensaje

`BotDispatcher` decide, en `ProcessIncomingWhatsAppMessage`, quién atiende:

1. **Ejecución viva en la conversación** → el mensaje es para ella.
2. **El tenant tiene flujos encendidos** → **el bot clásico queda en pausa**
   (nunca contestan los dos) y arranca el primer flujo cuyo disparador coincida:
   - primero los de **palabra clave** (el mensaje la contiene, o es exactamente
     ella);
   - después los de **inicio de conversación**: conversación nueva, **reabierta**
     (estaba cerrada), o el cliente vuelve a escribir tras **N horas de silencio**
     (opcional por flujo; útil porque los ISPs casi nunca cierran chats).
   - Empate: menor `priority` (el orden de la lista) y luego el id más viejo.
   - Nunca en una conversación con asesor asignado, ni con una reacción.
3. **Sin flujos encendidos** → el bot clásico, exactamente como antes.

Al arrancar, el enrutador **reclama** la conversación en el acto
(`bot_active = true`) y recién después encola el job. Si esperara al job, la
auto-asignación que corre a continuación en el mismo webhook le daría el chat a un
asesor y el flujo nacería muerto.

El enrutador está aislado con try/catch: si falla (un esquema sin migrar), el
mensaje ya guardado sigue hacia la auto-asignación, sin bot.

**Los humanos mandan.** Si un asesor toma la conversación, el `ConversationObserver`
corta la ejecución en el acto. Si un asesor le escribe al cliente sin asignarse,
el motor lo detecta en el siguiente evento y se calla. (El bot cableado tenía
esto como límite conocido; acá está resuelto.) Las notas internas no cuentan.

---

## 6. Cómo se ejecuta por dentro

### Modelo de datos

| Tabla | Qué guarda |
|---|---|
| `bot_flows` | Nombre, **borrador** (`draft`, JSON), versión publicada, interruptor, disparador copiado al publicar, prioridad |
| `bot_flow_versions` | Copia **inmutable** del grafo por versión, con nota y quién publicó |
| `bot_flow_runs` | Una ejecución por conversación: versión, bloque actual, variables, estado, último mensaje leído, espera |
| `bot_flow_steps` | La traza: cada bloque ejecutado, entrada, salida, y el `message_id` de lo que envió |

Todas con `BelongsToTenant` y `tenant_id` explícito en cada escritura. Detalle de
columnas en [modelo-de-datos.md](modelo-de-datos.md).

> Desviación de la propuesta: no hay tablas `bot_flow_nodes` / `bot_flow_edges`.
> El grafo vive como JSON en el borrador y en cada versión, con el mismo formato
> que usa el lienzo. Así una versión es una sola fila inmutable, y "volver a una
> versión" es copiar un JSON. Tampoco se reusó `bot_logs`: sus columnas
> (`intent_detected`, `escalated`) son de la máquina de estados que CON-51 va a
> borrar; la traza nueva es `bot_flow_steps`.

### Las piezas

| Pieza | Qué hace |
|---|---|
| `FlowEngine` | El intérprete: avanza bloque a bloque hasta que toca esperar o el flujo termina. No sabe de colas ni de base ni de si envía de verdad |
| `Nodes\*` | Un tipo de bloque por clase: salidas, validación propia y ejecución |
| `FlowIO` | Todo lo que un bloque le hace al mundo. `LiveFlowIO` envía por `WhatsAppService`; `SimulatedFlowIO` solo anota |
| `FlowRunner` | Lo operativo: lock, bandeja en orden, cortes por asesor, persistencia de la traza |
| `BotDispatcher` | El enrutador de §5 |
| `FlowValidator` | La compuerta de publicación (§3 y §7) |
| `FlowPublisher` | Publicar, encender/apagar, restaurar |
| `RunBotFlow` (job) | Despierta al runner: arranque, mensaje o fin de espera |
| `flows:tick` | Cada minuto: reanuda Esperas y cierra ejecuciones abandonadas |

### Las cotas

- **Tope de pasos**: 25 por evento y 200 por ejecución (`config/flows.php`). Un
  ciclo que se colara corta, queda en la traza y la conversación pasa al equipo
  con una nota. No cuelga un worker.
- **Lock por conversación**: el mismo `bot:conv:{id}` del bot cableado, así los
  dos nunca corren a la vez sobre un hilo. Si está tomado, el job se re-encola
  (`release(2)`) en vez de perder el mensaje.
- **Bandeja en orden**: el job no procesa "su" mensaje sino todo lo que el
  cliente escribió desde `last_message_id`, de a uno. Dos mensajes en ráfaga se
  leen en orden y una sola vez aunque los workers tomen los jobs al revés.
  Además, lo escrito **antes** de que el bot preguntara no cuenta como respuesta
  ("hola" + "1" mandados antes de ver el menú no eligen la opción 1).
- **Una ejecución viva por conversación**: índice único en `live_conversation_id`
  (igual a `conversation_id` mientras vive, NULL al terminar).
- **Versión inmutable**: la ejecución usa la versión con la que arrancó.
- **Sin reintentos tras una excepción**: `maxExceptions = 1`. Reintentar podría
  mandarle al cliente lo que ya salió; ante un error, `failed()` cierra la
  ejecución y pasa la conversación al equipo con una nota.
- **Binding de tenant explícito** en el job, liberado en `finally`.
- **Todo sale por `WhatsAppService`**, incluidos los mensajes interactivos
  (`sendInteractive()`).
- **Pregunta abandonada**: a las 24 h sin respuesta (`flows.input_timeout_hours`)
  la ejecución caduca sin mandar nada; el siguiente mensaje se trata como nuevo.

### La cola

El job corre en `config('flows.queue')`, que arranca en **`default`**. Ningún
bloque de la v1 llama a terceros —todo es base propia, ispwatch cacheado o
`WhatsAppService`, lo mismo que hacía el bot cableado en esa cola—. La cola
propia la exige el nodo HTTP (CON-50): pasar `FLOWS_QUEUE=flows` **sin** agregar el
programa de Supervisor que la escuche deja a todos los flujos mudos.

### El nodo HTTP (CON-50), cuando llegue

Mete una dependencia externa dentro de un job. Restricciones: nunca en la cola de
envíos, timeout corto, bloqueo de red interna (SSRF), secretos cifrados, respuesta
acotada y rama de error obligatoria.

---

## 7. Qué revisa el validador antes de publicar

- Exactamente un Inicio; tipos de bloque conocidos; ids válidos y únicos.
- La configuración de cada bloque (textos obligatorios, límites de WhatsApp:
  4096 caracteres de texto, 1024 de cuerpo interactivo, 3 botones de 20
  caracteres, 10 filas de 24).
- **Toda salida conectada** a un bloque: una rama que no lleva a nada dejaría al
  cliente hablándole a la nada. Solo Fin y Pasar a un asesor terminan.
- Nada vuelve al Inicio; ninguna salida tiene dos conexiones; ningún bloque se
  conecta a sí mismo.
- **Ciclos que nunca esperan al cliente** (aunque tengan una Espera en el medio):
  el bot repetiría mensajes sin parar. Un ciclo es válido si pasa por una
  Pregunta o un Menú ("volver al menú" es lo más común y es legítimo).
- **La ventana de 24 h** (§3).
- **Variables inexistentes** (y las `cliente.*` sin bloque *Datos del cliente*).
- Etiquetas y equipos que existan **en ese tenant**.
- Advertencia (no error): bloques sueltos que nunca se ejecutan.

---

## 8. Observabilidad desde el día uno

- **Métricas de 7 días por flujo** en la lista: conversaciones, % resueltas por el
  bot (Fin), % que pasaron al equipo, y cuántas veces un menú **no entendió**. Ese
  último es la medida directa de si las palabras clave sirven — lo que CON-78
  quería medir.
- **Actividad** por flujo: las últimas 50 ejecuciones con su recorrido bloque a
  bloque, lo que dijo el cliente, lo que contestó el bot y si WhatsApp lo
  **entregó, lo leyó o lo rechazó** (vía `message_id`).
- Cada mensaje del bot queda en el chat como `type = 'bot'`; si el envío falló, el
  chat muestra por qué.

---

## 9. Convivencia y migración del bot clásico

- Con un flujo encendido, el clásico **no responde** aunque su interruptor diga
  activado. Configuración y *Configuración → Bot* lo dicen ("en pausa por un flujo").
- Las conversaciones que quedaron a medias con el clásico se liberan solas al
  siguiente mensaje (si no, `bot_active = true` les impediría la auto-asignación).
- "Tu bot actual, editable" convierte el clásico en un flujo con sus mismos
  textos, horario y switches. Diferencias deliberadas: palabras clave como
  palabra completa y saludo también en conversaciones reabiertas.
- Retirar `HandleBotResponse` e `IntentDetector` es CON-51, cuando ningún tenant
  dependa de ellos.

---

## 10. Qué NO es esto

- **No es n8n.** Un ISP que necesite una integración exótica la llama por el nodo
  HTTP (CON-50).
- **No es un bot con IA.** La IA entra como variante del Menú (CON-25).
- **No reemplaza a Campañas.** Los flujos reaccionan a lo que escribe el cliente;
  el contacto masivo saliente tiene su herramienta, con audiencia, pacing y opt-out.

---

## 11. Límites conocidos de la v1

1. **Solo texto.** El bloque Mensaje no envía imágenes, PDF ni plantillas.
2. **Sin nodo HTTP** (CON-50).
3. **Un asesor asignado desde siempre apaga el bot para ese cliente.** Una
   conversación que quedó asignada y nunca se cerró no vuelve a recibir el flujo
   (los humanos mandan). Cerrar los chats resueltos, o el cierre automático, lo
   resuelve.
4. **Lo que el cliente escribe durante una Espera** queda en el chat pero el flujo
   no lo lee.
5. **El silencio cuenta desde el último mensaje, sea de quien sea.** Si un aviso de
   factura o una campaña salió hace más horas que las de silencio configuradas, la
   respuesta del cliente arranca el flujo (recibe el menú en vez de ir directo al
   equipo). Si no se quiere, dejar el flujo solo con "conversación nueva o
   reabierta".
6. **Una conversación reabierta recibe el flujo de nuevo**, aunque el cliente solo
   escriba "gracias" después de que la cerraron.
7. **El editor es para portátil o escritorio**; en pantallas chicas avisa en vez
   de romperse.

Ver [bot.md](bot.md) para el bot clásico y [campanas.md](campanas.md) para el
contacto masivo.
