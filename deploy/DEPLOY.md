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

### 6. Permisos de `storage` para www-data (¡o el chat tira 500 al enviar medios!)

PHP-FPM corre como **www-data** y necesita ESCRIBIR en `storage/app/public` cada vez
que se envía una imagen o audio desde el chat. Si `storage` quedó como `deploy:deploy`,
www-data no puede escribir y `sendMedia` tira **500 SERVER ERROR**.

Esto se arregla **una sola vez** (logueado como tu SSH_USER, con tu password de sudo):

```bash
cd /var/www/converza-crm
# dueño = usuario de deploy (para que los 'php artisan' del deploy escriban),
# grupo = www-data con escritura (para que PHP-FPM escriba en runtime)
sudo chown -R "$(whoami)":www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
# setgid: los archivos/carpetas creados en runtime heredan el grupo www-data
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
```

No se hace en el deploy automático porque el deploy-user no tiene sudo sin password para
`chown/chmod`, y con el `setgid` de arriba los medios nuevos ya heredan el grupo correcto,
así que no hay que repetirlo.

> Nota: el disco `supabase` (driver s3) no está operativo —falta el paquete
> `league/flysystem-aws-s3-v3`—, así que los medios se guardan SOLO en disco local.
> Para no intentar supabase en vano, podés poner `MEDIA_DISK=public` en el `.env`.

### 7. ffmpeg para notas de voz grabadas en el navegador

El audio grabado desde el chat sale en `webm/opus`, que WhatsApp no acepta. El servidor
lo transcodifica a OGG/opus con **ffmpeg**. Sin ffmpeg, grabar audio falla con
*"No se pudo procesar el audio grabado"*. Instalalo una vez:

```bash
sudo apt-get update
sudo apt-get install -y ffmpeg
which ffmpeg                                  # /usr/bin/ffmpeg
sudo -u www-data ffmpeg -version | head -1    # www-data debe poder ejecutarlo
```

El paquete de Ubuntu ya incluye libopus. Como `FFMPEG_PATH` por defecto es `ffmpeg` y
`/usr/bin` está en el PATH, no hace falta tocar el `.env`. (Solo si PHP-FPM no lo
encuentra: `FFMPEG_PATH=/usr/bin/ffmpeg` en `.env` + `php artisan config:cache` + reload
de php-fpm.) Subir un `.mp3`/`.ogg`/`.m4a` ya listo NO requiere ffmpeg.

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
4. **`deploy/sync-secrets.php`** — propaga el secret `DB_PASSWORD` del repo a
   `DB_PASSWORD` **e** `ISPWATCH_DB_PASSWORD` del `.env`, juntas. Va antes de
   `migrate` (que fallaría con la contraseña vieja) y de `config:cache` (que la
   congelaría). Si el secret no existe, no toca nada y sigue.
5. `php artisan migrate --force`

   > ⚠️ **Esto migra solo `converza`.** El schema `converza_dev` usa la misma base
   > y no lo toca nadie automáticamente; queda atrás en silencio hasta que algo
   > falla ahí. Tras un despliegue con migraciones nuevas, corré también:
   >
   > ```bash
   > cd /var/www/converza-crm
   > DB_SEARCH_PATH=converza_dev php artisan migrate --force
   > ```

6. Cache de config/routes/views
7. **`deploy:verify`** — comprueba que la config recién cacheada funcione: ambas
   conexiones de BD, Redis y disco escribible. No aborta a media asta (dejaría
   los workers con código viejo), pero **falla el workflow al final** si algo no
   responde. `migrate --force` solo ejerce `pgsql`; sin este paso una
   `ISPWATCH_DB_PASSWORD` vieja pasa desapercibida.
8. Reload PHP-FPM
9. **Restart de los queue workers** (toman el código nuevo)

---

## 📊 Monitoreo en producción

