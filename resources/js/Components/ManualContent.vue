<script setup>
/**
 * Contenido del manual de usuario. FUENTE ÚNICA: lo consumen tanto
 * /manual (dentro de la app, con sesión) como /ayuda (público, sin login).
 * Editar aquí actualiza los dos a la vez.
 */
import { ref, computed, onMounted, onUnmounted, nextTick } from 'vue';

defineProps({
    /** true = se está viendo en /ayuda, sin sesión: no hay Dashboard al que volver. */
    publicMode: { type: Boolean, default: false },
});

/**
 * Las secciones van agrupadas porque son 20: una lista plana de 20 ítems
 * en el sidebar es imposible de escanear. Los grupos reflejan cómo se usa
 * el producto (primero entenderlo, luego operarlo, luego automatizarlo),
 * no una categoría arbitraria.
 */
const groups = [
    {
        label: 'Empezar',
        items: [
            { id: 'intro',          title: 'Bienvenido a Converza' },
            { id: 'conceptos',      title: 'Conceptos clave' },
            { id: 'roles',          title: 'Roles y permisos' },
            { id: 'primeros-pasos', title: 'Puesta en marcha' },
        ],
    },
    {
        label: 'Operación diaria',
        items: [
            { id: 'dashboard',     title: 'Dashboard' },
            { id: 'chat',          title: 'Chat' },
            { id: 'contactos',     title: 'Contactos' },
            { id: 'etiquetas',     title: 'Etiquetas' },
            { id: 'quick-replies', title: 'Respuestas rápidas' },
            { id: 'closing-notes', title: 'Notas de cierre' },
        ],
    },
    {
        label: 'Alcance y automatización',
        items: [
            { id: 'plantillas', title: 'Plantillas de WhatsApp' },
            { id: 'campanas',   title: 'Campañas masivas' },
            { id: 'avisos',     title: 'Avisos automáticos' },
            { id: 'bot',        title: 'Bot de atención' },
        ],
    },
    {
        label: 'Administración',
        items: [
            { id: 'staff',         title: 'Staff y Equipos' },
            { id: 'metricas',      title: 'Métricas' },
            { id: 'configuracion', title: 'Configuración' },
        ],
    },
    {
        label: 'Ayuda',
        items: [
            { id: 'antibaneo', title: 'Buenas prácticas anti-baneo' },
            { id: 'faq',       title: 'Preguntas frecuentes' },
            { id: 'soporte',   title: 'Soporte' },
        ],
    },
];

const allSections = groups.flatMap(g => g.items);

const activeSection = ref('intro');
const search = ref('');

// El filtro busca en el título de la sección; los grupos que quedan sin
// resultados desaparecen para no dejar encabezados huérfanos.
const filteredGroups = computed(() => {
    const q = search.value.trim().toLowerCase();
    if (!q) return groups;
    return groups
        .map(g => ({ ...g, items: g.items.filter(i => i.title.toLowerCase().includes(q)) }))
        .filter(g => g.items.length > 0);
});

let observer = null;

function scrollToSection(id) {
    const el = document.getElementById(id);
    if (el) {
        el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        activeSection.value = id;
    }
}

onMounted(() => {
    nextTick(() => {
        observer = new IntersectionObserver(
            (entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) activeSection.value = entry.target.id;
                });
            },
            { rootMargin: '-25% 0px -65% 0px', threshold: 0 }
        );
        allSections.forEach((s) => {
            const el = document.getElementById(s.id);
            if (el) observer.observe(el);
        });
    });
});

onUnmounted(() => {
    if (observer) observer.disconnect();
});
</script>

