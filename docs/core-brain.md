# Core Brain

> **En una frase:** el back-office privado del dueño del SaaS — una ficha por
> cada ISP cliente que cruza lo que tiene en Converza con lo que tiene en
> ispwatch, más el cobro y el soporte de esa relación comercial.

**Archivos clave**
- [app/Http/Controllers/Brain/](../app/Http/Controllers/Brain/) — Cockpit, Account, Billing, Finance, Ticket, TicketInbox
- [app/Services/Brain/CobroAutomatico.php](../app/Services/Brain/CobroAutomatico.php) — cobro automático (§8), y [`AvisosDeCobro`](../app/Services/Brain/AvisosDeCobro.php)
- [app/Http/Middleware/EnsureServicioActivo.php](../app/Http/Middleware/EnsureServicioActivo.php) — el corte del panel (§8)
- [app/Http/Controllers/SupportController.php](../app/Http/Controllers/SupportController.php) — el portal del ISP (§9); la única puerta de un tenant hacia estas tablas
- [app/Models/Brain/](../app/Models/Brain/) — Account, AccountProduct, AccountInvoice, AccountPayment, SupportTicket, TicketEvent, AccountNote
- [app/Services/Brain/PlanCatalog.php](../app/Services/Brain/PlanCatalog.php) — catálogo de planes
- [app/Http/Middleware/EnsureInternalAccess.php](../app/Http/Middleware/EnsureInternalAccess.php) — el muro
- [core-brain-plan.md](core-brain-plan.md) — plan de diseño original (histórico)

---

## 1. Qué es y qué no es

**Es:** el lugar donde el fundador ve, de un ISP cliente, *quién es, qué tiene
contratado, cuánto paga, si está al día y qué soporte ha pedido* — en una sola
ficha.

**No es:** un módulo del producto. Ningún usuario de un tenant puede llegar a una
ruta, tabla ni endpoint del Brain. Está detrás de `auth` + `internal`, y sus
tablas **no usan** `BelongsToTenant` a propósito.

---

## 2. Las tres capas, otra vez

| Capa | Quién | El Brain |
|---|---|---|
| 1 | Tú y tu equipo interno | Los únicos que lo ven |
| 2 | **Los ISPs que te pagan** | **El objeto central: la Cuenta** |
| 3 | Los clientes finales del ISP | Solo lectura y agregado. Nunca se tocan |

---

## 3. Por qué `accounts` y no `tenants`

Tres razones, todas prácticas:

1. Un ISP puede tener **ispwatch pero todavía no Converza** (o al revés). No
   siempre hay un `tenant` de Converza donde colgar la información.
2. La información comercial (cuánto paga, cuándo renueva, si está en mora) es
   **privada del fundador** y no debe vivir en la fila `tenants`, que es
   territorio del admin del ISP.
3. `accounts` es la **llave de cruce** entre ambos productos. `tenants` (Converza)
   y `tenant` (ispwatch) cuelgan de ella, no al revés.

De ahí que `accounts.tenant_id` y `accounts.ispwatch_tenant_id` sean ambos
**nullable**.

---

## 4. Acceso

```php
User::canAccessBrain()   // is_superadmin || internal_role !== null
User::internalRole()     // 'owner' (superadmin o internal_role='owner') | 'viewer'
User::isBrainOwner()     // permite escribir
```

`EnsureInternalAccess` responde **403** a todo lo demás, sin excepción.

En el menú lateral, los ítems del Brain aparecen en una sección aparte
(*SaaS Admin*, en ámbar) solo para quien tiene acceso.

Rutas: `/brain`, `/brain/accounts`, `/brain/accounts/{id}`, `/brain/tickets`,
`/brain/finanzas`.

El ítem *Tickets* lleva un badge rojo con los que están esperando respuesta
nuestra (§9).

---

## 5. Cockpit (`/brain`)

Tablero de mando. En una sola consulta agregada:

