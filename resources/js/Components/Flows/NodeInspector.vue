<script setup>
// Panel de propiedades del bloque seleccionado. Edita `node.data` en el lugar:
// el nodo es el del store de Vue Flow, así que el lienzo se actualiza solo y el
// editor detecta el cambio para marcar "sin guardar".
import { computed, watch } from 'vue';
import FlowTextarea from './FlowTextarea.vue';
import { NODE_TYPES, OPERATOR_OPTIONS, WEEK_DAYS, newId } from './nodeCatalog';

const props = defineProps({
    node: { type: Object, required: true },
    catalog: { type: Object, required: true },
    variables: { type: Array, default: () => [] },
    issues: { type: Array, default: () => [] },
});

const emit = defineEmits(['delete', 'duplicate']);

const data = computed(() => props.node.data);
const meta = computed(() => NODE_TYPES[props.node.type] ?? { label: props.node.type });

// Estructuras que un bloque viejo o una plantilla podrían no traer.
watch(() => props.node.id, () => {
    const d = props.node.data;
    if (props.node.type === 'start') {
        d.trigger ??= { type: 'conversation_start', idle_hours: null };
        d.timezone ??= 'America/Bogota';
    }
    if (props.node.type === 'menu') {
        d.options ??= [];
        d.style ??= 'text';
        d.append_options ??= true;
        d.max_retries ??= 1;
    }
    if (props.node.type === 'condition') {
        d.rules ??= [];
        d.match ??= 'all';
    }
    if (props.node.type === 'question') {
        d.validation ??= 'any';
        d.max_retries ??= 1;
        d.save_to_contact ??= '';
    }
    if (props.node.type === 'handoff') {
        d.assign ??= 'auto';
        d.note ??= true;
    }
}, { immediate: true });

// ── Inicio ───────────────────────────────────────────────────────────────────
const idleEnabled = computed({
    get: () => !!data.value.trigger?.idle_hours,
    set: (on) => { data.value.trigger.idle_hours = on ? 12 : null; },
});

const keywordsText = computed({
    get: () => {
        const k = data.value.trigger?.keywords;
        return Array.isArray(k) ? k.join(', ') : (k ?? '');
    },
    set: (value) => { data.value.trigger.keywords = value; },
});

// ── Pregunta ─────────────────────────────────────────────────────────────────
// El nombre de la variable se normaliza mientras se escribe: minúsculas, sin
// tildes ni espacios. Es lo que pide el validador.
function normalizeVarName(value) {
    return String(value ?? '')
        .toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '')
        .replace(/[^a-z0-9_]+/g, '_')
        .replace(/^_+/, '')
        .slice(0, 40);
}

// ── Menú ─────────────────────────────────────────────────────────────────────
const titleMax = computed(() => ({ buttons: 20, list: 24 }[data.value.style] ?? 80));
const maxOptions = computed(() => (data.value.style === 'buttons' ? 3 : 10));

function addOption() {
    if ((data.value.options ?? []).length >= maxOptions.value) return;
    data.value.options.push({ id: newId('o'), label: `Opción ${data.value.options.length + 1}`, keywords: '' });
}

function moveOption(index, delta) {
    const list = data.value.options;
    const target = index + delta;
    if (target < 0 || target >= list.length) return;
    const [item] = list.splice(index, 1);
    list.splice(target, 0, item);
}

// ── Condición ────────────────────────────────────────────────────────────────
function addRule(kind) {
    const rule = { kind };
    if (kind === 'variable') Object.assign(rule, { variable: 'respuesta', operator: 'contains', value: '' });
    if (kind === 'label') Object.assign(rule, { label_id: props.catalog.labels?.[0]?.id ?? null, operator: 'has' });
    if (kind === 'schedule') Object.assign(rule, { days: [1, 2, 3, 4, 5], start: '08:00', end: '18:00' });
    data.value.rules.push(rule);
}

function toggleDay(rule, day) {
    rule.days ??= [];
    const i = rule.days.indexOf(day);
    if (i === -1) rule.days.push(day); else rule.days.splice(i, 1);
}

const variableNames = computed(() => props.variables.map((v) => v.name));

// En la plantilla, un '}}' literal cerraría la interpolación: se arma en JS.
const token = (name) => '{' + '{' + name + '}' + '}';

