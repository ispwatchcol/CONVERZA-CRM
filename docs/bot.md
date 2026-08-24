# Bot de atención

> **En una frase:** una máquina de estados por palabras clave que atiende el primer
> contacto, califica al lead y lo entrega a un asesor — con interruptor, horario y
> switches por paso configurables desde *Configuración → Bot*.

**Archivos clave**
- [app/Jobs/HandleBotResponse.php](../app/Jobs/HandleBotResponse.php) — la máquina de estados
- [app/Services/Bot/IntentDetector.php](../app/Services/Bot/IntentDetector.php) — detección de intención
- [app/Models/BotSetting.php](../app/Models/BotSetting.php) — config, defaults y `respondsAt()`
- [app/Http/Controllers/BotSettingsController.php](../app/Http/Controllers/BotSettingsController.php) — `/settings/bot`
- [resources/js/Pages/Settings/Bot.vue](../resources/js/Pages/Settings/Bot.vue) — la UI
- [app/Observers/ConversationObserver.php](../app/Observers/ConversationObserver.php) — apaga el bot al asignar

---

## 1. Qué es y qué no es

**Es:** un contestador determinista de palabras clave para el **primer contacto**. Saluda
con un menú, detecta qué quiere el lead, le da la respuesta comercial de esa rama, le pide
dos datos de calificación y lo entrega a un humano.

**No es:** un asistente con IA, ni un flujo dinámico. Las ramas, las palabras clave y el
orden de los pasos están **en código**. Lo configurable es: si el bot está encendido,
cuándo responde, qué pasos ejecuta y qué texto envía en cada uno.

**Nunca compite con un humano.** Actúa solo en conversaciones **sin asignar**, y en cuanto
`assigned_to` deja de ser nulo el `ConversationObserver` lo apaga.

---

## 2. El flujo

```
Cliente escribe
   └─ ProcessIncomingWhatsAppMessage (guarda el mensaje)
      └─ si (conversación nueva || bot_active) → HandleBotResponse   [cola]

HandleBotResponse — lock "bot:conv:{id}" 30 s
   ├─ ¿assigned_to es null?               si no → fin (lo tomó un humano)
   ├─ ¿existe fila en bot_settings?       si no → fin (nunca configurado)
   ├─ ¿bot_enabled && respondsAt()?       si no → CORTE (§4)
   │
   ├─ conversación nueva ─────────────► msg_greeting (menú)       → greeting_sent
   ├─ greeting_sent → IntentDetector
   │     'agent'         ─────────────► msg_handoff               → handed_off
   │     'unknown' (1º)  ─────────────► msg_fallback_1            → greeting_sent
   │     'unknown' (2º)  ─────────────► msg_fallback_2            → handed_off
   │     demo|socio|price|info ──────► msg_{rama}                → qualifying_subscribers
   ├─ qualifying_subscribers ────────► msg_ask_name               → qualifying_name
   └─ qualifying_name ───────────────► msg_handoff                → handed_off
```

El estado vive en `conversations`: `bot_active`, `bot_step`, `bot_failed_intents` y
`bot_context` (JSON con la intención, el nº de suscriptores y el nombre capturados).

Cada envío hace tres cosas: llama a `WhatsAppService::sendMessage()`, crea una fila en
`messages` con `type = 'bot'` (para que el asesor vea en el chat lo que dijo el bot) y
escribe en `bot_logs`.

> El bot usa **texto libre**, no plantillas HSM, así que solo funciona dentro de la
> ventana de 24 h. Como siempre responde a un mensaje entrante, la ventana está abierta
> por definición.

---

## 3. Horario de atención

`respondsAt()` en [`BotSetting`](../app/Models/BotSetting.php) decide si el bot puede
hablar ahora. Con `schedule_enabled = false` devuelve `true` y manda solo el interruptor.

| Columna | Qué hace |
|---|---|
| `schedule_mode` | `inside` = responde **dentro** de la franja · `outside` = responde **solo fuera** (guardia nocturna) |
| `schedule_days` | Días ISO-8601: `1` = lunes … `7` = domingo |
| `schedule_start` / `schedule_end` | `HH:MM` |
| `schedule_timezone` | La app corre en **UTC**; la franja se evalúa en esta zona |