| Indicador | Cálculo |
|---|---|
| Cuentas totales / activas | Contadores condicionales sobre `accounts` |
| Con Converza / con ispwatch | `tenant_id` / `ispwatch_tenant_id` no nulos |
| En riesgo | `status in ('past_due','suspended')` |
| **MRR por moneda** | Suma de `account_products` activos de cuentas activas |
| **Por cobrar** | Saldo de facturas no pagadas ni anuladas, por moneda |
| Facturas vencidas | `status = 'overdue'` o `due_at` en el pasado |

> El MRR es **combo-safe**: la fila `ispwatch` de un combo va en 0, así que el
> ingreso no se cuenta dos veces.

---

## 6. Cuentas

### Ficha (`/brain/accounts/{id}`)

Reúne en una pantalla:

- **Identidad y estado comercial** — nombre, razón social, contacto, país,
  `status` (`prospect`/`active`/`past_due`/`suspended`/`churned`), `health`
  (`good`/`at_risk`/`critical`), responsable interno, fechas de onboarding y
  renovación, `billing_day`.
- **Productos contratados** — qué tiene de cada app, plan, ciclo, importe y moneda.
- **Semáforo de uso** — clientes reales en ispwatch y agentes reales en Converza
  contra los topes del plan.
- **Cobros** — facturas y pagos del SaaS a ese ISP.
- **Soporte** — tickets con su bitácora, y notas fijables.
- **Cross-links** — salto directo al tenant de Converza y al de ispwatch.

### Salud desde ispwatch

`IspwatchRepository::tenantHealth()` trae clientes activos, suspendidos y
facturas vencidas del ISP. Es **solo lectura y agregado**: los clientes finales
nunca se tocan.

---

## 7. Catálogo de planes

[`PlanCatalog`](../app/Services/Brain/PlanCatalog.php) es la **única fuente de
verdad** de los precios. Lo leen el modal de nueva cuenta, el editor de productos
y la validación del backend.

Precios en **USD** (moneda base). El negocio cobra mixto: cada `account_product`
guarda su propio `amount` + `currency`, así que elegir un plan solo
**pre-rellena** el precio — el fundador puede sobrescribirlo (por ejemplo, cobrar
el equivalente en COP a un ISP local). El `plan_key` guardado no cambia.

### Los planes

**ISPWatch** — por número de clientes del ISP

| Plan | Precio | Tope |
|---|---|---|
| Gratis | $0 | 30 clientes |
| Emprendedor | $19 | 200 |
| Crecimiento ★ | $47 | 800 |
| Ilimitado | $79 | ilimitado |
| Enterprise | a medida | 2.000+ |

**Converza** — por número de agentes

| Plan | Precio | Tope |
|---|---|---|
| Inicial | $0 | 1 agente |
| Pro ★ | $29 | 3 agentes |
| Business | $39 | 8 agentes |

**Combos** — ambas apps a precio de paquete

| Plan | Precio | Topes | Ahorro |
|---|---|---|---|
| Combo hasta 200 | $39 | 200 clientes / 2 agentes | $9 |
| Combo hasta 800 ★ | $69 | 800 clientes / 5 agentes | $13 |
| Combo Ilimitado | $109 | ilimitado / ilimitado | $15 |
| Combo Enterprise | a medida | por abonado | — |

### Cómo se guarda un combo

Un combo crea **dos filas** en `account_products` con el mismo `plan_key`:

| Fila | `product` | `amount` |
|---|---|---|
| 1 | `converza` | precio del combo |
| 2 | `ispwatch` | **0** |

Así el MRR no se duplica.

### Topes y semáforo

Cada plan declara sus topes en forma numérica: `max_clients` y/o `max_agents`,
donde **`null` = ilimitado**.

> El tope de agentes de un combo es **propio**, no el del plan Converza que
> referencia: el *Combo hasta 800* da **5** agentes aunque incluya *Converza Pro*,
> que suelto son 3.

