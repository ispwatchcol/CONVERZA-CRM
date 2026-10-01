<script setup>
// El simulador: conversa con el flujo del lienzo (aunque no esté guardado) con
// el MISMO motor que atiende a los clientes, pero sin enviar nada por WhatsApp.
// Probar escribiéndole a un número real quema calidad del número.
import { computed, nextTick, ref } from 'vue';
import axios from 'axios';
import { ENDED_REASONS, NODE_TYPES, outcomeLabel } from './nodeCatalog';

const props = defineProps({
    flowId: { type: Number, required: true },
    getGraph: { type: Function, required: true },
    catalog: { type: Object, required: true },
});

const emit = defineEmits(['active-node', 'visited']);

const messages = ref([]);       // { from: 'client'|'bot'|'system'|'notice', text, options?, kind? }
const state = ref(null);        // lo que devuelve el servidor; null = sin arrancar
const trace = ref([]);
const input = ref('');
const loading = ref(false);
const error = ref(null);
const showSettings = ref(false);
const showTrace = ref(false);
const scroller = ref(null);

// Cómo es el cliente simulado.
const settings = ref({
    name: 'Cliente de prueba',
    phone: '',
    window_open: true,
    labels: [],
});

const status = computed(() => state.value?.status ?? null);
const live = computed(() => ['pending', 'waiting_input', 'waiting_timer'].includes(status.value));
const waitingTimer = computed(() => status.value === 'waiting_timer');
const ended = computed(() => state.value && !live.value);

const statusText = computed(() => {
    if (!state.value) return 'Escribe como si fueras el cliente para arrancar el flujo.';
    if (status.value === 'waiting_input') return 'Esperando la respuesta del cliente…';
    if (waitingTimer.value) return `En una espera hasta las ${formatTime(state.value.resume_at)}.`;
    return `Flujo terminado: ${ENDED_REASONS[state.value.ended_reason] ?? state.value.ended_reason}.`;
});

