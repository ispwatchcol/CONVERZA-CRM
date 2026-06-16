# Core Brain — Plan de implementación

> Centro de mando interno del fundador para centralizar la información de **tus
> clientes** (los ISPs que usan ispwatch y/o Converza): quién pagó, qué tiene
> contratado, en qué estado está, y todo su soporte en un solo lugar.
>
> Construido **dentro de Converza**, reutilizando lo que ya existe (superadmin,
> multi-tenant, `IspwatchRepository` read-only, Inertia + Vue). Solo lo ves tú y
> el equipo que tú habilites. **No toca ni afecta a tus clientes ni a los
> clientes de tus clientes.**

---

## 0. El concepto que hace que todo esto sea correcto

Hay **tres capas de "clientes"** y este proyecto solo opera sobre la del medio:

| Capa | Quién | Dónde vive hoy | Core Brain |
|------|-------|----------------|------------|
| **1. Tú + tu equipo** | Founders / operación interna | `users.is_superadmin` | Únicos que ven el Brain |
| **2. Tus clientes (los ISPs)** | Empresas que te pagan por ispwatch/Converza | `tenants` (Converza) + `tenant` (ispwatch) | **El objeto central del Brain → "Cuenta"** |
| **3. Clientes de tus clientes** | Usuarios finales del ISP | `customer_profile` / `users` (ispwatch) | **Solo lectura, agregado. Nunca se tocan.** |

La pieza nueva es la **Cuenta** (`accounts`): la ficha unificada de cada ISP que
te paga, que cruza por primera vez en un solo registro lo que el ISP tiene en
Converza y lo que tiene en ispwatch, más tu información comercial privada (plan,
valor mensual, fecha de renovación, estado de cobro).

> **Por qué "Cuenta" y no reutilizar `tenants`:**
> 1. Un ISP puede tener ispwatch pero **todavía no** Converza (o al revés) → no
>    siempre hay un `tenant` de Converza al cual colgar la info.
> 2. Tu información comercial (cuánto te paga, cuándo renueva, si está en mora)
>    es **privada del founder** y no debe vivir en la fila `tenants`, que es
>    territorio del admin del ISP.
> 3. La Cuenta es la **llave de cruce** entre ambos productos. `tenants` y
>    `tenant` (ispwatch) cuelgan de ella, no al revés.

---

## 1. Principios de diseño (no negociables)

1. **Aislamiento total del inquilino.** Todo el Brain vive detrás de un muro de
   middleware (`auth` + `internal`). Ningún usuario de un tenant puede llegar a
   una ruta, tabla ni endpoint del Brain. Las tablas nuevas **no** usan el trait
   `BelongsToTenant`: no aparecen en ningún controlador tenant-scoped.
2. **ispwatch es sagrado y read-only.** El Brain lee ispwatch por la única capa
   permitida (`IspwatchRepository`, conexión `ispwatch`, solo `SELECT`). **Jamás
   escribe** en ispwatch. Los usuarios finales del ISP nunca se enteran ni se ven
   afectados.
3. **Cambios aditivos sobre lo existente.** La única columna que tocamos en una
   tabla existente es `users.internal_role` (nullable, default `null`). Para
   cualquier usuario de tenant el comportamiento es idéntico al de hoy.
4. **Reutilizar, no reinventar.** El patrón superadmin (`is_superadmin`,
   `EnsureSuperadmin`, rutas `/admin`), el motor de WhatsApp, los Inertia pages y
   `AppLayout.vue` ya existen. El Brain se monta encima.
5. **Manual primero, automático después.** Hoy tus procesos de cobro son
   manuales. El Brain primero te da un lugar para registrarlos a mano (y verlos
   claros); la automatización (recordatorios de cobro, conciliación) viene en una
   fase posterior, una vez la data está estructurada.

---

## 2. Stack y dónde encaja (estado actual)

- **Laravel 12 / PHP 8.2**, Inertia 2 + **Vue 3**, Tailwind 4, Vite 7.
- **Postgres (Supabase)** con schemas `converza` (prod) y `converza_dev` (local),
  conexión `pgsql` con `search_path`.
