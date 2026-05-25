# CONVERZA CRM

CRM con chatbot integrado vía **WhatsApp Cloud API**. Construido con **Laravel 12 + Inertia + Vue 3 + Tailwind**.

---

## Stack

| Capa | Tecnología |
|---|---|
| Backend | Laravel 12 (PHP 8.2) |
| Frontend | Vue 3 + Inertia.js + Tailwind CSS 4 |
| Build | Vite |
| DB local | SQLite |
| DB producción | MySQL |
| Cache/Sesión producción | Redis |
| Mensajería | Meta WhatsApp Cloud API |

---

## Entornos

| Entorno | URL |
|---|---|
| Local | http://127.0.0.1:8000 |
| Producción | https://converza-crm.duckdns.org |

---

## Setup local

### Requisitos
- PHP 8.2+
- Composer
- Node 18+
- Git

### Instalación inicial
```powershell
git clone <repo> CONVERZA-CRM
cd CONVERZA-CRM

composer install
npm install

cp .env.example .env
php artisan key:generate

# Crea el archivo SQLite vacío
New-Item database\database.sqlite

php artisan migrate
```

### Variables de entorno relevantes (`.env`)
```env
APP_ENV=local
APP_URL=http://127.0.0.1:8000

# URL pública canónica del SaaS — siempre es la misma para todos los clientes
# y no cambia entre local y prod. La usa /settings para mostrar la "Webhook URL
# para Meta" y la "URL pública" en el card de workspace.
APP_PUBLIC_URL=https://converza-crm.duckdns.org

DB_CONNECTION=sqlite

WHATSAPP_API_URL=https://graph.facebook.com/v18.0/<PHONE_NUMBER_ID>
WHATSAPP_API_TOKEN=<TOKEN_PERMANENTE_META>
WHATSAPP_APP_SECRET=<APP_SECRET>
WHATSAPP_VERIFY_TOKEN=converza-token-2024
```

### Levantar el servidor
```powershell
npm run dev
```

