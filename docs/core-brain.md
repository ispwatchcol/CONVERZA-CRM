# Core Brain

> **En una frase:** el back-office privado del dueño del SaaS — una ficha por
> cada ISP cliente que cruza lo que tiene en Converza con lo que tiene en
> ispwatch, más el cobro y el soporte de esa relación comercial.

**Archivos clave**
- [app/Http/Controllers/Brain/](../app/Http/Controllers/Brain/) — Cockpit, Account, Billing, Ticket
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

Rutas: `/brain`, `/brain/accounts`, `/brain/accounts/{id}`.

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

`account_invoices` + `account_payments`. Registro manual — el Brain nace como
lugar donde anotar lo que hoy se hace a mano, no como motor de facturación
automática.

Facturas: número único, concepto, importe, impuesto, total, moneda, estado
(`draft`/`sent`/`paid`/`partial`/`overdue`/`void`), fechas de emisión,
vencimiento, pago y periodo.

Pagos: importe, moneda, método (`transfer`/`cash`/`card`/`other`), referencia y
fecha.

> **El saldo a favor es derivado:** `Σ pagos − Σ facturas`. No se guarda como
> columna; se calcula y se descuenta solo. Los pagos llevan un flag de aplicación
> de crédito.

`billing_day` en la cuenta define el día de corte de facturación de ese ISP.

---

## 9. Soporte interno

`support_tickets` con estado (`open`/`pending`/`resolved`/`closed`), prioridad
(`low`/`normal`/`high`/`urgent`), categoría, producto y origen
(`manual`/`whatsapp`/`email`), más `first_response_at` y `resolved_at`.

`ticket_events` es la bitácora: `message`, `note`, `status_change`, `assignment`,
con `meta` JSON (`{from:'open', to:'resolved'}`).

`account_notes` son notas de la cuenta, con `pinned` para fijar las importantes
arriba.

---

## 10. Principios no negociables

Si tocas el Brain, estos son los límites:

1. **Aislamiento total del inquilino.** Ningún usuario de tenant llega aquí.
   Las tablas del Brain **no** usan `BelongsToTenant` y no aparecen en ningún
   controlador tenant-scoped.
2. **ispwatch es sagrado y read-only.** Solo `IspwatchRepository`, solo `SELECT`.
   Los clientes finales del ISP nunca se enteran ni se ven afectados.
3. **Cambios aditivos.** La única columna que el Brain agregó a una tabla
   existente es `users.internal_role` (nullable, default `null`). Para cualquier
   usuario de tenant el comportamiento es idéntico al de antes.
4. **Reutilizar, no reinventar.** El patrón superadmin, el motor de WhatsApp,
   Inertia y `AppLayout.vue` ya existían. El Brain se monta encima.
5. **Manual primero, automático después.** Primero un lugar donde registrar y ver
   claro; la automatización (recordatorios de cobro, conciliación) viene cuando
   la data esté estructurada.
