# Seguridad

> **En una frase:** el activo a proteger es el **aislamiento entre tenants**;
> todo lo demás (tokens, roles, superficie web) es secundario frente a que los
> mensajes de un ISP nunca aparezcan en el workspace de otro.

**Archivos clave**
- [app/Models/Concerns/BelongsToTenant.php](../app/Models/Concerns/BelongsToTenant.php)
- [app/Http/Middleware/](../app/Http/Middleware/) — los cinco middleware
- [ispwatch-readonly.sql](ispwatch-readonly.sql) · [converza-roles-hardening.sql](converza-roles-hardening.sql)

---

## 1. Modelo de amenazas

| Riesgo | Impacto | Estado |
|---|---|---|
| **Fuga entre tenants** | Crítico — mensajes de clientes de un ISP visibles para otro | Mitigado en 4 capas (§2) |
| **Robo de tokens de WhatsApp** | Alto — suplantación del ISP ante sus clientes | Cifrados en base (§4) |
| **Escritura accidental en ispwatch** | Alto — corrompe la fuente de verdad del ISP | Convención + rol SELECT-only (§5) |
| **Webhook falsificado** | Medio — inyección de mensajes falsos | ⚠️ **Sin mitigar** (§6) |
| **Escalada de privilegios interna** | Medio | Roles jerárquicos en backend (§3) |
| **Baneo del número por Meta** | Alto para el negocio | Pacing, opt-out, warm-up |

---

## 2. Aislamiento multi-tenant

Cuatro capas, deliberadamente redundantes.

### Capa 1 — Global scope

`BelongsToTenant` añade `where tenant_id = app('tenant')->id` a **toda** consulta
de los modelos que lo usan, y auto-etiqueta las escrituras.

### Capa 2 — Filtro explícito

Las consultas sensibles repiten `->where('tenant_id', $tenantId)`. Es idempotente
y sobrevive si el binding del contenedor no está (jobs, comandos).

### Capa 3 — Guard de pertenencia

Cada acción sobre un modelo recibido por route-model binding valida:

```php
abort_if($conversation->tenant_id !== app('tenant')->id, 403);
```

### Capa 4 — Validación tenant-aware

```php
Rule::exists('conversations', 'id')->where('tenant_id', $tenantId)
```

Un ID ajeno ni siquiera pasa el `validate()`.

### El punto débil conocido: los workers

`queue:work` **no reconstruye el contenedor entre jobs**. Un binding `tenant`
heredado archivaría mensajes en el tenant equivocado. Esto ya causó una fuga
real.

**Patrón obligatorio** en todo job y comando que toca datos de tenant:

```php
app()->forgetInstance('tenant');
$tenant = Tenant::find($this->tenantId);   // el ID viaja explícito, siempre
app()->instance('tenant', $tenant);
try { … } finally { app()->forgetInstance('tenant'); }
```

Aplicado en `ProcessIncomingWhatsAppMessage`, `HandleBotResponse`,
`RunCampaignTick`, `SendBillingNotifications` y `SendEventNotifications`.

> **Al revisar un PR que agregue un job nuevo, esto es lo primero que hay que
> mirar.**

### Aislamiento en el navegador

El navegador comparte una cookie de sesión entre pestañas. Tres defensas:

1. `HandleInertiaRequests::version()` incluye `userId:tenantId` en el hash → GET
   desfasado responde 409 + recarga dura.
2. `EnsureConsistentIdentity` aplica la misma comprobación a POST/PUT/PATCH/DELETE
   (Inertia no lo hace).
3. `AppLayout.vue` observa la identidad con `flush:'sync'` y bloquea el render
   antes de pintar datos ajenos; además propaga el logout entre pestañas por
   `localStorage`.

---

## 3. Autenticación y roles

### Autenticación
Sesión de Laravel con cookie, contraseñas `bcrypt` (12 rondas). Sin 2FA, sin SSO,
sin API tokens.

### Tres jerarquías independientes

**Roles de staff dentro del tenant** — `viewer < agent < admin`

```php
$user->hasStaffRole('agent');   // agent o admin
```

Aplicado con `->middleware('role:agent')` / `role:admin` en las rutas.
Un superadmin del SaaS **siempre** cuenta como `admin` dentro de cualquier tenant.

**Superadmin del SaaS** — `users.is_superadmin`, middleware `superadmin`,
consola cross-tenant en `/admin`.

**Rol interno / Core Brain** — `users.internal_role` (`owner` | `viewer`),
middleware `internal`, área `/brain`.

> `superadmin` y `role:admin` **no son lo mismo**. El primero protege la consola
> cross-tenant del dueño del SaaS; el segundo, acciones administrativas dentro de
> un workspace.

### Restricción de visibilidad de los agentes

Un `agent` **solo ve las conversaciones asignadas a él**. Es una restricción de
**backend**: se aplica en el query (`where assigned_to = …`), no en un filtro de
la UI. No se puede eludir manipulando el query string.

Excepción controlada: si a un agente lo reasignan mientras tiene el chat abierto,
en recargas **parciales** se cae a scope de tenant para no blanquearle el hilo.
Es **solo lectura** — las escrituras siguen bloqueadas en sus propios endpoints.

---

## 4. Secretos

