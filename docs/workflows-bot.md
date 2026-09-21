# Workflows del bot — arquitectura para el cliente

> **Estado: PROPUESTA.** La decisión de motor está condicionada al resultado de
> CON-78 (encender el bot actual en un ISP real y medir). Este documento fija la
> forma de la solución y las restricciones que ya conocemos; lo que falta validar
> está marcado como tal, no disimulado.
>
> **Fecha:** 2026-09-21 · **Épica:** CON-36 · **Spike:** CON-46

---

## 1. Qué problema resuelve, y para quién

**Para el ISP:** poder cambiar cómo atiende su bot —menús, preguntas,
condiciones, consultas a ispwatch, entrega a un asesor— sin pedirle nada al
equipo de Converza.

**Hoy no se puede.** `HandleBotResponse` es una máquina de estados cableada con
un menú fijo de cinco opciones y `IntentDetector` con palabras clave en código.
`BotSetting` deja editar **el texto** de esos mensajes, no la forma del flujo.
Agregarle una sexta rama a un cliente es código y despliegue.

### Lo que la evidencia obliga a decir antes de seguir

Medido en producción el 12 y el 21/09/2026:

| Hecho | Dato |
|---|---|
| Empresas con bot configurado | **1 de 3** — sólo el tenant interno |
| Chaguaní (836 conversaciones) y Tocaima (216) | sin configurar. Nunca se les encendió |
| Conversaciones que el bot tocó en su vida | 16, todas internas. Última: 20/08 |
| Mensajes automáticos enviados | 15 en total, todos en mayo de 2026 |
| Campañas creadas en la historia del sistema | **cero** |

**La rigidez del bot es un techo real, pero todavía teórico:** ningún ISP llegó a
chocar contra él porque ninguno llegó a usar el bot. El riesgo de construir el
workspace completo sin validar es repetir el mismo error, más caro. Por eso
CON-78 va primero.

Este documento existe igual porque la forma de la solución no depende de esa
validación — lo que depende es **cuándo** construirla y **con cuántos nodos**.

---

## 2. La decisión: motor propio + un nodo HTTP

Tres opciones estaban sobre la mesa (detalle en CON-46). Se propone la **C,
híbrida**: un motor de flujos dentro de Converza, más **un** nodo de
`HTTP request / webhook` como escape hatch hacia n8n, Make o la API del cliente.

**Por qué no n8n como núcleo:**

- **Recursos.** El droplet es de 1 GB y ya carga Postgres, Redis y los workers.
  n8n necesita otro host: costo nuevo y un dominio de fallo nuevo.
- **Aislamiento.** n8n no separa por tenant sin Enterprise. Una instancia
  compartida deja que un ISP vea flujos y credenciales de otro; una por ISP no
  escala en costo.
- **Superficie.** Hoy no hay API pública. Habría que construirla, autenticarla y
  limitarla — y aun así el pacing anti-baneo, la ventana de 24 h y las plantillas
  tendrían que aplicarse de este lado.
- **Audiencia.** Los usuarios son ISPs, no ingenieros de automatización.

**Por qué sí motor propio:** el runtime conversacional queda en el mismo sistema
que es dueño del número de WhatsApp, así que hereda gratis las colas, el binding
de tenant, `WhatsAppService`, el pacing y las plantillas.

**Y el escape hatch:** un solo nodo HTTP cubre la cola larga de integraciones sin
traerse la infraestructura.

---

## 3. La restricción que manda sobre todo lo demás

**La ventana de 24 h de WhatsApp no es un detalle de implementación: es la
primera regla de diseño del editor.**

Fuera de las 24 h posteriores al último mensaje *del cliente*, WhatsApp no
entrega texto libre. Y no lo rechaza en el momento: acepta la llamada con 200 y
un `wamid`, y el rechazo llega después por webhook. Para el flujo, eso significa
que **un nodo de "enviar mensaje" puede fallar el 100 % de las veces sin que nada
en el editor lo delate.**