**Franja nocturna.** Si `end <= start` la ventana cruza medianoche (18:00 → 08:00). El día
que cuenta es aquel en que la ventana **empieza**: con el lunes marcado, la franja va del
lunes 18:00 al martes 08:00. Un martes a las 02:00 sigue dentro de la ventana del lunes.

**Casos límite:** el inicio entra y el fin no (`08:00` sí, `18:00` no). `start == end` es
una franja de 24 h sobre los días marcados. Sin días marcados nunca se está "dentro" —
lo que en modo `outside` significa que el bot cubre siempre. Una zona horaria inválida
guardada en BD **no** deja al bot mudo: `respondsAt()` devuelve `true` y manda el
interruptor.

La lógica está cubierta por [`tests/Unit/BotScheduleTest.php`](../tests/Unit/BotScheduleTest.php).

---

## 4. El corte

Cuando el interruptor se apaga o la franja se cierra **mientras el bot conducía** una
conversación, el bot no puede quedarse callado: el cliente está esperando una respuesta.

`cutOff()` envía `msg_handoff`, registra un `bot_logs` con `intent_detected = 'cut_off'` y
`escalated = true`, y llama a `deactivateBot()` (que asigna un agente si el tenant tiene
la auto-asignación activa).

Como `deactivateBot()` pone `bot_active = false`, **el corte ocurre exactamente una vez**:
los mensajes siguientes de ese cliente ya no despiertan al bot.

Si el bot **no** tenía el control (conversación nueva con el bot apagado), no se envía
nada. Silencio, que es lo que se pidió.

---

## 5. Los cinco pasos

Cada switch apagado significa **saltar ese paso y avanzar al siguiente habilitado**; si no
queda ninguno, handoff. Con los cinco encendidos el comportamiento es el histórico.

| Switch | Apagado significa |
|---|---|
| `step_greeting_enabled` | Sin menú. Cada conversación nueva recibe `msg_handoff` y pasa directo a un asesor |
| `step_branches_enabled` | Sin discurso comercial. El bot detecta la rama, la deja en `bot_context` y pasa directo a calificar (aquí sí se envía `msg_ask_subscribers`) |
| `step_qualify_subscribers_enabled` | No pregunta el tamaño del ISP; salta al nombre o al handoff |
| `step_qualify_name_enabled` | No pide el nombre ni lo guarda en la ficha del contacto |
| `step_fallback_enabled` | Sin segunda oportunidad: el primer "no entendí" envía `msg_fallback_2` y escala |

> ⚠️ **Los textos de rama ya preguntan por los suscriptores.** `msg_info`, `msg_demo`,
> `msg_socio` y `msg_price` terminan con esa pregunta, así que al entrar al paso desde una
> rama **no** se envía `msg_ask_subscribers` — sería preguntar dos veces. Ese texto solo se
> usa cuando se llega al paso con las ramas apagadas. Si desactivas el paso de suscriptores
> pero dejas las ramas activas, **edita los textos de rama** para quitar la pregunta; la UI
> avisa de esto.

---

## 6. Detección de intención

[`IntentDetector::detect()`](../app/Services/Bot/IntentDetector.php) devuelve
`demo | socio | info | price | agent | unknown`, en este orden:

1. Selección numérica exacta (`1`–`5`) o su emoji (`1️⃣`–`5️⃣`).
2. Emoji numérico **dentro** del texto (`"3️⃣ quiero la demo"`).
3. `str_contains` de las palabras clave, sobre el texto en minúsculas.
4. `unknown`.

**El orden de los grupos decide los empates**: si un texto matchea `socio` y `price`, gana
`socio` porque está definido antes. Dentro de cada grupo las frases largas van primero para
que un match corto no las tape.

Falsos positivos conocidos: `'ver'` cae en `demo`, y `'gratis'` / `'programa'` en `socio`
por delante de `price`. Cambiar las palabras clave exige deploy — es deuda anotada en
[mejoras.md](mejoras.md).

---

## 7. Configuración

*Configuración → Bot de respuestas automáticas* (tarjeta con el interruptor y el estado) →
**Configurar mensajes, horario y pasos** lleva a `/settings/bot`.