| Secreto | Dónde | Protección |
|---|---|---|
| `wa_access_token` | `tenants` | Cast `encrypted` (APP_KEY) + `$hidden` |
| `wa_app_secret` | `tenants` | Ídem |
| `APP_KEY` | `.env` | Nunca en git |
| Credenciales de base | `.env` | Ídem |
| Llave SSH de deploy | GitHub Secrets | Par dedicado, solo para el droplet |

`Tenant::$hidden` excluye los tokens de cualquier serialización JSON, así que no
viajan a Inertia por accidente.

> ⚠️ **Rotar `APP_KEY` inutiliza todos los tokens cifrados.** El código lo detecta
> (`DecryptException` → `cannot decrypt tenant access token` en el log) y deja al
> tenant en estado "no configurado" en lugar de romper, pero hay que re-ingresar
> los tokens manualmente.

**Nunca loguear un token.** Los logs de error de la Graph API incluyen el body de
la respuesta, no el header de autorización — mantenlo así.

---

## 5. ispwatch: solo lectura

Tres barreras:

1. **Convención:** todo el acceso pasa por `IspwatchRepository`, que solo hace
   `SELECT`.
2. **Modelos espejo** con `$connection = 'ispwatch'`, usados solo para leer.
3. **Permisos de base:** en producción debería usarse el rol `converza_reader`
   con SELECT-only — ver [ispwatch-readonly.sql](ispwatch-readonly.sql).

La tercera es la única que de verdad garantiza el resto. **Verifica que esté
aplicada en producción.**

---

## 6. Superficie pública

Solo dos rutas sin autenticación:

| Ruta | Riesgo |
|---|---|
| `GET/POST /webhook` | Ver abajo |
| `GET/POST /login` | Sin rate limiting explícito |
| `GET /up` | Health check de Laravel |

### ⚠️ El webhook no verifica la firma

`X-Hub-Signature-256` se **reenvía** al forwarder pero **no se valida** contra
`WHATSAPP_APP_SECRET`. Cualquiera que conozca la URL puede publicar un payload
con un `phone_number_id` válido e inyectar mensajes falsos en el chat de un
tenant.

Mitigación parcial existente: los mensajes sin tenant coincidente se descartan, y
`wa_message_id` es único.

Corrección propuesta en
[mejoras.md](mejoras.md#m-01-el-webhook-no-verifica-la-firma-de-meta).

### Otras notas

- **CSRF** está activo en todo salvo `/webhook`.
- **Rate limiting:** no hay. Ni en login ni en las rutas de escritura.
- **Los medios requieren autenticación y pertenencia:** `/media/{path}` pasa por
  el middleware `auth`, y además `MediaController` comprueba que la ruta pedida
  sea el `media_path` de un mensaje **del tenant en sesión**. Sin tenant
  enlazado, 404. Es la capa 3 (§2) aplicada a los archivos: la sesión sola
  dejaba bajar el medio de otro workspace conociendo la ruta.

---

## 7. Datos personales

Converza almacena teléfonos, nombres, correos y el **contenido íntegro de las
conversaciones**, más los medios que los clientes envían.

| Aspecto | Estado |
|---|---|
| Cifrado en tránsito | ✅ HTTPS (Let's Encrypt) |
| Cifrado en reposo | Solo los tokens de WhatsApp. Mensajes y medios **en claro** |
| Retención de medios | 90 días (`MEDIA_CLEANUP_DAYS`); la metadata se conserva |
| Retención de mensajes | **Indefinida** |
| Borrado de un contacto | Elimina el contacto, no su historial de mensajes |
| Exportación de datos | No implementada |
| Registro de auditoría | Parcial: transferencias y asignaciones quedan como mensajes de sistema; no hay log de accesos |

Si el proyecto necesita cumplir con una normativa de datos personales, esta tabla
es el punto de partida del análisis de brechas.

---

## 8. Checklist de revisión de PR

Antes de aprobar cambios que toquen datos:

- [ ] ¿El modelo nuevo usa `BelongsToTenant`? (Si no, ¿es del Brain a propósito?)
- [ ] ¿El job nuevo rebindea el tenant con el patrón forget/bind/finally?
- [ ] ¿El `tenantId` viaja explícito al job, o se confía en el contenedor?
- [ ] ¿Las acciones sobre modelos enlazados por ruta validan `tenant_id`?
- [ ] ¿Las reglas `exists` filtran por tenant?
- [ ] ¿La ruta nueva tiene el middleware de rol correcto?
- [ ] ¿Se loguea algún token o secreto?
- [ ] ¿Se escribe en ispwatch? (No debería, nunca)
- [ ] ¿La migración se corrió en **ambos** schemas?

---

## 9. Endurecimiento pendiente

Por orden de impacto:

1. **Validar la firma del webhook** (§6).
2. **Rate limiting** en login y rutas de escritura.
3. Confirmar que `converza_reader` (SELECT-only) esté realmente aplicado en
   producción.
4. Registro de auditoría de accesos a conversaciones.
5. Política de retención y borrado de mensajes.
6. 2FA para superadmins.

> Resuelto el 31/08/2026: la pertenencia al tenant en `MediaController`. Un
> medio solo se sirve si un mensaje del tenant en sesión lo referencia; sin
> tenant enlazado se responde 404. Siempre 404 y nunca 403, para no confirmar
> que el archivo existe en otro workspace.