No es hipotético. Entre el 11 y el 20/09/2026, dos asesores escribiendo a mano
generaron **120 mensajes rechazados** por esa causa — el 27,5 % de todo lo que
escribieron. 117 de ellos iban a hilos de más de 30 días.

Consecuencias no negociables para la arquitectura:

1. **El nodo de mensaje conoce la ventana.** Sabe si el hilo está dentro o fuera
   y, fuera, exige una plantilla aprobada. No "avisa": lo exige.
2. **El editor lo muestra al diseñar, no al fallar.** Un flujo con un nodo de
   texto libre después de una espera de 48 h es un flujo roto, y la validación
   previa a publicar tiene que decirlo — no descubrirlo el cliente.
3. **Sin plantilla aprobada no hay flujo saliente.** Un ISP sin plantilla neutra
   aprobada no puede construir nada que contacte fuera de ventana. Hoy Tocaima
   está exactamente ahí. El editor debe decirlo con todas las letras en vez de
   dejar armar algo que nunca va a funcionar.

> La lección viene de un error propio: se entregó un aviso de ventana cerrada sin
> forma de medir si cambiaba el comportamiento, y hubo que deducirlo del conteo
> de fallos. **Todo nodo que envíe algo tiene que ser observable desde el día
> uno.**

---

## 4. Qué ve el cliente

Una sección **Workspace** con la lista de sus flujos. Cada flujo tiene un
borrador y una versión publicada; se edita el borrador y "Publicar" crea una
versión nueva. Nadie debe poder romperle el bot a sus clientes a media edición.

### Los bloques de la v1

El criterio: cubrir lo que hoy hace el bot cableado **más** lo que ya se sabe que
piden los clientes. Nada más.

| Bloque | Qué hace | De dónde sale |
|---|---|---|
| **Mensaje** | Envía texto, media o plantilla, con variables | el `msg_*` de hoy, generalizado |
| **Pregunta** | Envía y espera respuesta; guarda en una variable | el paso `qualifying_name` |
| **Menú** | Opciones numeradas, una rama por opción | el menú fijo de 5 opciones |
| **Condición** | Ramifica por variable, etiqueta, horario u hora | nuevo |
| **Datos de ispwatch** | Consulta el cliente por teléfono (solo lectura): servicio, estado, saldo, factura | nuevo |
| **Handoff** | Apaga el bot y entrega a un asesor | el `handed_off` de hoy |
| **Etiquetar** | Aplica una etiqueta al contacto | nuevo |
| **Espera** | Pausa N minutos | nuevo |
| **Fin** | Cierra el flujo | — |

**Detección de intención:** el bloque Menú acepta palabras clave, que es lo que
hace hoy `IntentDetector`. La clasificación con IA (CON-25) entra como **una
variante de ese bloque**, no como un rediseño.

### El simulador

Un panel donde el admin conversa con su flujo **sin enviar nada por WhatsApp**:
la misma ejecución del motor con salida simulada.

No es comodidad. Sin simulador, probar un flujo implica escribirle a un número
real, y eso quema calidad del número — el riesgo que este proyecto cuida por
encima de todo.

---

## 5. Cómo se ejecuta por dentro

### Modelo de datos

- `bot_flows` — tenant, nombre, estado (`draft`/`published`), versión, disparador.
- `bot_flow_nodes` — tipo, configuración (JSON), posición en el lienzo.
- `bot_flow_edges` — origen, destino, condición de la rama.
- `bot_flow_runs` — conversación, nodo actual, variables, estado. **Reemplaza a
  `conversations.bot_step` y `bot_context`.**

Todos con `BelongsToTenant`.

### Cotas obligatorias

Aprendidas de operar el bot actual y de incidentes reales de este proyecto:

- **Tope de pasos por mensaje entrante.** Corta bucles. Sin esto, un flujo mal
  armado por el cliente bloquea un worker, y sólo hay cuatro.