- Conexión separada **`ispwatch`** (Postgres, **solo lectura**).
- **Redis** para cache y colas; worker `queue:work` en el droplet.
- Multi-tenant: cada `tenant` = un ISP; scoping por `BelongsToTenant` +
  `app('tenant')` resuelto en `ResolveTenant`.
- Ya existe el área superadmin: `is_superadmin` → middleware `superadmin` →
  rutas `/admin/*` → `Admin\TenantsController` + `Pages/Admin/Tenants/Index.vue`.
  **El Brain es la evolución natural de esa área.**

Reutilizamos directamente:
- `App\Services\Ispwatch\IspwatchRepository` (capa read-only; cache 60s).
- El patrón de `Admin\TenantsController` (vista cross-tenant del founder).
- El motor WhatsApp (`WhatsAppController`, `ChatController`, `conversations`,
  `messages`, jobs de envío).

---

## 3. Acceso interno (login del equipo)

No hace falta un login aparte: el equipo interno son `users` normales que entran
por el mismo `/login`. Lo que cambia es **qué ven** según un rol interno nuevo.

### 3.1 Rol interno

Nueva columna en `users`:

```
users.internal_role  enum nullable  →  null | owner | admin | finance | support | viewer
```

- `null` → usuario normal de tenant (todos los actuales). Cero cambios.
- `owner` → tú. Acceso total (equivale a `is_superadmin`, que sigue existiendo).
- `admin` → mano derecha: todo salvo facturación sensible si así lo decides.
- `finance` → ve y edita el módulo de **Cobros**.
- `support` → ve cuentas y **Soporte**, sin Cobros.
- `viewer` → solo lectura de todo el Brain.

> `is_superadmin = true` implica `owner` para efectos del Brain (no hay que
> migrar tu usuario actual: ya entras).

### 3.2 Middleware y rutas

- Nuevo middleware `App\Http\Middleware\EnsureInternalAccess` (alias `internal`),
  registrado en `bootstrap/app.php` junto a `superadmin` y `role`.
  Deja pasar si `is_superadmin || internal_role !== null`.
- Nuevo grupo de rutas con prefijo **`/brain`** y nombre `brain.`:

```php
Route::middleware(['auth', 'internal'])->prefix('brain')->name('brain.')->group(function () {
    Route::get('/', [Brain\CockpitController::class, 'index'])->name('cockpit');
    Route::resource('accounts', Brain\AccountController::class);
    // ... (ver fases)
});
```

- Para módulos sensibles (editar cobros, borrar cuentas) se añade una capa fina
  de **Policies** que checa `internal_role` (`owner|admin|finance`), no solo el
  middleware de entrada.

### 3.3 Navegación

`HandleInertiaRequests` ya comparte el usuario; añadir flags compartidos
`auth.can.brain` (booleano) y `auth.internal_role`. El menú del Brain en
`AppLayout.vue` se renderiza **solo** cuando `auth.can.brain === true`. Para
cualquier otro usuario el menú no existe.

---

## 4. Modelo de datos (tablas nuevas, schema `converza`)

Todas viven en el schema de Converza (recordatorio: las migraciones se corren en
**ambos** schemas, `converza` y `converza_dev` — ver §9). Ninguna usa
`BelongsToTenant`.

### 4.1 `accounts` — la ficha del ISP (objeto central)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `name` | string | Nombre comercial del ISP |
| `legal_name` | string null | Razón social |
| `slug` | string unique | |
| `tenant_id` | FK → `tenants.id` null | Su workspace de Converza (si tiene) |
| `ispwatch_tenant_id` | string null | Id lógico del `tenant` en ispwatch (si tiene) |
| `status` | enum | `prospect \| active \| past_due \| suspended \| churned` |
| `owner_user_id` | FK → `users.id` null | Account manager interno |
| `contact_name` | string null | Contacto principal en el ISP |
| `contact_email` | string null | |
| `contact_phone` | string null | Usado para cruzar WhatsApp |
| `country` | string null | |
| `onboarding_at` | date null | **Fecha de inicio** |
| `renewal_at` | date null | **Fecha de renovación** |
| `health` | enum null | `good \| at_risk \| critical` (manual o derivado) |
| `notes` | text null | Resumen corto |
| `timestamps` + `softDeletes` | | |

