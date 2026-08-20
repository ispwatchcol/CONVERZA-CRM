# Operaciones — Runbook

> **En una frase:** cómo se despliega Converza, cómo se vigila y qué hacer cuando
> algo se rompe en producción.

**Archivos clave**
- [.github/workflows/deploy.yml](../.github/workflows/deploy.yml) — el pipeline
- [deploy/DEPLOY.md](../deploy/DEPLOY.md) — setup inicial del servidor
- [deploy/supervisor/converza-worker.conf](../deploy/supervisor/converza-worker.conf) — workers

---

## 1. El entorno de producción

| | |
|---|---|
| Servidor | Droplet Ubuntu en DigitalOcean, **1 GB de RAM** |
| IP | `159.223.140.27` |
| Acceso | `ssh deploy@159.223.140.27` |
| Ruta | `/var/www/converza-crm` |
| URL | <https://converza-crm.duckdns.org> |
| Web | nginx + PHP-FPM 8.2 |
| Config nginx | `/etc/nginx/sites-available/converza-crm` |
| SSL | Let's Encrypt (Certbot, renovación automática) |
| Base de datos | PostgreSQL en Supabase, schema `converza` |
| Cache / sesión | Redis local |
| Cola | Tabla `jobs`, 4 workers de Supervisor |

> **1 GB de RAM es la restricción que explica el diseño del deploy.** No corras
> `npm run build` en el droplet: el OOM-killer mata a Vite. Se buildea siempre en
> el runner de CI o en local.

---

## 2. Deploy

### Automático

Cada push o merge a `main` dispara
[deploy.yml](../.github/workflows/deploy.yml).

**Fase 1 — en el runner de GitHub** (7 GB de RAM)
`npm ci` → `npm run build` → `rsync --delete` de `public/build/` al droplet.

> `--delete` es importante: limpia hashes viejos. Sin él, `manifest.json` puede
> apuntar a archivos que ya no existen y toda la app devuelve 500. Ya pasó.

**Fase 2 — en el droplet por SSH**

```bash
git fetch origin --prune
git checkout -- .              # descarta ediciones manuales en archivos trackeados
git reset --hard <sha>         # al commit EXACTO que disparó el workflow
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan view:clear && php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.2-fpm
sudo supervisorctl restart converza-worker:*
```

- `git reset --hard <sha>` (no `HEAD`) garantiza que el PHP coincida con los
  assets subidos, aunque alguien empuje otro commit mientras corre el deploy.
- **Nunca uses `git clean` aquí:** borraría el `.env`.
- `concurrency: deploy-production` → un deploy a la vez.

### Manual (fallback)

Si Actions está caído:

```powershell
git checkout main && git pull origin main
npm ci && npm run build
scp -r public/build/* deploy@159.223.140.27:/var/www/converza-crm/public/build/

ssh deploy@159.223.140.27 'cd /var/www/converza-crm && \
  git pull origin main && \
  composer install --no-dev --optimize-autoloader --no-interaction && \
  php artisan migrate --force && \
  php artisan view:clear && php artisan config:cache && php artisan route:cache && \
  sudo systemctl reload php8.2-fpm && \
  sudo supervisorctl restart converza-worker:*'
```

### Disparar manualmente

Actions → *Deploy to production* → **Run workflow**.

---

## 3. Setup inicial del servidor (una vez en la vida)

Detalle completo en [deploy/DEPLOY.md](../deploy/DEPLOY.md). Resumen de los seis
pasos que **no** hace el deploy automático:

**1. Llave SSH dedicada**

```bash
ssh-keygen -t ed25519 -C "github-actions" -f ~/.ssh/github_actions -N ""
cat ~/.ssh/github_actions.pub >> ~/.ssh/authorized_keys
chmod 600 ~/.ssh/authorized_keys
```

Secrets de GitHub: `SSH_HOST`, `SSH_USER`, `SSH_PRIVATE_KEY`.

**2. sudo sin contraseña para reload/restart**

```bash
sudo visudo -f /etc/sudoers.d/deploy-reload
```
```
deploy ALL=(ALL) NOPASSWD: /bin/systemctl reload php8.2-fpm
deploy ALL=(ALL) NOPASSWD: /usr/bin/supervisorctl restart converza-worker:*
```

**3. Supervisor**

```bash
sudo apt-get install -y supervisor
sudo systemctl enable --now supervisor
sudo cp /var/www/converza-crm/deploy/supervisor/converza-worker.conf /etc/supervisor/conf.d/
sudo supervisorctl reread && sudo supervisorctl update
sudo supervisorctl start converza-worker:*
sudo supervisorctl status converza-worker:*    # 4 procesos RUNNING
```

