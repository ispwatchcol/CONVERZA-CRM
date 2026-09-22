<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    tickets:     { type: Object, default: () => ({ data: [], links: [] }) },
    filtros:     { type: Object, default: () => ({}) },
    contadores:  { type: Object, default: () => ({}) },
    internos:    { type: Array,  default: () => [] },
});

const estadoLabel = { open: 'Abierto', pending: 'Pendiente', resolved: 'Resuelto', closed: 'Cerrado' };
const estadoColor = {
    open:     'bg-blue-100 text-blue-700',
    pending:  'bg-yellow-100 text-yellow-700',
    resolved: 'bg-green-100 text-green-700',
    closed:   'bg-gray-100 text-gray-500',
};
const prioridadLabel = { low: 'Baja', normal: 'Normal', high: 'Alta', urgent: 'Urgente' };
const prioridadColor = { low: 'text-gray-500', normal: 'text-blue-600', high: 'text-orange-500', urgent: 'text-red-600 font-semibold' };
const productoLabel  = { ispwatch: 'ISPWatch', converza: 'Converza' };
const origenLabel    = { manual: 'Manual', whatsapp: 'WhatsApp', email: 'Email', portal: 'Portal del ISP' };
const categoriaLabel = { technical: 'Técnico', billing: 'Facturación', onboarding: 'Puesta en marcha', other: 'Otro' };

// ── Filtros ──────────────────────────────────────────────────────────────────
const buscar = ref(props.filtros.buscar || '');