- **Timeout por nodo**, corto y explícito.
- **Lock por conversación.** Dos mensajes del mismo cliente en el mismo segundo
  no pueden ejecutar el flujo dos veces.
- **Versionado inmutable.** Un run en curso termina con la versión con la que
  empezó. Publicar no puede dejar a un cliente a mitad de un flujo que ya no
  existe.
- **Binding de tenant explícito en los jobs**: rebindear desde el `tenant_id` del
  run y liberar en `finally`. El container se reusa entre jobs, y ese patrón ya
  causó una fuga de mensajes entre tenants.
- **Cola propia (`flows`)**, separada de la de envíos, para que un nodo lento no
  atasque los mensajes a clientes.
- **Todo lo que sale pasa por `WhatsAppService`.** Nada de HTTP a Meta desde el
  motor: ahí viven el pacing, las plantillas y el manejo de BSUID.

### El nodo HTTP, que es el más peligroso

Mete una dependencia externa dentro de un job. Restricciones:

- Nunca en la cola de envíos.
- Timeout corto y reintentos acotados.
- **Bloqueo de red interna (SSRF).** Un tenant no puede apuntar a `127.0.0.1`,
  `169.254.169.254` ni a la red privada del droplet.
- Secretos cifrados en reposo y ocultos en la UI tras guardarse.
- Respuesta acotada y mapeo explícito de campos a variables.
- **Ruta de fallo definida:** si la llamada falla, el flujo toma la rama de error.
  Jamás deja al cliente en silencio.

---

## 6. Qué NO es esto

- **No es n8n.** No hay catálogo de cientos de integraciones ni ejecución
  arbitraria de código. Un ISP que necesite eso lo llama por el nodo HTTP.
- **No es un bot con IA.** La IA entra como variante del bloque Menú cuando se
  justifique, no como núcleo.
- **No reemplaza a Campañas.** Los flujos son conversacionales —reaccionan a lo
  que escribe el cliente—. El contacto masivo saliente ya tiene su herramienta,
  con audiencia, pacing y opt-out.

> Dato incómodo que conviene tener presente: Campañas **nunca se ha usado**, y en
> cambio los asesores hacen contacto masivo a mano, uno por uno, con el 100 % de
> los mensajes rechazados. Antes de construir una herramienta nueva, vale la pena
> entender por qué no se usa la que ya existe.

---

## 7. Orden de construcción

| Paso | Qué | Por qué en ese orden |
|---|---|---|
| **0** | CON-78 · encender el bot actual en un ISP real y medir 2 semanas | Devuelve el inventario de flujos reales que CON-46 necesita para decidir. Cuesta horas, no semanas |
| 1 | CON-46 · cerrar la decisión de motor con ese inventario | Es la compuerta de la épica |
| 2 | CON-47 · modelo de datos y motor | El corazón |
| 3 | CON-49 · librería de nodos v1 + simulador | Sin nodos el motor no hace nada |
| 4 | CON-48 · editor visual | Lo más caro; va cuando lo de abajo funciona |
| 5 | CON-50 · nodo HTTP | Se puede diferir sin bloquear nada |
| 6 | CON-51 · migrar el bot actual y borrar la máquina de estados | Cierre: que no queden dos bots |

---

## 8. Lo que falta validar

No está resuelto y no conviene fingir que sí:

1. **Si algún ISP quiere un bot.** Ninguno lo ha usado. CON-78 responde esto.
2. **Cuántos flujos reales necesitan algo fuera de mensaje / pregunta /
   condición / dato de ispwatch / handoff.** Es el criterio con el que CON-46
   decide entre A, B y C. Sin el inventario, la recomendación de este documento
   es razonada pero no está probada.
3. **Si el editor visual hace falta en la v1.** Un formulario por pasos podría
   alcanzar para los primeros flujos y cuesta una fracción del lienzo.

Ver [bot.md](bot.md) para cómo funciona el bot actual y
[campanas.md](campanas.md) para la herramienta de contacto masivo.