Verifica antes que PHP-FPM corra como `www-data`; si no, ajusta `user=` en el
`.conf`.

**4. Permisos de storage** — ver §6.

**5. ffmpeg**

```bash
sudo apt-get install -y ffmpeg
sudo -u www-data ffmpeg -version | head -1   # www-data debe poder ejecutarlo
```

**6. `.env` de producción**

```env
APP_ENV=production
APP_DEBUG=false
LOG_LEVEL=warning
QUEUE_CONNECTION=database
DB_SEARCH_PATH=converza
```

**7. Cron del scheduler** — sin esto **no corre ningún aviso ni campaña**:

```cron
* * * * * cd /var/www/converza-crm && php artisan schedule:run >> /dev/null 2>&1
```

Verifica: `php artisan schedule:list`.

---

## 4. Monitoreo diario

### 4.1 `/health` — el chequeo profundo

```bash
curl -s https://converza-crm.duckdns.org/health | jq
```

Verifica **ambas** conexiones de BD (`pgsql` y `ispwatch`), Redis, y que
`storage/logs` siga siendo escribible por www-data. Devuelve **200** solo si todo
responde; **503** si algo falla. Contrato:

> `"status":"ok"` ⟺ HTTP 200 ⟺ todos los checks en `ok:true`.
> No existe ningún caso de 200 con otro status.

Se registra en `bootstrap/app.php`, **fuera del grupo `web`**, para que no dependa
de sesión ni Inertia — que usan Redis y la BD, justo lo que debe diagnosticar.
`/up` (el health de Laravel) sigue existiendo pero es superficial: solo confirma
que PHP responde.

> **No uses `/login` como health check.** Devuelve 200 con la BD caída porque no
> la toca. El 20/08/2026 eso hizo que una caída de 9 h 54 m pasara inadvertida.

### 4.2 Centinela externo

UptimeRobot consulta `/health` cada 5 minutos desde fuera de DigitalOcean, con la
app instalada en los teléfonos del equipo para que llegue como notificación push.

El plan gratuito solo permite `HEAD` —sin validación de contenido—, así que **el
código HTTP es la única señal**. Por eso importa que `/health` nunca devuelva 200
con un chequeo en rojo: si alguien toca ese endpoint, mantener esa propiedad no es
opcional.

### 4.3 Comprobaciones manuales

```bash
ssh deploy@159.223.140.27

# ¿Los workers están vivos?
sudo supervisorctl status converza-worker:*

# ¿La cola está al día? Prod usa REDIS, no la tabla `jobs`:
#   DB::table('jobs')->count() siempre da 0 y no significa nada.
sudo -u www-data php /var/www/converza-crm/artisan tinker \
  --execute="echo Illuminate\Support\Facades\Queue::size();"

# ¿Hay jobs fallidos?
sudo -u www-data php /var/www/converza-crm/artisan queue:failed

# ¿Entró algo que no se guardó? (repara solo, esto es para verificar)
sudo -u www-data php /var/www/converza-crm/artisan webhooks:reconcile --dry-run

# Logs en vivo
sudo tail -f /var/www/converza-crm/storage/logs/laravel.log
sudo tail -f /var/log/supervisor/converza-worker.log

# Disco (los medios y el log crudo lo llenan)
df -h && du -sh /var/www/converza-crm/storage/app/public/whatsapp-media

# Memoria
free -m
```

### Semáforo rápido

| Señal | Verde | Rojo |
|---|---|---|
| `/health` | 200 `"status":"ok"` | 503, o sin responder |
| `Queue::size()` | < 50 y bajando | Sube sin parar |
| Workers | 4 RUNNING | Menos, o reiniciando en bucle |
| `queue:failed` | Vacío | Crece |
| `webhooks:reconcile --dry-run` | «Sin huecos» | Reporta huecos |
| Disco | < 80 % | > 90 % |
| Calidad del número | Verde en Meta | Amarillo o rojo |

---

## 5. Escalado

Si la cola se acumula de forma sostenida:

```bash
sudo nano /etc/supervisor/conf.d/converza-worker.conf
# numprocs=4 → 8
sudo supervisorctl reread && sudo supervisorctl update
```

Regla de dedo: 1 worker por core. Con 1 GB de RAM, subir de 8 puede provocar
swapping — antes de eso, agranda el droplet.

---