El estado tiene **tres** valores, no dos: `Bot inactivo`, `Bot activo` y
`Activo — fuera de horario`. El tercero es el que evita el diagnóstico perdido: el
interruptor está encendido y aun así no sale nada.

Escritura solo para `role:admin` (`settings.bot.update` y `settings.bot.toggle`); el
`index` lo ve cualquier usuario autenticado, en modo lectura.

Hay **dos** endpoints de escritura a propósito: `update` guarda el formulario completo y
exige todos los campos, y `toggle` valida solo `bot_enabled` para el interruptor rápido de
la tarjeta de *Configuración*. Sin ese segundo endpoint, un PUT con solo el flag fallaría
la validación de `update`.

`BotSetting::defaults()` es la fuente de verdad de los textos y de los valores iniciales.
El controlador la mergea cuando el tenant aún no tiene fila, y **descarta los nulls** de la
fila existente antes del merge: las columnas añadidas después (`msg_ask_*`,
`schedule_days`) están vacías en filas antiguas y, sin ese filtro, pisarían el default con
`null` y el formulario llegaría en blanco.

---

## 8. Diagnóstico: el bot no respondió

Recorre la lista en orden. Casi siempre falla en los cinco primeros puntos.

| # | Comprobar | Cómo |
|---|---|---|
| 1 | Interruptor | `bot_settings.bot_enabled` del tenant |
| 2 | Horario | `schedule_enabled` + la franja. La píldora de la UI dice *"Activo — fuera de horario"* cuando es esto |
| 3 | Zona horaria | La app corre en UTC. Una zona mal puesta desplaza la franja horas enteras |
| 4 | ¿Ya hay dueño? | Con `assigned_to` no nulo el bot no actúa, por diseño |
| 5 | ¿Conversación nueva? | El job solo se despacha si la conversación es nueva **o** `bot_active` es true |
| 6 | Paso apagado | Un `step_*_enabled` en false salta ese paso — mira la tabla de §5 |
| 7 | ¿Qué hizo? | `bot_logs` guarda mensaje entrante, intención detectada, respuesta y si escaló |
| 8 | Cola viva | `sudo supervisorctl status converza-worker:*` — el bot es un job, sin worker no corre |
| 9 | Credenciales | Sin `wa_phone_number_id` el `WhatsAppService` responde en **modo mock**: se registra el `Message` pero no sale nada a Meta (`wa_message_id` nulo) |

```sql
-- Qué hizo el bot en las últimas 24 h, agrupado por intención
SELECT intent_detected, count(*), sum(escalated::int) AS escalados
FROM bot_logs
WHERE tenant_id = 1 AND created_at > now() - interval '1 day'
GROUP BY 1 ORDER BY 2 DESC;
```

### Errores frecuentes

| Síntoma | Causa |
|---|---|
| El interruptor está en ON y no responde nada | Fuera de la franja horaria, o `schedule_mode` invertido |
| Responde de madrugada y no de día | `schedule_mode = 'outside'` cuando se quería `inside` |
| La franja nocturna cubre un día de más | El día se cuenta por donde la ventana **empieza**, no por donde termina |
| Pregunta dos veces por los suscriptores | Se activó `msg_ask_subscribers` en un flujo con ramas activas (no debería: ver §5) |
| El cliente queda esperando tras apagar el bot | No debería: el corte envía `msg_handoff`. Busca un `bot_logs` con `intent_detected = 'cut_off'` |
| Un contacto que ya habló no recibe el saludo | Correcto por ahora: la conversación se reabre con `bot_step = 'handed_off'` y el job ni se despacha |

---

## 9. Límites conocidos

1. **El bot no se apaga si un admin responde sin asignarse.** El observer solo reacciona a
   cambios de `assigned_to`.
2. **Una conversación reabierta nunca vuelve a saludar.** `resolveForContact` reutiliza el
   hilo, así que `bot_step` sigue en `handed_off`.
3. **Las palabras clave exigen deploy.** No hay configuración por tenant del
   `IntentDetector`.
4. `bot_logs.context_data` es `text` con JSON escrito a mano — no es filtrable con SQL de
   JSON.
