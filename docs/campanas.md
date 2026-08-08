# Campañas masivas

> **En una frase:** secuencias multi-paso de plantillas de WhatsApp, con audiencia
> de cuatro orígenes, pacing en dos dimensiones (ritmo por minuto y volumen
> diario), opt-out automático y embudo por paso.

**Archivos clave**
- [app/Http/Controllers/CampaignController.php](../app/Http/Controllers/CampaignController.php)
- [app/Console/Commands/RunCampaignTick.php](../app/Console/Commands/RunCampaignTick.php) — el motor
- [app/Jobs/SendCampaignMessageJob.php](../app/Jobs/SendCampaignMessageJob.php)
- [app/Services/Campaigns/AudienceBuilder.php](../app/Services/Campaigns/AudienceBuilder.php)
- [app/Services/Campaigns/CampaignMessageBuilder.php](../app/Services/Campaigns/CampaignMessageBuilder.php)
- [app/Services/Campaigns/WarmupBudget.php](../app/Services/Campaigns/WarmupBudget.php)
- [config/campaigns.php](../config/campaigns.php)

---

## 1. Modelo conceptual

```
Campaign  ──< CampaignStep        (la definición de la secuencia)
    │
    └─────< CampaignRecipient     (la INSCRIPCIÓN de un contacto)
                  │
                  └──< CampaignSend   (un MENSAJE real: paso × destinatario)
```

- **Campaign** — la campaña, su audiencia, su estado y sus contadores.
- **CampaignStep** — el paso *N*: qué plantilla, cuánto esperar y bajo qué
  condición.
- **CampaignRecipient** — el destinatario **inscrito** en la secuencia: en qué
  paso va (`current_step`), su estado (`enrollment_status`) y cuándo toca lo
  siguiente (`next_action_at`).
- **CampaignSend** — un envío concreto. Aquí viven `wa_message_id` y los
  timestamps de estado.

Esta estructura llegó en una migración con **backfill**: cada campaña previa se
convirtió en una secuencia de un paso y cada envío en un `CampaignSend`, sin
pérdida de datos. Ver
[2026_06_17_000002_create_campaign_sequences_tables.php](../database/migrations/2026_06_17_000002_create_campaign_sequences_tables.php).

---

## 2. Permisos

| Acción | Rol |
|---|---|
| Ver listado y ficha, exportar | Cualquiera |
| Crear, editar, lanzar, pausar, reanudar, cancelar, probar, reintentar, duplicar, borrar | **admin** |

Está así porque una campaña mal lanzada afecta el *quality rating* del número —
y eso afecta a **toda** la operación del ISP, no solo a esa campaña.

---

## 3. Audiencia

`AudienceBuilder::build()` normaliza los cuatro orígenes a una lista única.

| `source_type` | Origen |
|---|---|
| `upload` | Excel/CSV parseado en el navegador (librería `xlsx`) |
| `crm` | Contactos propios, filtrables por etiqueta y por estado en ispwatch |
| `manual` | Lista pegada a mano |
| `sheet` | Google Sheets publicado (se descarga con HTTP) |

En todos los casos el builder:

1. **Normaliza** el teléfono (mismas reglas que el resto del sistema).
2. **Deduplica**.
3. Cruza contra `campaign_opt_outs` y **excluye** a quien se dio de baja.
4. Casa con un `Contact` existente si hay match, para reutilizar su nombre.

Devuelve filas + **estadísticas** (total, válidos, duplicados, excluidos), que
alimentan la vista previa. **No persiste nada**: el controlador decide si es un
preview o si crea los `CampaignRecipient` al lanzar.

Endpoint de preview: `POST /campaigns/preview-audience`.

---

## 4. Mensaje y variables

`POST /campaigns/preview-message` renderiza la plantilla con datos reales antes
de lanzar.

El `variable_mapping` asocia cada variable de la plantilla con:

- una **columna** del archivo/hoja, o
- un **valor fijo** para toda la campaña.