`PlanCatalog::limitsFor()` resuelve los topes efectivos: **un combo pisa** lo que
hubiera puesto un plan suelto; sin combo, cada familia gobierna su dimensión.
Devuelve `null` en la dimensión que la cuenta no tiene contratada — distinto de
tenerla ilimitada, donde el sub-array existe con `limit => null`.

`PlanCatalog::usage()` cruza tope con uso real:

| Estado | Umbral |
|---|---|
| `unlimited` | Sin tope |
| `ok` | < 75 % |
| `warn` | 75-89 % |
| `high` | 90-99 % |
| `over` | ≥ 100 % |

> Usa `floor`, no `round`: con `round`, 799/800 mostraría "100 %" sin estar
> excedido.

**Esto no bloquea nada.** Converza lee ispwatch en solo lectura, así que el tope
de clientes es **informativo**: un semáforo para decidir el upgrade a mano.

---

## 8. Cobros del SaaS

`account_invoices` + `account_payments`. Las facturas se crean a mano o las crea
el **cobro automático** (abajo), por cuenta.

Facturas: número único, concepto, importe, impuesto, total, moneda, estado
(`draft`/`sent`/`paid`/`partial`/`overdue`/`void`), fechas de emisión,
vencimiento, pago y periodo, y de dónde salió (`source`: `manual`/`auto`).

Pagos: importe, moneda, método (`transfer`/`cash`/`card`/`other`), referencia y
fecha.

> **El saldo a favor es derivado:** `Σ pagos − Σ facturas`. No se guarda como
> columna; se calcula y se descuenta solo. Los pagos llevan un flag de aplicación
> de crédito.

`billing_day` en la cuenta define el día de corte de facturación de ese ISP.

### Cómo se aplica la plata

`Account::allocateCredit()` reparte la plata que entró y todavía no cubre
ninguna factura entre las facturas abiertas, **de la más vieja a la más nueva**,
como pagos `is_credit_application`. Corre al crear una factura, al registrar un
pago, al anular una factura y en cada pasada del cobro automático.

> **Antes**, un pago sin factura se quedaba esperando a la *próxima* factura. Con
> el cobro automático eso es un cliente que pagó y sigue cortado: la factura
> vencida seguía debiendo. Por eso ahora va primero a la abierta más vieja.

"Plata libre" no es lo mismo que el saldo de la cuenta. Con una factura abierta
de 69 y un pago suelto de 69, el saldo da 0 (no debe nada) pero esos 69 están
libres y le tocan justo a esa factura. `Account::freeCreditByCurrency()` lo
calcula topando lo cubierto de cada factura en su total.

### Cobro automático

Por cuenta, desde **Cobros → Cobro automático** en la ficha. Nace apagado.
Lo corre `brain:billing-run` cada hora entre 8 a. m. y 8 p. m. de Colombia
([`CobroAutomatico`](../app/Services/Brain/CobroAutomatico.php)):

1. **Aplica la plata libre** (arriba).
2. **Factura el período vigente** si no tiene factura: una por moneda, con los
   productos **mensuales** activos con valor (la fila ispwatch de un combo, en
   0, cae sola). Número `F-NNN` consecutivo, concepto "Plan — mes año".
3. **Recuerda** el día antes del vencimiento.
4. **Corta** si hay una factura vencida con saldo, y **reactiva** si el corte fue
   por cobro y ya no queda nada vencido.

