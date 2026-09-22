<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onMounted, ref } from 'vue';

const props = defineProps({
    ticket: { type: Object, required: true },
});

// Mismas etiquetas que el listado: "pending" se le muestra como "En progreso".
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
        day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

const cerrado = computed(() => props.ticket.status === 'closed');

// ── Responder ────────────────────────────────────────────────────────────────
const form = useForm({ body: '' });
const hilo = ref(null);

function enviar() {
    form.post(route('support.messages.store', props.ticket.id), {
        preserveScroll: true,
        onSuccess: () => { form.reset(); nextTick(alFinal); },
    });
}

function alFinal() {
    if (hilo.value) hilo.value.scrollTop = hilo.value.scrollHeight;
}

onMounted(alFinal);
</script>

<template>
    <Head :title="`Soporte · #${ticket.id}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-3xl mx-auto">

            <!-- Volver -->
            <Link :href="route('support.index')" class="inline-flex items-center gap-1.5 text-sm text-gray-500 hover:text-gray-900 transition mb-4">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                Mis requerimientos
            </Link>

            <!-- Cabecera -->
            <div class="bg-white rounded-2xl border border-gray-200 px-5 py-4 mb-4">
                <div class="flex items-start justify-between gap-4 flex-wrap">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="text-xs font-mono text-gray-400">#{{ ticket.id }}</span>
                            <h1 class="text-lg font-bold text-gray-900">{{ ticket.subject }}</h1>
                        </div>
                        <div class="mt-1.5 flex flex-wrap gap-3 text-xs text-gray-500">
                            <span v-if="ticket.category">{{ categoriaLabel[ticket.category] ?? ticket.category }}</span>
                            <span v-if="ticket.product">{{ productoLabel[ticket.product] }}</span>
                            <span>Abierto {{ fmtFecha(ticket.created_at) }}</span>
                            <span v-if="ticket.resolved_at">Resuelto {{ fmtFecha(ticket.resolved_at) }}</span>
                        </div>
                    </div>
                    <span class="inline-flex items-center px-2.5 py-1 rounded-full text-[11px] font-semibold shrink-0" :class="estadoBadge[ticket.status]">
                        {{ estadoLabel[ticket.status] ?? ticket.status }}
                    </span>
                </div>
            </div>

            <!-- Hilo -->
            <div ref="hilo" class="bg-white rounded-2xl border border-gray-200 p-5 space-y-4 max-h-[55vh] overflow-y-auto">
                <template v-for="e in ticket.events" :key="e.id">
                    <!-- Avance de estado -->
                    <div v-if="e.type === 'status_change'" class="flex items-center gap-3">
                        <div class="h-px bg-gray-100 flex-1"></div>
                        <p class="text-[11px] text-gray-400 whitespace-nowrap">
                            Pasó a <strong class="text-gray-500">{{ estadoLabel[e.to] ?? e.to }}</strong> · {{ fmtFecha(e.created_at) }}
                        </p>
                        <div class="h-px bg-gray-100 flex-1"></div>
                    </div>

                    <!-- Mensaje -->
                    <div v-else class="flex" :class="e.author_side === 'isp' ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[85%]">
                            <div
                                class="rounded-2xl px-4 py-2.5 text-sm whitespace-pre-wrap"
                                :class="e.author_side === 'isp'
                                    ? 'bg-accent/10 border border-accent/20 text-gray-800 rounded-br-sm'
                                    : 'bg-gray-50 border border-gray-200 text-gray-800 rounded-bl-sm'"
                            >{{ e.body }}</div>
                            <p class="mt-1 text-[11px] text-gray-400" :class="e.author_side === 'isp' ? 'text-right' : ''">
                                {{ e.author_name }} · {{ fmtFecha(e.created_at) }}
                            </p>
                        </div>
                    </div>
                </template>
            </div>

            <!-- Responder -->
            <div v-if="!cerrado" class="mt-4">
                <p v-if="ticket.status === 'resolved'" class="mb-2 text-xs text-gray-500">
                    Lo dimos por resuelto. Si el tema sigue, escribe acá y se vuelve a abrir.
                </p>
                <div class="flex gap-2 items-end">
                    <textarea
                        v-model="form.body"
                        rows="2"
                        maxlength="5000"
                        placeholder="Escribe tu respuesta…"
                        class="flex-1 px-3 py-2 text-sm border border-gray-300 rounded-xl focus:outline-none focus:ring-2 focus:ring-accent/40 resize-none"
                        @keydown.enter.meta.prevent="enviar"
                        @keydown.enter.ctrl.prevent="enviar"
                    ></textarea>
                    <button
                        @click="enviar"
                        :disabled="form.processing || !form.body.trim()"
                        class="px-4 py-2.5 text-sm font-semibold text-white bg-accent rounded-xl hover:opacity-90 disabled:opacity-40 transition shrink-0"
                    >
                        {{ form.processing ? 'Enviando…' : 'Enviar' }}
                    </button>
                </div>
                <p v-if="form.errors.body" class="mt-1 text-xs text-red-600">{{ form.errors.body }}</p>
            </div>
            <div v-else class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-xs text-gray-500">
                Este requerimiento está cerrado.
                <Link :href="route('support.index')" class="font-semibold text-accent hover:underline">Abre uno nuevo</Link>
                si necesitas retomarlo.
            </div>
        </div>
    </AppLayout>
</template>
