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

### Desplegar cambios
```bash
ssh deploy@159.223.140.27
cd /var/www/converza-crm
git pull origin <rama>
composer install --no-dev --optimize-autoloader
npm ci && npm run build
php artisan migrate --force
php artisan config:cache && php artisan route:cache
```

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

# Producción (desde el droplet)
sudo systemctl reload nginx          # Recargar nginx
sudo certbot renew                   # Renovar SSL manual (corre auto vía cron)
tail -f storage/logs/laravel.log     # Ver logs en vivo
```