> El **estado** y la **renovación** viven aquí; el **plan y el valor mensual**
> viven en `account_products` (un ISP puede tener dos productos con valores
> distintos).

### 4.2 `account_products` — productos contratados

Soporta "uno, otro, o ambos" de forma limpia (una fila por producto).

| Columna | Tipo | Notas |
|---|---|---|
| `id` | bigint PK | |
| `account_id` | FK cascade | |
| `product` | enum | `ispwatch \| converza` |
| `plan` | string null | "Básico", "Pro", etc. |
| `status` | enum | `active \| paused \| cancelled` |
| `billing_cycle` | enum | `monthly \| quarterly \| yearly` |
| `amount` | decimal(12,2) | **Valor del ciclo** |
| `currency` | string(3) | default `COP` |
| `started_at` | date null | |
| `renews_at` | date null | |
| `cancelled_at` | date null | |
| `timestamps` | | unique(`account_id`,`product`) |

### 4.3 Cobros — tu facturación a los ISPs

Tu ingreso (lo que el ISP **te** paga a ti) es independiente de la facturación
que ispwatch hace al usuario final. Es data nueva.

**`account_invoices`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `account_id` | FK | |
| `account_product_id` | FK null | A qué producto corresponde |
| `number` | string unique | Consecutivo interno |
| `concept` | string | |
| `amount` / `tax` / `total` | decimal(12,2) | |
| `currency` | string(3) | |
| `status` | enum | `draft \| sent \| paid \| partial \| overdue \| void` |
| `issued_at` / `due_at` / `paid_at` | date null | **vencimiento = `due_at`** |
| `period_start` / `period_end` | date null | |
| `created_by` | FK → users | |
| `timestamps` | | |

**`account_payments`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `account_id` | FK | |
| `account_invoice_id` | FK null | null = abono libre |
| `amount` | decimal(12,2) | |
| `currency` | string(3) | |
| `method` | enum | `transfer \| cash \| card \| other` |
| `reference` | string null | Comprobante / nota |
| `paid_at` | date | |
| `recorded_by` | FK → users | |
| `timestamps` | | |

> "Quién pagó / quién debe / cuánto / cuándo vence" sale de cruzar
> `account_invoices.status` + `due_at` con la suma de `account_payments`.

### 4.4 Soporte

**`support_tickets`**

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `account_id` | FK | |
| `subject` | string | |
| `status` | enum | `open \| pending \| resolved \| closed` |
| `priority` | enum | `low \| normal \| high \| urgent` |
| `category` | string null | `technical \| billing \| onboarding \| other` |
| `product` | enum null | `ispwatch \| converza` |
| `assigned_to` | FK → users null | |
| `opened_by` | FK → users null | |
| `source` | enum | `manual \| whatsapp \| email` |
| `first_response_at` / `resolved_at` | datetime null | Para SLA/métricas |
| `timestamps` | | |

**`ticket_events`** — línea de tiempo del ticket

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `support_ticket_id` | FK cascade | |
| `type` | enum | `message \| note \| status_change \| assignment` |
| `body` | text null | |
| `meta` | json null | p.ej. `{from:'open',to:'resolved'}` |
| `author_user_id` | FK → users null | |
| `created_at` | | |

**`account_notes`** — notas internas a nivel cuenta (no atadas a ticket)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `account_id` | FK | |
| `author_user_id` | FK → users | |
| `body` | text | |
| `pinned` | boolean default false | Notas fijadas arriba |
| `timestamps` | | |

### 4.5 WhatsApp central (enlace, ver §7)

En v1 no se crean tablas de mensajería nuevas: se reutiliza el motor WhatsApp
existente y se **resuelve la cuenta por teléfono** al vuelo. Si más adelante se
quiere persistir el cruce, se añade:

**`account_contacts`** (opcional, fase WhatsApp)

