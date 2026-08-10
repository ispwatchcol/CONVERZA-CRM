# Manual de usuario

> **El manual vive en la aplicación, no en este archivo.**

| Dónde | URL | Quién entra |
|---|---|---|
| **Público** | <https://converza-crm.duckdns.org/ayuda> | Cualquiera con el link, sin login |
| **Dentro de la app** | <https://converza-crm.duckdns.org/manual> | Usuarios con sesión (menú lateral → Manual) |

Las dos páginas muestran **exactamente el mismo contenido**: renderizan el mismo
componente Vue.

---

## Dónde se edita

**Fuente única:** [`resources/js/Components/ManualContent.vue`](../resources/js/Components/ManualContent.vue)

Ese componente lo consumen:

- [`Pages/Manual/Index.vue`](../resources/js/Pages/Manual/Index.vue) — versión con sesión, dentro de `AppLayout`
- [`Pages/Manual/Public.vue`](../resources/js/Pages/Manual/Public.vue) — versión pública, con su propio encabezado

Editar el componente actualiza las dos a la vez. **No hay una segunda copia que
mantener.**

```bash
# tras editar el manual
npm run build     # y desplegar como cualquier otro cambio de frontend
```

---

## Por qué este archivo ya no tiene el texto

Antes sí lo tenía, y esa era exactamente la trampa: el mismo manual existía en
tres sitios (este markdown, el componente Vue y una página externa) sin nada que
los mantuviera iguales. En cuanto se corrige una sección en producción, las
copias empiezan a mentir — y una documentación que miente es peor que no tenerla.

La regla del repo es la misma para todo: **un solo lugar donde vive cada cosa**,
igual que `WhatsAppService` es la única puerta hacia Meta y `EventCatalog` la
única fuente de los avisos. Ver [README](README.md).

---

## Qué cubre el manual

20 secciones agrupadas, con buscador y preguntas frecuentes:

| Grupo | Secciones |
|---|---|
| **Empezar** | Bienvenida · Conceptos clave (ventana de 24 h) · Roles y permisos · Puesta en marcha |
| **Operación diaria** | Dashboard · Chat · Contactos · Etiquetas · Respuestas rápidas · Notas de cierre |
| **Alcance y automatización** | Plantillas · Campañas masivas · Avisos automáticos · Bot |
| **Administración** | Staff y Equipos · Métricas · Configuración |
| **Ayuda** | Buenas prácticas anti-baneo · Preguntas frecuentes · Soporte |

Para la documentación **técnica** (arquitectura, integraciones, operación) ver el
[índice de docs](README.md).