## 6. Permisos de storage

PHP-FPM corre como `www-data` y necesita **escribir** en `storage/app/public` en
cada envío de imagen o audio. Si `storage` quedó como `deploy:deploy`, el envío
de medios da **500**.

Se arregla **una sola vez**:

```bash
cd /var/www/converza-crm
sudo chown -R "$(whoami)":www-data storage bootstrap/cache
sudo chmod -R 775 storage bootstrap/cache
sudo find storage bootstrap/cache -type d -exec chmod g+s {} \;
```

El `setgid` (`g+s`) hace que los archivos creados en runtime hereden el grupo
`www-data`, así que no hay que repetirlo en cada deploy. Por eso el pipeline no
lo hace: el usuario de deploy no tiene sudo sin contraseña para `chown`/`chmod`.

> Nota: el disco `supabase` (driver s3) **no está operativo**. Deja
> `MEDIA_DISK=public` para no intentarlo en vano en cada envío.

---

## 6 bis. Rotar credenciales sin tumbar producción

El 20/08/2026 se rotó la contraseña de Postgres en Supabase y producción quedó
caída **9 h 54 m**, porque el `.env` del droplet nunca se actualizó. La rotación
en sí fue correcta; lo que faltó fue propagarla.

### Dónde vive cada credencial

Una sola contraseña de Postgres aparece en **cuatro variables repartidas en dos
máquinas**. Rotarla y tocar solo una es el error fácil:

| Credencial | Dónde | Variables |
|---|---|---|
| Postgres (Supabase) | `.env` del droplet | `DB_PASSWORD` **y** `ISPWATCH_DB_PASSWORD` |
| Postgres (Supabase) | `.env` local de cada dev | las mismas dos |
| Token de WhatsApp | Columna `tenants.wa_access_token`, cifrada | — (se cambia en `/settings`) |
| Token global de WhatsApp | `.env` del droplet | `WHATSAPP_API_TOKEN` |

> Las dos conexiones de Postgres usan el **mismo usuario y la misma contraseña**;
> solo cambian de `search_path` (`converza` vs `public`). Es facilísimo arreglar
> una y olvidar la otra: pasó exactamente eso, y el síntoma fue que `/login`
> funcionaba mientras el dashboard seguía en 500.

### Procedimiento

```bash
# 1. Rotar en el proveedor (Supabase → Settings → Database)

# 2. Propagar al droplet — LAS DOS variables
ssh deploy@159.223.140.27
cd /var/www/converza-crm
cp .env .env.bak.$(date +%Y%m%d-%H%M)          # respaldo antes de tocar
sed -i 's|^DB_PASSWORD=.*|DB_PASSWORD=<nueva>|' .env
sed -i 's|^ISPWATCH_DB_PASSWORD=.*|ISPWATCH_DB_PASSWORD=<nueva>|' .env

# 3. Aplicar. SIN config:cache el cambio NO surte efecto: prod cachea la config.
php artisan config:cache
sudo systemctl reload php8.2-fpm
sudo supervisorctl restart converza-worker:*

# 4. Verificar ANTES de irte — comprueba las dos conexiones, no solo una
php artisan deploy:verify
```

`deploy:verify` es el paso que no se salta. Sale con código 1 si algo no
responde e imprime el motivo con la conexión que falló.

### Red de seguridad

Desde el 20/08/2026 el despliegue corre `deploy:verify` automáticamente y
**falla el workflow** si alguna dependencia no responde con la config
desplegada. Antes, `migrate --force` solo ejercía `pgsql`, así que una
`ISPWATCH_DB_PASSWORD` vieja pasaba desapercibida hasta que alguien abría el
dashboard.

Y si aun así se escapa, el centinela sobre `/health` avisa en menos de 5
minutos (§4).

---

## 7. Incidentes frecuentes

### 🔴 No entra ningún mensaje

**Diagnóstico, en orden:**

```bash
# 1. ¿La app puede hablar con sus dependencias?
curl -s https://converza-crm.duckdns.org/health | jq

# 2. ¿Meta está llamando? El log crudo registra TODO webhook que llega,
#    incluso si después falla el procesamiento.
tail -3 storage/logs/webhook-raw-$(date +%F).log
grep -c inbound storage/logs/webhook-raw-$(date +%F).log

# 3. ¿Se reconoció el tenant?
grep "ningún tenant coincide" storage/logs/laravel.log | tail -5

# 4. ¿Los workers corren?
sudo supervisorctl status converza-worker:*
```

