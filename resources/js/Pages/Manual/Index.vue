<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, nextTick } from 'vue';

const sections = [
    { id: 'intro',          title: 'Bienvenido a Converza',     icon: 'home' },
    { id: 'primeros-pasos', title: 'Primeros pasos',            icon: 'rocket' },
    { id: 'dashboard',      title: 'Dashboard',                 icon: 'dashboard' },
    { id: 'contactos',      title: 'Contactos',                 icon: 'contacts' },
    { id: 'etiquetas',      title: 'Etiquetas',                 icon: 'labels' },
    { id: 'chat',           title: 'Chat',                      icon: 'chat' },
    { id: 'plantillas',     title: 'Plantillas de WhatsApp',    icon: 'templates' },
    { id: 'staff',          title: 'Staff (Asesores)',          icon: 'staff' },
    { id: 'equipos',        title: 'Equipos',                   icon: 'teams' },
    { id: 'quick-replies',  title: 'Respuestas Rápidas',        icon: 'quickreplies' },
    { id: 'closing-notes',  title: 'Notas de Cierre',           icon: 'closingnotes' },
    { id: 'metricas',       title: 'Métricas',                  icon: 'metrics' },
    { id: 'configuracion',  title: 'Configuración',             icon: 'settings' },
    { id: 'soporte',        title: 'Soporte',                   icon: 'support' },
];

