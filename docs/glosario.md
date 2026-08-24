# Glosario

Vocabulario del dominio. La palabra "cliente" significa tres cosas distintas
según quién la use — esta tabla existe para evitar esa confusión.

---

## Las tres capas de "cliente"

| Término | Quién es | Dónde vive |
|---|---|---|
| **Dueño del SaaS** / founder | Tú y tu equipo interno | `users.is_superadmin`, `users.internal_role` |
| **Cliente del SaaS** / ISP / **tenant** / workspace / **cuenta** | La empresa que te paga por usar Converza | `tenants` + `accounts` (Brain) |
| **Cliente final** / suscriptor / **contacto** | El usuario de internet del ISP | `contacts` (Converza), `customer_profile` (ispwatch) |

Cuando alguien dice "el cliente no recibió el mensaje", casi siempre habla del
**cliente final**. Cuando dice "hay que facturarle al cliente", habla del **ISP**.

---

## A — L

**Agente** · Rol de staff que puede enviar mensajes y gestionar conversaciones,
pero **solo ve las que tiene asignadas**. Ver [Rol de staff](#r--z).

**Aviso automático** · Mensaje de WhatsApp que Converza envía solo al ocurrir un
evento (factura generada, pago registrado, falla de router…). Ver
[avisos-automaticos.md](avisos-automaticos.md).

**Bot** · Máquina de estados por palabras clave que atiende el primer contacto y
escala a un humano. Se apaga en cuanto un agente toma la conversación. Tiene
interruptor, horario de atención y un switch por paso del flujo. Ver
[bot.md](bot.md).

**Campaña** · Envío masivo de plantillas a una lista, con secuencia de pasos y
pacing. Ver [campanas.md](campanas.md).

**Catch-up** · Ventana de días durante la cual un aviso perdido se reintenta sin
duplicar. Default: 2 días.

**Cloud API** · La API de WhatsApp de Meta alojada por ellos (frente a la
*On-Premises API*, obsoleta). Es la que usa Converza.

**Colisión** · Situación en la que dos asesores tienen abierta la misma
conversación. Converza la detecta con presencia y avisa.

**Contacto** · Una persona con WhatsApp dentro de un tenant. Único por
`(tenant_id, phone)`. Se crea solo al recibir el primer mensaje.

**Conversación** · El hilo con un contacto. `open` | `closed` | `pending`. Un
contacto tiene una conversación activa a la vez.

**Core Brain** · Back-office privado del dueño del SaaS. Ver
[core-brain.md](core-brain.md).

**Cuenta** (*Account*) · La ficha comercial de un ISP en el Core Brain. Cruza su
tenant de Converza con su tenant de ispwatch, más plan, cobros y soporte.

**Cursor de eventos** · Marca de agua (`ispwatch_event_cursors`) del último ID
procesado por tenant y evento. Base del polling incremental sobre ispwatch.

**Evento** (de aviso) · Un propósito del catálogo: `invoice_created`,
`payment_reminder`, `service_activated`… Definidos en `EventCatalog`.

**Fan-out** · Un solo hecho origen que genera muchos envíos. El caso típico: una
falla de router avisa a todos sus clientes activos.

**Global scope** · Filtro que Eloquent añade a todas las consultas de un modelo.
Aquí, `where tenant_id = …`. Es la base del aislamiento.

**Idempotencia** · Que repetir la operación no duplique el efecto. En avisos, por
ciclo; en el webhook, por `wa_message_id` único.

**ispwatch** · Sistema de gestión para ISPs (proyecto Laravel separado, Postgres
en Supabase). Fuente de verdad de clientes, facturas, pagos y routers. Converza
lo lee, **nunca** lo escribe.

**Least-busy** · Estrategia de auto-asignación: al agente activo con menos
conversaciones abiertas. Empate → menor ID (round-robin de facto).

---

## M — Q

**MRR** · *Monthly Recurring Revenue*. Ingreso mensual recurrente del SaaS.
Calculado en el Cockpit sumando los productos activos de las cuentas activas.

**Messaging tier** · Nivel de mensajería: cuántos destinatarios **únicos** puedes
contactar por iniciativa propia cada 24 h (250 / 1.000 / 10.000 / 100.000 /
ilimitado). Lo asigna Meta.

**Nota de cierre** · Motivo con el que se cierra una conversación. Alimenta las
métricas de cierre.

**Nota interna** · Mensaje visible **solo para el equipo**, guardado como
`Message` con `type = 'note'`. **Nunca** se envía al cliente.

**Opt-out** · Baja voluntaria. Se dispara con palabras clave (`STOP`, `BAJA`,
`CANCELAR`…) y excluye al contacto de **todas** las campañas futuras del tenant.

**Pacing** · Espaciar los envíos para no salir en ráfaga y proteger la reputación
del número. Dos dimensiones: ritmo por minuto y volumen diario (warm-up).

**Plantilla** (*template*) · Mensaje pre-aprobado por Meta. La **única** forma de
escribir fuera de la ventana de 24 h.

**PPPoE** · Protocolo de autenticación de la conexión del suscriptor. El usuario
PPPoE identifica al cliente final en la red del ISP.

**Presencia** · Quién está en línea y qué conversación está viendo. Se calcula
desde el heartbeat del polling, guardado en cache con TTL de 20 s.

**Prospecto** · Contacto que **no** existe en la base de ispwatch.

**Quality rating** · Calificación de calidad del número en Meta (verde/amarillo/
rojo), basada en bloqueos y reportes. En rojo, Meta restringe el número.

---

## R — Z

**Rol de staff** · `viewer` < `agent` < `admin`, dentro de un tenant. **No
confundir** con `superadmin` (dueño del SaaS) ni con `internal_role` (Core Brain).

**Saldo a favor** (*credit balance*) · Crédito del cliente. En ispwatch se aplica
**al crear** la factura, dejándola en `balance_due = 0`. Por eso los avisos usan
`balance_due` y no `total`.

**Secuencia** · Serie de pasos de una campaña, con espera y condición entre ellos.

**Slug** · Identificador legible y único de un tenant. El slug `default` es el
del dueño del SaaS y el **único** que puede usar las credenciales del `.env`.

**Staff member** · La pertenencia de un usuario a un tenant, con rol. Separado de
`users` (identidad) para poder desactivar a alguien sin borrar su historial.

**Superadmin** · Dueño del SaaS (`users.is_superadmin`). Accede a `/admin` y
cuenta como `admin` dentro de cualquier tenant.

**Tenant** · Un workspace = un ISP cliente. La unidad de aislamiento de todo el
sistema.

**Throttle** · Mensajes por minuto de una campaña. Default 20.

**Ventana de 24 horas** · Periodo tras el último mensaje del cliente durante el
cual puedes responder con texto libre. Fuera de ella, solo plantillas. **La regla
que condiciona todo el diseño.**

**WABA** · *WhatsApp Business Account*. La cuenta de Meta que agrupa números y
plantillas. `tenants.wa_business_account_id`.

**Warm-up** · Rampa de volumen diario para un número nuevo: empieza bajo y sube
cada día. Por número, compartido entre todas sus campañas.

**Webhook** · `POST /webhook`. La puerta de entrada de todo lo que envía Meta:
mensajes y acuses de estado.

---

## Falsos amigos

Pares que se confunden con frecuencia:

| No confundir | Con | Diferencia |
|---|---|---|
| `superadmin` | `role:admin` | Dueño del SaaS vs. admin de un workspace |
| Tenant | Cuenta (*Account*) | El workspace técnico vs. la ficha comercial |
| Plantilla | Respuesta rápida | Aprobada por Meta y siempre válida vs. texto libre dentro de las 24 h |
| Nota interna | Nota de cierre | Contexto durante la conversación vs. motivo al cerrarla |
| `throttle` | Warm-up | Ritmo por minuto vs. volumen por día |
| `notification_type` | `notificar_wpp` | El campo vigente de ispwatch vs. la columna legada |
| `balance_due` | `total` | Lo que el cliente debe vs. lo facturado antes del saldo a favor |
| Contacto | Cliente de ispwatch | Un contacto puede ser prospecto; solo algunos existen en ispwatch |