| Columna | Tipo | Notas |
|---|---|---|
| `id` | PK | |
| `account_id` | FK | |
| `phone` | string | Normalizado |
| `label` | string null | "WhatsApp soporte", "Dueño", etc. |
| unique(`phone`) | | Para resolver conversación → cuenta |

---

## 5. Integración con ispwatch y Converza (hidratar la Cuenta)

La ficha de cada Cuenta se enriquece **en vivo** con datos de ambos sistemas,
todo read-only por las capas existentes:

- **Desde Converza (tenant vinculado):** nº de conversaciones abiertas, agentes
  activos, estado de la conexión WhatsApp (`wa_status`), último mensaje. Se lee
  con queries dirigidas por `tenant_id` (sin pasar por el global scope, ya que el
  Brain corre como founder).
- **Desde ispwatch (read-only):** nº de clientes finales del ISP, nº de facturas,
  facturas vencidas del ISP, estado del servicio. Se añaden métodos nuevos a
  `IspwatchRepository` (todos `SELECT`), por ejemplo:
  - `tenantInfo()` (ya existe): nombre, `customers_count`, `invoices_count`.
  - `tenantHealth($ispwatchTenantId)` (nuevo): nº clientes activos vs suspendidos,
    facturas vencidas, etc. — para el semáforo de salud.
- **Desde el Brain:** estado de cobro (al día / en mora según
  `account_invoices`), próxima renovación, tickets abiertos, MRR.

> Regla: **todo lo de ispwatch pasa por `IspwatchRepository`**. Si el schema de
> ispwatch cambia, ese es el único archivo a tocar (ya es la convención).

---

## 6. Cockpit 360 (la vista que de verdad usarás)

Pantalla `/brain` (home del Brain) y ficha `/brain/accounts/{account}`:

**Home (`Cockpit`):**
- Tarjetas: # cuentas activas, MRR total, $ por cobrar, cuentas en mora,
  renovaciones próximas (30 días), tickets abiertos por prioridad.
- Tabla de cuentas con filtros (estado, producto, mora, manager) y búsqueda.
- Alertas: "X cuentas vencen este mes", "Y facturas en mora".

**Ficha de cuenta (la pantalla estrella para soporte):**
- Cabecera: nombre, estado, salud, manager, productos + planes + valor.
- Pestaña **Resumen**: datos comerciales + métricas en vivo (Converza + ispwatch).
- Pestaña **Cobros**: facturas, pagos, saldo, "al día / en mora".
- Pestaña **Soporte**: tickets (con prioridad) + notas internas.
- Pestaña **WhatsApp**: conversaciones cruzadas por teléfono.
- Botón directo: "Abrir chat en Converza", "Ver en ispwatch".

Esto es lo que responde tu necesidad real: *"saber quién pagó, sus datos, etc.,
para que sea fácil ayudarlos en soporte y en todo"*.

---

## 7. WhatsApp central

Ya tienes un motor WhatsApp por tenant completo. Para "uno o varios números, todo
en el mismo panel":

- **v1 (recomendado para empezar):** crear un **tenant interno** ("Converza HQ")
  que sostiene tu(s) línea(s) de soporte. El Brain muestra ese inbox enriquecido:
  cada conversación se cruza contra `accounts` por teléfono (`contact_phone` /
  `account_contacts`) para mostrar "esta persona es de la cuenta X". Reutiliza
  `ChatController` / `conversations` / `messages` tal cual.
- **Varios números:** en v1, agregar varios tenants internos en una sola vista
  del Brain (un selector de línea). El modelo actual es 1 número por tenant.
- **v2 (si lo necesitas):** soporte nativo multi-número por tenant. Es trabajo
  mayor (tocar el webhook, el routing de envío y el modelo de conversación); se
  difiere hasta que el volumen lo justifique.

> No se inventa mensajería nueva: el WhatsApp central es una **vista** sobre el
> motor existente, filtrada para el equipo interno y cruzada con las cuentas.

---

## 8. Fases de implementación

Cada fase es entregable y deja el sistema usable. Orden recomendado abajo.