<template>
        <div class="max-w-7xl mx-auto px-4 md:px-6 py-6">

            <!-- ── Header ─────────────────────────────────────────────────── -->
            <div class="mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-accent to-accent-light flex items-center justify-center shadow-md shrink-0">
                        <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                        </svg>
                    </div>
                    <div class="min-w-0">
                        <h1 class="text-2xl font-bold text-gray-900">Manual de Usuario</h1>
                        <p class="text-sm text-gray-500">Guía completa para sacarle el máximo provecho a Converza CRM.</p>
                    </div>
                </div>
            </div>

            <div class="flex gap-6">

                <!-- ── Sidebar de navegación interna ──────────────────────── -->
                <aside class="hidden lg:block w-64 shrink-0">
                    <nav class="sticky top-4 bg-white rounded-xl border border-gray-200 p-3 max-h-[calc(100vh-7rem)] overflow-y-auto">
                        <div class="px-1 pb-3">
                            <div class="relative">
                                <svg class="w-4 h-4 absolute left-2.5 top-1/2 -translate-y-1/2 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-5.197-5.197m0 0A7.5 7.5 0 105.196 5.196a7.5 7.5 0 0010.607 10.607z" />
                                </svg>
                                <input
                                    v-model="search"
                                    type="search"
                                    placeholder="Buscar sección…"
                                    class="w-full pl-8 pr-3 py-1.5 text-sm border border-gray-200 rounded-lg focus:ring-1 focus:ring-accent focus:border-accent"
                                />
                            </div>
                        </div>

                        <div v-for="g in filteredGroups" :key="g.label" class="mb-1">
                            <p class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold px-3 pt-3 pb-1.5">{{ g.label }}</p>
                            <button
                                v-for="s in g.items"
                                :key="s.id"
                                @click="scrollToSection(s.id)"
                                class="w-full flex items-center gap-2.5 px-3 py-1.5 text-sm rounded-lg text-left transition"
                                :class="activeSection === s.id ? 'bg-accent/10 text-accent font-medium' : 'text-gray-600 hover:bg-gray-50'"
                            >
                                <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="activeSection === s.id ? 'bg-accent' : 'bg-gray-300'"></span>
                                <span class="truncate">{{ s.title }}</span>
                            </button>
                        </div>

                        <p v-if="filteredGroups.length === 0" class="px-3 py-4 text-sm text-gray-400">
                            Sin resultados para "{{ search }}".
                        </p>
                    </nav>
                </aside>

                <!-- ── Contenido ──────────────────────────────────────────── -->
                <div class="flex-1 min-w-0 space-y-6">

                    <!-- Selector de secciones (mobile) -->
                    <div class="lg:hidden bg-white rounded-xl border border-gray-200 p-3">
                        <select
                            :value="activeSection"
                            @change="scrollToSection($event.target.value)"
                            class="w-full text-sm border-gray-300 rounded-lg focus:ring-accent focus:border-accent"
                        >
                            <optgroup v-for="g in groups" :key="g.label" :label="g.label">
                                <option v-for="s in g.items" :key="s.id" :value="s.id">{{ s.title }}</option>
                            </optgroup>
                        </select>
                    </div>

                    <!-- ─────── Bienvenido ─────── -->
                    <section id="intro" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Bienvenido a Converza</h2>
                        <p class="text-gray-600 leading-relaxed mb-4">
                            <strong>Converza CRM</strong> centraliza las conversaciones de WhatsApp de tu empresa en una
                            sola bandeja: reparte los chats entre tu equipo, dispara los avisos de facturación solo y
                            mide cómo responde cada asesor.
                        </p>
                        <div class="grid sm:grid-cols-2 lg:grid-cols-4 gap-3">
                            <div class="p-4 rounded-lg bg-accent/5 border border-accent/20">
                                <div class="text-accent font-semibold text-sm mb-1">Centraliza</div>
                                <p class="text-xs text-gray-600">Todas tus conversaciones en una bandeja compartida.</p>
                            </div>
                            <div class="p-4 rounded-lg bg-accent/5 border border-accent/20">
                                <div class="text-accent font-semibold text-sm mb-1">Organiza</div>
                                <p class="text-xs text-gray-600">Etiquetas, equipos, plantillas y respuestas rápidas.</p>
                            </div>
                            <div class="p-4 rounded-lg bg-accent/5 border border-accent/20">
                                <div class="text-accent font-semibold text-sm mb-1">Automatiza</div>
                                <p class="text-xs text-gray-600">Avisos de factura, pago y fallas sin intervención.</p>
                            </div>
                            <div class="p-4 rounded-lg bg-accent/5 border border-accent/20">
                                <div class="text-accent font-semibold text-sm mb-1">Mide</div>
                                <p class="text-xs text-gray-600">Tiempos de respuesta, cierres y carga por asesor.</p>
                            </div>
                        </div>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>¿Primera vez aquí?</strong> Lee <em>Conceptos clave</em> antes que nada: la
                            <em>ventana de 24 horas</em> de WhatsApp explica por qué el producto funciona como funciona.
                        </div>
                    </section>

                    <!-- ─────── Conceptos clave ─────── -->
                    <section id="conceptos" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Conceptos clave</h2>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">La ventana de 24 horas</h3>
                        <div class="p-4 rounded-lg bg-amber-50 border border-amber-200">
                            <p class="text-sm text-amber-900 font-medium mb-2">
                                Puedes enviar mensajes libres solo durante las <strong>24 horas siguientes al último
                                mensaje del cliente</strong>. Fuera de esa ventana, únicamente plantillas aprobadas por Meta.
                            </p>
                            <p class="text-xs text-amber-900/80">
                                Por eso existen las plantillas, las campañas y los avisos automáticos: son la única forma
                                legal de iniciar una conversación. Cada mensaje del cliente reinicia el reloj.
                            </p>
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Contacto, conversación y mensaje</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Contacto:</strong> una persona con un número de WhatsApp. Se crea sola cuando alguien te escribe.</li>
                            <li><strong>Conversación:</strong> el hilo con ese contacto. Está abierta o cerrada.</li>
                            <li><strong>Mensaje:</strong> cada burbuja del hilo (entrante, saliente, nota interna o evento de sistema).</li>
                        </ul>
                        <p class="text-sm text-gray-600 mt-3">
                            Un contacto tiene una conversación activa a la vez. Si el cliente escribe cuando la
                            conversación estaba cerrada, se <strong>reabre la existente</strong> — no se crea un chat duplicado.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Cliente de ispwatch o prospecto</h3>
                        <p class="text-sm text-gray-600">
                            Si tu workspace está vinculado a ispwatch, Converza busca el teléfono del contacto en tu base
                            de clientes. Si <strong>coincide</strong>, el panel derecho del chat muestra estado del
                            servicio, usuario PPPoE, IP, saldo a favor y facturas pendientes. Si <strong>no
                            coincide</strong>, se trata como prospecto y todo lo demás funciona igual.
                        </p>
                    </section>

                    <!-- ─────── Roles ─────── -->
                    <section id="roles" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Roles y permisos</h2>
                        <p class="text-gray-600 mb-4">Hay tres roles dentro de un workspace.</p>

                        <div class="overflow-x-auto -mx-6 px-6">
                            <table class="w-full text-sm min-w-[520px]">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-gray-500">
                                        <th class="py-2 px-3 font-medium rounded-l-lg">Acción</th>
                                        <th class="py-2 px-3 font-medium text-center">Viewer</th>
                                        <th class="py-2 px-3 font-medium text-center">Agente</th>
                                        <th class="py-2 px-3 font-medium text-center rounded-r-lg">Admin</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-gray-600">
                                    <tr><td class="py-2 px-3">Ver conversaciones</td><td class="py-2 px-3 text-center">Todas</td><td class="py-2 px-3 text-center font-medium text-gray-800">Solo las suyas</td><td class="py-2 px-3 text-center">Todas</td></tr>
                                    <tr><td class="py-2 px-3">Enviar mensajes y archivos</td><td class="py-2 px-3 text-center text-gray-400">—</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Asignar y transferir</td><td class="py-2 px-3 text-center text-gray-400">—</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Notas internas</td><td class="py-2 px-3 text-center text-gray-400">—</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Reabrir conversaciones</td><td class="py-2 px-3 text-center text-gray-400">—</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Borrar una conversación</td><td class="py-2 px-3 text-center text-gray-400">—</td><td class="py-2 px-3 text-center text-gray-400">—</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Crear y lanzar campañas</td><td class="py-2 px-3 text-center">Ver</td><td class="py-2 px-3 text-center">Ver</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Gestionar Staff y Equipos</td><td class="py-2 px-3 text-center">Ver</td><td class="py-2 px-3 text-center">Ver</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Configurar el bot</td><td class="py-2 px-3 text-center">Ver</td><td class="py-2 px-3 text-center">Ver</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                    <tr><td class="py-2 px-3">Contactos, Etiquetas, Plantillas</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td><td class="py-2 px-3 text-center text-accent font-bold">Sí</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Lo más importante de esta tabla:</strong> un agente <strong>solo ve las conversaciones
                            que tiene asignadas</strong>. No es un filtro que pueda quitar. Si un asesor dice "no veo el
                            chat de Fulano", casi siempre es porque no está asignado a él.
                        </div>
                    </section>

                    <!-- ─────── Puesta en marcha ─────── -->
                    <section id="primeros-pasos" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Puesta en marcha</h2>
                        <p class="text-gray-600 mb-4">
                            Orden recomendado para un workspace nuevo. Los pasos 1 y 2 son obligatorios; el resto se
                            puede armar sobre la marcha.
                        </p>
                        <ol class="space-y-3">
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">1</span>
                                <div>
                                    <p class="font-medium text-gray-900">Conectar WhatsApp</p>
                                    <p class="text-sm text-gray-600"><em>Configuración → WhatsApp</em>. Ingresa Phone Number ID, Business Account ID, Access Token, App Secret y Verify Token. Pulsa <em>Probar conexión</em>: debe quedar en verde. Sin esto no entra ni sale ningún mensaje.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">2</span>
                                <div>
                                    <p class="font-medium text-gray-900">Crear el equipo</p>
                                    <p class="text-sm text-gray-600">En <em>Staff</em>, da de alta a los asesores con su rol. Empieza con roles altos solo para quien de verdad los necesita.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">3</span>
                                <div>
                                    <p class="font-medium text-gray-900">Agrupar por áreas</p>
                                    <p class="text-sm text-gray-600"><em>Equipos</em> (Ventas, Soporte, Cobranza). Recomendable a partir de tres asesores.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">4</span>
                                <div>
                                    <p class="font-medium text-gray-900">Definir etiquetas</p>
                                    <p class="text-sm text-gray-600">Con qué criterios vas a clasificar contactos. <em>Cargar ejemplos</em> te da un set inicial.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">5</span>
                                <div>
                                    <p class="font-medium text-gray-900">Traer las plantillas</p>
                                    <p class="text-sm text-gray-600"><em>Plantillas → Sincronizar</em>. Baja de Meta todas las plantillas aprobadas.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">6</span>
                                <div>
                                    <p class="font-medium text-gray-900">Cargar respuestas rápidas</p>
                                    <p class="text-sm text-gray-600">Las diez preguntas que más te hacen, resueltas con un clic.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-gray-200 text-gray-600 text-sm font-bold flex items-center justify-center">7</span>
                                <div>
                                    <p class="font-medium text-gray-900">Activar avisos automáticos <span class="text-xs font-normal text-gray-400">— opcional</span></p>
                                    <p class="text-sm text-gray-600"><em>Configuración → Avisos</em>.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-gray-200 text-gray-600 text-sm font-bold flex items-center justify-center">8</span>
                                <div>
                                    <p class="font-medium text-gray-900">Activar el bot y la auto-asignación <span class="text-xs font-normal text-gray-400">— opcional</span></p>
                                    <p class="text-sm text-gray-600">Para que los primeros contactos se atiendan solos y los chats nuevos se repartan entre los asesores.</p>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <!-- ─────── Dashboard ─────── -->
                    <section id="dashboard" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Dashboard</h2>
                        <p class="text-gray-600 mb-4">La pantalla de inicio. Te muestra de un vistazo el estado de tu operación.</p>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Tarjetas:</strong> conversaciones abiertas, clientes esperando respuesta, cerradas hoy y cuentas por cobrar (si estás vinculado a ispwatch).</li>
                            <li><strong>Gráfica de mensajes:</strong> enviados frente a recibidos, últimos 7 días.</li>
                            <li><strong>Necesitan atención:</strong> conversaciones con mayor tiempo de espera. Es la lista por la que deberías empezar el día.</li>
                            <li><strong>Conversaciones recientes:</strong> clic en cualquiera para abrirla en el Chat.</li>
                            <li><strong>Estado del sistema:</strong> avisa si WhatsApp no está configurado o hay problemas de conexión.</li>
                        </ul>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Cómo se cuenta:</strong> "clientes esperando" son las conversaciones cuyo último mensaje
                            real es del cliente. Las notas internas y los eventos de sistema no cuentan como respuesta:
                            escribir una nota no baja el contador.
                        </div>
                    </section>

                    <!-- ─────── Chat ─────── -->
                    <section id="chat" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Chat</h2>
                        <p class="text-gray-600 mb-4">
                            El corazón de Converza. Tres columnas: lista de conversaciones, hilo y ficha del contacto.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Filtros de la lista</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Todas</strong> · <strong>Abiertas</strong> · <strong>Cerradas</strong></li>
                            <li><strong>Mías:</strong> asignadas a ti y abiertas.</li>
                            <li><strong>Sin asignar:</strong> abiertas sin dueño — <em>la cola que hay que vaciar</em>.</li>
                            <li><strong>No leídas:</strong> con mensajes entrantes que tú no has leído.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Cómo funcionan los no leídos</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            El badge verde es <strong>por asesor</strong>: cada uno tiene su propio contador, y que tu
                            compañero abra un chat no baja el tuyo.
                        </p>
                        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Excepción importante:</strong> cuando un asesor <strong>responde</strong> a un cliente,
                            la conversación se marca como leída para todo el equipo — alguien ya la atendió. Y las
                            reacciones del cliente (👍) no vuelven a marcarla como no leída: un emoji sobre un mensaje ya
                            contestado no pide respuesta.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">El hilo</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Separadores de día</strong> e indicador flotante de fecha al hacer scroll.</li>
                            <li><strong>Scroll inteligente:</strong> si estás leyendo mensajes viejos, uno nuevo no te arrastra al final.</li>
                            <li><strong>Enlaces clicables</strong> y ubicaciones con enlace directo a Google Maps.</li>
                            <li><strong>Tipos especiales:</strong> pedidos del catálogo, respuestas a botones, reacciones y mensajes que WhatsApp no permite mostrar (encuestas, mensajes que se autodestruyen, códigos de verificación) aparecen con un texto explicativo.</li>
                            <li><strong>Estados:</strong> enviado → entregado → leído. Si falla, se marca como fallido.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Enviar mensajes</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Texto:</strong> al cliente le llega precedido de tu nombre, para que sepa con quién habla. En el CRM lo ves limpio.</li>
                            <li><strong>Archivos:</strong> clic en el clip, <strong>pegar</strong> una imagen del portapapeles (ideal para capturas) o <strong>arrastrar y soltar</strong>. Imágenes, audio, video y documentos (PDF, Word, Excel, PowerPoint, TXT, CSV), hasta <strong>16 MB</strong>, con pie de foto opcional.</li>
                            <li><strong>Notas de voz:</strong> verás un medidor de nivel en vivo. Si la barra no se mueve, tu micrófono no está capturando y la nota saldría muda — el sistema te lo impide antes de enviar.</li>
                            <li><strong>Escuchar audios:</strong> barra de progreso navegable y control de velocidad (1× / 1.5× / 2×).</li>
                            <li><strong>Ver imágenes y documentos:</strong> clic para abrir el visor, navegación entre imágenes con flechas y descarga con el nombre original.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Notas internas</h3>
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            El interruptor <strong>Nota</strong> cambia el modo del campo de texto. Una nota interna
                            <strong>nunca se envía al cliente</strong>: queda en el hilo, visible solo para tu equipo.
                            Además no reordena la lista ni cambia el "último mensaje", así que no ensucia la bandeja.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Asignar y transferir</h3>
                        <p class="text-sm text-gray-600 mb-2">Al transferir ocurren tres cosas:</p>
                        <ol class="space-y-1 text-sm text-gray-600 list-decimal list-inside">
                            <li>Queda un evento en el hilo ("Ana transfirió la conversación de Luis a Marta").</li>
                            <li>El <strong>cliente recibe un aviso</strong> de la transferencia.</li>
                            <li>Si el bot estaba activo, <strong>se apaga</strong>.</li>
                        </ol>
                        <p class="text-sm text-gray-600 mt-3">
                            Con la <strong>auto-asignación</strong> activada, los chats nuevos van al agente con menos
                            conversaciones abiertas.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Presencia y colisión</h3>
                        <p class="text-sm text-gray-600">
                            Cuando otro asesor está viendo la misma conversación que tú, aparece un aviso con su nombre —
                            evita dos respuestas simultáneas al mismo cliente. En el selector de asesores, un punto indica
                            quién está en línea ahora.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Panel de ispwatch</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            Si el contacto es cliente tuyo en ispwatch, la columna derecha muestra estado del servicio,
                            usuario PPPoE, IP asignada, saldo a favor y facturas pendientes con su vencimiento (las
                            vencidas en rojo).
                        </p>
                        <div class="p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>¿Acabas de registrar un pago?</strong> Estos datos se refrescan cada 60 segundos.
                            Espera un minuto antes de preocuparte.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Clientes que ocultan su número</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            WhatsApp ya permite que una persona te escriba con un <strong>nombre de usuario</strong> en
                            vez de su número. En esos chats verás la etiqueta
                            <span class="px-1.5 py-0.5 rounded bg-sky-100 text-sky-700 text-xs font-medium">Ocultó su número en WhatsApp</span>
                            y, en lugar del teléfono, su usuario (por ejemplo <code>@FGChitiva</code>).
                        </p>
                        <p class="text-sm text-gray-600 mb-2">
                            Le puedes responder con normalidad: escribir, mandar fotos, audios y archivos funciona igual.
                            Lo único que cambia es que <strong>no se puede cruzar con ispwatch</strong>, porque ese cruce
                            se hace por teléfono.
                        </p>
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Ojo:</strong> que no aparezca el panel de ispwatch <em>no</em> significa que no sea
                            cliente tuyo. Solo significa que no tenemos su número para buscarlo. Si necesitas
                            identificarlo, pídele la cédula o el nombre del titular por el chat.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Cerrar y reabrir</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            Al cerrar se pide una <strong>nota de cierre</strong>. Con la conversación cerrada no se puede
                            enviar nada: hay que reabrirla primero. Si el cliente escribe, se reabre sola.
                        </p>
                        <p class="text-sm text-gray-600 mb-2">
                            Cada contacto tiene <strong>un solo chat</strong>. Cerrar no archiva ni parte el historial: el
                            mismo hilo se reabre con todo lo anterior, escriba el cliente o le escribas tú, y también
                            cuando le llega un aviso automático o una campaña.
                        </p>
                        <div class="p-3 bg-sky-50 border border-sky-200 rounded-lg text-sm text-sky-900 mb-2">
                            <strong>Cierre automático (opcional):</strong> si el admin lo activa en
                            <em>Configuración → Cierre automático</em>, los chats donde ya respondiste y el cliente no
                            volvió a escribir se cierran solos pasadas las horas que elijas (2 por defecto). Nunca se
                            cierra un chat cuyo último mensaje sea del cliente —ese sigue esperando respuesta— y el
                            cliente no recibe ningún mensaje: el cierre es silencioso y queda anotado en el chat.
                        </div>
                        <div class="p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-900">
                            <strong>Irreversible:</strong> <em>Eliminar</em> (solo admin) borra la conversación y todos sus
                            mensajes sin papelera. Cerrar es casi siempre lo correcto; eliminar es para limpiar pruebas o spam.
                        </div>
                    </section>

                    <!-- ─────── Contactos ─────── -->
                    <section id="contactos" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Contactos</h2>
                        <p class="text-gray-600 mb-4">
                            Tu base de clientes y prospectos. <strong>Se crean solos</strong> cuando alguien te escribe por
                            primera vez; también puedes crearlos a mano.
                        </p>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Editar:</strong> nombre, correo y notas.</li>
                            <li><strong>Asignar etiquetas</strong> para clasificar (cliente, prospecto, moroso…).</li>
                            <li><strong>Abrir chat</strong> directo desde la fila.</li>
                            <li><strong>Enlace / QR de WhatsApp</strong> para compartir el contacto.</li>
                            <li><strong>Eliminar:</strong> <span class="text-red-600">acción irreversible</span>.</li>
                            <li><strong>Buscar y filtrar</strong> por nombre, teléfono o etiqueta.</li>
                        </ul>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Formato de teléfonos:</strong> Converza normaliza los números. Un móvil colombiano de
                            10 dígitos que empieza en 3 recibe automáticamente el prefijo 57, así el contacto que creas a
                            mano y el que crea WhatsApp son el mismo, sin duplicados.
                        </div>
                    </section>

                    <!-- ─────── Etiquetas ─────── -->
                    <section id="etiquetas" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Etiquetas</h2>
                        <p class="text-gray-600 mb-4">
                            Clasificación transversal, con color. Se usan para filtrar en Contactos, asignar desde el chat,
                            <strong>segmentar audiencias de campañas</strong> (el uso más potente) y analizar en Métricas.
                        </p>
                        <ol class="space-y-2 text-sm text-gray-600 list-decimal list-inside">
                            <li>Haz clic en <em>Nueva etiqueta</em>.</li>
                            <li>Ingresa el nombre (ej. <em>VIP</em>, <em>Moroso</em>, <em>Prospecto</em>).</li>
                            <li>Elige un color para identificarla de un vistazo.</li>
                            <li>Guarda. Queda disponible para asignarse a contactos.</li>
                        </ol>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Consejo:</strong> menos etiquetas y bien definidas es mejor que muchas ambiguas. Un buen
                            set inicial: Cliente, Prospecto, Moroso, VIP, No contactar. Si tu workspace es nuevo, usa
                            <em>Cargar ejemplos</em>.
                        </div>
                    </section>

                    <!-- ─────── Respuestas rápidas ─────── -->
                    <section id="quick-replies" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Respuestas rápidas</h2>
                        <p class="text-gray-600 mb-4">
                            Mensajes pre-escritos que puedes enviar en segundos durante una conversación. Ideales para
                            preguntas frecuentes.
                        </p>
                        <ol class="space-y-2 text-sm text-gray-600 list-decimal list-inside">
                            <li>Define un <strong>atajo</strong> (ej. <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">/horarios</code>).</li>
                            <li>Escribe el <strong>mensaje</strong> que se enviará al cliente.</li>
                            <li>Guarda. En el Chat aparece con el botón de rayo o escribiendo <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">/</code>.</li>
                        </ol>
                        <p class="text-sm text-gray-600 mt-3">
                            También puedes <strong>duplicarlas</strong> para crear variantes, <strong>activarlas o
                            desactivarlas</strong> sin borrarlas, y <strong>cargar ejemplos</strong> para empezar más rápido.
                        </p>
                        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Diferencia con las plantillas:</strong> las respuestas rápidas son <strong>texto
                            libre</strong> y solo funcionan <strong>dentro</strong> de la ventana de 24 horas. No requieren
                            aprobación de Meta. Las plantillas sí, y funcionan siempre.
                        </div>
                    </section>

                    <!-- ─────── Notas de cierre ─────── -->
                    <section id="closing-notes" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Notas de cierre</h2>
                        <p class="text-gray-600 mb-4">
                            Cada conversación que cierras lleva una nota que explica cómo terminó: venta concretada,
                            cliente sin interés, soporte resuelto, etc.
                        </p>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Trazabilidad:</strong> sabes el motivo de cierre de cada chat.</li>
                            <li><strong>Métricas:</strong> alimentan el reporte de cierres por asesor.</li>
                            <li><strong>Reapertura:</strong> puedes reabrir un chat cerrado si el cliente vuelve.</li>
                        </ul>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Consejo:</strong> acuerda con tu equipo una lista corta y fija de motivos ("Venta
                            cerrada", "Sin interés", "Soporte resuelto", "No contesta"). Si cada uno escribe lo que quiere,
                            la métrica no sirve para nada.
                        </div>
                    </section>

                    <!-- ─────── Plantillas ─────── -->
                    <section id="plantillas" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Plantillas de WhatsApp</h2>
                        <p class="text-gray-600 mb-4">
                            Mensajes <strong>pre-aprobados por Meta</strong>. Son la única forma de escribirle a alguien
                            fuera de la ventana de 24 horas.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Sincronizar</h3>
                        <p class="text-sm text-gray-600">
                            El botón <em>Sincronizar</em> consulta tu cuenta de WhatsApp Business y trae todas las
                            plantillas con su contenido, idioma y estado. Hazlo cada vez que crees o modifiques plantillas
                            en Meta. También puedes redactarlas aquí y enviarlas a aprobación.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Estados</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Aprobada:</strong> lista para usar.</li>
                            <li><strong>Pendiente:</strong> Meta la está revisando (minutos a horas).</li>
                            <li><strong>Rechazada:</strong> hay que corregirla y reenviarla.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Categorías</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Utility:</strong> facturas, recordatorios, confirmaciones. Relacionadas con una transacción existente.</li>
                            <li><strong>Marketing:</strong> promociones y novedades. <strong>Obligatoria para campañas masivas</strong>.</li>
                            <li><strong>Authentication:</strong> códigos de verificación. Solo para eso.</li>
                        </ul>
                        <div class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            Usar una plantilla <em>utility</em> para promoción viola la política de Meta y pone en riesgo tu
                            número. Por eso las campañas de Converza exigen categoría <em>marketing</em>.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Variables</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            Las plantillas llevan huecos que se rellenan al enviar. Converza usa variables con nombre:
                        </p>
                        <pre v-pre class="bg-gray-100 rounded-lg p-3 text-xs text-gray-700 overflow-x-auto">Hola {{nombre_cliente}}, tu factura {{numero_factura}} por {{monto}}
vence el {{fecha_vencimiento}}.</pre>
                        <p class="text-sm text-gray-600 mt-2">
                            El editor te muestra la <strong>paleta de variables disponibles</strong> según el propósito que
                            elijas, con descripción y ejemplo de cada una. No tienes que memorizar nada ni recordar el
                            orden: escribes el nombre y listo.
                        </p>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Tip:</strong> activa o desactiva plantillas con el interruptor para que aparezcan u
                            oculten en los selectores sin tener que borrarlas.
                        </div>
                    </section>

                    <!-- ─────── Campañas ─────── -->
                    <section id="campanas" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Campañas masivas</h2>
                        <p class="text-gray-600 mb-4">
                            Envío masivo de plantillas a una lista, con seguimiento y protección del número. Solo los
                            <strong>administradores</strong> pueden crear, lanzar, pausar o cancelar campañas.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Crear una campaña</h3>
                        <ol class="space-y-3">
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">1</span>
                                <div>
                                    <p class="font-medium text-gray-900">Audiencia</p>
                                    <p class="text-sm text-gray-600">Cuatro orígenes: Excel/CSV, contactos del CRM filtrados por etiqueta, lista pegada a mano o Google Sheets. Converza te muestra cuántos quedan y cuántos se descartan, con el motivo (número inválido, duplicado, baja previa).</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">2</span>
                                <div>
                                    <p class="font-medium text-gray-900">Mensaje</p>
                                    <p class="text-sm text-gray-600">Eliges una plantilla marketing aprobada y mapeas cada variable a una columna de tu archivo o a un valor fijo. Hay vista previa con datos reales.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">3</span>
                                <div>
                                    <p class="font-medium text-gray-900">Secuencia <span class="text-xs font-normal text-gray-400">— opcional</span></p>
                                    <p class="text-sm text-gray-600">El paso 1 siempre se envía; los siguientes son seguimientos, cada uno con su plantilla, su espera (días y horas) y su condición, típicamente "si no respondió".</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">4</span>
                                <div>
                                    <p class="font-medium text-gray-900">Ritmo y programación</p>
                                    <p class="text-sm text-gray-600">Mensajes por minuto (20 por defecto, deliberadamente conservador) y fecha de lanzamiento.</p>
                                </div>
                            </li>
                        </ol>

                        <div class="mt-4 p-3 bg-emerald-50 border border-emerald-200 rounded-lg text-sm text-emerald-700">
                            <strong>La respuesta detiene la secuencia.</strong> Si el contacto responde en cualquier
                            momento, deja de recibir seguimientos. Eso es una conversión: pasa a ser una conversación
                            normal en el Chat, no un destinatario al que seguir persiguiendo.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Lanzar y controlar</h3>
                        <p class="text-sm text-gray-600">
                            Una campaña pasa por <em>borrador → programada → enviando → completada</em>. En cualquier
                            momento puedes pausar, reanudar o cancelar. Antes de lanzar en serio, usa
                            <strong>Probar</strong>: envía la campaña a un número tuyo para ver exactamente cómo llega.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Seguimiento</h3>
                        <p class="text-sm text-gray-600">
                            La ficha muestra el embudo <strong>enviados → entregados → leídos → respondidos</strong>, más
                            fallidos y omitidos, con desglose por paso. Puedes exportar los resultados, reintentar los
                            fallidos y duplicar la campaña.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Bajas automáticas</h3>
                        <p class="text-sm text-gray-600">
                            Si un contacto responde <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">STOP</code>,
                            <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">BAJA</code>,
                            <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">CANCELAR</code> o similares, Converza lo
                            agrega a la lista de bajas, detiene su secuencia y lo <strong>excluye de todas las campañas
                            futuras</strong>. No tienes que hacer nada, y no puedes saltártelo: es una protección del número.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Calentamiento del número</h3>
                        <p class="text-sm text-gray-600">
                            Un número nuevo que envía 5.000 mensajes el primer día se quema. El calentamiento es una rampa
                            de volumen diario: empieza bajo, sube cada día y tiene un techo. Está <strong>apagado por
                            defecto</strong>, porque activarlo limita el volumen y esa decisión es tuya.
                        </p>
                    </section>

                    <!-- ─────── Avisos automáticos ─────── -->
                    <section id="avisos" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Avisos automáticos</h2>
                        <p class="text-gray-600 mb-4">
                            Converza vigila tu base de ispwatch y envía mensajes de WhatsApp solos cuando ocurren ciertos
                            eventos. Es la funcionalidad que más trabajo manual ahorra.
                            <span class="text-gray-500">Requiere que tu workspace esté vinculado a ispwatch.</span>
                        </p>

                        <div class="overflow-x-auto -mx-6 px-6">
                            <table class="w-full text-sm min-w-[480px]">
                                <thead class="bg-gray-50">
                                    <tr class="text-left text-gray-500">
                                        <th class="py-2 px-3 font-medium rounded-l-lg">Evento</th>
                                        <th class="py-2 px-3 font-medium rounded-r-lg">Cuándo se dispara</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 text-gray-600">
                                    <tr><td class="py-2 px-3 font-medium text-gray-800">Factura generada</td><td class="py-2 px-3">El día que tu router genera la factura del ciclo. Solo a quien queda debiendo algo</td></tr>
                                    <tr><td class="py-2 px-3 font-medium text-gray-800">Recordatorio de pago</td><td class="py-2 px-3">El día de recordatorio configurado en el router</td></tr>
                                    <tr><td class="py-2 px-3 font-medium text-gray-800">Aviso de suspensión</td><td class="py-2 px-3">El día de corte, a quien tenga saldo pendiente</td></tr>
                                    <tr><td class="py-2 px-3 font-medium text-gray-800">Bienvenida</td><td class="py-2 px-3">Al activarse el servicio de un cliente nuevo</td></tr>
                                    <tr><td class="py-2 px-3 font-medium text-gray-800">Pago registrado</td><td class="py-2 px-3">Al registrarse un pago en ispwatch</td></tr>
                                    <tr><td class="py-2 px-3 font-medium text-gray-800">Falla masiva — inicio</td><td class="py-2 px-3">Cuando cae un core o router: avisa a todos sus clientes activos</td></tr>
                                    <tr><td class="py-2 px-3 font-medium text-gray-800">Falla masiva — restablecido</td><td class="py-2 px-3">Cuando ese router vuelve a estar operativo</td></tr>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Las fechas no se configuran aquí:</strong> se leen de la configuración de facturación de
                            tu router en ispwatch. Converza respeta lo que ya tenías.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Cómo activarlos</h3>
                        <p class="text-sm text-gray-600">
                            En <em>Configuración → Avisos</em>, para cada evento: elige la plantilla y enciende el
                            interruptor. <strong>Todos están apagados por defecto</strong>, y un aviso sin plantilla
                            asignada no envía nada. La bitácora de <em>Notificaciones</em> muestra todo lo enviado: a
                            quién, cuándo, con qué plantilla y con qué resultado — es el lugar al que ir cuando un cliente
                            dice "no me llegó".
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Variables de deuda acumulada</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            Además de las obvias, los tres avisos de facturación incluyen variables opcionales para hablar
                            de la mora arrastrada: <strong>saldo total</strong> (todas las facturas pendientes),
                            <strong>saldo anterior</strong> (solo meses previos), <strong>facturas pendientes</strong>,
                            <strong>detalle de pendientes</strong> y <strong>saldo a favor</strong>.
                        </p>
                        <div class="p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Sobre los montos:</strong> la variable <em>monto</em> es lo que el cliente
                            <strong>debe pagar</strong>, ya descontado su saldo a favor — no el total bruto facturado.
                            Cobrarle el bruto es cobrarle algo ya saldado. Si necesitas el bruto, usa <em>monto total</em>.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Salvaguardas</h3>
                        <p class="text-sm text-gray-600 mb-2">El sistema está diseñado para no inundar a nadie:</p>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Sin duplicados:</strong> un cliente no recibe dos veces el mismo aviso del mismo ciclo.</li>
                            <li><strong>Recuperación:</strong> si el sistema estuvo caído el día del aviso, lo reintenta hasta 2 días sin cruzar al ciclo siguiente.</li>
                            <li><strong>Ritmo:</strong> los avisos salen espaciados, nunca en ráfaga.</li>
                            <li><strong>Frescura:</strong> no se envían avisos por hechos de hace más de 2 días (6 horas para fallas de router). Nadie recibe una bienvenida rancia.</li>
                            <li><strong>Carga masiva:</strong> si importas 500 clientes de golpe, no se envían 500 bienvenidas. Solo las altas manuales las reciben.</li>
                            <li><strong>Arranque en frío:</strong> al activar un evento por primera vez no se dispara el histórico.</li>
                        </ul>
                    </section>

                    <!-- ─────── Bot ─────── -->
                    <section id="bot" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Bot de atención</h2>
                        <p class="text-gray-600 mb-4">
                            Un bot de palabras clave que atiende el primer contacto y escala a un humano. Se configura en
                            <em>Configuración → Bot</em>.
                        </p>

                        <pre class="bg-gray-100 rounded-lg p-4 text-xs text-gray-700 overflow-x-auto leading-relaxed">Cliente escribe por primera vez
      ↓
  Saludo con menú (1-5)
      ↓
 ¿Se entiende la intención?
   ├─ Sí  → mensaje de esa rama + pregunta de calificación
   │         ↓
   │      pide nº de suscriptores → pide nombre (si no lo tiene)
   │         ↓
   │      mensaje de traspaso → se apaga → asigna asesor
   │
   ├─ No (1ª vez) → "no entendí, ¿me repites?"
   └─ No (2ª vez) → traspaso a un asesor</pre>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Qué configuras</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>El interruptor.</strong> Está también en la tarjeta <em>Bot de respuestas automáticas</em> de Configuración, para apagarlo rápido sin entrar al detalle.</li>
                            <li><strong>El horario de atención.</strong> Días y franja en los que el bot responde. Puedes invertirlo: que el bot cubra <em>solo fuera</em> del horario, cuando no hay nadie en la bandeja.</li>
                            <li><strong>Los pasos del flujo.</strong> Cada paso tiene su switch. Apagar uno hace que el bot lo salte y siga con el siguiente.</li>
                            <li><strong>El texto de cada mensaje:</strong> el saludo con el menú, una respuesta por rama, las dos preguntas de calificación, el traspaso y los dos de "no entendí".</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Reglas que conviene conocer</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li>El bot <strong>solo actúa en conversaciones sin asignar</strong>. En cuanto un asesor toma el chat, se apaga.</li>
                            <li>El cliente puede responder con un número, el emoji o palabras sueltas.</li>
                            <li>El bot <strong>captura el nombre</strong> del cliente y lo guarda en su ficha.</li>
                            <li>Si el cliente pide hablar con una persona, el traspaso es inmediato.</li>
                            <li>Si apagas el bot (o se cierra el horario) mientras atiende a alguien, esa conversación <strong>recibe el mensaje de traspaso</strong> y queda libre para un asesor. Nadie se queda esperando.</li>
                            <li>La etiqueta <em>"Activo — fuera de horario"</em> significa que el bot está encendido pero ahora mismo no responde por la franja configurada.</li>
                            <li>Ojo: las respuestas de las ramas <strong>terminan preguntando por el nº de suscriptores</strong>. Si apagas ese paso, quita la pregunta de esos textos.</li>
                        </ul>
                    </section>

                    <!-- ─────── Staff y equipos ─────── -->
                    <section id="staff" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Staff y Equipos</h2>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Staff</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            Los miembros de tu workspace que pueden iniciar sesión y atender conversaciones. Solo un
                            <strong>admin</strong> puede gestionarlos.
                        </p>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Crear directamente:</strong> nombre, correo y contraseña inicial.</li>
                            <li><strong>Invitar por email:</strong> el asesor termina su registro desde el correo.</li>
                            <li><strong>Editar:</strong> nombre, rol y equipo asignado.</li>
                            <li><strong>Activar/desactivar:</strong> bloquea el acceso sin eliminar la cuenta.</li>
                            <li><strong>Eliminar:</strong> remueve el asesor del workspace.</li>
                        </ul>
                        <div class="mt-3 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Al desactivar a un asesor</strong>, sus conversaciones abiertas quedan disponibles para
                            reasignarse. Revísalas: no se reparten solas.
                        </div>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Equipos</h3>
                        <p class="text-sm text-gray-600">
                            Agrupan a tus asesores por función (Ventas, Soporte, Cobranza) y facilitan la distribución de
                            conversaciones. Cada equipo tiene nombre, color y descripción; puedes agregar o quitar miembros
                            desde su vista, o usar <em>Cargar ejemplos</em> para empezar con equipos básicos.
                        </p>
                    </section>

                    <!-- ─────── Métricas ─────── -->
                    <section id="metricas" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Métricas</h2>
                        <p class="text-gray-600 mb-4">Reportes sobre datos reales de los últimos 30 días.</p>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Resumen:</strong> conversaciones, contactos, mensajes y tasas.</li>
                            <li><strong>Tiempo de respuesta:</strong> promedio, más rápido, más lento y porcentaje de mensajes respondidos.</li>
                            <li><strong>Mensajes por día:</strong> enviados frente a recibidos.</li>
                            <li><strong>Crecimiento de contactos:</strong> nuevos contactos por semana, 12 semanas.</li>
                            <li><strong>Mensajes por hora:</strong> distribución de 0 a 23 h — con qué horario cubrir el turno.</li>
                            <li><strong>Mensajes por tipo:</strong> texto, imagen, audio, documento.</li>
                            <li><strong>Top contactos:</strong> quiénes más escriben.</li>
                            <li><strong>Por asesor:</strong> mensajes enviados, chats cerrados, abiertos asignados y tiempo de respuesta individual.</li>
                        </ul>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Cómo se calcula el tiempo de respuesta:</strong> por cada mensaje recibido se busca la
                            siguiente respuesta en esa conversación y se mide la diferencia. Se descartan los recibidos que
                            aún no tienen respuesta y las diferencias mayores a 7 días, que distorsionarían el promedio.
                        </div>
                    </section>

                    <!-- ─────── Configuración ─────── -->
                    <section id="configuracion" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Configuración</h2>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Perfil del workspace</h3>
                        <p class="text-sm text-gray-600">
                            Nombre, identificador y estado. El nombre aparece en los avisos automáticos como la variable
                            <em>empresa</em>.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">WhatsApp API</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Phone Number ID:</strong> ID del número que registraste en Meta.</li>
                            <li><strong>Business Account ID:</strong> ID de la cuenta de WhatsApp Business.</li>
                            <li><strong>Verify Token:</strong> token que usa el webhook para verificarse contra Meta.</li>
                            <li><strong>Access Token:</strong> token de acceso permanente de la app de Meta.</li>
                            <li><strong>App Secret:</strong> secreto de la app.</li>
                            <li><strong>Probar conexión:</strong> valida las credenciales sin enviar mensajes.</li>
                        </ul>
                        <p class="text-sm text-gray-600 mt-2">
                            Los tokens se guardan cifrados. Aquí también encuentras la <strong>URL del webhook</strong> que
                            hay que registrar en Meta.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Salud del número</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Calificación de calidad:</strong> verde, amarillo o rojo. Rojo es antesala de restricción.</li>
                            <li><strong>Nivel de mensajería:</strong> a cuántos destinatarios únicos puedes escribir cada 24 h (250 / 1.000 / 10.000 / 100.000+).</li>
                            <li><strong>Nombre verificado:</strong> cómo te ve el cliente.</li>
                        </ul>
                        <p class="text-sm text-gray-600 mt-2">Revísalo <strong>antes</strong> de lanzar una campaña grande.</p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Avisos, asignación y bot</h3>
                        <p class="text-sm text-gray-600">
                            Asignación de plantilla y encendido de cada evento automático; interruptor de auto-asignación
                            al agente menos ocupado; y los mensajes del bot.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Cierre automático</h3>
                        <p class="text-sm text-gray-600">
                            Solo admin. Cierra los chats donde el equipo ya respondió y el cliente no volvió a escribir
                            en las horas que definas (2 por defecto). Los chats con el último mensaje del cliente nunca
                            se cierran solos, y al cliente no le llega ningún mensaje.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-5 mb-2">Integración ispwatch</h3>
                        <p class="text-sm text-gray-600">
                            Informativa, no editable. Muestra si tu workspace está vinculado y el estado de la conexión
                            (clientes, facturas, pagos). La vinculación la realiza el equipo de Converza.
                        </p>

                        <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-900">
                            <strong>Advertencia:</strong> nunca compartas tus tokens de WhatsApp con terceros. Si sospechas
                            que se filtraron, regenéralos en Meta Business Manager y actualízalos aquí de inmediato.
                        </div>
                    </section>

                    <!-- ─────── Anti-baneo ─────── -->
                    <section id="antibaneo" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Buenas prácticas anti-baneo</h2>
                        <p class="text-gray-600 mb-4">
                            Tu número de WhatsApp Business es un activo frágil. Meta puede restringirlo o banearlo, y
                            recuperarlo es lento o imposible.
                        </p>

                        <div class="grid md:grid-cols-2 gap-4">
                            <div class="p-4 rounded-lg bg-red-50 border border-red-200">
                                <div class="text-red-900 font-semibold text-sm mb-2">Lo que te quema el número</div>
                                <ul class="space-y-1.5 text-xs text-red-900/90 list-disc list-inside">
                                    <li>Enviar en frío a listas compradas o sin consentimiento</li>
                                    <li>Que la gente te reporte o bloquee</li>
                                    <li>Ráfagas grandes desde un número nuevo</li>
                                    <li>Usar plantillas <em>utility</em> para promoción</li>
                                    <li>Ignorar las bajas</li>
                                </ul>
                            </div>
                            <div class="p-4 rounded-lg bg-emerald-50 border border-emerald-200">
                                <div class="text-emerald-700 font-semibold text-sm mb-2">Lo que te protege</div>
                                <ul class="space-y-1.5 text-xs text-gray-600 list-disc list-inside">
                                    <li>Envía solo a quien te dio su número</li>
                                    <li>Activa el calentamiento con números nuevos</li>
                                    <li>Respeta el ritmo por defecto (20/min)</li>
                                    <li>Marketing para promoción, utility para transacciones</li>
                                    <li>Vigila la calidad en <em>Salud del número</em></li>
                                    <li>Usa <em>Probar</em> antes de lanzar</li>
                                    <li>Responde rápido: un número que conversa tiene mejor reputación que uno que solo emite</li>
                                </ul>
                            </div>
                        </div>

                        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Si tu calidad baja a amarillo o rojo:</strong> para las campañas de inmediato, revisa
                            qué mensaje generó reportes y deja el número descansar unos días atendiendo solo conversaciones
                            entrantes.
                        </div>
                    </section>

                    <!-- ─────── FAQ ─────── -->
                    <section id="faq" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Preguntas frecuentes</h2>

                        <div class="divide-y divide-gray-100">
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>No puedo enviar un mensaje: dice que la conversación está cerrada</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Pulsa <em>Reabrir</em> en la cabecera del chat.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>No veo un chat que sé que existe</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Si eres agente, solo ves las conversaciones asignadas a ti. Pide a un administrador que te la asigne.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>El cliente no recibió mi mensaje</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Mira el estado de la burbuja. Si es <em>fallido</em>, lo más probable es que estés fuera de la ventana de 24 horas: usa una plantilla. Verifica también que WhatsApp esté en verde en el menú lateral.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>Registré un pago en ispwatch pero el chat sigue mostrando la deuda</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Espera un minuto: los datos de ispwatch se refrescan cada 60 segundos.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>No suena la alerta de mensaje nuevo</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Haz clic una vez en cualquier parte de la página. Los navegadores bloquean el audio hasta que el usuario interactúa.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>¿Mi nota interna se envió al cliente?</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">No es posible: las notas nunca salen. Si el cliente lo recibió, el interruptor <em>Nota</em> estaba apagado al escribir.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>¿Puedo enviar a alguien que nunca me escribió?</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Sí, pero solo con una plantilla aprobada, individual o por campaña.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>Un contacto aparece dos veces</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Suele ser el mismo número escrito distinto. Converza normaliza los móviles colombianos, pero un número extranjero mal escrito puede duplicarse. Corrígelo en Contactos.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>¿Puedo recuperar una conversación eliminada?</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">No. El borrado es definitivo y sin papelera. Para casi todo, cerrar es la opción correcta.</p>
                            </details>
                            <details class="py-3 group">
                                <summary class="cursor-pointer text-sm font-medium text-gray-800 hover:text-accent transition list-none flex items-center justify-between gap-3">
                                    <span>¿Puedo tener dos números de WhatsApp?</span>
                                    <span class="text-gray-400 group-open:rotate-45 transition-transform text-lg leading-none">+</span>
                                </summary>
                                <p class="text-sm text-gray-600 mt-2">Un workspace maneja un número. Para varios se requieren workspaces separados: consúltalo con el equipo de Converza.</p>
                            </details>
                        </div>
                    </section>

                    <!-- ─────── Soporte ─────── -->
                    <section id="soporte" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Soporte</h2>
                        <p class="text-gray-600 mb-4">
                            ¿Necesitas ayuda? Usa el botón verde flotante de WhatsApp en la esquina inferior derecha. Al
                            hacer clic se abre WhatsApp con un mensaje pre-armado que incluye tu nombre y el de tu
                            workspace, para que el equipo de Converza te identifique al toque.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Antes de escribir</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li>Revisa este manual: probablemente tu pregunta ya está respondida.</li>
                            <li>Verifica el indicador de estado de WhatsApp en el menú lateral (verde = conectado).</li>
                            <li>Si reportas un error, incluye: <strong>qué hacías</strong>, <strong>qué esperabas</strong> y <strong>qué pasó</strong>.</li>
                        </ul>

                        <!-- El invitado no tiene Dashboard al que volver. -->
                        <div class="mt-6 p-4 rounded-xl bg-gradient-to-r from-accent/10 to-accent-light/10 border border-accent/20">
                            <p v-if="publicMode" class="text-sm text-gray-700">
                                <strong class="text-accent">¿Ya tienes cuenta?</strong>
                                <a href="/login" class="text-accent font-semibold hover:underline">Inicia sesión</a>
                                para operar tu workspace.
                            </p>
                            <p v-else class="text-sm text-gray-700">
                                <strong class="text-accent">¿Listo para empezar?</strong> Vuelve al
                                <a :href="route('dashboard')" class="text-accent font-semibold hover:underline">Dashboard</a>
                                y arranca a operar tu workspace.
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
</template>

<style scoped>
/* El marcador nativo de <details> compite con nuestro icono "+". */
details > summary::-webkit-details-marker { display: none; }
</style>