function formatTime(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

async function scrollDown() {
    await nextTick();
    if (scroller.value) scroller.value.scrollTop = scroller.value.scrollHeight;
}

async function call(payload) {
    loading.value = true;
    error.value = null;

    try {
        const { data } = await axios.post(route('flows.simulate', props.flowId), {
            graph: props.getGraph(),
            state: state.value,
            ...settings.value,
            ...payload,
        });

        for (const out of data.outbox ?? []) {
            messages.value.push({ from: 'bot', kind: out.kind, text: out.text, options: out.options ?? [], button: out.button });
        }
        for (const effect of data.effects ?? []) {
            messages.value.push({ from: 'system', text: describeEffect(effect) });
        }
        for (const notice of data.notices ?? []) {
            messages.value.push({ from: 'notice', text: notice });
        }

        // El traspaso de resguardo (ventana cerrada, error) también queda dicho.
        if (data.state && !['pending', 'waiting_input', 'waiting_timer'].includes(data.state.status)
            && !(data.effects ?? []).some((e) => e.kind === 'handoff')
            && data.state.ended_reason !== 'completed') {
            messages.value.push({ from: 'system', text: `⚠️ ${ENDED_REASONS[data.state.ended_reason] ?? data.state.ended_reason}` });
        }

        state.value = data.state;
        trace.value = [...trace.value, ...(data.trace ?? [])];
        emit('active-node', live.value ? data.state.current_node : null);
        emit('visited', [...new Set(trace.value.map((t) => t.node_id))]);
    } catch (e) {
        error.value = e.response?.data?.message ?? 'No se pudo simular. Revisa el flujo.';
    } finally {
        loading.value = false;
        scrollDown();
    }
}

function describeEffect(effect) {
    if (effect.kind === 'label') return `🏷️ ${effect.action === 'remove' ? 'Quita' : 'Pone'} la etiqueta «${effect.label}»`;
    if (effect.kind === 'contact') return `📇 Guarda en la ficha (${effect.field}): ${effect.value}`;
    if (effect.kind === 'handoff') {
        const parts = ['🤝 Pasa la conversación al equipo'];
        if (effect.team) parts.push(`(${effect.team})`);
        parts.push(effect.assign ? '· se auto-asigna' : '· queda sin asignar');
        return parts.join(' ') + (effect.note ? `\n\nNota interna:\n${effect.note}` : '');
    }
    return JSON.stringify(effect);
}

function send(text = null, replyId = null) {
    const body = (text ?? input.value).trim();
    if (!body || loading.value) return;
    if (ended.value) reset(false);

    messages.value.push({ from: 'client', text: body });
    input.value = '';
    call({ text: body, reply_id: replyId });
}

function skipWait() {
    messages.value.push({ from: 'system', text: '⏩ Espera saltada' });
    call({ skip_wait: true });
}

function reset(clearMessages = true) {
    state.value = null;
    trace.value = [];
    if (clearMessages) messages.value = [];
    error.value = null;
    emit('active-node', null);
    emit('visited', []);
}

function toggleLabel(id) {
    const list = settings.value.labels;
    const i = list.indexOf(id);
    if (i === -1) list.push(id); else list.splice(i, 1);
}

defineExpose({ reset });
</script>

<template>
    <div class="flex flex-col h-full min-h-0">
        <!-- Cabecera -->
        <div class="flex items-center justify-between gap-2 pb-3 border-b border-gray-100">
            <div>
                <p class="text-sm font-semibold text-gray-900">Simulador</p>
                <p class="text-[11px] text-gray-500">No envía nada por WhatsApp ni toca la base.</p>
            </div>
            <div class="flex items-center gap-1">
                <button type="button" class="p-1.5 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100" title="Cliente simulado" @click="showSettings = !showSettings">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16 7a4 4 0 11-8 0 4 4 0 018 0zM12 14a7 7 0 00-7 7h14a7 7 0 00-7-7z"/></svg>
                </button>
                <button type="button" class="px-2 py-1 rounded-lg text-xs text-gray-500 hover:text-gray-800 hover:bg-gray-100" @click="reset()">Reiniciar</button>
            </div>
        </div>

        <!-- Cliente simulado -->
        <div v-if="showSettings" class="py-3 border-b border-gray-100 space-y-2">
            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-0.5">Nombre del contacto</label>
                    <input v-model="settings.name" type="text" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs" :disabled="!!state" />
                </div>
                <div>
                    <label class="block text-[11px] font-medium text-gray-600 mb-0.5">Teléfono (para ispwatch)</label>
                    <input v-model="settings.phone" type="text" placeholder="3001234567" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs" :disabled="!!state" />
                </div>
            </div>
            <p class="text-[11px] text-gray-500">
                Con el teléfono de un cliente real, «Datos del cliente» trae sus datos de ispwatch (solo lectura).
            </p>
            <div v-if="catalog.labels?.length">
                <p class="text-[11px] font-medium text-gray-600 mb-1">Etiquetas que ya tiene</p>
                <div class="flex flex-wrap gap-1">
                    <button v-for="l in catalog.labels" :key="l.id" type="button" :disabled="!!state"
                            class="px-2 py-0.5 rounded-full text-[11px] border"
                            :class="settings.labels.includes(l.id) ? 'bg-pink-500 border-pink-500 text-white' : 'border-gray-200 text-gray-500'"
                            @click="toggleLabel(l.id)">{{ l.name }}</button>
                </div>
            </div>
            <label class="flex items-center gap-2 text-[11px] text-gray-600">
                <input v-model="settings.window_open" type="checkbox" class="rounded border-gray-300 text-accent focus:ring-accent" :disabled="!!state" />
                Ventana de 24 h abierta (desmárcalo para ver qué pasa si se cerró)
            </label>
            <p v-if="state" class="text-[11px] text-amber-700">Reinicia la simulación para cambiar al cliente.</p>
        </div>

        <!-- Conversación -->
        <div ref="scroller" class="flex-1 min-h-0 overflow-y-auto py-3 space-y-2 bg-[#efeae2] -mx-4 px-4">
            <p v-if="!messages.length" class="text-center text-xs text-gray-500 mt-8 px-6">
                Escribe abajo el primer mensaje del cliente (por ejemplo «Hola») y mira cómo responde tu flujo.
            </p>

            <template v-for="(m, i) in messages" :key="i">
                <div v-if="m.from === 'client'" class="flex justify-end">
                    <div class="max-w-[85%] rounded-xl rounded-tr-sm bg-[#d9fdd3] px-3 py-1.5 text-sm text-gray-900 whitespace-pre-line shadow-sm">{{ m.text }}</div>
                </div>

                <div v-else-if="m.from === 'bot'" class="flex justify-start">
                    <div class="max-w-[85%] rounded-xl rounded-tl-sm bg-white px-3 py-1.5 shadow-sm">
                        <p class="text-sm text-gray-900 whitespace-pre-line break-words">{{ m.text }}</p>
                        <div v-if="m.kind === 'buttons' || m.kind === 'list'" class="mt-2 border-t border-gray-100 pt-1.5 space-y-1">
                            <p v-if="m.kind === 'list'" class="text-[11px] text-center text-sky-600 font-medium">☰ {{ m.button }}</p>
                            <button v-for="opt in m.options" :key="opt.id" type="button"
                                    class="w-full text-center text-xs font-medium text-sky-600 rounded-lg py-1 hover:bg-sky-50 disabled:opacity-40"
                                    :disabled="loading || status !== 'waiting_input'"
                                    @click="send(opt.title, opt.id)">
                                {{ opt.title }}
                            </button>
                        </div>
                    </div>
                </div>

                <div v-else-if="m.from === 'system'" class="flex justify-center">
                    <p class="max-w-[90%] rounded-lg bg-white/80 px-3 py-1.5 text-[11px] text-gray-600 whitespace-pre-line">{{ m.text }}</p>
                </div>

                <div v-else class="flex justify-center">
                    <p class="max-w-[90%] rounded-lg bg-amber-50 px-3 py-1.5 text-[11px] text-amber-800">{{ m.text }}</p>
                </div>
            </template>

            <p v-if="loading" class="text-center text-[11px] text-gray-500">…</p>
        </div>

        <!-- Estado + controles -->
        <div class="pt-2 space-y-2">
            <p class="text-[11px] text-gray-500">{{ statusText }}</p>
            <p v-if="error" class="text-xs text-red-600 bg-red-50 rounded-lg px-2 py-1">{{ error }}</p>

            <button v-if="waitingTimer" type="button" class="w-full rounded-lg border border-slate-300 py-1.5 text-xs font-medium text-slate-700 hover:bg-slate-50" :disabled="loading" @click="skipWait">
                ⏩ Saltar la espera
            </button>

            <form class="flex items-center gap-2" @submit.prevent="send()">
                <input v-model="input" type="text" maxlength="1000"
                       :placeholder="ended ? 'Escribe para empezar otra simulación…' : 'Escribe como el cliente…'"
                       class="flex-1 min-w-0 px-3 py-2 border border-gray-200 rounded-full text-sm" />
                <button type="submit" class="shrink-0 w-9 h-9 rounded-full bg-accent text-white flex items-center justify-center disabled:opacity-50" :disabled="loading || !input.trim()">
                    <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 24 24"><path d="M2.01 21L23 12 2.01 3 2 10l15 2-15 2z"/></svg>
                </button>
            </form>

            <div v-if="trace.length">
                <button type="button" class="text-[11px] text-gray-500 hover:text-gray-800" @click="showTrace = !showTrace">
                    {{ showTrace ? '▾' : '▸' }} Recorrido ({{ trace.length }} pasos)
                </button>
                <ol v-if="showTrace" class="mt-1 max-h-40 overflow-y-auto space-y-0.5">
                    <li v-for="(t, i) in trace" :key="i" class="text-[11px] text-gray-600">
                        <span class="text-gray-400">{{ i + 1 }}.</span>
                        {{ t.node_type === 'engine' ? 'Motor' : (NODE_TYPES[t.node_type]?.label ?? t.node_type) }}
                        → <span class="font-medium">{{ outcomeLabel(t) }}</span>
                    </li>
                </ol>
            </div>
        </div>
    </div>
</template>
