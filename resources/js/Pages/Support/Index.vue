<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    tickets:        { type: Array,   default: () => [] },
    account_linked: { type: Boolean, default: false },
    categorias:     { type: Array,   default: () => [] },
});

const page = usePage();

// Fallback de siempre por si el portal todavía no le sirve: el número de soporte.
const soporteWhatsApp = computed(() => {
    const nombre  = page.props.auth?.user?.name?.split(' ')[0] || 'Hola';
    const espacio = page.props.tenant?.name || 'mi workspace';
    const saludo  = `¡Hola! Soy ${nombre} del workspace "${espacio}" en Converza CRM y necesito ayuda con:`;
    return `https://wa.me/573125759381?text=${encodeURIComponent(saludo)}`;
});

// ── Etiquetas ────────────────────────────────────────────────────────────────
// "pending" se le muestra al ISP como "En progreso": para él lo relevante es que
// estamos en eso, no nuestro nombre interno del estado.
const estadoLabel = {
    open:     'Abierto',
    pending:  'En progreso',
    resolved: 'Resuelto',
    closed:   'Cerrado',
};

const estadoBadge = {
    open:     'bg-blue-100 text-blue-700',
    pending:  'bg-amber-100 text-amber-700',
    resolved: 'bg-emerald-100 text-emerald-700',
    closed:   'bg-gray-100 text-gray-600',
};

const categoriaLabel = {
    technical:  'Técnico',
    billing:    'Facturación',
    onboarding: 'Puesta en marcha',
    other:      'Otro',
};

const productoLabel = { ispwatch: 'ISPWatch', converza: 'Converza' };