// ── Espera ───────────────────────────────────────────────────────────────────
const maxWait = computed(() => props.catalog.limits?.max_wait_minutes ?? 1380);
</script>

<template>
    <div class="space-y-5">
        <!-- Encabezado -->
        <div class="flex items-start justify-between gap-3">
            <div>
                <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Bloque</p>
                <h3 class="text-base font-semibold text-gray-900">{{ meta.label }}</h3>
                <p class="text-xs text-gray-500">{{ meta.hint }}</p>
            </div>
            <div v-if="node.type !== 'start'" class="flex items-center gap-1 shrink-0">
                <button type="button" class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100" title="Duplicar bloque" @click="emit('duplicate')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 16H6a2 2 0 01-2-2V6a2 2 0 012-2h8a2 2 0 012 2v2m-6 12h8a2 2 0 002-2v-8a2 2 0 00-2-2h-8a2 2 0 00-2 2v8a2 2 0 002 2z"/></svg>
                </button>
                <button type="button" class="p-2 rounded-lg text-gray-400 hover:text-red-600 hover:bg-red-50" title="Borrar bloque (Supr)" @click="emit('delete')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                </button>
            </div>
        </div>

        <!-- Problemas de este bloque -->
        <div v-if="issues.length" class="space-y-1.5">
            <p
                v-for="(issue, i) in issues"
                :key="i"
                class="text-xs rounded-lg px-3 py-2"
                :class="issue.severity === 'error' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'"
            >
                {{ issue.message }}
            </p>
        </div>

        <!-- ═══ INICIO ═══ -->
        <template v-if="node.type === 'start'">
            <div class="space-y-2">
                <label class="block text-sm font-medium text-gray-700">¿Cuándo arranca este flujo?</label>
                <button type="button" class="w-full text-left rounded-xl border p-3 transition"
                        :class="data.trigger.type === 'conversation_start' ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'"
                        @click="data.trigger.type = 'conversation_start'">
                    <p class="text-sm font-semibold text-gray-900">Cuando empieza una conversación</p>
                    <p class="text-xs text-gray-500 mt-0.5">Un contacto nuevo escribe, o una conversación cerrada se reabre.</p>
                </button>
                <button type="button" class="w-full text-left rounded-xl border p-3 transition"
                        :class="data.trigger.type === 'keyword' ? 'border-emerald-300 bg-emerald-50' : 'border-gray-200 hover:border-gray-300'"
                        @click="data.trigger.type = 'keyword'">
                    <p class="text-sm font-semibold text-gray-900">Cuando el cliente escribe una palabra clave</p>
                    <p class="text-xs text-gray-500 mt-0.5">Ej. «menú», «saldo», «soporte». Gana sobre el inicio de conversación.</p>
                </button>
            </div>

            <div v-if="data.trigger.type === 'conversation_start'" class="rounded-xl bg-gray-50 p-3 space-y-2">
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input v-model="idleEnabled" type="checkbox" class="mt-0.5 rounded border-gray-300 text-accent focus:ring-accent" />
                    <span>También cuando el cliente vuelve a escribir después de un silencio largo</span>
                </label>
                <div v-if="idleEnabled" class="flex items-center gap-2 pl-6">
                    <input v-model.number="data.trigger.idle_hours" type="number" min="1" max="720" class="w-20 px-2 py-1.5 border border-gray-200 rounded-lg text-sm" />
                    <span class="text-xs text-gray-500">horas sin mensajes en el chat</span>
                </div>
                <p class="text-[11px] text-gray-500">
                    Útil si tus chats casi nunca se cierran: sin esto, un cliente que ya te había escrito antes no vuelve a ver el bot.
                </p>
            </div>

            <div v-else class="rounded-xl bg-gray-50 p-3 space-y-2">
                <label class="block text-xs font-medium text-gray-700">Palabras clave (separadas por coma)</label>
                <input v-model="keywordsText" type="text" placeholder="menú, ayuda, saldo" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm" />
                <div class="flex gap-2">
                    <button type="button" class="flex-1 text-xs rounded-lg border px-2 py-1.5"
                            :class="(data.trigger.match ?? 'contains') === 'contains' ? 'border-emerald-300 bg-white text-emerald-700 font-semibold' : 'border-gray-200 text-gray-500'"
                            @click="data.trigger.match = 'contains'">El mensaje la contiene</button>
                    <button type="button" class="flex-1 text-xs rounded-lg border px-2 py-1.5"
                            :class="data.trigger.match === 'exact' ? 'border-emerald-300 bg-white text-emerald-700 font-semibold' : 'border-gray-200 text-gray-500'"
                            @click="data.trigger.match = 'exact'">El mensaje es solo eso</button>
                </div>
                <p class="text-[11px] text-gray-500">No importan mayúsculas ni tildes. Se busca la palabra completa: «ver» no se activa con «verificar».</p>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Zona horaria (horarios y saludo)</label>
                <select v-model="data.timezone" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                    <option v-for="tz in catalog.timezones" :key="tz" :value="tz">{{ tz }}</option>
                </select>
            </div>

            <div class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2 space-y-1">
                <p>El bot nunca atiende un chat que ya tiene asesor asignado, y si un asesor toma el chat o le escribe al cliente, el bot se calla.</p>
                <p>Mientras haya un flujo encendido, el bot clásico de Configuración → Bot queda en pausa.</p>
            </div>
        </template>

        <!-- ═══ MENSAJE ═══ -->
        <template v-else-if="node.type === 'message'">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Texto</label>
                <FlowTextarea v-model="data.text" :variables="variables" :rows="6" placeholder="Hola {{contacto.primer_nombre}}…" />
            </div>
        </template>

        <!-- ═══ PREGUNTA ═══ -->
        <template v-else-if="node.type === 'question'">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Pregunta</label>
                <FlowTextarea v-model="data.text" :variables="variables" :rows="4" placeholder="¿Cuál es tu número de cédula?" />
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Guardar la respuesta en</label>
                <div class="flex items-center gap-1">
                    <span class="text-sm text-gray-400" v-text="'{' + '{'"></span>
                    <input :value="data.save_as" type="text" placeholder="cedula"
                           class="flex-1 px-3 py-2 border border-gray-200 rounded-xl text-sm font-mono"
                           @input="data.save_as = normalizeVarName($event.target.value); $event.target.value = data.save_as" />
                    <span class="text-sm text-gray-400" v-text="'}' + '}'"></span>
                </div>
                <p class="text-[11px] text-gray-500 mt-1">Después la usas en otros bloques, y aparece en la nota que recibe el asesor.</p>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Tipo de respuesta</label>
                    <select v-model="data.validation" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                        <option value="any">Cualquier texto</option>
                        <option value="number">Un número</option>
                        <option value="email">Un correo</option>
                        <option value="phone">Un teléfono</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Reintentos</label>
                    <select v-model.number="data.max_retries" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                        <option v-for="n in [0, 1, 2, 3]" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>
            </div>
            <div v-if="data.validation !== 'any'">
                <label class="block text-xs font-medium text-gray-700 mb-1">Si la respuesta no sirve, decir</label>
                <FlowTextarea v-model="data.retry_text" :variables="variables" :rows="2" placeholder="(texto por defecto según el tipo)" />
                <p class="text-[11px] text-gray-500 mt-1">Agotados los reintentos, el flujo sale por «Sin respuesta válida».</p>
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Guardar también en la ficha del contacto</label>
                <select v-model="data.save_to_contact" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                    <option value="">No</option>
                    <option value="name">Como su nombre</option>
                    <option value="email">Como su correo</option>
                </select>
            </div>
        </template>

        <!-- ═══ MENÚ ═══ -->
        <template v-else-if="node.type === 'menu'">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Texto del menú</label>
                <FlowTextarea v-model="data.text" :variables="variables" :rows="4" :maxlength="data.style === 'text' ? 4096 : 1024" />
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Cómo se ven las opciones</label>
                <div class="grid grid-cols-3 gap-1.5">
                    <button v-for="s in [{ v: 'text', l: 'Numeradas', h: 'Cualquier teléfono' }, { v: 'buttons', l: 'Botones', h: 'Hasta 3' }, { v: 'list', l: 'Lista', h: 'Hasta 10' }]"
                            :key="s.v" type="button"
                            class="rounded-lg border px-2 py-1.5 text-center"
                            :class="data.style === s.v ? 'border-indigo-300 bg-indigo-50 text-indigo-700' : 'border-gray-200 text-gray-600 hover:border-gray-300'"
                            @click="data.style = s.v">
                        <span class="block text-xs font-semibold">{{ s.l }}</span>
                        <span class="block text-[10px] opacity-70">{{ s.h }}</span>
                    </button>
                </div>
            </div>

            <div v-if="data.style === 'list'">
                <label class="block text-xs font-medium text-gray-700 mb-1">Texto del botón que abre la lista</label>
                <input v-model="data.button_label" type="text" maxlength="20" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm" />
            </div>

            <label v-if="data.style === 'text'" class="flex items-start gap-2 text-xs text-gray-700">
                <input v-model="data.append_options" type="checkbox" class="mt-0.5 rounded border-gray-300 text-accent focus:ring-accent" />
                <span>Agregar las opciones numeradas debajo del texto (1️⃣ 2️⃣ …). Desmárcalo si tu texto ya las trae.</span>
            </label>

            <div>
                <div class="flex items-center justify-between mb-1">
                    <label class="text-sm font-medium text-gray-700">Opciones</label>
                    <span class="text-[11px] text-gray-400">{{ data.options.length }}/{{ maxOptions }}</span>
                </div>
                <div class="space-y-2">
                    <div v-for="(opt, i) in data.options" :key="opt.id" class="rounded-xl border border-gray-200 p-2 space-y-1.5">
                        <div class="flex items-center gap-1.5">
                            <span class="w-5 h-5 rounded-full bg-indigo-100 text-indigo-700 text-[10px] font-bold flex items-center justify-center shrink-0">{{ i + 1 }}</span>
                            <input v-model="opt.label" type="text" :maxlength="titleMax" placeholder="Nombre de la opción"
                                   class="flex-1 min-w-0 px-2 py-1.5 border border-gray-200 rounded-lg text-sm" />
                            <button type="button" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="i === 0" title="Subir" @click="moveOption(i, -1)">↑</button>
                            <button type="button" class="p-1 text-gray-400 hover:text-gray-700 disabled:opacity-30" :disabled="i === data.options.length - 1" title="Bajar" @click="moveOption(i, 1)">↓</button>
                            <button type="button" class="p-1 text-gray-400 hover:text-red-600" title="Quitar opción" @click="data.options.splice(i, 1)">✕</button>
                        </div>
                        <input v-model="opt.keywords" type="text" placeholder="Palabras clave: saldo, cuánto debo, factura"
                               class="w-full px-2 py-1.5 border border-gray-100 bg-gray-50 rounded-lg text-xs" />
                    </div>
                </div>
                <button type="button" class="mt-2 text-xs font-medium text-accent hover:underline disabled:opacity-40 disabled:no-underline"
                        :disabled="data.options.length >= maxOptions" @click="addOption">
                    + Agregar opción
                </button>
                <p class="text-[11px] text-gray-500 mt-1">
                    El cliente elige tocando el botón, escribiendo el número, el nombre de la opción o una de sus palabras clave.
                </p>
            </div>

            <div class="grid grid-cols-3 gap-3 items-end">
                <div class="col-span-2">
                    <label class="block text-xs font-medium text-gray-700 mb-1">Si no entiende, decir</label>
                    <input v-model="data.retry_text" type="text" placeholder="No entendí tu respuesta 😅. Elige una de las opciones:"
                           class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm" />
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Reintentos</label>
                    <select v-model.number="data.max_retries" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                        <option v-for="n in [0, 1, 2, 3]" :key="n" :value="n">{{ n }}</option>
                    </select>
                </div>
            </div>
            <p class="text-[11px] text-gray-500 -mt-3">Agotados los reintentos, el flujo sale por «No entendió».</p>
        </template>

        <!-- ═══ CONDICIÓN ═══ -->
        <template v-else-if="node.type === 'condition'">
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Sale por «Sí» cuando se cumplen</label>
                <select v-model="data.match" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                    <option value="all">Todas las reglas</option>
                    <option value="any">Al menos una regla</option>
                </select>
            </div>

            <div class="space-y-2">
                <div v-for="(rule, i) in data.rules" :key="i" class="rounded-xl border border-gray-200 p-2.5 space-y-2">
                    <div class="flex items-center justify-between">
                        <select v-model="rule.kind" class="px-2 py-1 border border-gray-200 rounded-lg text-xs font-semibold">
                            <option value="variable">Una variable</option>
                            <option value="label">Una etiqueta del contacto</option>
                            <option value="schedule">El horario</option>
                        </select>
                        <button type="button" class="p-1 text-gray-400 hover:text-red-600 text-xs" title="Quitar regla" @click="data.rules.splice(i, 1)">✕</button>
                    </div>

                    <template v-if="rule.kind === 'variable'">
                        <input v-model="rule.variable" list="flow-variable-names" type="text" placeholder="respuesta"
                               class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs font-mono" />
                        <select v-model="rule.operator" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs">
                            <option v-for="op in OPERATOR_OPTIONS" :key="op.value" :value="op.value">{{ op.label }}</option>
                        </select>
                        <input v-if="!['is_empty', 'is_not_empty'].includes(rule.operator)" v-model="rule.value" type="text" placeholder="valor"
                               class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs" />
                    </template>

                    <template v-else-if="rule.kind === 'label'">
                        <select v-model="rule.operator" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs">
                            <option value="has">Tiene la etiqueta</option>
                            <option value="not_has">No tiene la etiqueta</option>
                        </select>
                        <select v-model.number="rule.label_id" class="w-full px-2 py-1.5 border border-gray-200 rounded-lg text-xs">
                            <option v-for="l in catalog.labels" :key="l.id" :value="l.id">{{ l.name }}</option>
                        </select>
                        <p v-if="!catalog.labels?.length" class="text-[11px] text-amber-700">No tienes etiquetas. Créalas en Etiquetas.</p>
                    </template>

                    <template v-else-if="rule.kind === 'schedule'">
                        <div class="flex flex-wrap gap-1">
                            <button v-for="d in WEEK_DAYS" :key="d.value" type="button"
                                    class="px-2 py-1 rounded-md text-[11px] font-semibold border"
                                    :class="(rule.days ?? []).includes(d.value) ? 'bg-amber-500 border-amber-500 text-white' : 'border-gray-200 text-gray-500'"
                                    @click="toggleDay(rule, d.value)">{{ d.label }}</button>
                        </div>
                        <div class="flex items-center gap-2">
                            <input v-model="rule.start" type="time" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs" />
                            <span class="text-xs text-gray-400">a</span>
                            <input v-model="rule.end" type="time" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs" />
                        </div>
                        <p class="text-[11px] text-gray-500">«Sí» dentro de la franja. Si el fin es menor que el inicio, la franja cruza la medianoche.</p>
                    </template>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 text-xs">
                <button type="button" class="text-accent font-medium hover:underline" @click="addRule('variable')">+ Variable</button>
                <button type="button" class="text-accent font-medium hover:underline" @click="addRule('label')">+ Etiqueta</button>
                <button type="button" class="text-accent font-medium hover:underline" @click="addRule('schedule')">+ Horario</button>
            </div>

            <datalist id="flow-variable-names">
                <option v-for="name in variableNames" :key="name" :value="name" />
            </datalist>
        </template>

        <!-- ═══ DATOS DEL CLIENTE ═══ -->
        <template v-else-if="node.type === 'ispwatch'">
            <p v-if="!catalog.ispwatchLinked" class="text-xs text-amber-800 bg-amber-50 rounded-lg px-3 py-2">
                Tu empresa todavía no está vinculada con ispwatch: este bloque siempre va a salir por «No encontrado».
                Pídenos la vinculación por Soporte.
            </p>
            <p class="text-sm text-gray-600">
                Busca al cliente en ispwatch con el teléfono del chat. Solo lee: nunca modifica nada en ispwatch.
            </p>
            <div class="rounded-xl bg-gray-50 p-3">
                <p class="text-xs font-semibold text-gray-700 mb-2">Después de este bloque puedes usar:</p>
                <ul class="space-y-1">
                    <li v-for="v in variables.filter(v => v.name.startsWith('cliente.'))" :key="v.name" class="text-[11px] text-gray-600">
                        <code class="text-accent" v-text="token(v.name)"></code> — {{ v.description }}
                    </li>
                </ul>
            </div>
            <p class="text-[11px] text-gray-500">
                Si varios clientes comparten el teléfono, se elige el que mejor coincide con el nombre del contacto.
                Los saldos son lo que el cliente debe de verdad (ya descontado su saldo a favor).
            </p>
        </template>

        <!-- ═══ ETIQUETAR ═══ -->
        <template v-else-if="node.type === 'tag'">
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Acción</label>
                    <select v-model="data.action" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                        <option value="add">Poner</option>
                        <option value="remove">Quitar</option>
                    </select>
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">Etiqueta</label>
                    <select v-model.number="data.label_id" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                        <option :value="null" disabled>Elige…</option>
                        <option v-for="l in catalog.labels" :key="l.id" :value="l.id">{{ l.name }}</option>
                    </select>
                </div>
            </div>
            <p v-if="!catalog.labels?.length" class="text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">No tienes etiquetas todavía. Créalas en la sección Etiquetas.</p>
        </template>

        <!-- ═══ ESPERA ═══ -->
        <template v-else-if="node.type === 'wait'">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Esperar</label>
                <div class="flex items-center gap-2">
                    <input v-model.number="data.minutes" type="number" min="1" :max="maxWait" class="w-28 px-3 py-2 border border-gray-200 rounded-xl text-sm" />
                    <span class="text-sm text-gray-500">minutos</span>
                </div>
                <div class="flex flex-wrap gap-1.5 mt-2">
                    <button v-for="m in [5, 30, 60, 240]" :key="m" type="button" class="px-2 py-1 rounded-md border border-gray-200 text-[11px] text-gray-600 hover:border-gray-300" @click="data.minutes = m">
                        {{ m >= 60 ? (m / 60) + ' h' : m + ' min' }}
                    </button>
                </div>
            </div>
            <p class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
                WhatsApp solo deja escribir texto libre hasta 24 h después del último mensaje del cliente. Por eso las
                esperas seguidas no pueden sumar más de {{ Math.floor(maxWait / 60) }} h. Lo que el cliente escriba
                durante la espera queda en el chat, pero el bot no lo lee.
            </p>
        </template>

        <!-- ═══ PASAR A UN ASESOR ═══ -->
        <template v-else-if="node.type === 'handoff'">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1">Mensaje al cliente <span class="text-xs font-normal text-gray-400">(opcional)</span></label>
                <FlowTextarea v-model="data.text" :variables="variables" :rows="3" />
            </div>
            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">Mandar al equipo</label>
                <select v-model="data.team_id" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm">
                    <option :value="null">Sin equipo</option>
                    <option v-for="t in catalog.teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                </select>
            </div>
            <div class="space-y-1.5">
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input v-model="data.assign" type="radio" value="auto" class="mt-0.5 text-accent focus:ring-accent" />
                    <span>Asignarla al asesor menos ocupado<span v-if="data.team_id"> del equipo</span></span>
                </label>
                <label class="flex items-start gap-2 text-sm text-gray-700">
                    <input v-model="data.assign" type="radio" value="none" class="mt-0.5 text-accent focus:ring-accent" />
                    <span>Dejarla sin asignar en la bandeja</span>
                </label>
                <p v-if="data.assign === 'auto' && !catalog.autoAssign" class="text-[11px] text-amber-700 bg-amber-50 rounded-lg px-2 py-1.5">
                    Tu empresa tiene la auto-asignación apagada (Configuración → Asignación): la conversación va a quedar sin asignar.
                </p>
            </div>
            <label class="flex items-start gap-2 text-sm text-gray-700">
                <input v-model="data.note" type="checkbox" class="mt-0.5 rounded border-gray-300 text-accent focus:ring-accent" />
                <span>Dejarle al asesor una nota interna con lo que respondió el cliente</span>
            </label>
        </template>

        <!-- ═══ FIN ═══ -->
        <template v-else-if="node.type === 'end'">
            <p class="text-sm text-gray-600">
                El flujo termina sin pasar a un asesor: el bot resolvió. La conversación queda en la bandeja; si el
                cliente vuelve a escribir, la atiende tu equipo.
            </p>
        </template>
    </div>
</template>
