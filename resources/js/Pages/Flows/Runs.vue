<script setup>
// Actividad de un flujo: cada conversación que atendió, qué bloque corrió, qué
// le dijo al cliente y si WhatsApp lo entregó. Sirve para responder "¿por qué
// el bot le dijo eso?" sin abrir la base de datos.
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { ENDED_REASONS, NODE_TYPES, RUN_STATUS, outcomeLabel } from '@/Components/Flows/nodeCatalog';

const props = defineProps({
    flow: { type: Object, required: true },
    runs: { type: Array, default: () => [] },
    status: { type: String, default: null },
});

const open = ref(new Set());

function toggle(id) {
    const next = new Set(open.value);
    next.has(id) ? next.delete(id) : next.add(id);
    open.value = next;
}

const FILTERS = [
    { value: null, label: 'Todas' },
    { value: 'live', label: 'En curso' },
    { value: 'completed', label: 'Resueltas' },
    { value: 'handed_off', label: 'Pasaron al equipo' },
    { value: 'cancelled', label: 'Cortadas' },
    { value: 'failed', label: 'Fallidas' },
];

function filter(value) {
    router.get(route('flows.runs', props.flow.id), value ? { status: value } : {}, { preserveScroll: true, preserveState: true });
}

const DELIVERY = {
    sent: { label: 'Enviado', class: 'text-gray-500' },
    delivered: { label: 'Entregado', class: 'text-sky-600' },
    read: { label: 'Leído', class: 'text-emerald-600' },
    failed: { label: 'No entregado', class: 'text-red-600' },
};

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

function variableEntries(vars) {
    return Object.entries(vars ?? {}).filter(([, v]) => v !== null && String(v).trim() !== '');
}
</script>

<template>
    <Head :title="`Actividad · ${flow.name}`" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-5xl mx-auto">
            <div class="mb-6">
                <div class="flex items-center gap-2 mb-1 text-sm">
                    <Link :href="route('flows.index')" class="text-gray-400 hover:text-accent">Flujos del bot</Link>
                    <span class="text-gray-300">/</span>
                    <Link :href="route('flows.edit', flow.id)" class="text-gray-400 hover:text-accent">{{ flow.name }}</Link>
                </div>
                <h1 class="text-2xl font-bold text-gray-900">Actividad</h1>
                <p class="text-sm text-gray-500 mt-1">Las últimas 50 conversaciones que atendió este flujo, bloque por bloque.</p>
            </div>

            <div class="flex flex-wrap gap-2 mb-4">
                <button v-for="f in FILTERS" :key="f.label" type="button"
                        class="px-3 py-1.5 rounded-full text-xs font-medium border transition"
                        :class="status === f.value ? 'bg-accent text-white border-accent' : 'bg-white text-gray-600 border-gray-200 hover:border-gray-300'"
                        @click="filter(f.value)">{{ f.label }}</button>
            </div>

            <div v-if="!runs.length" class="bg-white rounded-2xl p-10 shadow-sm border border-gray-100 text-center text-sm text-gray-500">
                Todavía no hay conversaciones{{ status ? ' con este filtro' : '' }}.
                <span v-if="!flow.is_active"> El flujo está apagado: enciéndelo para que empiece a atender.</span>
            </div>

            <div v-else class="space-y-2">
                <div v-for="run in runs" :key="run.id" class="bg-white rounded-2xl shadow-sm border border-gray-100">
                    <button type="button" class="w-full flex flex-wrap items-center gap-3 px-4 py-3 text-left" @click="toggle(run.id)">
                        <span class="text-xs text-gray-500 w-28 shrink-0">{{ formatDate(run.started_at) }}</span>
                        <span class="text-sm font-medium text-gray-900 min-w-0 truncate flex-1">{{ run.contact ?? 'Contacto' }}</span>
                        <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold" :class="RUN_STATUS[run.status]?.class ?? 'bg-gray-100 text-gray-600'">
                            {{ RUN_STATUS[run.status]?.label ?? run.status }}
                        </span>
                        <span v-if="run.ended_reason" class="text-xs text-gray-500">{{ ENDED_REASONS[run.ended_reason] ?? run.ended_reason }}</span>
                        <span class="text-[11px] text-gray-400">v{{ run.version }} · {{ run.steps_count }} pasos</span>
                        <span class="text-gray-400 text-xs">{{ open.has(run.id) ? '▾' : '▸' }}</span>
                    </button>

                    <div v-if="open.has(run.id)" class="px-4 pb-4 border-t border-gray-100">
                        <div class="flex items-center justify-between pt-3">
                            <p class="text-xs font-semibold text-gray-700">Recorrido</p>
                            <Link :href="route('chat.index', { conversation: run.conversation_id })" class="text-xs text-accent hover:underline">Abrir el chat →</Link>
                        </div>

                        <ol class="mt-2 space-y-2">
                            <li v-for="step in run.steps" :key="step.id" class="rounded-xl bg-gray-50 px-3 py-2">
                                <div class="flex flex-wrap items-center gap-2 text-xs">
                                    <span class="font-semibold text-gray-800">{{ step.node_type === 'engine' ? 'Motor' : (NODE_TYPES[step.node_type]?.label ?? step.node_type) }}</span>
                                    <span class="text-gray-500">→ {{ outcomeLabel(step) }}</span>
                                    <span v-if="step.delivery" class="ml-auto text-[11px] font-medium" :class="DELIVERY[step.delivery]?.class">{{ DELIVERY[step.delivery]?.label ?? step.delivery }}</span>
                                </div>
                                <p v-if="step.input" class="mt-1 text-xs text-gray-700"><span class="text-gray-400">Cliente:</span> {{ step.input }}</p>
                                <p v-if="step.output" class="mt-1 text-xs text-gray-700 whitespace-pre-line"><span class="text-gray-400">Bot:</span> {{ step.output }}</p>
                                <p v-if="step.failure" class="mt-1 text-xs text-red-600">{{ step.failure }}</p>
                                <p v-if="step.detail?.motivo" class="mt-1 text-[11px] text-gray-500">Motivo: {{ step.detail.motivo }}</p>
                            </li>
                        </ol>

                        <div v-if="variableEntries(run.variables).length" class="mt-3">
                            <p class="text-xs font-semibold text-gray-700 mb-1">Lo que recogió el bot</p>
                            <dl class="grid grid-cols-1 sm:grid-cols-2 gap-x-4 gap-y-1">
                                <template v-for="[k, v] in variableEntries(run.variables)" :key="k">
                                    <div class="text-[11px]"><dt class="inline text-gray-400">{{ k }}:</dt> <dd class="inline text-gray-700">{{ v }}</dd></div>
                                </template>
                            </dl>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