const activeSection = ref('intro');
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
                    if (entry.isIntersecting) {
                        activeSection.value = entry.target.id;
                    }
                });
            },
            { rootMargin: '-30% 0px -60% 0px', threshold: 0 }
        );
        sections.forEach((s) => {
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
    <Head title="Manual de Usuario" />

    <AppLayout>
        <div class="max-w-7xl mx-auto px-4 md:px-6 py-6">
            <!-- Header -->
            <div class="mb-6">
                <div class="flex items-center gap-3">
                    <div class="w-12 h-12 rounded-xl bg-gradient-to-br from-accent to-accent-light flex items-center justify-center shadow-md">
                        <svg class="w-7 h-7 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 6.042A8.967 8.967 0 006 3.75c-1.052 0-2.062.18-3 .512v14.25A8.987 8.987 0 016 18c2.305 0 4.408.867 6 2.292m0-14.25a8.966 8.966 0 016-2.292c1.052 0 2.062.18 3 .512v14.25A8.987 8.987 0 0018 18a8.967 8.967 0 00-6 2.292m0-14.25v14.25" />
                        </svg>
                    </div>
                    <div>
                        <h1 class="text-2xl font-bold text-gray-900">Manual de Usuario</h1>
                        <p class="text-sm text-gray-500">Guía completa para sacarle el máximo provecho a Converza CRM.</p>
                    </div>
                </div>
            </div>

            <div class="flex gap-6">
                <!-- ── Sidebar de navegación interna ─────────────────────────── -->
                <aside class="hidden lg:block w-64 shrink-0">
                    <nav class="sticky top-4 bg-white rounded-xl border border-gray-200 p-3 max-h-[calc(100vh-7rem)] overflow-y-auto">
                        <p class="text-[10px] uppercase tracking-wider text-gray-400 font-semibold px-3 py-2">Contenido</p>
                        <button
                            v-for="s in sections"
                            :key="s.id"
                            @click="scrollToSection(s.id)"
                            class="w-full flex items-center gap-2.5 px-3 py-2 text-sm rounded-lg text-left transition"
                            :class="activeSection === s.id ? 'bg-accent/10 text-accent font-medium' : 'text-gray-600 hover:bg-gray-50'"
                        >
                            <span class="w-1.5 h-1.5 rounded-full shrink-0" :class="activeSection === s.id ? 'bg-accent' : 'bg-gray-300'"></span>
                            <span class="truncate">{{ s.title }}</span>
                        </button>
                    </nav>
                </aside>

                <!-- ── Contenido del manual ───────────────────────────────────── -->
                <div class="flex-1 min-w-0 space-y-6">

                    <!-- Selector de secciones (mobile) -->
                    <div class="lg:hidden bg-white rounded-xl border border-gray-200 p-3">
                        <select
                            :value="activeSection"
                            @change="scrollToSection($event.target.value)"
                            class="w-full text-sm border-gray-300 rounded-lg focus:ring-accent focus:border-accent"
                        >
                            <option v-for="s in sections" :key="s.id" :value="s.id">{{ s.title }}</option>
                        </select>
                    </div>

                    <!-- ─────── 1. Intro ─────── -->
                    <section id="intro" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Bienvenido a Converza</h2>
                        <p class="text-gray-600 leading-relaxed mb-4">
                            <strong>Converza CRM</strong> es una plataforma de comunicación con tus clientes por WhatsApp.
                            Centraliza todas tus conversaciones, organiza tus contactos, asigna chats a tu equipo y mide
                            el desempeño de tu operación, todo desde un solo lugar.
                        </p>
                        <div class="grid sm:grid-cols-3 gap-3">
                            <div class="p-4 rounded-lg bg-accent/5 border border-accent/20">
                                <div class="text-accent font-semibold text-sm mb-1">Centraliza</div>
                                <p class="text-xs text-gray-600">Todas tus conversaciones de WhatsApp en una sola bandeja.</p>
                            </div>
                            <div class="p-4 rounded-lg bg-accent/5 border border-accent/20">
                                <div class="text-accent font-semibold text-sm mb-1">Organiza</div>
                                <p class="text-xs text-gray-600">Etiquetas, equipos, plantillas y respuestas rápidas.</p>
                            </div>
                            <div class="p-4 rounded-lg bg-accent/5 border border-accent/20">
                                <div class="text-accent font-semibold text-sm mb-1">Mide</div>
                                <p class="text-xs text-gray-600">Métricas de tiempos de respuesta, cierres y carga por asesor.</p>
                            </div>
                        </div>
                    </section>

                    <!-- ─────── 2. Primeros pasos ─────── -->
                    <section id="primeros-pasos" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Primeros pasos</h2>
                        <p class="text-gray-600 mb-4">Para empezar a operar tu workspace en Converza te recomendamos seguir este orden:</p>
                        <ol class="space-y-3">
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">1</span>
                                <div>
                                    <p class="font-medium text-gray-900">Configura WhatsApp API</p>
                                    <p class="text-sm text-gray-600">Ve a <em>Configuración → WhatsApp</em> e ingresa las credenciales de Meta (Phone Number ID, Business Account, tokens). Sin esto no podrás enviar ni recibir mensajes.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">2</span>
                                <div>
                                    <p class="font-medium text-gray-900">Invita a tu equipo</p>
                                    <p class="text-sm text-gray-600">En <em>Staff</em> crea o invita a los asesores que atenderán los chats.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">3</span>
                                <div>
                                    <p class="font-medium text-gray-900">Crea tus etiquetas y equipos</p>
                                    <p class="text-sm text-gray-600">Define cómo vas a clasificar a tus contactos y agrupa a los asesores por área (ventas, soporte, etc.).</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">4</span>
                                <div>
                                    <p class="font-medium text-gray-900">Sincroniza plantillas de Meta</p>
                                    <p class="text-sm text-gray-600">En <em>Plantillas</em> haz <em>Sincronizar</em> para traer las plantillas aprobadas por Meta.</p>
                                </div>
                            </li>
                            <li class="flex gap-3">
                                <span class="w-7 h-7 shrink-0 rounded-full bg-accent text-white text-sm font-bold flex items-center justify-center">5</span>
                                <div>
                                    <p class="font-medium text-gray-900">Empieza a chatear</p>
                                    <p class="text-sm text-gray-600">Cuando un cliente te escriba, su conversación aparecerá automáticamente en <em>Chat</em>.</p>
                                </div>
                            </li>
                        </ol>
                    </section>

                    <!-- ─────── 3. Dashboard ─────── -->
                    <section id="dashboard" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Dashboard</h2>
                        <p class="text-gray-600 mb-4">
                            La pantalla principal de Converza. Te muestra de un vistazo el estado de tu operación.
                        </p>
                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">¿Qué vas a encontrar?</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Saludo y fecha:</strong> personalizado con tu nombre.</li>
                            <li><strong>Tarjetas de estadísticas:</strong> conversaciones abiertas, pendientes, cerradas hoy y cuentas por cobrar (si tu workspace está vinculado a ispwatch).</li>
                            <li><strong>Gráfica de mensajes:</strong> enviados vs. recibidos en los últimos 7 días.</li>
                            <li><strong>Necesitan atención:</strong> conversaciones con mayor tiempo de espera.</li>
                            <li><strong>Conversaciones recientes:</strong> los últimos chats activos.</li>
                            <li><strong>Estado del sistema:</strong> alertas si WhatsApp no está configurado o si hay problemas de conexión.</li>
                        </ul>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Tip:</strong> haz clic en cualquier conversación del listado para abrirla directamente en Chat.
                        </div>
                    </section>

                    <!-- ─────── 4. Contactos ─────── -->
                    <section id="contactos" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Contactos</h2>
                        <p class="text-gray-600 mb-4">
                            Tu base de datos de clientes. Cada contacto representa un número de WhatsApp y guarda su historial,
                            etiquetas y notas.
                        </p>
                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">¿Qué puedes hacer?</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Crear contacto manual:</strong> botón <em>Nuevo contacto</em>. Ingresa nombre y número.</li>
                            <li><strong>Editar:</strong> haz clic sobre la fila o el ícono de lápiz.</li>
                            <li><strong>Asignar etiquetas:</strong> para clasificar (cliente, prospecto, moroso, etc.).</li>
                            <li><strong>Abrir chat:</strong> ícono de WhatsApp en la fila para ir directo a la conversación.</li>
                            <li><strong>Eliminar:</strong> solo si ya no necesitas el contacto. <span class="text-red-600">Acción irreversible.</span></li>
                        </ul>
                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Búsqueda y filtros</h3>
                        <p class="text-sm text-gray-600">Usa la barra superior para buscar por nombre o teléfono, y los selectores para filtrar por etiqueta.</p>
                        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Importante:</strong> los contactos también se crean automáticamente cuando alguien te escribe por primera vez.
                        </div>
                    </section>

                    <!-- ─────── 5. Etiquetas ─────── -->
                    <section id="etiquetas" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Etiquetas</h2>
                        <p class="text-gray-600 mb-4">
                            Las etiquetas te permiten clasificar contactos y conversaciones con colores personalizables.
                            Son la base para segmentar tu operación.
                        </p>
                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Cómo crearlas</h3>
                        <ol class="space-y-2 text-sm text-gray-600 list-decimal list-inside">
                            <li>Haz clic en <em>Nueva etiqueta</em>.</li>
                            <li>Ingresa nombre (ej. <em>VIP</em>, <em>Soporte</em>, <em>Moroso</em>).</li>
                            <li>Elige un color para que sea fácil de identificar.</li>
                            <li>Guarda. La etiqueta queda disponible para asignarse a contactos.</li>
                        </ol>
                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Tip:</strong> si tu workspace es nuevo, usa <em>Cargar ejemplos</em> para poblar etiquetas comunes y empezar más rápido.
                        </div>
                    </section>

                    <!-- ─────── 6. Chat ─────── -->
                    <section id="chat" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Chat</h2>
                        <p class="text-gray-600 mb-4">
                            El corazón de Converza. Aquí gestionas todas las conversaciones de WhatsApp en tiempo real.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Estructura de la pantalla</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Columna izquierda:</strong> listado de conversaciones (abiertas, pendientes, cerradas).</li>
                            <li><strong>Columna central:</strong> mensajes de la conversación seleccionada.</li>
                            <li><strong>Columna derecha:</strong> información del contacto, etiquetas y notas.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Acciones disponibles</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Enviar mensaje:</strong> texto, imagen, audio, video o documento.</li>
                            <li><strong>Usar respuesta rápida:</strong> escribe <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">/</code> y selecciona una respuesta predefinida.</li>
                            <li><strong>Enviar plantilla:</strong> útil para reactivar conversaciones fuera de la ventana de 24h.</li>
                            <li><strong>Asignar a asesor:</strong> transfiere la conversación a otro miembro del equipo.</li>
                            <li><strong>Cerrar conversación:</strong> con una <em>nota de cierre</em> para dejar constancia del resultado.</li>
                            <li><strong>Reabrir:</strong> si necesitas continuar una conversación ya cerrada.</li>
                        </ul>

                        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Ventana de 24h de WhatsApp:</strong> Meta solo permite enviar mensajes libres dentro de las 24 horas posteriores al último mensaje del cliente. Después de eso debes usar una <strong>plantilla aprobada</strong>.
                        </div>
                    </section>

                    <!-- ─────── 7. Plantillas ─────── -->
                    <section id="plantillas" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Plantillas de WhatsApp</h2>
                        <p class="text-gray-600 mb-4">
                            Las plantillas son mensajes pre-aprobados por Meta que puedes enviar fuera de la ventana de 24h.
                            Sirven para notificaciones, confirmaciones, recordatorios, campañas, etc.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Sincronizar plantillas</h3>
                        <p class="text-sm text-gray-600 mb-2">
                            El botón <em>Sincronizar</em> consulta tu WhatsApp Business Account de Meta y trae todas las plantillas aprobadas con su contenido, idioma y estado.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Estados de plantilla</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Aprobada:</strong> lista para usar.</li>
                            <li><strong>Pendiente:</strong> Meta aún la está revisando.</li>
                            <li><strong>Rechazada:</strong> necesita corregirse y reenviarse a aprobación desde Meta Business Manager.</li>
                        </ul>

                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Tip:</strong> activa o desactiva plantillas con el toggle para que aparezcan u oculten en el selector de Chat sin tener que borrarlas.
                        </div>
                    </section>

                    <!-- ─────── 8. Staff ─────── -->
                    <section id="staff" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Staff (Asesores)</h2>
                        <p class="text-gray-600 mb-4">
                            Aquí administras a los miembros de tu workspace que pueden iniciar sesión y atender conversaciones.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Crear o invitar</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Crear directamente:</strong> defines nombre, correo y contraseña inicial.</li>
                            <li><strong>Invitar por email:</strong> Converza envía un correo para que el asesor termine su registro.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Acciones</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Editar:</strong> cambia nombre, rol o equipo asignado.</li>
                            <li><strong>Activar/Desactivar:</strong> bloquea temporalmente el acceso sin eliminar la cuenta.</li>
                            <li><strong>Eliminar:</strong> remueve el asesor del workspace.</li>
                        </ul>

                        <div class="mt-4 p-3 bg-amber-50 border border-amber-200 rounded-lg text-sm text-amber-900">
                            <strong>Importante:</strong> al desactivar un asesor, sus conversaciones abiertas quedan disponibles para reasignarse.
                        </div>
                    </section>

                    <!-- ─────── 9. Equipos ─────── -->
                    <section id="equipos" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Equipos</h2>
                        <p class="text-gray-600 mb-4">
                            Los equipos agrupan a tus asesores por función (Ventas, Soporte, Cobranza, etc.) y facilitan la distribución de conversaciones.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Cómo funcionan</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li>Crea un equipo con nombre, color y descripción opcional.</li>
                            <li>Agrega o quita miembros desde la vista del equipo.</li>
                            <li>Usa el botón <em>Cargar ejemplos</em> para empezar con equipos básicos predefinidos.</li>
                        </ul>

                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Tip:</strong> al asignar una conversación, puedes elegir un equipo en lugar de un asesor específico para que cualquiera del equipo la tome.
                        </div>
                    </section>

                    <!-- ─────── 10. Quick Replies ─────── -->
                    <section id="quick-replies" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Respuestas Rápidas</h2>
                        <p class="text-gray-600 mb-4">
                            Mensajes pre-escritos que puedes enviar en segundos durante una conversación.
                            Ideales para preguntas frecuentes.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Cómo crearlas</h3>
                        <ol class="space-y-2 text-sm text-gray-600 list-decimal list-inside">
                            <li>Define un <strong>atajo</strong> (ej. <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">/horarios</code>).</li>
                            <li>Escribe el <strong>mensaje</strong> que se enviará al cliente.</li>
                            <li>Guarda. Estará disponible en el Chat al escribir <code class="bg-gray-100 px-1.5 py-0.5 rounded text-xs">/</code>.</li>
                        </ol>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Acciones extra</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Duplicar:</strong> útil para crear variantes.</li>
                            <li><strong>Activar/Desactivar:</strong> sin tener que eliminarla.</li>
                            <li><strong>Cargar ejemplos:</strong> para empezar más rápido.</li>
                        </ul>
                    </section>

                    <!-- ─────── 11. Closing Notes ─────── -->
                    <section id="closing-notes" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Notas de Cierre</h2>
                        <p class="text-gray-600 mb-4">
                            Cada conversación que cierras puede llevar una nota que explica cómo terminó:
                            venta concretada, cliente sin interés, transferencia, etc.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">¿Para qué sirven?</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Trazabilidad:</strong> sabes el motivo de cierre de cada chat.</li>
                            <li><strong>Métricas:</strong> alimentan los reportes de cierres por tipo.</li>
                            <li><strong>Reabrir:</strong> puedes reabrir un chat ya cerrado si el cliente vuelve a escribir.</li>
                        </ul>

                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Tip:</strong> define en tu equipo una lista estandarizada de motivos de cierre para que las métricas sean consistentes.
                        </div>
                    </section>

                    <!-- ─────── 12. Métricas ─────── -->
                    <section id="metricas" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Métricas</h2>
                        <p class="text-gray-600 mb-4">
                            El módulo de reportes. Aquí mides el desempeño de tu operación con datos reales.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">¿Qué vas a ver?</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Volumen:</strong> mensajes enviados y recibidos por período.</li>
                            <li><strong>Tiempos de respuesta:</strong> promedio de primera respuesta y resolución.</li>
                            <li><strong>Cierres:</strong> conversaciones cerradas, agrupadas por motivo.</li>
                            <li><strong>Carga por asesor:</strong> cuántos chats atiende cada miembro del equipo.</li>
                            <li><strong>Etiquetas más frecuentes:</strong> los temas que más demandan tus clientes.</li>
                        </ul>

                        <div class="mt-4 p-3 bg-blue-50 border border-blue-200 rounded-lg text-sm text-blue-900">
                            <strong>Tip:</strong> usa los filtros de fecha para comparar semanas o meses y detectar tendencias.
                        </div>
                    </section>

                    <!-- ─────── 13. Configuración ─────── -->
                    <section id="configuracion" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Configuración</h2>
                        <p class="text-gray-600 mb-4">
                            Ajustes del workspace. Está dividida en secciones.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Perfil del workspace</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Nombre:</strong> cómo se llama tu empresa dentro de Converza.</li>
                            <li><strong>Slug:</strong> identificador único (lo gestiona el admin del SaaS).</li>
                            <li><strong>Estado:</strong> activo o desactivado.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">WhatsApp API</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li><strong>Phone Number ID:</strong> ID del número que registraste en Meta.</li>
                            <li><strong>Business Account ID:</strong> ID de la cuenta de WhatsApp Business.</li>
                            <li><strong>Verify Token:</strong> token que usa el webhook para verificarse contra Meta.</li>
                            <li><strong>Access Token:</strong> token de acceso permanente de la app de Meta.</li>
                            <li><strong>App Secret:</strong> secreto de la app para validar firmas de webhooks.</li>
                            <li><strong>Probar conexión:</strong> botón para verificar que las credenciales funcionen.</li>
                        </ul>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Integración ispwatch</h3>
                        <p class="text-sm text-gray-600">
                            Si tu workspace está vinculado a ispwatch verás aquí el estado de la conexión (clientes, facturas, pagos).
                            La vinculación inicial la realiza el administrador del SaaS.
                        </p>

                        <div class="mt-4 p-3 bg-red-50 border border-red-200 rounded-lg text-sm text-red-900">
                            <strong>Advertencia:</strong> nunca compartas tus tokens de WhatsApp con terceros. Si sospechas que se filtraron, regenéralos en Meta Business Manager y actualízalos aquí.
                        </div>
                    </section>

                    <!-- ─────── 14. Soporte ─────── -->
                    <section id="soporte" class="bg-white rounded-xl border border-gray-200 p-6 scroll-mt-4">
                        <h2 class="text-xl font-bold text-gray-900 mb-3">Soporte</h2>
                        <p class="text-gray-600 mb-4">
                            ¿Necesitas ayuda? Tienes el botón verde flotante de WhatsApp en la esquina inferior derecha de la pantalla.
                            Al hacer clic se abre WhatsApp con un mensaje pre-armado que incluye tu nombre y el de tu workspace,
                            para que el equipo de Converza te identifique al toque.
                        </p>

                        <h3 class="font-semibold text-gray-800 mt-4 mb-2">Antes de escribir</h3>
                        <ul class="space-y-2 text-sm text-gray-600 list-disc list-inside">
                            <li>Revisa este manual: probablemente tu pregunta ya está respondida.</li>
                            <li>Verifica el indicador de estado de WhatsApp API en el sidebar (verde = conectado).</li>
                            <li>Si reportas un error, incluye: qué hacías, qué esperabas y qué pasó.</li>
                        </ul>

                        <div class="mt-6 p-4 rounded-xl bg-gradient-to-r from-accent/10 to-accent-light/10 border border-accent/20">
                            <p class="text-sm text-gray-700">
                                <strong class="text-accent">¿Listo para empezar?</strong> Vuelve al
                                <a :href="route('dashboard')" class="text-accent font-semibold hover:underline">Dashboard</a>
                                y arranca a operar tu workspace.
                            </p>
                        </div>
                    </section>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