### Fase 0 — Cimientos del Brain (acceso + esqueleto)
**Objetivo:** que tú y tu equipo entren a un área interna vacía pero navegable.
- **Backend:** migración `users.internal_role`; middleware `EnsureInternalAccess`
  (alias `internal`); grupo de rutas `/brain`; `Brain\CockpitController@index`
  (placeholder); flags compartidos en `HandleInertiaRequests`.
- **Frontend:** entrada "Core Brain" en `AppLayout.vue` (visible solo con acceso);
  `Pages/Brain/Cockpit.vue` con layout y tarjetas vacías.
- **Aceptación:** un `viewer` interno entra a `/brain`; un usuario de tenant
  recibe 403 y no ve el menú. Cero cambios para usuarios existentes.

### Fase 1 — Empresas / Cuentas (directorio central)
**Objetivo:** la ficha de cada ISP, cruzando Converza + ispwatch.
- **Backend:** migraciones `accounts` + `account_products`; modelos + relaciones;
  `Brain\AccountController` (index/show/store/update); `AccountPolicy`;
  comando opcional `brain:import-accounts` que pre-puebla cuentas desde los
  `tenants` existentes y los tenants de ispwatch.
- **Integración:** nuevos métodos read-only en `IspwatchRepository` para la salud;
  hidratación en vivo en `show`.
- **Frontend:** `Pages/Brain/Accounts/Index.vue` (tabla + filtros + búsqueda) y
  `Show.vue` (cabecera + pestaña Resumen con métricas en vivo).
- **Aceptación:** veo todas mis cuentas, su producto/plan/valor/estado/renovación,
  y al abrir una veo datos vivos de Converza e ispwatch.

### Fase 2 — Cobros
**Objetivo:** saber quién pagó, quién debe, cuánto y cuándo vence.
- **Backend:** migraciones `account_invoices` + `account_payments`; modelos;
  `Brain\InvoiceController` + `Brain\PaymentController`; lógica de saldo y estado
  (`overdue` cuando `due_at < hoy` y saldo > 0); policy `finance/owner/admin`.
- **Frontend:** pestaña **Cobros** en la ficha; vista global de "Por cobrar" y
  "Vencidas"; registrar pago en 1 clic.
- **Automatización (opcional, al final):** comando `brain:billing-digest` que te
  manda un resumen (WhatsApp/email) de vencimientos y mora — reutiliza el patrón
  del job `whatsapp:billing-notify` ya existente.
- **Aceptación:** registro una factura y un pago a mano; el cockpit muestra MRR,
  por cobrar y mora correctos.

### Fase 3 — Soporte
**Objetivo:** tickets, notas internas y prioridad por cuenta.
- **Backend:** migraciones `support_tickets` + `ticket_events` + `account_notes`;
  modelos; `Brain\TicketController` (con cambio de estado/asignación que escribe
  `ticket_events`) + `Brain\NoteController`; policy.
- **Frontend:** pestaña **Soporte** en la ficha; bandeja global de tickets con
  filtros por prioridad/estado/manager; timeline del ticket.
- **Aceptación:** abro un ticket en una cuenta, le pongo prioridad alta, lo
  asigno, lo resuelvo, y queda el historial.

### Fase 4 — WhatsApp central
**Objetivo:** tu(s) número(s) de soporte en el panel, cruzados con cuentas.
- **Backend:** tenant interno "Converza HQ"; resolución conversación → cuenta por
  teléfono (helper o `account_contacts`); vista del inbox dentro del Brain.
- **Frontend:** pestaña **WhatsApp** en la ficha + inbox central con selector de
  línea; badge "Cuenta: X" en cada conversación.
- **Aceptación:** escribo desde mi número de soporte y, en la conversación, el
  Brain me dice de qué cuenta es y me deja saltar a su ficha.

### Fase 5 — Cockpit 360 + automatizaciones (pulido)
**Objetivo:** convertir el home en tu tablero diario.
- Tarjetas y alertas finales (renovaciones, mora, salud, tickets).
- Digests automáticos (cobros, renovaciones que vencen, cuentas en riesgo).
- Métricas internas: MRR, churn, tickets resueltos, tiempo de primera respuesta.