function fmtFecha(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

const abiertos = computed(() => props.tickets.filter(t => ['open', 'pending'].includes(t.status)));

// ── Nuevo requerimiento ──────────────────────────────────────────────────────
const showModal = ref(false);
const form = useForm({ subject: '', category: '', product: '', body: '' });

function abrirModal() {
    form.reset();
    form.clearErrors();
    showModal.value = true;
}

function enviar() {
    form.post(route('support.store'), {
        onSuccess: () => { showModal.value = false; },
    });
}
</script>

<template>
    <Head title="Soporte" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-4xl mx-auto">

            <!-- Header -->
            <div class="mb-6 flex items-start justify-between gap-4 flex-wrap">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Soporte</h1>
                    <p class="text-sm text-gray-500 mt-1">
                        Abre un requerimiento con el equipo de Converza y sigue sus avances desde acá,
                        sin tener que preguntar por WhatsApp en qué va.
                    </p>
                </div>
                <button
                    v-if="account_linked"
                    @click="abrirModal"
                    class="inline-flex items-center gap-2 px-4 py-2.5 text-sm font-semibold text-white bg-accent rounded-xl hover:opacity-90 transition shrink-0"
                >
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nuevo requerimiento
                </button>
            </div>

            <!-- Workspace sin cuenta de soporte: el portal necesita una ficha nuestra
                 a la que colgar el ticket, y todavía no la tiene. Se dice en vez de
                 dejar un botón que falla. -->
            <div v-if="!account_linked" class="rounded-2xl border border-amber-200 bg-amber-50 p-5">
                <h2 class="text-sm font-semibold text-amber-900">Tu workspace aún no tiene soporte activado</h2>
                <p class="text-sm text-amber-800 mt-1.5">
                    Para abrir requerimientos desde acá necesitamos terminar de vincular tu workspace con
                    tu cuenta de Converza. Escríbenos y lo dejamos listo en minutos.
                </p>
                <a
                    :href="soporteWhatsApp"
                    target="_blank"
                    rel="noopener"
                    class="mt-3 inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold text-white bg-emerald-600 rounded-xl hover:bg-emerald-700 transition"
                >
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.1-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.15-.147.347-.371.52-.569.174-.198.232-.34.348-.567.116-.226.058-.42-.018-.568-.075-.148-.669-1.611-.916-2.206-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.197 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.29.173-1.414-.074-.124-.272-.198-.57-.347z"/><path d="M20.52 3.449C12.831-3.984.106 1.407.101 11.893c0 2.096.549 4.14 1.595 5.945L0 24l6.335-1.652c7.905 4.27 17.661-1.4 17.665-10.449 0-3.176-1.24-6.165-3.495-8.415zm1.482 8.412c-.006 7.636-8.385 12.4-14.98 8.514l-.36-.214-3.75.975 1.005-3.645-.239-.375c-4.124-6.565.614-15.145 8.354-15.145 2.633 0 5.106 1.027 6.963 2.885a9.76 9.76 0 012.886 6.965z"/></svg>
                    Escribir por WhatsApp
                </a>
            </div>

            <template v-else>
                <!-- Resumen -->
                <div v-if="tickets.length" class="mb-4 text-xs text-gray-500">
                    {{ abiertos.length }} sin resolver · {{ tickets.length }} en total
                </div>

                <!-- Vacío -->
                <div v-if="!tickets.length" class="py-14 text-center bg-white rounded-2xl border border-dashed border-gray-200">
                    <p class="text-sm text-gray-500">Todavía no has abierto ningún requerimiento.</p>
                    <button @click="abrirModal" class="mt-2 text-sm font-semibold text-accent hover:underline">Abrir el primero</button>
                </div>

                <!-- Lista -->
                <div v-else class="space-y-2.5">
                    <Link
                        v-for="t in tickets"
                        :key="t.id"
                        :href="route('support.show', t.id)"
                        class="block bg-white rounded-2xl border border-gray-200 px-5 py-4 hover:border-gray-300 hover:shadow-sm transition"
                    >
                        <div class="flex items-start justify-between gap-4">
                            <div class="min-w-0 flex-1">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="text-xs font-mono text-gray-400">#{{ t.id }}</span>
                                    <span class="font-semibold text-gray-900 truncate">{{ t.subject }}</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium" :class="estadoBadge[t.status]">
                                        {{ estadoLabel[t.status] ?? t.status }}
                                    </span>
                                </div>
                                <div class="mt-1.5 flex flex-wrap gap-3 text-xs text-gray-500">
                                    <span v-if="t.category">{{ categoriaLabel[t.category] ?? t.category }}</span>
                                    <span v-if="t.product">{{ productoLabel[t.product] }}</span>
                                    <span>Abierto {{ fmtFecha(t.created_at) }}</span>
                                    <span v-if="t.last_event_at">Último movimiento {{ fmtFecha(t.last_event_at) }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-2 shrink-0 text-xs text-gray-400">
                                <span>{{ t.messages_count }} mensaje{{ t.messages_count !== 1 ? 's' : '' }}</span>
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                            </div>
                        </div>
                    </Link>
                </div>
            </template>
        </div>

        <!-- ── Modal: nuevo requerimiento ──────────────────────────────────── -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showModal" class="fixed inset-0 z-50 flex items-start justify-center pt-12 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between rounded-t-2xl">
                        <h2 class="text-lg font-bold text-gray-900">Nuevo requerimiento</h2>
                        <button @click="showModal = false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form @submit.prevent="enviar" class="p-6 space-y-4">
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Asunto *</label>
                            <input
                                v-model="form.subject"
                                required
                                maxlength="200"
                                placeholder="En una línea, qué necesitas"
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent/40"
                            />
                            <p v-if="form.errors.subject" class="mt-1 text-xs text-red-600">{{ form.errors.subject }}</p>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Tipo</label>
                                <select v-model="form.category" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent/40">
                                    <option value="">Sin clasificar</option>
                                    <option v-for="c in categorias" :key="c" :value="c">{{ categoriaLabel[c] ?? c }}</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Producto</label>
                                <select v-model="form.product" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent/40">
                                    <option value="">No aplica</option>
                                    <option value="converza">Converza</option>
                                    <option value="ispwatch">ISPWatch</option>
                                </select>
                            </div>
                        </div>

                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Cuéntanos qué pasa *</label>
                            <textarea
                                v-model="form.body"
                                required
                                rows="6"
                                maxlength="5000"
                                placeholder="Qué esperabas, qué pasó, y desde cuándo. Si es un error, en qué pantalla."
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-accent/40 resize-none"
                            ></textarea>
                            <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
                        </div>

                        <div class="flex items-center justify-end gap-2 pt-1">
                            <button type="button" @click="showModal = false" class="px-4 py-2 text-sm font-medium text-gray-600 hover:text-gray-900 transition">Cancelar</button>
                            <button
                                type="submit"
                                :disabled="form.processing || !form.subject.trim() || !form.body.trim()"
                                class="px-4 py-2 text-sm font-semibold text-white bg-accent rounded-xl hover:opacity-90 disabled:opacity-40 transition"
                            >
                                {{ form.processing ? 'Enviando…' : 'Enviar' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>