```bash
# Lo primero, sin SSH: el chequeo profundo.
# 200 "status":"ok" = BD (ambas conexiones), Redis y storage/logs sanos.
# 503 = algo cayó, y el JSON dice cuál.
curl -s https://converza-crm.duckdns.org/health | jq

ssh deploy@<IP-DROPLET>

# Estado de los workers
sudo supervisorctl status converza-worker:*

# Logs en vivo
sudo tail -f /var/log/supervisor/converza-worker.log

# Cuántos jobs hay encolados (idealmente cerca de 0).
# La cola de producción es REDIS: DB::table('jobs')->count() siempre da 0.
sudo -u www-data php /var/www/converza-crm/artisan tinker --execute="echo Illuminate\Support\Facades\Queue::size();"

# ¿Entró algún mensaje que no se guardó? (se repara solo cada 5 min)
sudo -u www-data php /var/www/converza-crm/artisan webhooks:reconcile --dry-run

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

### El caso peor: el workflow ni siquiera arranca

**Síntoma:** el run aparece en rojo pero **no tiene ningún job**, no hay logs que
abrir, y encima aparecen runs en ramas que el filtro `branches: [main]` debería
excluir. Es un *startup failure*: GitHub no pudo compilar el workflow, así que no
llegó a evaluar el filtro de ramas ni a agendar nada.

**Lo peligroso es que nadie se entera.** No hay job que falle, no llega correo de
"tu build falló", y `main` sigue recibiendo merges que nunca se despliegan.
Producción se queda congelada en silencio. Pasó entre el **21/08 y el 02/09/2026**:
doce días y tres merges sin desplegar, y se descubrió de casualidad auditando otra
cosa.

**La causa de aquella vez, y la trampa a evitar:** una expresión de GitHub Actions
**vacía** dentro de un comentario del bloque `run:`. GitHub sustituye las
expresiones en todo el bloque *antes* de que exista un shell, así que un `#` no
protege nada y una expresión vacía no compila. Nunca escribas la sintaxis de
Actions "de ejemplo" en este archivo; descríbela con palabras.

**Cómo confirmarlo** (la API lo dice sin necesidad de la web):

```bash
gh api repos/ispwatchcol/CONVERZA-CRM/actions/runs --jq \
  '.workflow_runs[0] | {rama: .head_branch, conclusion, id}'

# Si el run falló, mirá cuántos jobs tuvo. Cero jobs = startup failure.
gh api repos/ispwatchcol/CONVERZA-CRM/actions/runs/<ID>/jobs --jq '.total_count'
```

**Ahora hay una red automática.** El workflow *Validar workflows*
(`.github/workflows/validar-workflows.yml`) corre en cada PR que toque
`.github/workflows/` y ejecuta `.github/scripts/validate-workflows.py`, que revisa
que cada workflow sea YAML válido, que no haya expresiones de Actions **vacías**
—la trampa exacta de esta sección— y que ninguna quede sin cerrar. Se probó contra
el `deploy.yml` roto de verdad: lo señala en la línea 93 y devuelve código 1.

Corrélo en local antes de tocar cualquier workflow:

```bash
python .github/scripts/validate-workflows.py
```

> ⚠️ **Dos límites que conviene tener claros.**
>
> 1. Hoy el check es *advisory*: si falla, sale una X roja pero el merge se puede
>    hacer igual. Falta marcarlo como check obligatorio en las reglas de `main`,
>    que es un ajuste manual en GitHub — **CON-74**.
> 2. Si lo que se rompe es **el propio `validar-workflows.yml`**, tampoco arranca y
>    tampoco avisa: ningún workflow puede denunciar su propia falta de compilación.
>    Por eso ese archivo se mantiene deliberadamente corto, y por eso el chequeo
>    manual de abajo no se retira.

**Chequeo periódico que vale la pena:** comparar lo desplegado contra `main`. Si
difieren sin motivo, el pipeline está roto.

```bash
ssh deploy@<IP-DROPLET> 'cd /var/www/converza-crm && git rev-parse HEAD'
git rev-parse origin/main
```

### Errores dentro del deploy

GitHub → Actions → click en el run fallido → ver el log. Los errores comunes:

| Error | Solución |
|---|---|
| `supervisorctl: command not found` | Falta instalar Supervisor (Paso 2) |
| `sudo: a password is required` | Falta configurar sudoers (Paso 3) |
| `unix:///var/run/supervisor.sock no such file` | `sudo systemctl start supervisor` |
| `converza-worker: ERROR (no such process)` | Falta copiar el config + start (Paso 4) |
