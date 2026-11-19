<script setup>
// Agentes IA: el prompt de cada agente, su modelo y un chat para probarlo con la
// IA de verdad antes de ponerlo en un flujo (bloque «Agente IA»).
//
// El proveedor y la llave se configuran una vez en Configuración → Pagos e IA.
// La lista de modelos sale de la API de ese proveedor con la llave de la empresa.
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    agents: { type: Array, default: () => [] },
    // { ready, reason, provider, provider_label, model }
    ai: { type: Object, default: () => ({}) },
    variables: { type: Array, default: () => [] },
    limits: { type: Object, default: () => ({ max_instructions: 12000 }) },
});

const EXAMPLE = `Eres el asistente de ventas de {{empresa.nombre}}, un proveedor de internet por fibra óptica.

Lo que ofreces:
- Plan 20 megas: $60.000 al mes.
- Plan 50 megas: $90.000 al mes.
- Instalación gratis en el casco urbano; en zona rural, $50.000.

Cómo pagar:
{{empresa.medios_pago}}

Horario de atención: lunes a sábado de 8 a. m. a 6 p. m.

Si el cliente quiere contratar, pídele su nombre completo, dirección y barrio, y dile que un asesor lo contacta para agendar la instalación.
No hables de temas que no sean de la empresa.`;

// ── Chat de prueba (estado) ──────────────────────────────────────────────────
// Va antes que el formulario: load() lo vacía al cambiar de agente.
const chat = ref([]);          // [{ role: 'user'|'assistant', content, action? }]
const chatInput = ref('');
const chatBusy = ref(false);
const chatError = ref(null);
const chatBox = ref(null);

// ── Lista y formulario ───────────────────────────────────────────────────────
const selectedId = ref(props.agents[0]?.id ?? null);
const selected = computed(() => props.agents.find((a) => a.id === selectedId.value) ?? null);

const form = useForm({ name: '', instructions: '', model: '', is_active: true });

function load(agent) {
    form.defaults({
        name: agent?.name ?? '',
        instructions: agent?.instructions ?? '',
        model: agent?.model ?? '',
        is_active: agent?.is_active ?? true,
    });
    form.reset();
    form.clearErrors();
    chat.value = [];
    chatError.value = null;
}
load(selected.value);

function pick(agent) {
    if (form.isDirty && !confirm('Tienes cambios sin guardar en este agente. ¿Descartarlos?')) return;
    selectedId.value = agent?.id ?? null;
    load(agent);
}

function startNew() {
    pick(null);
    form.name = 'Asistente';
    form.instructions = EXAMPLE;
}

function save() {
    const options = { preserveScroll: true, onSuccess: () => form.defaults() };
    form.transform((d) => ({ ...d, model: String(d.model ?? '').trim() || null }));

    if (selected.value) {
        form.put(route('ai-agents.update', selected.value.id), options);
    } else {
        form.post(route('ai-agents.store'), {
            ...options,
            onSuccess: () => {
                // El recién creado es el de id más alto.
                const newest = [...props.agents].sort((a, b) => b.id - a.id)[0];
                selectedId.value = newest?.id ?? null;
                load(newest);
            },
        });
    }
}

function destroy() {
    if (!selected.value || !confirm(`¿Eliminar el agente «${selected.value.name}»? Si un flujo lo usa, ese bloque pasará la conversación al equipo.`)) return;
    router.delete(route('ai-agents.destroy', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { selectedId.value = props.agents[0]?.id ?? null; load(selected.value); },
    });
}

// ── Modelos (de la API del proveedor) ────────────────────────────────────────
const models = ref([]);
const modelsLoading = ref(false);
const modelsMessage = ref(null);
const manualModel = ref(false);

async function loadModels() {
    modelsLoading.value = true;
    modelsMessage.value = null;
    try {
        const { data } = await axios.get(route('ai-agents.models'));
        models.value = data.models ?? [];
        modelsMessage.value = data.ok ? (models.value.length ? null : 'Tu proveedor no informó modelos: escríbelo a mano.') : data.message;
        if (!models.value.length) manualModel.value = true;
    } catch (e) {
        modelsMessage.value = e.response?.data?.message ?? 'No se pudo traer la lista de modelos.';
    } finally {
        modelsLoading.value = false;
    }
}
if (props.ai?.ready) loadModels();