| Regla | Por qué |
|---|---|
| **Vence `billing_grace_days` después de EMITIDA**, no del inicio del período | Si el comando estuvo caído una semana, la factura no nace vencida y nadie se corta el mismo día que la recibe |
| **No factura meses anteriores a encenderlo** (`auto_billing_since`) | Pudieron cobrarse por fuera. Una factura vieja que aparece sola termina en un corte injusto. La del mes en curso, si falta, se crea a mano |
| **Una factura por cuenta, moneda y mes** (`billing_period` + índice único) | El comando corre cada hora. Si alguien anula la automática del mes, no se rehace |
| **Respeta la manual del mes** (`period_start` dentro del período) | Nadie cobra dos veces octubre porque una la hizo una persona y otra el comando |
| **Trimestrales y anuales quedan afuera** | Cobrarlos cada mes sería cobrar de más; la ficha los lista como "no se cobran solos" |
| **El corte de una persona no lo levanta el pago** (`suspension_reason`: `manual` vs `billing`) | Lo cortó por una razón que el código no conoce |

**Avisos al ISP** — [`AvisosDeCobro`](../app/Services/Brain/AvisosDeCobro.php):
factura nueva, recordatorio, corte y reactivación. Mismas reglas que los avisos de
tickets (§9): salen del número de Converza al `contact_phone` de la cuenta, texto
libre solo dentro de la ventana de 24 h, plantilla fuera (`BRAIN_BILLING_TPL_*`),
y sin plantilla se registran como `skipped` y no se intentan. La bitácora
(`account_billing_notices`, única por cuenta+tipo+ref) es la idempotencia y se ve
en la ficha. Un aviso que no sale **no frena el cobro**: el aviso es cortesía, la
fecha de vencimiento es el contrato. Si el saldo a favor cubre la factura entera,
no se avisa.

`BRAIN_BILLING_PAY_INSTRUCTIONS` (p. ej. el Nequi) va al final de cada aviso de
texto y en la pantalla de suspendido.

### Qué es "cortar"

`accounts.status = suspended`. Lo pone el cobro automático o el botón **Cortar
servicio** de la ficha (que ahora sí corta: antes solo cambiaba la etiqueta).

- **Converza:** [`EnsureServicioActivo`](../app/Http/Middleware/EnsureServicioActivo.php)
  le muestra al equipo del ISP la pantalla "servicio suspendido" (con lo que debe
  y cómo pagar) en vez del panel, y responde **423** a las llamadas de fondo.
  Quedan abiertos cerrar sesión, el manual y el portal de soporte. Corta el
  **panel** y nada más: los webhooks no pasan por ahí, así que los mensajes de los
  clientes del ISP se siguen guardando y el bot y los avisos automáticos siguen
  andando. Los clientes finales no se enteran de que su proveedor nos debe.
  El estado se cachea 60 s por tenant y `Account` lo olvida al guardar: un pago
  reabre el panel en el acto. Si la consulta falla, **deja pasar** (un error
  nuestro no deja sin servicio a quien pagó).
- **ispwatch:** Converza no puede escribir allá (§10.2). Publica el estado en
  `GET /saas/estado/{ispwatch_tenant_id}` con `Authorization: Bearer
  BRAIN_SAAS_STATUS_TOKEN` (sin token configurado, 404). Responde
  `{suspended, known, reason, since, message, payment_instructions}`. Un ISP que
  no es cuenta nuestra da `known: false, suspended: false`. Que ispwatch lo
  consulte y cierre su panel es trabajo del repo de ispwatch, y debe **fallar
  abierto** si esta ruta no responde.

## 8 bis. Finanzas (`/brain/finanzas`)

Lo que entra por cobros contra lo que cuesta mantener las apps, mes a mes.
[`FinanceController`](../app/Http/Controllers/Brain/FinanceController.php).

- **Ingresos** = plata que entró de verdad: `account_payments` sin las
  aplicaciones de saldo a favor (esa plata ya se contó al entrar). No es lo
  facturado: una factura sin pagar no paga el servidor.
- **Egresos** (`brain_expenses`): concepto, proveedor, categoría (lista fija, para
  que el reporte no se parta en "Servidor" y "servidores"), app (texto libre con
  sugerencias: `converza`, `ispwatch`, `general`; se guarda en minúsculas), tipo
  **fijo** o **variable**, valor, moneda y fecha.