---

## 9. Operación: migraciones y deploy

> Reglas propias de este entorno (no olvidar):

- **Migraciones en AMBOS schemas.** Cada migración del Brain se corre en
  `converza` y en `converza_dev` (override de `DB_SEARCH_PATH`). Documentar el
  comando en el PR de cada fase.
- **El `.env` local apunta a la BD de producción** (Supabase, schema `converza`,
  `APP_DEBUG=true`). Para probar lógica destructiva o reproducir errores: usar
  transacción + rollback. No correr migraciones a la ligera desde local.
- **Deploy manual por SSH** al droplet de DigitalOcean. Tras subir:
  `composer install`, `php artisan migrate` (en ambos schemas), `npm run build`,
  reiniciar el worker (`queue:restart`) y `php artisan cache:clear` (el repo de
  ispwatch cachea 60s).
- **ispwatch nunca se migra ni se escribe** desde aquí.

Convención de nombres de migración (sigue el patrón existente):
`2026_06_XX_000001_create_accounts_table.php`, etc.

---

## 10. Checklist de aislamiento y seguridad

- [ ] Todas las rutas del Brain bajo `auth` + `internal`.
- [ ] Tablas del Brain **sin** `BelongsToTenant` y **no** referenciadas por ningún
      controlador tenant-scoped.
- [ ] Módulos sensibles (Cobros, borrar cuenta) con Policy adicional por
      `internal_role`.
- [ ] Menú del Brain oculto salvo `auth.can.brain`.
- [ ] `IspwatchRepository` sigue siendo el único acceso a ispwatch, solo `SELECT`.
- [ ] El usuario de BD de ispwatch mantiene solo permisos `SELECT`
      (ver `docs/ispwatch-readonly.sql`).
- [ ] `users.internal_role` nullable, default `null` → cero impacto en tenants.
- [ ] Tests: un user de tenant recibe 403 en cada endpoint `/brain/*`.

---

## 11. Orden recomendado y esfuerzo aproximado

| Fase | Entrega | Esfuerzo |
|------|---------|----------|
| 0 | Acceso interno + esqueleto | XS (0.5–1 día) |
| 1 | Cuentas + ficha 360 (Converza+ispwatch) | M (3–5 días) |
| 2 | Cobros (manual) | M (3–4 días) |
| 3 | Soporte (tickets/notas/prioridad) | M (3–5 días) |
| 4 | WhatsApp central | M–L (depende de multi-número) |
| 5 | Cockpit pulido + automatizaciones | S–M, iterativo |

Empezar por **Fase 0 + Fase 1**: con eso ya tienes el directorio central vivo
con datos cruzados, que es el 80% del valor para soporte.

---

## 12. Decisiones abiertas (confírmame antes de codear)

1. **Cobros:** ¿hoy le facturas a los ISPs 100% a mano (Excel/recibos sueltos)?
   El plan asume sí → módulo de captura manual primero. Si ya hay un sistema,
   lo integramos en vez de duplicar.
2. **Moneda:** ¿`COP`, `USD`, o mixto por cuenta? El plan deja `currency` por
   producto/factura con default `COP`.
3. **Ruta/nombre:** ¿`/brain` ("Core Brain") o prefieres `/hq`, `/owner`, etc.?
4. **Equipo interno:** ¿quieres los 5 roles (`owner/admin/finance/support/
   viewer`) o arrancamos con solo `owner` + `viewer` y crecemos?
5. **WhatsApp:** ¿con un solo número de soporte basta para v1, o ya necesitas
   varios números en el mismo panel desde el día 1?

---

## 13. Glosario

- **Cuenta (`account`):** un ISP que te paga (tu cliente). Objeto central.
- **Tenant (Converza):** el workspace de un ISP dentro de Converza.
- **Tenant (ispwatch):** la organización del ISP dentro de ispwatch.
- **Usuario final:** cliente del ISP (capa 3). Read-only, nunca se toca.
- **`internal_role`:** rol del equipo interno para acceder al Brain.
- **Cobros:** lo que el ISP te paga a TI (≠ facturación de ispwatch al usuario final).