const modelOptions = computed(() => {
    const list = [...models.value];
    const current = String(form.model ?? '').trim();
    if (current && !list.some((m) => m.id === current)) list.unshift({ id: current, label: `${current} (actual)` });
    return list;
});

// ── Variables ────────────────────────────────────────────────────────────────
// En la plantilla, un '}}' literal cerraría la interpolación: se arma en JS.
const token = (name) => '{' + '{' + name + '}' + '}';
const promptBox = ref(null);
function insertVariable(name) {
    const el = promptBox.value;
    const text = token(name);
    if (!el) { form.instructions += text; return; }
    const start = el.selectionStart ?? form.instructions.length;
    const end = el.selectionEnd ?? start;
    form.instructions = form.instructions.slice(0, start) + text + form.instructions.slice(end);
    nextTick(() => { el.focus(); el.selectionStart = el.selectionEnd = start + text.length; });
}

// ── Chat de prueba ───────────────────────────────────────────────────────────
// Usa lo que está en el formulario (aunque no esté guardado) y la IA de verdad.
const chatEnded = computed(() => ['human', 'end'].includes(chat.value[chat.value.length - 1]?.action));

async function send() {
    const text = chatInput.value.trim();
    if (!text || chatBusy.value || !form.instructions.trim()) return;

    chat.value.push({ role: 'user', content: text });
    chatInput.value = '';
    chatBusy.value = true;
    chatError.value = null;
    scrollChat();

    try {
        const { data } = await axios.post(route('ai-agents.test'), {
            instructions: form.instructions,
            model: String(form.model ?? '').trim() || null,
            history: chat.value.filter((m) => m.content).map(({ role, content }) => ({ role, content })).slice(-40),
        });
        chat.value.push({ role: 'assistant', content: data.reply, action: data.action, exit: data.exit, model: data.model });
    } catch (e) {
        chatError.value = e.response?.data?.message ?? 'La IA no respondió. Intenta de nuevo.';
        chat.value.pop();
        chatInput.value = text;
    } finally {
        chatBusy.value = false;
        scrollChat();
    }
}

function resetChat() {
    chat.value = [];
    chatError.value = null;
}

function scrollChat() {
    nextTick(() => { if (chatBox.value) chatBox.value.scrollTop = chatBox.value.scrollHeight; });
}

watch(() => props.agents, () => {
    // Tras guardar, el formulario queda limpio con lo guardado.
    if (selected.value && !form.isDirty) load(selected.value);
});
</script>