- **Gastos fijos** (`brain_recurring_expenses`): la plantilla de lo que se paga
  cada mes o cada año. `brain:expenses-recurring` (diario, 6 a. m.) crea el egreso
  cuando le llega el día, uno por período (índice único). Solo el período
  vigente: si estuvo apagado meses, no aparecen meses viejos de golpe. Al crear
  uno cuyo día ya pasó, el del mes se registra en el acto. Editarlo cambia lo que
  viene, no lo ya registrado.
- La página muestra el resultado del mes, el "mes típico" (MRR contra gastos fijos
  llevados a mensual), los últimos seis meses y el desglose por categoría y por
  app.

**Todo por moneda, sin convertir.** El negocio cobra y paga en USD y COP; una tasa
de cambio inventada en el reporte haría parecer exacto lo que no lo es. Por la
misma razón no hay ingreso "por app": un combo se cobra en una sola fila.

---

## 9. Soporte

`support_tickets` con estado (`open`/`pending`/`resolved`/`closed`), prioridad
(`low`/`normal`/`high`/`urgent`), categoría, producto y origen
(`manual`/`whatsapp`/`email`/`portal`), más `first_response_at` y `resolved_at`.

`ticket_events` es la bitácora: `message`, `note`, `status_change`, `assignment`,
con `meta` JSON (`{from:'open', to:'resolved'}`).

`account_notes` son notas de la cuenta, con `pinned` para fijar las importantes
arriba.

### El portal del ISP (`/support`)

El ISP abre sus propios requerimientos y les sigue los avances desde su panel, en
vez de preguntar por WhatsApp cómo va lo que pidió. Eso convierte al ticket en un
objeto con **dos audiencias**, y ahí está todo el cuidado de este módulo.

- **Rutas:** `/support` (lista), `/support/{id}` (hilo), y los POST para abrir y
  responder. Van bajo `auth` + `role:admin`, **no** bajo `internal`.
- **Controlador propio:** [`SupportController`](../app/Http/Controllers/SupportController.php).
  No reutiliza `Brain\TicketController` a propósito: son dos modelos de
  autorización distintos y mezclarlos en condicionales es donde se cuela el caso
  que nadie contempló.
- **De qué cuenta son "mis" tickets:** `app('tenant')` → `accounts.tenant_id` →
  `support_tickets.account_id`. El `account_id` sale **siempre** de la sesión,
  nunca de la petición. Un ticket de otro ISP responde **404, no 403**: la
  diferencia de códigos ya confirmaría que existe.
- **Qué puede hacer el ISP:** abrir un ticket, leer el historial visible y
  responder. **No** puede cambiar estado, prioridad ni asignación — no hay ruta y
  los campos no se leen de la petición. Lo único que mueve un estado es responder
  sobre un ticket `resolved`: vuelve a `open`, porque si no quedaría fuera de toda
  bandeja nuestra esperando a que alguien se acuerde.
- **Cuenta sin tenant:** el portal es solo para cuentas con tenant de Converza.
  Un workspace todavía sin ficha en el Brain ve un aviso y el WhatsApp de soporte,
  no un botón que falla.

### La frontera de visibilidad

| Tipo de evento | ¿Lo ve el ISP? |
|---|---|
| `message` | **Sí** — es la conversación con el cliente |
| `status_change` | Sí, en forma legible ("pasó a En progreso") |
| `note` | **No** — nota interna del equipo |
| `assignment` | **No** |

La lista vive en una sola constante, `TicketEvent::VISIBLE_AL_ISP`, y es una
**allowlist**: un tipo nuevo queda fuera por omisión. El filtro se aplica en la
**consulta**, no en la vista — al navegador del cliente no viaja nada que no deba
leer. `meta` tampoco se copia en bruto: de `status_change` solo salen `from` y
`to`. Y del lado nuestro no se expone quién atendió: para el ISP somos "Soporte
Converza", no una lista con los nombres de nuestra gente.