function filtrar(cambios) {
    router.get(route('brain.tickets.index'), {
        ...props.filtros,
        buscar: buscar.value || undefined,
        ...cambios,
        page: undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

const estadoChips = [
    { value: 'esperando', label: 'Esperando respuesta' },
    { value: 'abiertos',  label: 'Abiertos' },
    { value: 'resolved',  label: 'Resueltos' },
    { value: 'todos',     label: 'Todos' },
];

// ── Tiempo de espera ─────────────────────────────────────────────────────────
// El dato que hace útil la bandeja: no "cuándo entró" sino "cuánto lleva
// esperándonos". Se muestra en la unidad más grande que ya se cumplió, porque
// "hace 3 días" se lee de un golpe y "hace 76 horas" hay que dividirlo.
function hace(iso) {
    if (!iso) return null;
    const minutos = Math.max(0, Math.floor((Date.now() - new Date(iso).getTime()) / 60000));
    if (minutos < 60)     return `${minutos} min`;
    const horas = Math.floor(minutos / 60);
    if (horas < 24)       return `${horas} h`;
    const dias = Math.floor(horas / 24);
    return `${dias} d`;
}

// Más de un día sin contestar se marca en rojo: es el umbral a partir del cual
// el cliente ya se preguntó si le llegó el mensaje.
function esperaColor(iso) {
    if (!iso) return 'text-gray-400';
    const horas = (Date.now() - new Date(iso).getTime()) / 3600000;
    if (horas >= 24) return 'text-red-600 font-semibold';
    if (horas >= 4)  return 'text-orange-600 font-medium';
    return 'text-gray-500';
}

function fmtFecha(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

// ── Responder / cambiar estado ───────────────────────────────────────────────
const abierto = ref(null);
// Por defecto NOTA INTERNA, igual que en la ficha: lo que se mande como `message`
// lo lee el ISP en su portal.
const eventForm = useForm({ type: 'note', body: '' });

function alternar(id) {
    abierto.value = abierto.value === id ? null : id;
    eventForm.reset();
    eventForm.type = 'note';
}

function enviar(t) {
    eventForm.post(route('brain.tickets.events.store', t.id), {
        preserveScroll: true,
        onSuccess: () => { eventForm.reset(); eventForm.type = 'note'; },
    });
}

function cambiarEstado(t, status) {
    router.patch(route('brain.tickets.status', t.id), { status }, { preserveScroll: true });
}

const hayFiltros = computed(() =>
    (props.filtros.estado && props.filtros.estado !== 'abiertos') ||
    props.filtros.prioridad || props.filtros.producto || props.filtros.origen ||
    props.filtros.asignado || props.filtros.buscar
);
</script>

<template>
    <Head title="Tickets" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">

            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Tickets</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Todo lo que entró, de todas las cuentas. Arriba, lo que lleva más tiempo esperando respuesta nuestra.
                </p>
            </div>

            <!-- Contadores -->
            <div class="grid grid-cols-3 gap-3 mb-6">
                <button @click="filtrar({ estado: 'esperando' })"
                        class="text-left bg-white rounded-2xl border p-4 transition hover:border-amber-300"
                        :class="filtros.estado === 'esperando' ? 'border-amber-400 ring-1 ring-amber-200' : 'border-gray-200'">
                    <p class="text-2xl font-bold" :class="(contadores.esperando ?? 0) > 0 ? 'text-red-600' : 'text-gray-900'">{{ contadores.esperando ?? 0 }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Esperando respuesta</p>
                </button>
                <button @click="filtrar({ estado: 'abiertos' })"
                        class="text-left bg-white rounded-2xl border p-4 transition hover:border-amber-300"
                        :class="filtros.estado === 'abiertos' ? 'border-amber-400 ring-1 ring-amber-200' : 'border-gray-200'">
                    <p class="text-2xl font-bold text-gray-900">{{ contadores.abiertos ?? 0 }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Abiertos</p>
                </button>
                <button @click="filtrar({ estado: 'abiertos', origen: 'portal' })"
                        class="text-left bg-white rounded-2xl border p-4 transition hover:border-amber-300"
                        :class="filtros.origen === 'portal' ? 'border-amber-400 ring-1 ring-amber-200' : 'border-gray-200'">
                    <p class="text-2xl font-bold text-gray-900">{{ contadores.portal ?? 0 }}</p>
                    <p class="text-xs text-gray-500 mt-0.5">Abiertos por el ISP</p>
                </button>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl border border-gray-200 p-4 mb-4 space-y-3">
                <div class="flex flex-wrap gap-1.5">
                    <button v-for="c in estadoChips" :key="c.value" @click="filtrar({ estado: c.value })"
                            class="px-3 py-1.5 text-xs font-medium rounded-lg border transition"
                            :class="filtros.estado === c.value ? 'bg-amber-100 border-amber-300 text-amber-800' : 'bg-white border-gray-200 text-gray-600 hover:border-gray-300'">
                        {{ c.label }}
                    </button>
                </div>

                <div class="flex flex-wrap gap-2 items-center">
                    <input v-model="buscar" @keyup.enter="filtrar({})" placeholder="Asunto o cuenta…"
                           class="flex-1 min-w-[180px] px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" />

                    <select :value="filtros.prioridad" @change="filtrar({ prioridad: $event.target.value })"
                            class="px-2 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-400">
                        <option value="">Toda prioridad</option>
                        <option v-for="(l, k) in prioridadLabel" :key="k" :value="k">{{ l }}</option>
                    </select>

                    <select :value="filtros.producto" @change="filtrar({ producto: $event.target.value })"
                            class="px-2 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-400">
                        <option value="">Todo producto</option>
                        <option v-for="(l, k) in productoLabel" :key="k" :value="k">{{ l }}</option>
                    </select>

                    <select :value="filtros.origen" @change="filtrar({ origen: $event.target.value })"
                            class="px-2 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-400">
                        <option value="">Todo origen</option>
                        <option v-for="(l, k) in origenLabel" :key="k" :value="k">{{ l }}</option>
                    </select>

                    <select :value="filtros.asignado" @change="filtrar({ asignado: $event.target.value })"
                            class="px-2 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-400">
                        <option value="">Cualquiera</option>
                        <option value="mi">Míos</option>
                        <option value="nadie">Sin asignar</option>
                        <option v-for="u in internos" :key="u.id" :value="String(u.id)">{{ u.name }}</option>
                    </select>

                    <select :value="filtros.orden" @change="filtrar({ orden: $event.target.value })"
                            class="px-2 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-400">
                        <option value="espera">Más tiempo esperando</option>
                        <option value="recientes">Más recientes</option>
                    </select>

                    <button v-if="hayFiltros" @click="buscar = ''; filtrar({ estado: 'abiertos', prioridad: '', producto: '', origen: '', asignado: '' })"
                            class="px-3 py-1.5 text-xs text-gray-500 hover:text-gray-900 transition">Limpiar</button>
                </div>
            </div>

            <!-- Lista -->
            <div v-if="tickets.data.length === 0" class="py-14 text-center bg-white rounded-2xl border border-dashed border-gray-200">
                <p class="text-sm text-gray-500">Nada por acá. Con estos filtros no hay tickets.</p>
            </div>

            <div v-else class="space-y-2.5">
                <div v-for="t in tickets.data" :key="t.id" class="bg-white rounded-2xl border border-gray-200 overflow-hidden"
                     :class="t.esperando_desde ? 'border-l-4 border-l-amber-400' : ''">

                    <div class="px-5 py-4">
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-xs font-mono text-gray-400">#{{ t.id }}</span>
                                    <Link v-if="t.account" :href="route('brain.accounts.show', t.account.id)"
                                          class="text-xs font-semibold text-amber-700 hover:underline">{{ t.account.name }}</Link>
                                    <span class="font-semibold text-gray-900">{{ t.subject }}</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium" :class="estadoColor[t.status]">{{ estadoLabel[t.status] }}</span>
                                    <span class="text-xs" :class="prioridadColor[t.priority]">{{ prioridadLabel[t.priority] }}</span>
                                    <span v-if="t.source === 'portal'" class="text-[11px] font-medium bg-blue-100 text-blue-700 px-1.5 py-0.5 rounded">Portal del ISP</span>
                                </div>

                                <div class="mt-1.5 flex flex-wrap gap-3 text-xs">
                                    <span v-if="t.esperando_desde" :class="esperaColor(t.esperando_desde)">
                                        {{ t.first_response_at ? 'El cliente espera hace' : 'Sin responder hace' }} {{ hace(t.esperando_desde) }}
                                    </span>
                                    <span v-else class="text-gray-400">Sin pendientes de nuestro lado</span>
                                    <span v-if="t.category" class="text-gray-500">{{ categoriaLabel[t.category] ?? t.category }}</span>
                                    <span v-if="t.product" class="text-gray-500">{{ productoLabel[t.product] }}</span>
                                    <span class="text-gray-500">{{ t.assigned_to ? `Asignado a ${t.assigned_to.name}` : 'Sin asignar' }}</span>
                                    <span class="text-gray-400">Abierto {{ fmtFecha(t.created_at) }}</span>
                                </div>

                                <!-- Vista previa del último mensaje: en la bandeja hace
                                     falta saber qué dice, no solo que existe. -->
                                <div v-if="t.ultimo_mensaje" class="mt-2 text-xs rounded-lg px-3 py-2"
                                     :class="t.ultimo_mensaje.from_client ? 'bg-blue-50 text-gray-700' : 'bg-gray-50 text-gray-600'">
                                    <span class="font-semibold">{{ t.ultimo_mensaje.from_client ? t.ultimo_mensaje.author + ' (cliente)' : t.ultimo_mensaje.author }}:</span>
                                    {{ t.ultimo_mensaje.body }}
                                </div>
                            </div>

                            <div class="flex flex-col items-end gap-1.5 shrink-0">
                                <button @click="alternar(t.id)" class="px-2.5 py-1 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">
                                    {{ abierto === t.id ? 'Cerrar' : 'Responder' }}
                                </button>
                                <span class="text-[11px] text-gray-400">{{ t.mensajes_count }} mensaje{{ t.mensajes_count !== 1 ? 's' : '' }}</span>
                            </div>
                        </div>
                    </div>

                    <!-- Responder + estado, sin salir de la bandeja -->
                    <div v-if="abierto === t.id" class="border-t border-gray-100 px-5 py-4 bg-gray-50 space-y-3">
                        <div class="rounded-lg border p-3 transition-colors"
                             :class="eventForm.type === 'message' ? 'border-blue-300 bg-blue-50' : 'border-yellow-300 bg-yellow-50'">
                            <div class="flex items-center gap-2 mb-2">
                                <button @click="eventForm.type = 'note'" type="button"
                                        class="px-2.5 py-1 text-xs font-medium rounded-lg border transition"
                                        :class="eventForm.type === 'note' ? 'bg-yellow-200 border-yellow-400 text-yellow-900' : 'bg-white border-gray-200 text-gray-500 hover:border-gray-300'">
                                    Nota interna
                                </button>
                                <button @click="eventForm.type = 'message'" type="button"
                                        class="px-2.5 py-1 text-xs font-medium rounded-lg border transition"
                                        :class="eventForm.type === 'message' ? 'bg-blue-200 border-blue-400 text-blue-900' : 'bg-white border-gray-200 text-gray-500 hover:border-gray-300'">
                                    Responder al cliente
                                </button>
                                <span class="text-[11px] font-medium" :class="eventForm.type === 'message' ? 'text-blue-700' : 'text-yellow-800'">
                                    {{ eventForm.type === 'message' ? 'Lo va a LEER el ISP en su portal' : 'Solo la vemos nosotros' }}
                                </span>
                            </div>
                            <div class="flex gap-2">
                                <textarea v-model="eventForm.body" rows="2"
                                          :placeholder="eventForm.type === 'message' ? 'Respuesta que verá el cliente…' : 'Nota para el equipo…'"
                                          class="flex-1 px-3 py-1.5 text-sm bg-white border border-gray-300 rounded-lg focus:outline-none focus:ring-2 resize-none"
                                          :class="eventForm.type === 'message' ? 'focus:ring-blue-400' : 'focus:ring-yellow-400'"></textarea>
                                <button @click="enviar(t)" :disabled="eventForm.processing || !eventForm.body.trim()"
                                        class="self-end px-3 py-1.5 text-xs font-medium text-white rounded-lg disabled:opacity-40 transition"
                                        :class="eventForm.type === 'message' ? 'bg-blue-600 hover:bg-blue-700' : 'bg-yellow-600 hover:bg-yellow-700'">
                                    {{ eventForm.type === 'message' ? 'Responder' : 'Guardar nota' }}
                                </button>
                            </div>
                        </div>

                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs text-gray-500">Mover a:</span>
                            <button v-for="(l, k) in estadoLabel" :key="k" @click="cambiarEstado(t, k)" :disabled="t.status === k"
                                    class="px-2.5 py-1 text-xs font-medium rounded-lg border transition disabled:opacity-30 disabled:cursor-default"
                                    :class="t.status === k ? 'bg-gray-100 border-gray-200 text-gray-500' : 'bg-white border-gray-200 text-gray-600 hover:border-gray-400'">
                                {{ l }}
                            </button>
                            <Link v-if="t.account" :href="route('brain.accounts.show', t.account.id)"
                                  class="ml-auto text-xs text-amber-700 hover:underline">Ver ficha de la cuenta →</Link>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Paginación -->
            <div v-if="tickets.links && tickets.links.length > 3" class="mt-5 flex flex-wrap gap-1 justify-center">
                <component :is="l.url ? 'a' : 'span'" v-for="l in tickets.links" :key="l.label" :href="l.url"
                           class="px-3 py-1.5 text-xs rounded-lg border transition"
                           :class="l.active ? 'bg-amber-100 border-amber-300 text-amber-800 font-semibold'
                                            : (l.url ? 'bg-white border-gray-200 text-gray-600 hover:border-gray-300' : 'bg-white border-gray-100 text-gray-300')"
                           v-html="l.label" />
            </div>
        </div>
    </AppLayout>
</template>