<template>
    <Head title="Agentes IA" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-7xl mx-auto">
            <div class="mb-6 flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Agentes IA</h1>
                    <p class="text-sm text-gray-500 mt-1 max-w-3xl">
                        Escribe cómo debe responder tu agente, pruébalo aquí con tu IA y ponlo a conversar con tus clientes en un flujo con el
                        bloque «Agente IA». Si el cliente pide una persona o el agente no sabe, el flujo pasa la conversación a tu equipo.
                    </p>
                </div>
                <button type="button" @click="startNew"
                        class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-semibold hover:opacity-90">
                    + Nuevo agente
                </button>
            </div>

            <!-- Estado de la IA de la empresa -->
            <div v-if="!ai.ready" class="mb-4 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div class="text-sm text-amber-800">
                    <p class="font-semibold">Tus agentes todavía no pueden responder.</p>
                    <p class="text-xs mt-0.5">{{ ai.reason }}
                        <Link :href="route('settings.index', { tab: 'pagos' })" class="underline">Ir a Configuración → Pagos e IA</Link></p>
                </div>
            </div>
            <div v-else class="mb-4 flex flex-wrap items-center gap-2 text-xs text-gray-600">
                <span class="inline-flex items-center gap-1.5 rounded-full bg-emerald-50 text-emerald-800 px-2.5 py-1 font-medium">
                    <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> IA lista
                </span>
                <span>Proveedor: <strong>{{ ai.provider_label }}</strong> · modelo por defecto: <code class="font-mono">{{ ai.model }}</code></span>
                <Link :href="route('settings.index', { tab: 'pagos' })" class="text-accent hover:underline">cambiar</Link>
            </div>

            <div class="grid lg:grid-cols-12 gap-4">
                <!-- Lista -->
                <aside class="lg:col-span-3 bg-white rounded-2xl border border-gray-100 shadow-sm p-3 h-fit">
                    <p v-if="!agents.length && !form.name" class="text-sm text-gray-500 p-3">
                        Todavía no tienes agentes. Crea uno con «Nuevo agente»: trae un ejemplo para que lo adaptes.
                    </p>
                    <button v-for="a in agents" :key="a.id" type="button" @click="pick(a)"
                            class="w-full text-left px-3 py-2.5 rounded-xl text-sm transition flex items-center justify-between gap-2"
                            :class="a.id === selectedId ? 'bg-violet-50 text-violet-900' : 'hover:bg-gray-50 text-gray-700'">
                        <span class="truncate font-medium">{{ a.name }}</span>
                        <span class="shrink-0 text-[10px] font-semibold" :class="a.is_active ? 'text-emerald-600' : 'text-gray-400'">
                            {{ a.is_active ? 'activo' : 'apagado' }}
                        </span>
                    </button>
                    <div v-if="!selected && form.name" class="px-3 py-2.5 rounded-xl text-sm bg-violet-50 text-violet-900 font-medium">
                        {{ form.name }} <span class="text-[10px] text-violet-500">(sin guardar)</span>
                    </div>
                </aside>

                <!-- Editor -->
                <section v-if="selected || form.name" class="lg:col-span-5 bg-white rounded-2xl border border-gray-100 shadow-sm p-5">
                    <form @submit.prevent="save" class="space-y-4">
                        <div class="grid grid-cols-3 gap-3 items-end">
                            <div class="col-span-2">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                                <input v-model="form.name" type="text" maxlength="80" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                                <p v-if="form.errors.name" class="text-[11px] text-red-600 mt-1">{{ form.errors.name }}</p>
                            </div>
                            <label class="flex items-center gap-2 text-sm text-gray-700 pb-2">
                                <input v-model="form.is_active" type="checkbox" class="rounded border-gray-300 text-accent focus:ring-accent">
                                Activo
                            </label>
                        </div>

                        <div>
                            <div class="flex items-center justify-between">
                                <label class="block text-sm font-medium text-gray-700 mb-1">Modelo</label>
                                <div class="flex items-center gap-3">
                                    <button v-if="ai.ready" type="button" class="text-[11px] text-accent hover:underline" :disabled="modelsLoading" @click="loadModels">
                                        {{ modelsLoading ? 'Cargando…' : 'Actualizar lista' }}
                                    </button>
                                    <button v-if="models.length" type="button" class="text-[11px] text-accent hover:underline" @click="manualModel = !manualModel">
                                        {{ manualModel ? 'Elegir de la lista' : 'Escribirlo a mano' }}
                                    </button>
                                </div>
                            </div>
                            <select v-if="models.length && !manualModel" v-model="form.model" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                                <option value="">El de mi empresa ({{ ai.model ?? 'sin configurar' }})</option>
                                <option v-for="m in modelOptions" :key="m.id" :value="m.id">{{ m.label }}</option>
                            </select>
                            <input v-else v-model="form.model" type="text" autocomplete="off" :placeholder="`En blanco = el de mi empresa (${ai.model ?? 'sin configurar'})`"
                                   class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm font-mono">
                            <p v-if="form.errors.model" class="text-[11px] text-red-600 mt-1">{{ form.errors.model }}</p>
                            <p v-else-if="modelsMessage" class="text-[11px] text-amber-700 mt-1">{{ modelsMessage }}</p>
                            <p v-else class="text-[11px] text-gray-400 mt-1">Los modelos que tu llave puede usar en {{ ai.provider_label ?? 'tu proveedor' }}. Uno más grande responde mejor y cuesta más.</p>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Instrucciones (el prompt)</label>
                            <textarea ref="promptBox" v-model="form.instructions" rows="16" :maxlength="limits.max_instructions"
                                      placeholder="Quién es el agente, qué vende, precios, horarios, qué NO debe prometer…"
                                      class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm leading-relaxed font-mono"></textarea>
                            <div class="flex justify-between text-[11px] mt-1">
                                <span v-if="form.errors.instructions" class="text-red-600">{{ form.errors.instructions }}</span>
                                <span v-else class="text-gray-400">Lo que no esté aquí, el agente no lo sabe: no inventa precios ni datos de la cuenta.</span>
                                <span class="text-gray-400 shrink-0 ml-2">{{ form.instructions.length }}/{{ limits.max_instructions }}</span>
                            </div>
                        </div>

                        <div class="rounded-xl bg-gray-50 p-3">
                            <p class="text-xs font-semibold text-gray-700 mb-2">Variables (toca para insertar)</p>
                            <div class="flex flex-wrap gap-1.5">
                                <button v-for="v in variables" :key="v.name" type="button" :title="v.description" @click="insertVariable(v.name)"
                                        class="px-2 py-1 rounded-lg bg-white border border-gray-200 text-[11px] font-mono text-accent hover:border-accent" v-text="token(v.name)"></button>
                            </div>
                            <p class="text-[11px] text-gray-500 mt-2">
                                En un flujo también puedes usar las de bloques anteriores (por ejemplo <code class="text-accent" v-text="token('cliente.saldo')"></code>
                                después de «Datos del cliente»). Converza le agrega siempre las reglas de seguridad y de formato de WhatsApp.
                            </p>
                        </div>

                        <div class="flex items-center justify-between gap-3">
                            <button v-if="selected" type="button" @click="destroy" class="text-sm text-red-600 hover:underline">Eliminar</button>
                            <span v-else></span>
                            <button type="submit" :disabled="form.processing || !form.isDirty && !!selected"
                                    class="px-5 py-2 bg-accent text-white rounded-xl text-sm font-semibold hover:opacity-90 disabled:opacity-50">
                                {{ form.processing ? 'Guardando…' : (selected ? 'Guardar cambios' : 'Crear agente') }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- Chat de prueba -->
                <section v-if="selected || form.name" class="lg:col-span-4 bg-white rounded-2xl border border-gray-100 shadow-sm flex flex-col min-h-[32rem]">
                    <div class="px-4 py-3 border-b border-gray-100 flex items-center justify-between">
                        <div>
                            <p class="text-sm font-semibold text-gray-800">Probar el agente</p>
                            <p class="text-[11px] text-gray-500">Con tu IA de verdad y lo que está en el formulario (aunque no esté guardado). No se envía nada por WhatsApp.</p>
                        </div>
                        <button type="button" class="text-[11px] text-accent hover:underline shrink-0" @click="resetChat">Reiniciar</button>
                    </div>

                    <div ref="chatBox" class="flex-1 overflow-y-auto p-4 space-y-2 bg-[#efeae2]">
                        <p v-if="!chat.length" class="text-xs text-gray-500 text-center mt-8">Escribe como si fueras un cliente.</p>
                        <template v-for="(m, i) in chat" :key="i">
                            <div class="flex" :class="m.role === 'user' ? 'justify-end' : 'justify-start'">
                                <div class="max-w-[85%] rounded-xl px-3 py-2 text-sm whitespace-pre-wrap shadow-sm"
                                     :class="m.role === 'user' ? 'bg-[#d9fdd3] text-gray-900' : 'bg-white text-gray-900'">
                                    <span v-if="m.content">{{ m.content }}</span>
                                    <span v-else class="italic text-gray-400">(sin mensaje)</span>
                                </div>
                            </div>
                            <p v-if="m.exit" class="text-center text-[11px] font-medium"
                               :class="m.action === 'human' ? 'text-orange-700' : 'text-emerald-700'">
                                {{ m.action === 'human' ? '🙋' : '✅' }} {{ m.exit }}
                            </p>
                        </template>
                        <div v-if="chatBusy" class="flex justify-start">
                            <div class="rounded-xl px-3 py-2 text-sm bg-white text-gray-400 shadow-sm">escribiendo…</div>
                        </div>
                    </div>

                    <p v-if="chatError" class="px-4 py-2 text-xs text-red-600 bg-red-50">{{ chatError }}</p>
                    <form @submit.prevent="send" class="p-3 border-t border-gray-100 flex gap-2">
                        <input v-model="chatInput" type="text" maxlength="2000" :disabled="chatBusy || !ai.ready || chatEnded"
                               :placeholder="!ai.ready ? 'Configura tu IA para probar' : (chatEnded ? 'La conversación terminó: reinicia para probar otra' : 'Escribe un mensaje…')"
                               class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm disabled:bg-gray-50">
                        <button type="submit" :disabled="chatBusy || !chatInput.trim() || !ai.ready || chatEnded"
                                class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-semibold disabled:opacity-50">Enviar</button>
                    </form>
                    <p class="px-4 pb-3 text-[10px] text-gray-400">Cada mensaje de prueba usa tu IA y cuesta como una conversación real.</p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
