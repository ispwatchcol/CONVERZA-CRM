# CONVERZA CRM

CRM multi-tenant de WhatsApp para ISPs, con bot, campañas masivas y avisos
automáticos. Construido sobre **Laravel 12 + Inertia + Vue 3 + Tailwind** y la
**WhatsApp Cloud API** de Meta.

> 📚 **La documentación completa está en [`docs/`](docs/README.md).** Este README
> es solo la puerta de entrada.

---

## Qué hace

| Módulo | Qué resuelve |
|---|---|
| **Chat** | Bandeja compartida multi-agente: asignación, notas internas, presencia, medios, no-leídos por asesor |
| **Campañas** | Secuencias multi-paso de plantillas con pacing anti-baneo, opt-out y embudo por paso |
| **Avisos automáticos** | Factura, recordatorio, corte, bienvenida, pago y falla de router, disparados por datos de ispwatch |
| **Bot** | Atención del primer contacto por palabras clave, con escalado a un humano |
| **Métricas** | Tiempos de respuesta, volumen, productividad por agente |
| **Core Brain** | Back-office privado del dueño del SaaS: cuentas, planes, cobros y soporte |

---

## Stack

| Capa | Tecnología |
|---|---|
| Backend | Laravel 12 (PHP 8.2) |
| Frontend | Vue 3 + Inertia.js 2 + Tailwind CSS 4 |
| Build | Vite 7 |
| Base de datos | **PostgreSQL** (Supabase) — schema `converza` / `converza_dev` |
| Base externa | PostgreSQL de ispwatch, **solo lectura** |
| Colas | `database` (4 workers vía Supervisor) |
| Cache / sesión | Redis en producción |
| Mensajería | Meta WhatsApp Cloud API (Graph v20.0) |

| Entorno | URL |
|---|---|
| Local | <http://127.0.0.1:8000> |
| Producción | <https://converza-crm.duckdns.org> |

---

## Arranque rápido

**Requisitos:** PHP 8.2+ (con `pdo_pgsql` y `gd`), Composer, Node 18+.

```powershell
git clone <repo> CONVERZA-CRM
cd CONVERZA-CRM

composer install
npm install

Copy-Item .env.example .env
php artisan key:generate
# → edita .env con las credenciales de base y de WhatsApp
php artisan migrate
```

```powershell
composer dev      # server + cola + logs + vite  ← recomendado
npm run dev       # solo server + vite (sin cola)
```

> ⚠️ Con `npm run dev` **la cola no corre**: los mensajes entrantes se encolan y
> nunca se procesan. Es la causa #1 de "el webhook llega pero el chat no muestra
> nada".

> ⚠️ El `.env` local apunta históricamente a la **base de producción**. Antes de
> tocar nada, lee
> [manual-desarrollador.md §3.1](docs/manual-desarrollador.md#31-la-advertencia-importante).

---

## Documentación

### Empezar
- **[Arquitectura](docs/arquitectura.md)** — cómo está armado y por qué
- **[Manual de desarrollador](docs/manual-desarrollador.md)** — setup, convenciones, depuración
- **[Manual de usuario](docs/manual-usuario.md)** — operación del producto

### Referencia
- [Modelo de datos](docs/modelo-de-datos.md) · [Integración WhatsApp](docs/integracion-whatsapp.md)
- [Avisos automáticos](docs/avisos-automaticos.md) · [Campañas](docs/campanas.md)
- [Core Brain](docs/core-brain.md) · [Seguridad](docs/seguridad.md) · [Glosario](docs/glosario.md)

### Operación
- **[Runbook](docs/operaciones.md)** — deploy, monitoreo, incidentes
- **[Mejoras y deuda técnica](docs/mejoras.md)** — qué hay que arreglar

---

## Deploy

Automático: cada push a `main` dispara
[.github/workflows/deploy.yml](.github/workflows/deploy.yml) — build de assets en
el runner, `rsync` al droplet y migraciones + cachés + reload por SSH.

> **No corras `npm run build` en el droplet:** tiene 1 GB de RAM y el OOM-killer
> mata a Vite. Buildea siempre en local o en el runner.

Setup inicial del servidor: [deploy/DEPLOY.md](deploy/DEPLOY.md).
Procedimientos y diagnóstico: [docs/operaciones.md](docs/operaciones.md).

---

## Webhook de WhatsApp

```
https://converza-crm.duckdns.org/webhook
```

Campo obligatorio a suscribir en Meta: **`messages`**.

```bash
curl "https://converza-crm.duckdns.org/webhook?hub.mode=subscribe&hub.verify_token=TU_TOKEN&hub.challenge=TEST123"
# debe responder: TEST123
```

Para recibir webhooks reales en tu máquina (Meta → producción → ngrok → local),
ver [manual-desarrollador.md §7](docs/manual-desarrollador.md#7-recibir-webhooks-reales-en-local).

---

## Comandos frecuentes

```powershell
# Desarrollo
composer dev
php artisan tinker
php artisan cache:clear                       # si ispwatch muestra datos viejos

# Multi-tenancy (dueño del SaaS)
php artisan tenant:link --list
php artisan tenant:link 1 17                  # Converza #1 ↔ ispwatch #17

# Avisos automáticos (sin enviar nada)
php artisan whatsapp:billing-notify --dry-run --tenant=1
php artisan whatsapp:events-notify  --dry-run --tenant=1

# Colas
php artisan queue:failed
php artisan queue:retry all
```

---

## Estructura

```
app/
├── Console/Commands/   # avisos, campañas, mantenimiento, tenant:link
├── Http/               # Controllers, Middleware (aislamiento y roles)
├── Jobs/               # procesamiento del webhook, bot, campañas
├── Models/             # + Brain/ (Core Brain) e Ispwatch/ (espejos read-only)
└── Services/           # WhatsAppService · IspwatchRepository · EventCatalog · PlanCatalog

resources/js/Pages/     # páginas Inertia, una carpeta por módulo
database/migrations/    # ⚠️ correr en AMBOS schemas: converza y converza_dev
docs/                   # documentación completa
deploy/                 # setup del servidor y config de Supervisor
```

Cuatro archivos concentran todo el acoplamiento externo — cualquier integración
empieza en uno de ellos:

1. `Services/WhatsAppService.php` — todo lo que sale hacia Meta
2. `Services/Ispwatch/IspwatchRepository.php` — todo lo que entra desde ispwatch
3. `Services/Notifications/EventCatalog.php` — qué avisos existen
4. `Services/Brain/PlanCatalog.php` — qué planes comerciales existen