| Qué ves | Causa | Solución |
|---|---|---|
| `/health` da 503 | Una dependencia caída — el JSON dice cuál | Según el check en rojo (§7 «Toda la app devuelve 500») |
| El log crudo del día no existe o no crece | Meta no está llamando | Verificar suscripción (§7.1) |
| Hay eventos en el log crudo pero `ningún tenant coincide` | `phone_number_id` no está en ningún tenant activo | Corregirlo en `/settings` |
| Hay eventos y nada aparece en el chat | La cola no corre | `sudo supervisorctl start converza-worker:*` |
| `Queue::size()` crece sin bajar | Workers muertos | Reiniciar; revisar el log de supervisor |

> El log crudo (`webhook-raw-*.log`) reemplazó al viejo `grep "Webhook received"`
> de `laravel.log`. Aquel era un `Log::info` que `LOG_LEVEL=warning` descarta —
> por eso ya no aparece, y por eso existe el canal aparte.

**Recuperar lo que no se guardó:** si hubo un corte y entraron mensajes durante
él, `webhooks:reconcile` los repara solo cada 5 minutos (ventana de 60 min). Para
un corte más largo, con rango explícito:

```bash
sudo -u www-data php artisan webhooks:replay --fecha=2026-08-20 --dry-run
sudo -u www-data php artisan webhooks:replay --desde="2026-08-20 09:51" --hasta="2026-08-20 13:19"
```

Reprocesar es seguro: `wa_message_id` tiene índice único, el bot no responde dos
veces y los acuses de estado no retroceden.

**7.1 Verificar la suscripción en Meta** (read-only, desde tinker):

```php
$t = App\Models\Tenant::find(1);
Http::withToken($t->wa_access_token)
    ->get("https://graph.facebook.com/v20.0/{$t->wa_business_account_id}/subscribed_apps")
    ->json();
```

`data` vacío = el número **no está suscrito**. Los salientes funcionarán y los
entrantes nunca llegarán. Es la causa más frecuente de este síntoma.

### 🔴 Los mensajes salientes quedan en `sent` y no llegan

Mismo diagnóstico que arriba: casi siempre es la suscripción del webhook (los
acuses de entrega llegan por el mismo canal). Verifica también la salud del
número en `/settings`.

### 🔴 Enviar imagen o audio da 500

Permisos de storage (§6). Confirma en el log:
`no se pudo guardar copia local (¿permisos de storage/app/public para www-data?)`.

Nota: desde el arreglo actual esto **no** debería dar 500 — el envío se registra
igual y solo la miniatura queda rota. Un 500 real apunta a otra cosa (revisa el
stack trace).

### 🔴 Grabar audio falla

```bash
which ffmpeg
sudo -u www-data ffmpeg -version | head -1
```

Si `www-data` no puede ejecutarlo, define `FFMPEG_PATH=/usr/bin/ffmpeg` en el
`.env`, `config:cache` y recarga PHP-FPM.

### 🟠 Notas de voz salientes mudas

**No es el servidor.** Es la captura del navegador o del micrófono. Verifica el
archivo:

```bash
ffprobe archivo.ogg
ffmpeg -i archivo.ogg -af volumedetect -f null - 2>&1 | grep mean_volume
```

Si el volumen medio es -91 dB, el audio estaba vacío desde el origen. El chat ya
tiene un medidor de nivel y bloquea el envío en ese caso.

### 🔴 No salen los avisos automáticos

