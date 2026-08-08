# Documentación — Converza CRM

Portal de documentación del proyecto. Todo lo que está aquí describe el estado
**real del código** en la rama de trabajo, no un diseño aspiracional.

> Si algo de esta documentación contradice al código, el código gana — y el
> documento debe corregirse en el mismo PR que cambió el comportamiento.

---

## ¿Qué es Converza?

Converza es un **CRM multi-tenant de WhatsApp** construido sobre la WhatsApp
Cloud API de Meta. Cada cliente del SaaS (un ISP) tiene su propio *workspace*
(`tenant`) con sus credenciales de WhatsApp, su equipo, sus contactos y sus
conversaciones, completamente aislados de los demás.

Sobre esa base hay cuatro capas de valor:

| Capa | Qué hace |
|---|---|
| **Bandeja compartida** | Chat multi-agente con asignación, notas internas, presencia y métricas |
| **Avisos automáticos** | Facturas, recordatorios, cortes, bienvenidas, pagos y fallas masivas disparados por datos de ispwatch |
| **Campañas masivas** | Secuencias multi-paso con pacing anti-baneo, opt-out y seguimiento de entrega |
| **Core Brain** | Back-office privado del fundador: cuentas de ISPs, planes, cobros y soporte |

---

## Índice

### Para empezar

| Documento | Para quién | Qué responde |
|---|---|---|
| [Arquitectura](arquitectura.md) | Devs, arquitectos | Cómo está armado, por qué, y cómo fluye un mensaje de punta a punta |
| [Manual de desarrollador](manual-desarrollador.md) | Devs | Cómo levantarlo, dónde tocar, cómo depurar, convenciones del repo |
| [Manual de usuario](manual-usuario.md) | Admins y asesores de un ISP | Cómo operar cada módulo del producto |

### Referencia técnica

| Documento | Qué contiene |
|---|---|
| [Modelo de datos](modelo-de-datos.md) | Diccionario de tablas, relaciones y decisiones de esquema |
| [Integración WhatsApp](integracion-whatsapp.md) | Cloud API, webhook, plantillas, medios, ventana de 24 h, límites |
| [Avisos automáticos](avisos-automaticos.md) | `whatsapp:billing-notify`, `whatsapp:events-notify`, catálogo de eventos |
| [Campañas masivas](campanas.md) | Audiencias, secuencias, warm-up, opt-out, métricas de campaña |
| [Core Brain](core-brain.md) | Cuentas de ISPs, catálogo de planes, cobros del SaaS |
| [Seguridad](seguridad.md) | Aislamiento multi-tenant, roles, secretos, superficie de ataque |

### Operación

| Documento | Qué contiene |
|---|---|
| [Operaciones (runbook)](operaciones.md) | Deploy, monitoreo, incidentes frecuentes y su diagnóstico |
| [Mejoras y deuda técnica](mejoras.md) | Backlog priorizado con hallazgos concretos del código |
| [Glosario](glosario.md) | Vocabulario del dominio (tenant vs. cuenta vs. contacto, etc.) |

### Documentos históricos

| Documento | Estado |
|---|---|
| [core-brain-plan.md](core-brain-plan.md) | Plan de diseño original del Core Brain. Histórico; ver [core-brain.md](core-brain.md) para el estado actual |
| [converza-schemas.sql](converza-schemas.sql) | Creación de los schemas `converza` / `converza_dev` en Postgres |
| [converza-roles-hardening.sql](converza-roles-hardening.sql) | Endurecimiento de roles de base de datos |
| [ispwatch-readonly.sql](ispwatch-readonly.sql) | Rol `converza_reader` con permisos SELECT-only sobre ispwatch |

---

## Rutas rápidas

**"Un mensaje entrante no aparece en el chat"**
→ [Operaciones · Incidentes](operaciones.md#incidentes-frecuentes) → [Integración WhatsApp · Webhook](integracion-whatsapp.md#webhook)

**"Necesito dar de alta un ISP nuevo"**
→ [Manual de desarrollador · Alta de un tenant](manual-desarrollador.md#alta-de-un-tenant-nuevo)

**"Los avisos de factura no salen"**
→ [Avisos automáticos · Diagnóstico](avisos-automaticos.md#diagnóstico-cuando-no-sale-un-aviso)

**"Quiero entender por qué el chat no usa WebSockets"**
→ [Arquitectura · Tiempo real por polling](arquitectura.md#tiempo-real-sin-websockets)

**"¿Qué hay que arreglar en este repo?"**
→ [Mejoras](mejoras.md)

---

## Convención de mantenimiento

- Los documentos usan **enlaces relativos** a archivos del repo
  (`../app/Services/WhatsAppService.php`) para que sean navegables desde el IDE
  y desde GitHub.
- Cada documento arranca con un bloque **"En una frase"** y **"Archivos clave"**.
- Cuando un comportamiento cambia, se actualiza el documento correspondiente en
  el mismo commit. La documentación desactualizada es peor que la ausente.