Este comando levanta **Laravel + Vite** juntos (vía `concurrently`). Abre [http://127.0.0.1:8000](http://127.0.0.1:8000).

---

## Producción

El droplet es Ubuntu en DigitalOcean, ip `159.223.140.27`, accesible vía:
```powershell
ssh deploy@159.223.140.27
```

### Stack del droplet
- nginx + PHP-FPM 8.2
- MySQL + Redis
- SSL Let's Encrypt (Certbot, auto-renew)
- Path del proyecto: `/var/www/converza-crm`
- Config nginx: `/etc/nginx/sites-available/converza-crm`

### Deploy automático (GitHub Actions)

Cada push a `main` dispara el workflow [.github/workflows/deploy.yml](.github/workflows/deploy.yml).

#### Cómo funciona

El workflow tiene **dos fases**:

| Fase | Dónde corre | Por qué |
|---|---|---|
| **Build de assets** (`npm ci && npm run build`) | Runner de GitHub (7 GB RAM) | El droplet solo tiene 1 GB de RAM — vite build se queda sin memoria y el kernel mata el proceso (OOM). Buildear en el runner evita esto. |
| **Deploy server-side** (git checkout + composer + migrate + cache + reload) | Droplet vía SSH | Es lo único que necesita acceso al servidor real. |

El runner sube los assets construidos al droplet vía `rsync --delete` (limpia hashes viejos que ya no existen — el bug que tuvimos antes), luego corre el resto vía SSH.

Además:
- **`concurrency: deploy-production`** — solo un deploy a la vez. Si llegan 2 pushes seguidos, el 2do espera al 1ro.
- **`git checkout ${{ github.sha }}`** — el droplet hace checkout al commit EXACTO que disparó el workflow, no a `HEAD` de `main`. Garantiza que el PHP code matchee los assets subidos, aunque alguien haga otro push mientras el deploy corre.

#### Setup inicial (una sola vez)

**1. Generar par de llaves SSH dedicado en el droplet:**
```bash
ssh deploy@159.223.140.27
ssh-keygen -t ed25519 -C "github-actions" -f ~/.ssh/github_actions -N ""
cat ~/.ssh/github_actions.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
cat ~/.ssh/github_actions   # copia TODO el contenido (incluye BEGIN/END)
```

**2. Permitir reload de PHP-FPM y restart de Supervisor sin contraseña:**
```bash
sudo visudo -f /etc/sudoers.d/deploy-reload
```
Pega estas líneas y guarda:
```
deploy ALL=(ALL) NOPASSWD: /bin/systemctl reload php8.2-fpm
deploy ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl restart converza-worker:*
```

**3. Configurar secrets en GitHub:**
Ve a `Settings → Secrets and variables → Actions → New repository secret` y crea:

| Nombre | Valor |
|---|---|
| `SSH_HOST` | `159.223.140.27` |
| `SSH_USER` | `deploy` |
| `SSH_PRIVATE_KEY` | Contenido completo del archivo `~/.ssh/github_actions` |

**4. Listo.** Cada `git push origin main` (o merge a `main`) dispara el deploy. Puedes ver el progreso en la pestaña **Actions** del repo.

#### Disparar deploy manual
En la pestaña **Actions** → workflow **Deploy to production** → **Run workflow**.

### Deploy manual (fallback)

Si GitHub Actions está caído y necesitas deployar a mano desde tu PC:

```powershell
# En tu PC local
git fetch origin && git checkout main && git pull origin main
npm ci && npm run build

# Subir assets al droplet
scp -r public/build/* deploy@159.223.140.27:/var/www/converza-crm/public/build/

# Correr el resto en el droplet
ssh deploy@159.223.140.27 'cd /var/www/converza-crm && \
  git pull origin main && \
  composer install --no-dev --optimize-autoloader --no-interaction && \
  php artisan migrate --force && \
  php artisan view:clear && php artisan config:cache && php artisan route:cache && \
  sudo systemctl reload php8.2-fpm'
```

**NO** corras `npm run build` directamente en el droplet — el OOM-killer matará el proceso. Build siempre en local o en el runner.

---

## Webhook de WhatsApp

### URL pública
```
https://converza-crm.duckdns.org/webhook
```

### Verify Token
Configurado en `.env` como `WHATSAPP_VERIFY_TOKEN`. Por defecto: `converza-token-2024`.

### Comprobar que el webhook responde
```bash
curl "https://converza-crm.duckdns.org/webhook?hub.mode=subscribe&hub.verify_token=converza-token-2024&hub.challenge=TEST123"
# Debe devolver: TEST123
```

### Suscripción de campos (Facebook Developer)
Solo es estrictamente necesario:
- `messages`

Los demás (`account_alerts`, `phone_number_quality_update`, etc.) son opcionales para monitoreo de cuenta.

---

## Reenviar el webhook a tu local (desarrollo)

Como Facebook solo permite **una URL** para el webhook, el flujo es:
**Meta → Producción → (reenvía a) → tu local vía ngrok**

Así puedes ver mensajes reales mientras desarrollas sin tumbar producción.

### 1. Levantar el local
```powershell
npm run dev
```

### 2. Exponer el local con ngrok
En otra terminal:
```powershell
.\ngrok.exe http 8000
```

Verás algo como:
```
Forwarding   https://abc123-xyz.ngrok-free.app -> http://localhost:8000
```
**Copia esa URL HTTPS.**

### 3. Activar el forwarder en producción
En el droplet:
```bash
ssh deploy@159.223.140.27
nano /var/www/converza-crm/.env
```

Agrega/edita la variable:
```env
WEBHOOK_FORWARD_URL=https://abc123-xyz.ngrok-free.app/webhook
```

Limpia caché:
```bash
cd /var/www/converza-crm
php artisan config:cache
```

> Si subiste cambios al código del controlador, primero hace falta `git pull origin <rama>`.

### 4. Probar
Envía un mensaje de WhatsApp al número de prueba. Deberías ver:

| Dónde | Qué |
|---|---|
| Log de producción | `Webhook received:` |
| Consola de ngrok | Una petición `POST /webhook` entrante |
| Log local | El mismo mensaje procesado |

Logs útiles:
```bash
# En producción
tail -f /var/www/converza-crm/storage/logs/laravel.log
```
```powershell
# En local
Get-Content storage\logs\laravel.log -Wait
```

### 5. Desactivar el forwarder
Cuando termines de desarrollar:
```bash
nano /var/www/converza-crm/.env
# Deja la variable vacía:  WEBHOOK_FORWARD_URL=
php artisan config:cache
```

Producción vuelve a procesar los webhooks sin reenviar nada.

---

## Multi-tenancy e integración con ISPWatch

Converza es **multi-tenant**: cada cliente del SaaS vive en su propio `tenant` con credenciales de WhatsApp separadas y una vinculación opcional a un cliente de **ISPWatch** (sistema externo de gestión IP, fuente de verdad de clientes/facturas).

### Cómo se conecta cada pieza

| Tabla en Converza | Apunta a | Para qué |
|---|---|---|
| `tenants.wa_*` | Meta Cloud API (cifrado) | Cada tenant trae su propia cuenta de WhatsApp Business |
| `tenants.ispwatch_tenant_id` | `ispwatch.tenant.id` | FK lógico al cliente ISP en la BD de ISPWatch (Postgres/Supabase, read-only) |

Cuando un contacto escribe por WhatsApp y abres la conversación, el sidebar de la derecha hace un lookup en ISPWatch por teléfono y muestra: estado del servicio, PPPoE, IP, saldo y facturas pendientes. Si el número no está en ISPWatch, se trata como prospecto.

### Vincular un tenant Converza con uno de ISPWatch

La vinculación es un acto de **administración del SaaS** — el cliente final no la ve ni la edita desde `/settings`. Solo el admin (tú) la hace por línea de comandos:

```bash
# Listar todos los tenants Converza y su vínculo actual
php artisan tenant:link --list

# Vincular tenant Converza #1 con el cliente ISPWatch #17
php artisan tenant:link 1 17

# Quitar la vinculación
php artisan tenant:link 1 --unlink
```

El comando valida que el `ispwatch_tenant_id` exista realmente en la BD de ISPWatch antes de guardar, avisa si otro tenant Converza ya está vinculado al mismo cliente ISPWatch, e invalida el caché del repo para que el cambio se vea de inmediato en `/settings` y en el sidebar del chat.

### Cómo lee Converza la BD de ISPWatch

- Conexión `ispwatch` declarada en [config/database.php](config/database.php) (driver `pgsql`, variables `ISPWATCH_DB_*` en `.env`).
- **Solo lectura**: Converza nunca escribe en ispwatch. Acceso encapsulado en [app/Services/Ispwatch/IspwatchRepository.php](app/Services/Ispwatch/IspwatchRepository.php) con caché de 60s.
- Mirror models en `app/Models/Ispwatch/*` con `protected $connection = 'ispwatch'`.
- En producción, idealmente usar el rol `converza_reader` (SELECT-only) en Supabase — ver `docs/ispwatch-readonly.sql`.

### Configuración por tenant en `/settings`

Lo que el admin del tenant SÍ puede editar desde la UI:
- **Perfil**: nombre, slug, activo/inactivo
- **WhatsApp**: Phone Number ID, Business Account ID, Access Token (cifrado), App Secret (cifrado), Verify Token, con un botón "Probar conexión" que hace `GET graph.facebook.com/v20.0/{phone_id}` para validar credenciales sin enviar mensajes.

Lo que es **solo informativo** (no editable):
- Vinculación con ISPWatch (gestionada por `tenant:link`)
- URL del workspace (es la misma para todos los clientes — `APP_URL`)

---

## Estructura del repo

```
app/
├── Http/
│   ├── Controllers/      # Dashboard, Chat, Contacts, WhatsApp, etc.
│   └── Middleware/
├── Models/               # Contact, Conversation, Message, Label, Template...
└── Services/
    └── WhatsAppService.php

resources/js/
├── Layouts/AppLayout.vue
└── Pages/                # Inertia pages (Dashboard, Chat, Contacts, ...)

routes/web.php            # Rutas Inertia + webhook público
config/services.php       # Config WhatsApp + forward_url
```

---

## Comandos útiles

```powershell
# Local
npm run dev                          # Levantar todo
php artisan migrate:fresh --seed     # Reset DB local
php artisan tinker                   # Shell interactivo
php artisan cache:clear              # Si ISPWatch cambió pero el sidebar muestra data vieja

# Multi-tenancy (admin del SaaS)
php artisan tenant:link --list       # Ver vinculaciones tenant Converza ↔ ISPWatch
php artisan tenant:link 1 17         # Vincular Converza #1 con ISPWatch #17
php artisan tenant:link 1 --unlink   # Desvincular

# Producción (desde el droplet)
sudo systemctl reload nginx          # Recargar nginx
sudo certbot renew                   # Renovar SSL manual (corre auto vía cron)
tail -f storage/logs/laravel.log     # Ver logs en vivo
```