En el Brain, escribir en un ticket **por defecto es nota interna**, y la caja
cambia de color y de botón según el tipo: si alguien se equivoca, que se
equivoque hacia el lado que no filtra.

`first_response_at` lo marca nuestra primera respuesta visible. Una nota interna
no cuenta — el cliente no la lee. (Hasta este cambio la columna nunca se llenaba:
se guardaba el `created_at` de un evento recién creado, que es `null` porque la
fecha la pone la BD.)

### La bandeja interna (`/brain/tickets`)

La ficha de la cuenta responde *"¿qué pasa con este ISP?"*. La bandeja responde la
otra pregunta, la que aparece en cuanto los tickets los abre el cliente: *"¿qué
entró y nadie ha atendido?"*.

**Qué cuenta como pendiente** — `SupportTicket::scopeEsperandoNuestraRespuesta()`:

> El ticket está abierto (`open`/`pending`) **y** o nunca le contestamos
> (`first_response_at IS NULL`), o el cliente escribió después de nuestra última
> respuesta.

Esa definición vive en **un solo sitio** y la usan las tres cosas que cuentan:
el filtro de la bandeja, sus contadores y el badge rojo de la navegación. Copiada
en tres lugares se desincroniza, y un badge que no cuadra con la lista deja de
mirarse a los dos días.

- Orden por defecto: arriba lo que lleva más tiempo esperándonos.
- Filtros por estado, prioridad, producto, origen, asignado y texto.
- Se responde y se cambia el estado **desde la bandeja**, sin abrir la ficha. El
  compositor es el mismo de la ficha y también arranca en **nota interna**.
- El badge de la navegación sale de un contador cacheado 30 s: es un número, no
  un dato en vivo, y sin caché costaría una consulta en cada petición Inertia.

Los cambios de estado pasan todos por `SupportTicket::cambiarEstadoA()`, que
escribe el evento y mantiene `resolved_at`: al reabrir lo limpia, porque si no el
ticket sigue diciendo que se resolvió un día en el que no quedó resuelto.

### Avisarle al ISP que su ticket avanzó

"Ver los avances" no puede depender de que el cliente se acuerde de entrar al
panel: sin aviso, el portal no reemplaza al WhatsApp de siempre, que era el
problema a resolver. Lo hace [`AvisosDeTicket`](../app/Services/Support/AvisosDeTicket.php),
en cola (`EnviarAvisoDeTicket`) para que la petición no espere a Meta.

**Tres momentos y nada más:** lo recibimos (acuse), le respondimos, quedó
resuelto. Un aviso por cada movimiento interno convierte la función en ruido y el
cliente la apaga. Pasar a `pending` o cerrar no avisa; una **nota interna**
tampoco, porque el cliente no la lee y no hay nada que ir a ver.

| Regla | Por qué |
|---|---|
| **Opt-in por cuenta** (`accounts.support_notify_enabled`, apagado por defecto) | Mandarle WhatsApp a quien no lo pidió es como se queman los números |
| **Sale del número de Converza** (tenant `default`, vía `WhatsAppService::forTenant`) | El aviso es nuestro, no del ISP. No pasa por `EventCatalog` (ver [avisos-automaticos.md §1](avisos-automaticos.md)) |
| **Nunca texto libre fuera de la ventana de 24 h** | Meta lo acepta con 200 y lo rechaza después por webhook; cada rechazo gasta quality rating. Sin plantilla configurada el aviso se registra como `skipped` y **no se intenta** |
| **Idempotente** | La fila de `ticket_notification_logs` se crea ANTES del envío, con `(ticket_event_id, kind)` único: un reintento del job choca y no reenvía |
| **Queda rastro donde se mira** | Cada intento escribe una `note` en la bitácora del propio ticket ("se le envió el acuse", "la respuesta quedó publicada en el portal del ISP. No se le avisó por WhatsApp: …"). Es interna: el ISP no la ve. Dice primero lo que **sí** pasó: la versión anterior ("No se envió el aviso de respuesta") se leía como si la respuesta misma no hubiera salido, y la respuesta siempre queda en el portal |

