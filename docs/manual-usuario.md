# Manual de usuario — Converza CRM

> **En una frase:** cómo operar Converza en el día a día, módulo por módulo,
> desde que un cliente te escribe hasta que mides el resultado.

Este manual es para el **equipo de un ISP** que usa Converza: administradores y
asesores. Si buscas cómo está construido el sistema, ve a
[manual-desarrollador.md](manual-desarrollador.md).

Dentro de la aplicación hay una versión resumida en **Manual** (menú lateral).
Este documento es la versión completa.

---

## Índice

1. [Conceptos que hay que entender primero](#1-conceptos-que-hay-que-entender-primero)
2. [Roles y qué puede hacer cada uno](#2-roles-y-qué-puede-hacer-cada-uno)
3. [Puesta en marcha](#3-puesta-en-marcha-checklist-del-administrador)
4. [Dashboard](#4-dashboard)
5. [Chat](#5-chat)
6. [Contactos](#6-contactos)
7. [Etiquetas](#7-etiquetas)
8. [Plantillas de WhatsApp](#8-plantillas-de-whatsapp)
9. [Campañas masivas](#9-campañas-masivas)
10. [Notificaciones automáticas](#10-notificaciones-automáticas)
11. [Bot de atención](#11-bot-de-atención)
12. [Staff y Equipos](#12-staff-y-equipos)
13. [Respuestas rápidas](#13-respuestas-rápidas)
14. [Notas de cierre](#14-notas-de-cierre)
15. [Métricas](#15-métricas)
16. [Configuración](#16-configuración)
17. [Buenas prácticas anti-baneo](#17-buenas-prácticas-anti-baneo)
18. [Preguntas frecuentes](#18-preguntas-frecuentes)

---

## 1. Conceptos que hay que entender primero

### La ventana de 24 horas

Esta es **la regla que gobierna todo** en WhatsApp Business.

> Puedes enviar mensajes libres (texto, imágenes, audios, lo que quieras) solo
> durante las **24 horas siguientes al último mensaje del cliente**. Fuera de esa
> ventana, únicamente puedes enviar **plantillas aprobadas por Meta**.

Por eso existen las plantillas, las campañas y los avisos automáticos: son la
única forma legal de iniciar una conversación.

Cada mensaje del cliente reinicia el reloj de 24 h.

### Contacto, conversación y mensaje

| Concepto | Qué es |
|---|---|
| **Contacto** | Una persona con un número de WhatsApp. Se crea sola cuando alguien te escribe |
| **Conversación** | El hilo con ese contacto. Está *abierta* o *cerrada* |
| **Mensaje** | Cada burbuja del hilo (entrante, saliente, nota interna o evento de sistema) |

Un contacto tiene una conversación activa a la vez. Si el cliente escribe cuando
la conversación estaba cerrada, se **reabre** la existente — no se crea un chat
duplicado.

### Cliente de ispwatch vs. prospecto

Si tu workspace está vinculado a ispwatch, Converza busca el teléfono del
contacto en tu base de clientes:

- **Coincide** → el panel derecho del chat muestra estado del servicio, usuario
  PPPoE, IP, saldo a favor y facturas pendientes.
- **No coincide** → se trata como prospecto. Todo lo demás funciona igual.

---

## 2. Roles y qué puede hacer cada uno

Hay tres roles dentro de un workspace:

| Acción | Viewer | Agente | Admin |
|---|:---:|:---:|:---:|
| Ver conversaciones | Todas | **Solo las suyas** | Todas |
| Enviar mensajes y archivos | — | ✅ | ✅ |
| Asignar / transferir conversaciones | — | ✅ | ✅ |
| Notas internas | — | ✅ | ✅ |
| Reabrir conversaciones | — | ✅ | ✅ |
| Etiquetar contactos desde el chat | — | ✅ | ✅ |
| Notas de cierre (crear/editar/reabrir) | Ver | ✅ | ✅ |
| Borrar una conversación | — | — | ✅ |
| Crear y lanzar campañas | Ver | Ver | ✅ |
| Gestionar Staff y Equipos | Ver | Ver | ✅ |
| Configurar el bot | Ver | Ver | ✅ |
| Auto-asignación | — | — | ✅ |
| Contactos, Etiquetas, Plantillas, Respuestas rápidas | ✅ | ✅ | ✅ |

> **Lo más importante de esta tabla:** un **agente solo ve las conversaciones que
> tiene asignadas**. No es un filtro que pueda quitar — es una restricción de
> servidor. Si un agente dice "no veo el chat de Fulano", casi siempre es porque
> no está asignado a él.

---

## 3. Puesta en marcha (checklist del administrador)

Orden recomendado para un workspace nuevo:

**1. Conectar WhatsApp** — *Configuración → WhatsApp*.
Ingresa Phone Number ID, Business Account ID, Access Token, App Secret y Verify
Token (te los da quien administra tu cuenta de Meta). Pulsa **Probar conexión**:
debe quedar en verde. Sin esto no entra ni sale ningún mensaje.

**2. Crear el equipo** — *Staff*. Da de alta a los asesores con su rol.
Empieza con roles altos solo para quien de verdad los necesita.

**3. Agrupar por áreas** — *Equipos* (Ventas, Soporte, Cobranza…). Opcional pero
recomendable si tienes más de 3 asesores.

**4. Definir etiquetas** — *Etiquetas*. Con qué criterios vas a clasificar
contactos (VIP, Moroso, Prospecto…). El botón **Cargar ejemplos** te da un set
inicial.

**5. Traer las plantillas** — *Plantillas* → **Sincronizar**. Baja de Meta todas
las plantillas aprobadas.

**6. Cargar respuestas rápidas** — *Respuestas rápidas*. Las 10 preguntas que más
te hacen, resueltas con un clic.

**7. (Opcional) Activar avisos automáticos** — *Configuración → Avisos*. Ver
[§10](#10-notificaciones-automáticas).

**8. (Opcional) Activar el bot** — *Configuración → Bot*. Ver [§11](#11-bot-de-atención).

**9. Activar la auto-asignación** — *Configuración → Asignación*, si quieres que
los chats nuevos se repartan solos.

---

## 4. Dashboard

La pantalla de inicio. De un vistazo:

- **Saludo y fecha** personalizados.
- **Tarjetas:** conversaciones abiertas, clientes esperando respuesta, cerradas
  hoy, y cuentas por cobrar (si estás vinculado a ispwatch).
- **Gráfica de mensajes:** enviados vs. recibidos, últimos 7 días.
- **Necesitan atención:** las conversaciones con mayor tiempo de espera. Es la
  lista por la que deberías empezar el día.
- **Conversaciones recientes:** clic en cualquiera para abrirla en el Chat.
- **Estado del sistema:** avisa si WhatsApp no está configurado o hay problemas
  de conexión.

> "Clientes esperando" cuenta conversaciones cuyo último mensaje real es del
> cliente. Las notas internas y los eventos de sistema **no** cuentan como
> respuesta: escribir una nota no baja el contador.

---

## 5. Chat

El módulo central. Tres columnas: lista de conversaciones · hilo · ficha del
contacto.

### 5.1 Lista de conversaciones

**Filtros** (chips con contador arriba de la lista):

| Filtro | Muestra |
|---|---|
| Todas | Todas las del workspace |
| Abiertas | Estado abierto |
| Mías | Asignadas a ti y abiertas |
| Sin asignar | Abiertas sin dueño ← *la cola que hay que vaciar* |
| No leídas | Con mensajes entrantes que **tú** no has leído |
| Cerradas | Ya cerradas |

Los agentes ven estos filtros aplicados siempre dentro de sus propias
conversaciones.

**Búsqueda:** por nombre o teléfono, en la barra superior de la lista.

**No leídos:** el badge verde es **por asesor**. Cada uno tiene su propio
contador; que tu compañero abra un chat no baja el tuyo.

> **Excepción importante:** cuando un asesor **responde** a un cliente, la
> conversación se marca como leída para **todo el equipo**. Alguien ya la
> atendió, así que no tiene sentido que siga en verde para los demás.
> Las reacciones (👍) del cliente **no** vuelven a marcar el chat como no leído:
> un emoji sobre un mensaje ya contestado no pide respuesta.

**Alertas de mensaje nuevo:** sonido, parpadeo del título de la pestaña y
contador en el título. El sonido requiere que hagas clic una vez en la página
(restricción del navegador).

### 5.2 El hilo

- **Separadores de día** ("Hoy", "Ayer", fecha) y un **indicador flotante** de
  fecha mientras haces scroll, igual que WhatsApp.
- **Scroll inteligente:** si estás leyendo mensajes viejos, un mensaje nuevo
  **no** te arrastra al final. Solo baja solo si ya estabas abajo.
- **Enlaces clicables:** las URLs del mensaje se convierten en enlaces.
- **Ubicaciones:** se muestran con enlace directo a Google Maps.
- **Tipos especiales:** pedidos del catálogo, respuestas a botones, reacciones y
  mensajes que WhatsApp no permite mostrar (encuestas, mensajes que se
  autodestruyen, códigos de verificación) aparecen con un texto explicativo.

**Estados de un mensaje saliente:** enviado → entregado → leído. Si falla, se
marca como fallido.

### 5.3 Enviar mensajes

**Texto:** escribe y Enter. Al cliente le llega precedido de tu nombre
(`*Ana:* Buenos días`), para que sepa con quién habla. En el CRM lo ves limpio.

**Archivos** — tres formas:

1. Clic en el clip y elegir.
2. **Pegar** una imagen desde el portapapeles (Ctrl+V) — ideal para capturas.
3. **Arrastrar y soltar** sobre la ventana del chat.

Tipos aceptados: imágenes (JPG, PNG, WEBP, GIF), audio (OGG, MP3, M4A, AAC, AMR),
video (MP4, 3GP) y documentos (PDF, Word, Excel, PowerPoint, TXT, CSV).
**Máximo 16 MB.** Puedes agregar un pie de foto antes de enviar.

**Notas de voz:** botón de micrófono. Verás un **medidor de nivel en vivo**: si
la barra no se mueve, tu micrófono no está capturando y la nota saldría muda —
el sistema te lo impide antes de enviar. Puedes cancelar la grabación.

**Escuchar audios:** el reproductor tiene barra de progreso navegable (clic para
saltar) y control de **velocidad** (1× / 1.5× / 2×).

**Ver imágenes y documentos:** clic para abrir el visor. Entre imágenes de la
misma conversación puedes navegar con flechas, y descargar con el nombre original
del archivo.

### 5.4 Notas internas

El interruptor **Nota** sobre el campo de texto cambia el modo:

> ⚠️ Una nota interna **NUNCA se envía al cliente**. Queda en el hilo, visible
> solo para tu equipo, con un estilo distinto.

Úsala para contexto ("ya se le ofreció el plan de 20 megas", "llamó dos veces
esta semana"). Las notas **no** reordenan la lista ni cambian el "último
mensaje", así que no ensucian la bandeja.

### 5.5 Respuestas rápidas

Botón de rayo o escribe `/`. Se abre el listado; un clic inserta el texto. Ver
[§13](#13-respuestas-rápidas).

### 5.6 Asignar y transferir

Desde el selector de asesor en la cabecera del chat.

Cuando transfieres:
1. Queda un **evento en el hilo**: "Ana transfirió la conversación de Luis a Marta".
2. El **cliente recibe un aviso**: "Has sido transferido con Marta. En breve te
   atenderé."
3. Si el bot estaba activo, **se apaga** automáticamente.

También puedes **quitar la asignación** para devolver el chat a la cola.

**Auto-asignación:** si el admin la activó, los chats nuevos van al agente con
**menos conversaciones abiertas**. En empate gana el de menor antigüedad en la
lista, lo que en la práctica reparte como un round-robin.

### 5.7 Presencia y colisión

Cuando otro asesor está viendo la misma conversación que tú, aparece un aviso con
su nombre. Evita la vergüenza de dos respuestas simultáneas al mismo cliente.

En el selector de asesores, un punto indica quién está **en línea** ahora.

### 5.8 Etiquetas del contacto

Desde la cabecera o la ficha derecha puedes agregar y quitar etiquetas sin salir
del chat. Se guardan al instante.

### 5.9 Panel de ispwatch

Si el contacto es cliente tuyo en ispwatch, la columna derecha muestra:

- Estado del servicio (activo / suspendido), con color
- Usuario PPPoE e IP asignada
- Saldo a favor
- **Facturas pendientes** con valor y vencimiento; las vencidas en rojo

> Si acabas de registrar un pago en ispwatch y aquí sigue apareciendo la deuda,
> espera un minuto: estos datos se refrescan cada 60 segundos.

### 5.10 Cerrar y reabrir

**Cerrar:** botón *Cerrar conversación*. Te pide una **nota de cierre** — el
motivo del cierre. Puedes usar plantillas de cierre para estandarizar.

Con la conversación cerrada **no se puede enviar nada**: hay que reabrirla
primero (botón *Reabrir*). Si el cliente escribe, se reabre sola.

**Eliminar** (solo admin) borra la conversación y todos sus mensajes de forma
**irreversible**. Cerrar es casi siempre lo correcto; eliminar es para limpiar
pruebas o spam.

---

## 6. Contactos

Tu base de clientes y prospectos.

**Se crean solos** cuando alguien te escribe por primera vez. También puedes
crearlos a mano con *Nuevo contacto*.

Qué puedes hacer:

- Editar nombre, correo y notas
- Asignar etiquetas
- Abrir el chat directo desde la fila
- **Generar un enlace / QR de WhatsApp** para compartir el contacto
- Eliminar (irreversible)

**Búsqueda y filtros:** por nombre, teléfono o etiqueta.

> **Formato de teléfonos:** Converza normaliza los números. Un móvil colombiano
> de 10 dígitos que empieza en 3 recibe automáticamente el prefijo `57`. Así el
> contacto que creas a mano y el que crea el webhook son el mismo, sin
> duplicados.

---

## 7. Etiquetas

Clasificación transversal, con color.

Para crear una: *Nueva etiqueta* → nombre → color → guardar. Con **Cargar
ejemplos** obtienes un set inicial típico.

Dónde se usan:

- Filtrar en Contactos
- Asignar desde el chat
- **Segmentar audiencias de campañas** ← el uso más potente
- Analizar en Métricas

**Consejo:** menos etiquetas y bien definidas es mejor que muchas ambiguas.
Un buen set inicial: `Cliente`, `Prospecto`, `Moroso`, `VIP`, `No contactar`.

---

## 8. Plantillas de WhatsApp

Las plantillas son mensajes **pre-aprobados por Meta**. Son la única forma de
escribirle a alguien fuera de la ventana de 24 h.

### Sincronizar

Botón **Sincronizar**: consulta tu cuenta de WhatsApp Business y trae todas las
plantillas con su contenido, idioma y estado. Hazlo cada vez que crees o
modifiques plantillas en Meta.

### Crear desde Converza

También puedes redactar una plantilla aquí y **enviarla a aprobación** a Meta con
el botón correspondiente. Elementos disponibles: encabezado, cuerpo, pie y botón
con enlace.

### Estados

| Estado | Significa |
|---|---|
| **Aprobada** | Lista para usar |
| **Pendiente** | Meta la está revisando (minutos a horas) |
| **Rechazada** | Hay que corregirla y reenviarla |

### Categorías

| Categoría | Para qué | Regla |
|---|---|---|
| **Utility** | Facturas, recordatorios, confirmaciones | Relacionada con una transacción existente |
| **Marketing** | Promociones, novedades, campañas en frío | **Obligatoria** para campañas masivas |
| **Authentication** | Códigos de verificación | Solo para eso |

> Usar una plantilla *utility* para promoción viola la política de Meta y pone en
> riesgo tu número. Las campañas de Converza exigen categoría *marketing* por eso.

### Variables

Las plantillas llevan huecos que se rellenan al enviar. Converza usa **variables
con nombre**:

```
Hola {{nombre_cliente}}, tu factura {{numero_factura}} por {{monto}}
vence el {{fecha_vencimiento}}.
```

El editor te muestra la **paleta de variables disponibles** según el propósito
(evento) que elijas para la plantilla, con descripción y ejemplo de cada una. No
tienes que memorizar nada ni recordar el orden: escribes el nombre y listo.

**Propósito "General":** una plantilla de uso libre que puede rellenar datos del
cliente (nombre, PPPoE, IP, saldo, dirección…), de tu empresa y de fecha/hora.

### Activar y desactivar

El interruptor de cada plantilla controla si aparece en los selectores, sin
tener que borrarla.

---

## 9. Campañas masivas

Envío masivo de plantillas a una lista, con seguimiento y protección del número.

> Solo los **administradores** pueden crear, lanzar, pausar o cancelar campañas.
> El resto del equipo puede verlas.

### 9.1 Crear una campaña

**Paso 1 — Audiencia.** Cuatro orígenes:

| Origen | Cómo |
|---|---|
| **Excel / CSV** | Subes el archivo y mapeas columnas |
| **CRM** | Filtras tus propios contactos por etiqueta |
| **Pegar** | Copias y pegas una lista de números |
| **Google Sheets** | Enlace a una hoja publicada |

Converza te muestra una **vista previa de la audiencia**: cuántos quedan, cuántos
se descartan y por qué (número inválido, duplicado, opt-out previo).

**Paso 2 — Mensaje.** Eliges una plantilla **marketing aprobada** y mapeas cada
variable a una columna de tu archivo o a un valor fijo. Hay **vista previa** del
mensaje ya renderizado con datos reales.

**Paso 3 — Secuencia (opcional).** Una campaña puede tener varios pasos:

- **Paso 1:** el envío inicial (siempre se envía).
- **Pasos 2..N:** seguimientos, cada uno con su plantilla, su espera
  (días/horas tras el paso anterior) y su **condición** — típicamente
  *"si no respondió"*.

> Si el contacto **responde** en cualquier momento, la secuencia se **detiene**.
> Eso es una conversión: pasa a ser una conversación normal en el Chat, no un
> destinatario al que seguir persiguiendo.

**Paso 4 — Ritmo y programación.**

- **Mensajes por minuto** (*throttle*): por defecto **20**, deliberadamente
  conservador.
- **Programar** para una fecha y hora, o lanzar de inmediato.

### 9.2 Lanzar y controlar

Una campaña pasa por: `borrador → programada → enviando → completada`.
En cualquier momento puedes **pausar**, **reanudar** o **cancelar**.

Antes de lanzar en serio, usa **Probar**: envía la campaña a un número tuyo para
ver exactamente cómo llega.

### 9.3 Seguimiento

La ficha de la campaña muestra el embudo:

**Enviados → Entregados → Leídos → Respondidos**, más fallidos y omitidos, con
el desglose **por paso** de la secuencia.

Acciones disponibles: **exportar** los resultados, **reintentar los fallidos**,
**duplicar** la campaña.

### 9.4 Opt-out (bajas)

Si un contacto responde con una palabra de baja (`STOP`, `BAJA`, `CANCELAR`,
`NO MAS`, `UNSUBSCRIBE`…), Converza:

1. Lo agrega a la lista de bajas del workspace.
2. Detiene cualquier secuencia en la que esté.
3. Lo **excluye de todas las campañas futuras**, automáticamente.

No tienes que hacer nada. Y no puedes saltártelo: es una protección del número,
no una preferencia.

### 9.5 Calentamiento del número (warm-up)

Un número nuevo que envía 5.000 mensajes el primer día se quema. El warm-up es
una **rampa de volumen diario**:

- Empieza en X mensajes/día
- Sube Y cada día
- Con un techo Z

Está **apagado por defecto** (activarlo capa el volumen y hay que decidirlo). Se
configura desde la sección de campañas.

---

## 10. Notificaciones automáticas

*(Requiere que tu workspace esté vinculado a ispwatch.)*

Converza vigila tu base de ispwatch y envía mensajes de WhatsApp solos cuando
ocurren ciertos eventos. Es la funcionalidad que más trabajo manual ahorra.

### 10.1 Eventos disponibles

| Evento | Cuándo se dispara |
|---|---|
| **Factura generada** | El día que tu router genera la factura del ciclo. Solo a quien queda debiendo algo |
| **Recordatorio de pago** | El día de recordatorio configurado en el router |
| **Aviso de suspensión** | El día de corte, a quien tenga saldo pendiente |
| **Bienvenida** | Al activarse el servicio de un cliente nuevo |
| **Pago registrado** | Al registrar un pago en ispwatch |
| **Falla masiva — inicio** | Cuando ispwatch reporta la caída de un core/router: avisa a **todos** sus clientes activos |
| **Falla masiva — restablecido** | Cuando ese router vuelve a estar operativo |

Las fechas y horas **no se configuran en Converza**: se leen de la configuración
de facturación de tu router en ispwatch. Converza respeta lo que ya tenías.

### 10.2 Cómo activarlos

*Configuración → Avisos*. Para cada evento:

1. Elige la **plantilla** que se enviará.
2. Enciende el interruptor.

**Todos están apagados por defecto.** Un aviso sin plantilla asignada no envía
nada.

### 10.3 Variables por evento

Cada evento expone su propio conjunto de variables. Además de las obvias
(nombre, monto, número de factura, vencimiento), los tres avisos de facturación
incluyen variables de **deuda acumulada**:

| Variable | Qué trae |
|---|---|
| `saldo_total` | Suma de **todas** las facturas pendientes, incluidos meses anteriores |
| `saldo_anterior` | Solo el arrastre de meses anteriores |
| `facturas_pendientes` | Cuántas facturas sin pagar tiene |
| `detalle_pendientes` | Lista de facturas con valor y vencimiento |
| `saldo_favor` | Saldo a favor que le queda |

Son opcionales: si no las usas, el mensaje habla solo del ciclo actual.

> **Importante sobre los montos:** `monto` es lo que el cliente **debe pagar**,
> ya descontado su saldo a favor — no el total bruto facturado. Cobrarle el bruto
> es cobrarle algo que ya estaba saldado. Si necesitas el bruto, usa `monto_total`.

### 10.4 Bitácora

*Notificaciones* (menú lateral) muestra todo lo enviado: a quién, cuándo, con qué
plantilla y con qué resultado. Es el lugar al que ir cuando un cliente dice "no
me llegó".

### 10.5 Salvaguardas

El sistema está diseñado para **no** inundar a nadie:

- **Idempotencia:** un cliente no recibe dos veces el mismo aviso del mismo ciclo,
  aunque el proceso corra mil veces.
- **Recuperación (catch-up):** si el sistema estuvo caído el día del aviso, lo
  reintenta hasta 2 días, sin cruzar al ciclo siguiente.
- **Ritmo:** máximo ~40 avisos por minuto, espaciados entre sí.
- **Frescura:** no se envían avisos por hechos de hace más de 2 días (6 horas
  para fallas de router). Nadie recibe una bienvenida rancia.
- **Detección de carga masiva:** si importas 500 clientes de golpe a ispwatch,
  **no** se envían 500 bienvenidas. Solo las altas manuales reciben bienvenida.
- **Arranque en frío:** al activar un evento por primera vez, no se dispara el
  histórico. Empieza a contar desde ese momento.

---

## 11. Bot de atención

*Configuración → Bot.*

Un bot de palabras clave que atiende el primer contacto y escala a un humano.

### Cómo funciona

```
Cliente escribe por primera vez
      ↓
  Saludo con menú (1-5)
      ↓
  Cliente responde
      ↓
 ¿Se entiende la intención?
   ├─ Sí  → mensaje de esa rama + pregunta de calificación
   │         ↓
   │      pide nº de suscriptores → pide nombre (si no lo tiene)
   │         ↓
   │      mensaje de traspaso + se apaga + asigna asesor
   │
   ├─ No (1ª vez) → "no entendí, ¿me repites?"
   └─ No (2ª vez) → traspaso a un asesor
```

### Qué configuras

Un interruptor general y el texto de cada mensaje:

- **Saludo** (con el menú numerado)
- **Información**, **Socio fundador**, **Demo**, **Precios** — una por rama
- **Traspaso** — el mensaje al pasar a un humano
- **No entendí 1** y **No entendí 2**

### Reglas que conviene conocer

- El bot **solo actúa en conversaciones sin asignar**. En cuanto un asesor toma
  el chat, el bot se apaga.
- El cliente puede escribir un número (`3`), el emoji (`3️⃣`) o palabras
  (`quiero ver una demo`).
- El bot **captura el nombre** del cliente y lo guarda en su ficha.
- Todo lo que hace queda registrado para auditoría.
- Si el cliente pide hablar con una persona, el traspaso es inmediato.

---

## 12. Staff y Equipos

### Staff

*Staff* lista a los miembros del workspace. Solo un **admin** puede gestionarlos.

- **Crear** directamente (nombre, correo, contraseña inicial) o **invitar** por
  correo.
- **Editar** nombre, rol y equipo.
- **Activar / desactivar** para bloquear el acceso sin borrar la cuenta.
- **Eliminar**.

> Al desactivar a un asesor, sus conversaciones abiertas quedan disponibles para
> reasignarse. Revísalas: no se reparten solas.

Si alguien entra al chat sin tener ficha de staff, Converza se la crea
automáticamente con rol **agente**.

### Equipos

Agrupan asesores por área (Ventas, Soporte, Cobranza). Cada equipo tiene nombre,
color y descripción. Con **Cargar ejemplos** obtienes un set inicial.

---

## 13. Respuestas rápidas

Mensajes pre-escritos, disponibles en el chat con un clic.

**Crear:** título, atajo (ej. `/horarios`), categoría y cuerpo del mensaje.

**Usar:** en el chat, botón de rayo o escribe `/`.

**Extras:** duplicar (para variantes), activar/desactivar, cargar ejemplos.

> Diferencia con las plantillas: las respuestas rápidas son **texto libre** y
> solo funcionan **dentro** de la ventana de 24 h. No requieren aprobación de
> Meta. Las plantillas sí, y funcionan siempre.

---

## 14. Notas de cierre

Cada conversación cerrada lleva una nota que explica cómo terminó.

**Para qué sirven:**

- **Trazabilidad:** por qué se cerró cada chat.
- **Métricas:** alimentan el reporte de cierres por asesor.
- **Reapertura:** puedes reabrir desde aquí si el cliente vuelve.

En *Notas de cierre* tienes la bitácora completa, con búsqueda y filtros.

**Consejo:** acuerda con tu equipo una lista corta y fija de motivos de cierre
("Venta cerrada", "Sin interés", "Soporte resuelto", "No contesta"). Si cada uno
escribe lo que quiere, la métrica no sirve para nada.

---

## 15. Métricas

Reportes sobre datos reales de los últimos 30 días.

| Bloque | Qué mide |
|---|---|
| **Resumen** | Conversaciones, contactos, mensajes, tasas |
| **Tiempo de respuesta** | Promedio, más rápido, más lento, y % de mensajes respondidos |
| **Mensajes por día** | Enviados vs. recibidos |
| **Crecimiento de contactos** | Nuevos contactos por semana (12 semanas) |
| **Mensajes por hora** | Distribución 0-23 h → **con qué horario cubrir el turno** |
| **Mensajes por tipo** | Texto, imagen, audio, documento… |
| **Top contactos** | Quiénes más escriben |
| **Por asesor** | Mensajes enviados, chats cerrados, abiertos asignados ahora y tiempo de respuesta individual |

**Cómo se calcula el tiempo de respuesta:** por cada mensaje recibido se busca la
siguiente respuesta en esa conversación y se mide la diferencia. Se descartan los
recibidos que aún no tienen respuesta y las diferencias mayores a 7 días
(conversaciones abandonadas que distorsionarían el promedio).

---

## 16. Configuración

### Perfil del workspace
Nombre, identificador y estado. El nombre aparece en los avisos automáticos como
variable `{{empresa}}`.

### WhatsApp API
Phone Number ID, Business Account ID, Access Token, App Secret y Verify Token.
Los tokens se guardan **cifrados**. El botón **Probar conexión** valida las
credenciales sin enviar mensajes.

También verás la **URL del webhook** que hay que registrar en Meta.

### Salud del número
Consulta el estado real de tu número en Meta:

| Dato | Qué significa |
|---|---|
| **Calificación de calidad** | Verde / amarillo / rojo. Rojo es antesala de restricción |
| **Nivel de mensajería** | A cuántos destinatarios únicos puedes escribir cada 24 h (250 / 1.000 / 10.000 / 100.000+) |
| **Nombre verificado** | Cómo te ve el cliente |

> Revísalo **antes** de lanzar una campaña grande.

### Avisos
Asignación de plantilla y encendido de cada evento automático. Ver
[§10](#10-notificaciones-automáticas).

### Asignación
Interruptor de auto-asignación al agente menos ocupado (solo admin).

### Bot
Ver [§11](#11-bot-de-atención).

### Integración ispwatch
**Informativa, no editable.** Muestra si tu workspace está vinculado y el estado
de la conexión. La vinculación la realiza el equipo de Converza.

> 🔒 **Nunca compartas tus tokens de WhatsApp.** Si sospechas que se filtraron,
> regenéralos en Meta Business Manager y actualízalos aquí de inmediato.

---

## 17. Buenas prácticas anti-baneo

Tu número de WhatsApp Business es un activo frágil. Meta puede restringirlo o
banearlo, y recuperarlo es lento o imposible.

**Lo que te quema el número:**

- Enviar en frío a listas compradas o sin consentimiento
- Que la gente te reporte o bloquee
- Ráfagas grandes desde un número nuevo
- Usar plantillas *utility* para promoción
- Ignorar las bajas

**Lo que te protege:**

| Práctica | Cómo |
|---|---|
| Envía solo a quien te dio su número | Nunca compres listas |
| Empieza despacio con números nuevos | Activa el warm-up |
| Respeta el ritmo | El throttle por defecto (20/min) existe por algo |
| Categoriza bien las plantillas | Marketing para promoción, utility para transacciones |
| Facilita la baja | El opt-out ya es automático; no lo esquives |
| Vigila la calidad | Revisa *Salud del número* con frecuencia |
| Prueba antes de lanzar | Botón **Probar** de cada campaña |
| Responde rápido | Un número que conversa tiene mejor reputación que uno que solo emite |

**Si tu calidad baja a amarillo o rojo:** para las campañas de inmediato, revisa
qué mensaje generó reportes y deja el número descansar unos días atendiendo solo
conversaciones entrantes.

---

## 18. Preguntas frecuentes

**No puedo enviar un mensaje, dice que la conversación está cerrada.**
Pulsa *Reabrir* en la cabecera del chat.

**No veo un chat que sé que existe.**
Si eres agente, solo ves lo tuyo. Pide a un admin que te lo asigne.

**El cliente no recibió mi mensaje.**
Mira el estado de la burbuja. Si es *fallido*, lo más probable es que estés fuera
de la ventana de 24 h — usa una plantilla. Verifica también que WhatsApp esté en
verde en el menú lateral.

**Registré un pago en ispwatch pero el chat sigue mostrando la deuda.**
Espera un minuto: los datos de ispwatch se refrescan cada 60 segundos.

**No suena la alerta de mensaje nuevo.**
Haz clic una vez en cualquier parte de la página. Los navegadores bloquean el
audio hasta que el usuario interactúa.

**Mi nota interna se envió al cliente.**
No es posible: las notas nunca salen. Si el cliente lo recibió, el interruptor
*Nota* estaba apagado al escribir.

**¿Puedo enviar a alguien que nunca me escribió?**
Sí, pero solo con una **plantilla aprobada** (individual o por campaña).

**Un contacto aparece dos veces.**
Suele ser el mismo número escrito distinto. Converza normaliza los móviles
colombianos, pero un número extranjero mal escrito puede duplicarse. Corrígelo
en *Contactos*.

**¿Puedo recuperar una conversación eliminada?**
No. El borrado es definitivo y sin papelera. Para casi todo, *cerrar* es la
opción correcta.

**¿Puedo tener dos números de WhatsApp?**
Un workspace maneja un número. Para varios, se requieren workspaces separados —
consúltalo con el equipo de Converza.

---

## Soporte

El botón verde flotante abre WhatsApp con el equipo de Converza y un mensaje
pre-armado que incluye tu nombre y el de tu workspace.

Antes de escribir:

1. Revisa este manual.
2. Comprueba que el indicador de WhatsApp del menú lateral esté en verde.
3. Si reportas un error, incluye: **qué hacías**, **qué esperabas** y **qué pasó**.