Ver [avisos-automaticos.md §8](avisos-automaticos.md#8-diagnóstico-cuando-no-sale-un-aviso).
Lo primero: ¿corre el cron de `schedule:run`?

```bash
php artisan schedule:list
grep CRON /var/log/syslog | tail -20
```

### 🔴 Toda la app devuelve 500 tras un deploy

Casi siempre son assets desincronizados: `manifest.json` apunta a hashes que ya no
existen.

```bash
ls -la /var/www/converza-crm/public/build/
cat /var/www/converza-crm/public/build/manifest.json | head
```

Solución: re-lanzar el workflow (el `rsync --delete` lo corrige).

Si no es eso, mira el error real:

```bash
tail -100 storage/logs/laravel.log
sudo tail -50 /var/log/nginx/error.log
```

### 🟠 Cambié el `.env` y no surte efecto

```bash
php artisan config:cache
sudo systemctl reload php8.2-fpm
```

En producción la config está cacheada. **Editar el `.env` sin re-cachear no hace
nada.**

### 🟠 El sidebar de ispwatch muestra datos viejos

Cache de 60 s → `php artisan cache:clear`.

### 🟠 El disco se llena

```bash
du -sh /var/www/converza-crm/storage/app/public/whatsapp-media
php artisan media:clean --dry-run
php artisan media:clean
```

Baja `MEDIA_CLEANUP_DAYS` o quita `document` de `MEDIA_DOWNLOAD_TYPES`: los
documentos de WhatsApp pesan hasta 100 MB, frente a 16 MB de los vídeos. Son el
mayor riesgo de almacenamiento.

### 🟠 Un contacto aparece con varios chats

Arrastre histórico: hasta agosto 2026 cada punto de envío resolvía la
conversación con `firstOrCreate([... 'status' => 'open'])`, así que con el chat
**cerrado** abría uno nuevo. Un cliente que escribió tres veces quedaba con tres
hilos y el historial partido.

La causa está corregida (`Conversation::resolveForContact()`: un solo hilo por
contacto, se reabre en vez de duplicar). Para limpiar lo que ya está en la BD:

```bash
php artisan chats:merge-duplicates --dry-run          # revisar SIEMPRE primero
php artisan chats:merge-duplicates --tenant=2         # aplicar
```

Conserva el hilo más antiguo, mueve mensajes/notas/logs del bot y consolida los
"leídos" por agente. Si aparecen duplicados **nuevos**, es un bug: algún envío
está creando conversaciones sin pasar por `resolveForContact()`.

### 🟠 Se cierran chats solos

Es el cierre automático por inactividad (`chats:auto-close`, cada 5 min), opt-in
por tenant en Configuración → Cierre automático (`auto_close_enabled` /
`auto_close_hours`, default 2 h). Solo cierra chats cuyo **último mensaje es
saliente**; si el último es del cliente no se toca. No envía nada por WhatsApp:
deja una nota de sistema en el hilo.

```bash
php artisan chats:auto-close --dry-run                # qué cerraría ahora
php artisan chats:auto-close --tenant=2 --hours=1 --dry-run
```

### 🔴 Un tenant ve datos de otro

**Incidente crítico.** Actuación:

1. Identificar el job o petición implicada en los logs.
2. Comprobar que el job rebindea el tenant (patrón forget/bind/finally).
3. Detener los workers si sigue ocurriendo:
   `sudo supervisorctl stop converza-worker:*`.
4. Corregir, desplegar, reanudar.

Ver [seguridad.md §2](seguridad.md#2-aislamiento-multi-tenant).

---

## 8. Mantenimiento periódico

| Tarea | Frecuencia | Cómo |
|---|---|---|
| Revisar `queue:failed` | Diaria | `queue:failed` / `queue:retry all` |
| Revisar la salud del número | Semanal | `/settings` de cada tenant |
| Espacio en disco | Semanal | `df -h` |
| Limpieza de medios | Automática (dom. 03:00) | `media:clean` |
| Renovación SSL | Automática (cron) | `sudo certbot renew --dry-run` para verificar |
| Backup de base | **Supabase** | Verificar que la política de retención esté activa |
| Actualizar dependencias | Trimestral | `composer outdated`, `npm outdated` |

> ⚠️ **No hay backup propio de la base.** Se depende íntegramente de la política
> de Supabase. Verifícala.

---

## 9. Rollback

No hay procedimiento automatizado. Manual:

```bash
ssh deploy@159.223.140.27
cd /var/www/converza-crm
git log --oneline -10
git reset --hard <sha-anterior>
composer install --no-dev --optimize-autoloader
php artisan config:cache && php artisan route:cache
sudo systemctl reload php8.2-fpm
sudo supervisorctl restart converza-worker:*
```

⚠️ **Los assets no vuelven atrás con esto.** Hay que reconstruirlos desde ese
commit y subirlos, o re-lanzar el workflow desde el commit anterior.

⚠️ **Las migraciones tampoco.** Si el commit fallido migró el esquema, hay que
revertirlo a mano. Por eso las migraciones deben ser aditivas siempre que se
pueda.

Ver [mejoras.md](mejoras.md#m-07-no-hay-rollback-ni-staging).

---

## 10. Contactos y accesos

| Recurso | Dónde |
|---|---|
| Servidor | `ssh deploy@159.223.140.27` |
| Base de datos | Panel de Supabase |
| Dominio | DuckDNS |
| WhatsApp | Meta Business Manager / developers.facebook.com |
| CI/CD | Pestaña Actions del repo |
| Soporte del SaaS | +57 312 575 9381 (botón flotante en la app) |
