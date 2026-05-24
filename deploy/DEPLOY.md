# Deploy a producción

El deploy en sí está automatizado vía **GitHub Actions** (`.github/workflows/deploy.yml`) — se dispara con cada push a `main` y hace pull, build, migración, cache, reload de PHP-FPM y restart de los workers de cola.

Lo único que requiere SSH manual es el **setup inicial de Supervisor** (una sola vez en la vida del servidor).

---

## 🔧 Setup inicial — UNA SOLA VEZ

### 1. Verificá el usuario de PHP-FPM

```bash
ssh root@<IP-DROPLET>
ps aux | grep -E 'php-fpm|nginx' | grep -v root | head -3
```

Si **no es `www-data`** (puede ser `forge`, `ubuntu`, etc.), editá `deploy/supervisor/converza-worker.conf` en el repo y cambiá la línea `user=www-data` antes de continuar.

### 2. Instalá Supervisor

```bash
sudo apt-get update
sudo apt-get install -y supervisor
sudo systemctl enable supervisor
sudo systemctl start supervisor
```

### 3. Asegurate que el deploy-user pueda reiniciar supervisor sin password

Editá sudoers para permitir que el usuario SSH (el de GitHub Actions) corra `supervisorctl restart` sin password:

```bash
sudo visudo -f /etc/sudoers.d/converza-deploy
```

Pegá (reemplazá `deploy-user` por tu `SSH_USER` real):
```
deploy-user ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl restart converza-worker\:*, /bin/systemctl reload php8.2-fpm
```

Guardá y cerrá.

### 4. Copiá el config del worker y arrancalo

```bash
# Hace falta haber hecho al menos un deploy primero, para que el archivo exista en el servidor
sudo cp /var/www/converza-crm/deploy/supervisor/converza-worker.conf /etc/supervisor/conf.d/

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl start converza-worker:*

# Verificá: deben aparecer 4 procesos en RUNNING
sudo supervisorctl status converza-worker:*
```

Output esperado:
```
converza-worker:converza-worker_00   RUNNING   pid 12345, uptime 0:00:05
converza-worker:converza-worker_01   RUNNING   pid 12346, uptime 0:00:05
converza-worker:converza-worker_02   RUNNING   pid 12347, uptime 0:00:05
converza-worker:converza-worker_03   RUNNING   pid 12348, uptime 0:00:05
```

### 5. Confirmá el `.env` de producción

```bash
sudo nano /var/www/converza-crm/.env
```

Asegurate que tenga:
```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://tu-dominio.com
QUEUE_CONNECTION=database
LOG_LEVEL=warning
```

**Listo.** A partir de ahora cada `git push origin main` despliega solo.

---

## 🚀 Deploys regulares

Solo hacé merge a `main`:

```bash
git checkout main
git merge pruebas
git push origin main
```

GitHub Actions se encarga del resto:
1. Pull código nuevo
2. `composer install --no-dev`
3. `npm ci && npm run build`
4. `php artisan migrate --force`
5. Cache de config/routes/views
6. Reload PHP-FPM
7. **Restart de los queue workers** (toman el código nuevo)

---

## 📊 Monitoreo en producción

```bash
ssh root@<IP-DROPLET>

# Estado de los workers
sudo supervisorctl status converza-worker:*

# Logs en vivo
sudo tail -f /var/log/supervisor/converza-worker.log

# Cuántos jobs hay encolados (idealmente cerca de 0)
sudo -u www-data php /var/www/converza-crm/artisan tinker --execute="echo DB::table('jobs')->count();"

# Jobs fallidos
sudo -u www-data php /var/www/converza-crm/artisan queue:failed

# Reintentar todos los fallidos
sudo -u www-data php /var/www/converza-crm/artisan queue:retry all

# Logs de Laravel
sudo tail -f /var/www/converza-crm/storage/logs/laravel.log
```

---

## ⚡ Escalado

Si ves que la cola se va llenando (`jobs` count subiendo constantemente), agregá más workers:

```bash
sudo nano /etc/supervisor/conf.d/converza-worker.conf
# Cambiá numprocs=4 → 8 (o más)

sudo supervisorctl reread
sudo supervisorctl update
sudo supervisorctl status converza-worker:*
```

Regla de dedo: 1 worker por core de CPU. Un droplet de 2 vCPU maneja bien hasta 4 workers.

---

## 🆘 Si algo falla en el deploy automático

GitHub → Actions → click en el run fallido → ver el log. Los errores comunes:

| Error | Solución |
|---|---|
| `supervisorctl: command not found` | Falta instalar Supervisor (Paso 2) |
| `sudo: a password is required` | Falta configurar sudoers (Paso 3) |
| `unix:///var/run/supervisor.sock no such file` | `sudo systemctl start supervisor` |
| `converza-worker: ERROR (no such process)` | Falta copiar el config + start (Paso 4) |