Cada paso puede tener su propio `variable_mapping`; si no, hereda el de la
campaña.

> **La plantilla debe ser categoría `marketing`.** Lo exige
> `campaigns.template_category`. Usar `utility` para promoción en frío viola la
> política de Meta y arriesga el número. En base la categoría se guarda en
> minúscula.

---

## 5. Secuencias

| Campo del paso | Significado |
|---|---|
| `step_order` | 1 = envío inicial |
| `template_id` | Plantilla de ese paso |
| `delay_hours` | Espera tras el paso anterior (la UI ofrece días + horas) |
| `send_condition` | `always` (obligatorio en el paso 1) o `if_not_replied` |

### Estados de la inscripción

| `enrollment_status` | Significa |
|---|---|
| `active` | Esperando su próximo paso |
| `sending` | Job despachado, envío en vuelo |
| `completed` | Terminó la secuencia |
| `replied` | **Respondió** → secuencia detenida |
| `opted_out` | Se dio de baja → detenida |
| `failed` | Falló |

### La respuesta detiene todo

Cuando llega un mensaje entrante,
[`ProcessIncomingWhatsAppMessage::handleCampaignSignals`](../app/Jobs/ProcessIncomingWhatsAppMessage.php)
busca la inscripción de ese teléfono (`active`, `sending` o `completed`, sin
`replied_at`), la marca como `replied`, limpia `next_action_at`, marca el último
`CampaignSend` e incrementa `campaigns.replied_count`.

> Una respuesta es una **conversión**: a partir de ahí es una conversación normal
> en el Chat, no un destinatario al que seguir persiguiendo.

---

## 6. El motor: `campaigns:tick`

Corre **cada minuto** con `withoutOverlapping(2)`. En cada tic:

1. Promueve campañas `scheduled` cuya `scheduled_at` ya pasó → `sending`.
2. Por cada **tenant** con campañas en envío (rebindando el tenant explícitamente,
   igual que los demás jobs — ver
   [arquitectura §4.4](arquitectura.md#44-el-peligro-real-los-workers)):
   - Calcula el presupuesto de warm-up disponible.
   - Toma las inscripciones cuyo `next_action_at` ya venció.
   - Valida la **condición** del paso. Solo los envíos que pasan consumen
     presupuesto.
   - Despacha un `SendCampaignMessageJob` por cada una, **escalonados dentro del
     minuto** para no salir en ráfaga.
3. Recupera inscripciones atascadas en `sending` más de **15 minutos**
   (`STALE_SENDING_MINUTES`): un job perdido no debe congelar al destinatario.

El tic es barato cuando no hay campañas activas: un par de consultas cortas.

---

## 7. Las dos dimensiones del pacing

Es la distinción que más se confunde:

| | `throttle_per_minute` | Warm-up |
|---|---|---|
| **Controla** | Ritmo **dentro** del minuto | **Volumen total** del día |
| **Ámbito** | Por campaña | Por **número** (tenant), compartido entre todas sus campañas |
| **Default** | 20 | Apagado |
| **Por qué** | No salir en ráfaga | El tier y la calidad de Meta son por número, no por campaña |

### Warm-up

Rampa configurable por tenant (`campaign_warmups`):

| Parámetro | Default |
|---|---|
| `enabled` | **false** (opt-in) |
| `start_per_day` | 50 |
| `daily_increment` | 50 |
| `max_per_day` | 1000 |

> Arranca apagado **a propósito**: encenderlo por defecto caparía de golpe las
> campañas de todos los tenants existentes.

`WarmupBudget::usedLast24h()` cuenta sobre `campaign_sends` en una **ventana
rodante de 24 h**, no en día calendario — así refleja el tier real de Meta y no
depende del timezone del servidor (que corre en UTC). Incluye los envíos en
estado `queued` para no pasarse del tope por la carrera entre el conteo y el
envío real.

### Umbrales de tier

`campaigns.tier_warn_thresholds` = `[250, 1000, 10000, 100000]`. Solo se usan
para **advertir en la UI** antes de lanzar; no se hacen cumplir — Meta decide el
tier real.

---

## 8. Opt-out

Palabras clave (`CAMPAIGNS_OPT_OUT_KEYWORDS`, insensibles a mayúsculas):

```
STOP, BAJA, CANCELAR, NO MAS, NO MÁS, UNSUBSCRIBE
```

Al detectarlas en un mensaje entrante, el sistema:

1. Inserta en `campaign_opt_outs` (único por `tenant_id + phone`).
2. Marca como `opted_out` todas las inscripciones activas o en vuelo de ese
   teléfono y limpia su `next_action_at`.
3. Lo excluye de **todas** las campañas futuras del tenant, vía `AudienceBuilder`.

Es automático y no configurable por campaña: es una protección del número, no
una preferencia.

---

## 9. Ciclo de vida y acciones

```
draft ──lanzar──► scheduled ──(hora)──► sending ──► completed
                                  ▲  │
                          reanudar│  │pausar
                                  │  ▼
                                paused
                                  │
                                  └──cancelar──► cancelled
```

| Acción | Ruta |
|---|---|
| Probar (envío a un número propio) | `POST /campaigns/{id}/test` |
| Lanzar / pausar / reanudar / cancelar | `POST /campaigns/{id}/{accion}` |
| Reintentar fallidos | `POST /campaigns/{id}/retry-failed` |
| Duplicar | `POST /campaigns/{id}/duplicate` |
| Exportar resultados | `GET /campaigns/{id}/export` |

> Las rutas con segmento estático (`/campaigns/create`, `/preview-audience`…)
> están declaradas **antes** del wildcard `{campaign}`, que además lleva
> `whereNumber()`. Sin eso, `/campaigns/create` se interpretaría como un ID.

---

## 10. Métricas

Contadores desnormalizados en `campaigns` para la UI:
`total_recipients`, `sent_count`, `delivered_count`, `read_count`,
`failed_count`, `skipped_count`, `replied_count`.

El detalle real está en `campaign_sends`, con índice `(campaign_id, step_order)`
para el embudo por paso:

```sql
SELECT step_order, status, count(*)
FROM campaign_sends WHERE campaign_id = ?
GROUP BY step_order, status ORDER BY 1, 2;
```

Los estados `delivered` / `read` los alimenta
[`ProcessWhatsAppStatusUpdate`](../app/Jobs/ProcessWhatsAppStatusUpdate.php)
desde los acuses del webhook, cruzando por `wa_message_id`.

---

## 11. Diagnóstico

| Síntoma | Dónde mirar |
|---|---|
| La campaña quedó en `scheduled` y no arranca | ¿Corre el cron de `schedule:run`? |
| Estado `sending` pero no sale nada | Presupuesto de warm-up agotado; revisar `WarmupBudget::usedLast24h()` |
| Todos fallan | Plantilla no aprobada, idioma incorrecto, o número sin credenciales |
| Muchos `skipped` | Opt-outs y duplicados: mirar las estadísticas de la audiencia |
| Destinatarios atascados en `sending` | Jobs perdidos; el tic los recupera a los 15 min. Revisar `queue:failed` |
| Se detuvo la secuencia de alguien | Probablemente respondió (`enrollment_status = 'replied'`) — es el comportamiento correcto |

```bash
php artisan campaigns:tick            # forzar un tic manual
php artisan queue:failed              # jobs de envío fallidos
```

```sql
-- Estado de una campaña
SELECT enrollment_status, count(*) FROM campaign_recipients
WHERE campaign_id = ? GROUP BY 1;

-- Consumo de warm-up del tenant en 24 h
SELECT count(*) FROM campaign_sends
WHERE tenant_id = ? AND (sent_at >= now() - interval '24 hours' OR status = 'queued');
```