El teléfono es `accounts.support_notify_phone` o, si está vacío, el
`contact_phone` de la ficha.

**Plantillas.** Los nombres van en `config/support.php` (`SUPPORT_NOTIFY_TPL_*`) y
esperan dos variables nombradas: `{{numero}}` y `{{asunto}}`. Hasta que existan
aprobadas en Meta, los avisos que caigan fuera de la ventana quedan en `skipped`
— el sistema funciona, simplemente no manda lo que no puede entregar.

### El aviso en espejo: a nosotros, por correo

Cuando un ISP abre un ticket por el portal nos llega un correo
(`TicketNuevoDelPortal` → `SUPPORT_INTERNAL_EMAIL`). La bandeja y su badge avisan
a quien ya está mirando la pantalla; esto avisa cuando nadie está mirando, que es
justo cuando el portal se volvería peor que el WhatsApp de siempre — el cliente
cree que ya lo recibimos.

- **Va por correo, no por WhatsApp.** Para avisarnos a nosotros mismos no hace
  falta pelear con la ventana de 24 h ni esperar que Meta apruebe una plantilla.
- **No depende del opt-in de la cuenta.** Ese interruptor decide lo que recibe el
  ISP, no lo que nos enteramos nosotros.
- **Sin transporte de correo** (`MAIL_MAILER` en `log`, que es el default de
  Laravel) el aviso se registra como `skipped` con motivo `correo_sin_transporte`
  en vez de dejar una fila que diga "enviado" y a nadie avisado.
- El que **sí** sale no escribe nota en el ticket: sería una línea de ruido en
  cada ticket del portal. El que no sale, sí — eso hay que verlo sin ir a buscar
  la tabla.

Diagnóstico cuando no llega: [operaciones.md §7](operaciones.md) → "Un ISP abrió
un ticket y no me llegó el correo".

---

## 10. Principios no negociables

Si tocas el Brain, estos son los límites:

1. **Aislamiento total del inquilino.** Ningún usuario de tenant llega a una ruta
   del Brain. Las tablas del Brain **no** usan `BelongsToTenant`.

   Las excepciones son tres, y están construidas para no ser un agujero:

   - El **portal de soporte** (§9): ruta aparte fuera de `internal`, controlador
     aparte, `account_id` derivado de la sesión, 404 para lo ajeno y una
     allowlist de tipos de evento.
   - La **pantalla de servicio suspendido** (§8): la cuenta sale del `tenant_id`
     del usuario, nunca de la petición, y solo muestra número, concepto, saldo y
     vencimiento de las facturas abiertas de ESA cuenta.
   - **`GET /saas/estado/{id}`** (§8): fuera del grupo `web`, con token propio,
     404 si no hay token configurado, y solo dice si está cortado.

   Si mañana hace falta otra puerta, se abre igual —una ruta propia con su propia
   consulta—, **nunca** relajando `EnsureInternalAccess` ni metiendo un `if` en un
   controlador del Brain.
2. **ispwatch es sagrado y read-only.** Solo `IspwatchRepository`, solo `SELECT`.
   Los clientes finales del ISP nunca se enteran ni se ven afectados.
3. **Cambios aditivos.** La única columna que el Brain agregó a una tabla
   existente es `users.internal_role` (nullable, default `null`). Para cualquier
   usuario de tenant el comportamiento es idéntico al de antes.
4. **Reutilizar, no reinventar.** El patrón superadmin, el motor de WhatsApp,
   Inertia y `AppLayout.vue` ya existían. El Brain se monta encima.
5. **Manual primero, automático después.** Primero un lugar donde registrar y ver
   claro; la automatización viene cuando la data esté estructurada. El cobro
   automático (§8) llegó así: opt-in por cuenta, y nunca sobre meses anteriores a
   encenderlo.
