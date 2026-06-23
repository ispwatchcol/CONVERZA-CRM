<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Head, Link } from '@inertiajs/vue3';
import { ref, computed, watch, nextTick } from 'vue';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    filters:   { type: Object, default: () => ({}) },
    stats:     { type: Object, default: () => ({}) },
    languages: { type: Object, default: () => ({}) },
    // Catálogo de eventos: [{ key, label, description, trigger, variables: [{name,label,description,sample}] }]
    events:    { type: Array, default: () => [] },
    metaConfigured: { type: Boolean, default: false },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');

const statusChips = [
    { value: '',         label: 'Todas' },
    { value: 'approved', label: 'Aprobadas' },
    { value: 'pending',  label: 'Pendientes' },
    { value: 'rejected', label: 'Rechazadas' },
];

function applyFilters() {
    router.get(route('templates.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function setStatus(v) {
    statusFilter.value = v;
    applyFilters();
}

function clearFilters() {
    search.value = '';
    statusFilter.value = '';
    applyFilters();
}

const hasFilters = computed(() => search.value !== '' || statusFilter.value !== '');

// ── Modal create/edit ────────────────────────────────────────────────────────
const showModal = ref(false);
const editing = ref(null);
const form = useForm({
    name: '',
    category: 'utility',
    language: 'es_CO',
    // Propósito de la plantilla (evento del catálogo). Define qué variables hay.
    event_key: '',
    body: '',
    header_text: '',
    footer_text: '',
    button_text: '',
    button_url: '',
    team_label: '',
    // Muestras por variable POSICIONAL (legado): { 1: 'Jhon', 2: '$2541' }. Las
    // variables nombradas usan las muestras del catálogo automáticamente.
    variable_examples: {},
});

// ── Propósito + variables del evento ─────────────────────────────────────────
const bodyRef = ref(null);

const selectedEvent = computed(() => props.events.find(e => e.key === form.event_key) || null);
const eventVariables = computed(() => selectedEvent.value?.variables ?? []);
const sampleByName = computed(() =>
    Object.fromEntries(eventVariables.value.map(v => [v.name, v.sample]))
);

// Tokens nombrados {{nombre_cliente}} presentes en el body (únicos).
const namedTokens = computed(() => {
    const names = [...(form.body || '').matchAll(/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/g)].map(m => m[1]);
    return [...new Set(names)];
});

const allowedVarNames = computed(() => eventVariables.value.map(v => v.name));
const unknownTokens = computed(() => namedTokens.value.filter(n => !allowedVarNames.value.includes(n)));

// Error de coherencia evento ↔ variables (mismo criterio que el backend).
const variableError = computed(() => {
    if (namedTokens.value.length === 0) return '';
    if (!form.event_key) return 'El mensaje usa variables con nombre ({{...}}). Elige primero un propósito para habilitarlas.';
    if (unknownTokens.value.length) {
        return 'Variables no disponibles para este propósito: ' + unknownTokens.value.map(n => '{{' + n + '}}').join(', ');
    }
    return '';
});

// Inserta {{nombre}} en la posición del cursor del textarea del body.
function insertVariable(name) {
    const token = '{{' + name + '}}';
    const el = bodyRef.value;
    if (!el) { form.body = (form.body || '') + token; return; }
    const start = el.selectionStart ?? form.body.length;
    const end = el.selectionEnd ?? form.body.length;
    form.body = form.body.slice(0, start) + token + form.body.slice(end);
    nextTick(() => {
        el.focus();
        const pos = start + token.length;
        el.setSelectionRange(pos, pos);
    });
}

const bodyChars = computed(() => (form.body || '').length);
const bodyOverflow = computed(() => bodyChars.value > 1024);

// Números de variable {{1}}, {{2}}… presentes en el body, únicos y ordenados.
const variableNumbers = computed(() => {
    const nums = [...(form.body || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map(m => parseInt(m[1], 10));
    return [...new Set(nums)].sort((a, b) => a - b);
});

// Mantiene form.variable_examples alineado con las variables del body:
// agrega las nuevas (vacías) y descarta las que ya no existen.
watch(variableNumbers, (nums) => {
    const next = {};
    nums.forEach((n) => { next[n] = form.variable_examples?.[n] ?? ''; });
    form.variable_examples = next;
});

// ¿Falta alguna muestra? Solo bloquea cuando hay que enviar a Meta.
const examplesIncomplete = computed(() =>
    variableNumbers.value.some(n => !String(form.variable_examples[n] ?? '').trim())
);

// Las muestras solo son obligatorias al crear y enviar a revisión de Meta.
const needsExamples = computed(() =>
    !editing.value && props.metaConfigured && examplesIncomplete.value
);

// Etiqueta "{{n}}" como string — se construye en JS para no confundir al
// compilador de Vue, que interpreta los {{ }} literales como interpolación.
const varLabel = (n) => '{' + '{' + n + '}' + '}';

// Vista previa del mensaje: sustituye variables nombradas por su muestra del
// catálogo y las posicionales {{n}} por la muestra que escribió el usuario.
const examplePreview = computed(() => {
    if (!form.body) return '';
    return form.body
        .replace(/\{\{\s*([a-z][a-z0-9_]*)\s*\}\}/g, (_, n) => sampleByName.value[n] || `{{${n}}}`)
        .replace(/\{\{\s*(\d+)\s*\}\}/g, (_, n) => {
            const val = String(form.variable_examples[parseInt(n, 10)] ?? '').trim();
            return val || `{{${n}}}`;
        });
});

function openCreate() {
    editing.value = null;
    form.reset();
    form.category = 'utility';
    form.language = 'es_CO';
    form.event_key = '';
    showModal.value = true;
}

function openEdit(t) {
    editing.value = t;
    form.name = t.name;
    form.category = t.category;
    form.language = t.language || 'es_CO';
    form.event_key = t.event_key || '';
    form.body = t.body;
    form.header_text = t.header_text || '';
    form.footer_text = t.footer_text || '';
    form.button_text = t.button_text || '';
    form.button_url = t.button_url || '';
    form.team_label = t.team_label || '';
    form.variable_examples = {}; // el watch las repuebla según las {{n}} del body
    showModal.value = true;
    showDetail.value = false;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; form.reset(); } };
    if (editing.value) {
        form.put(route('templates.update', editing.value.id), opts);
    } else {
        form.post(route('templates.store'), opts);
    }
}

function toggleActive(t) {
    router.patch(route('templates.toggle', t.id), {}, { preserveScroll: true });
}

function deleteTemplate(t) {
    const metaMsg = t.meta_id
        ? 'Esta plantilla está sincronizada con Meta. Borrarla solo la quita del mirror local — sigue existiendo en Meta Business hasta que la elimines allá. '
        : '';
    askConfirm({
        title: 'Eliminar plantilla',
        message: `${metaMsg}¿Seguro que quieres eliminar "${t.name}"?`,
        confirmLabel: 'Eliminar',
        variant: 'danger',
        onConfirm: () => router.delete(route('templates.destroy', t.id), { preserveScroll: true }),
    });
}

// ── Diálogo de confirmación (reemplaza el confirm() nativo del navegador) ──────
const confirmDialog = ref({
    show: false,
    title: '',
    message: '',
    confirmLabel: 'Confirmar',
    variant: 'accent', // 'accent' | 'danger'
    onConfirm: null,
});

function askConfirm(opts) {
    confirmDialog.value = {
        show: true,
        title: opts.title ?? '¿Confirmar?',
        message: opts.message ?? '',
        confirmLabel: opts.confirmLabel ?? 'Confirmar',
        variant: opts.variant ?? 'accent',
        onConfirm: opts.onConfirm ?? null,
    };
}

function acceptConfirm() {
    const cb = confirmDialog.value.onConfirm;
    confirmDialog.value.show = false;
    if (cb) cb();
}

// ── Sync con Meta ────────────────────────────────────────────────────────────
const syncing = ref(false);
function syncWithMeta() {
    askConfirm({
        title: 'Sincronizar con Meta',
        message: 'Se consultará a Meta y se actualizará tu mirror local de plantillas. Tus plantillas locales no se eliminan.',
        confirmLabel: 'Sincronizar',
        onConfirm: () => {
            syncing.value = true;
            router.post(route('templates.sync'), {}, {
                preserveScroll: true,
                onFinish: () => { syncing.value = false; },
            });
        },
    });
}

// ── Enviar borrador local a revisión de Meta ──────────────────────────────────
// Solo para plantillas sin meta_id (borradores creados sin credenciales). Meta
// exige una muestra por cada variable posicional {{n}} del body; las nombradas se
// autocompletan en el backend desde el catálogo del evento.
const showSubmit = ref(false);
const submitTarget = ref(null);
const submitForm = useForm({ variable_examples: {} });

const submitVarNumbers = computed(() => {
    const nums = [...(submitTarget.value?.body || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].map(m => parseInt(m[1], 10));
    return [...new Set(nums)].sort((a, b) => a - b);
});

const submitExamplesIncomplete = computed(() =>
    submitVarNumbers.value.some(n => !String(submitForm.variable_examples[n] ?? '').trim())
);

function openSubmit(t) {
    submitTarget.value = t;
    submitForm.reset();
    submitForm.clearErrors();
    const next = {};
    [...(t.body || '').matchAll(/\{\{\s*(\d+)\s*\}\}/g)].forEach((m) => { next[parseInt(m[1], 10)] = ''; });
    submitForm.variable_examples = next;
    showSubmit.value = true;
    showDetail.value = false;
}

function confirmSubmit() {
    submitForm.post(route('templates.submit', submitTarget.value.id), {
        preserveScroll: true,
        onSuccess: () => { showSubmit.value = false; submitTarget.value = null; },
    });
}

// ── Drawer de detalle ────────────────────────────────────────────────────────
const showDetail = ref(false);
const selected = ref(null);

function openDetail(t) {
    selected.value = t;
    showDetail.value = true;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function statusBadge(status) {
    return {
        approved: 'bg-emerald-100 text-emerald-700',
        pending:  'bg-amber-100 text-amber-700',
        rejected: 'bg-red-100 text-red-700',
    }[status] || 'bg-gray-100 text-gray-600';
}

function statusLabel(status) {
    return { approved: 'Aprobada', pending: 'Pendiente', rejected: 'Rechazada' }[status] || status;
}

function categoryBadge(cat) {
    return {
        marketing:      'bg-pink-100 text-pink-700',
        utility:        'bg-blue-100 text-blue-700',
        authentication: 'bg-purple-100 text-purple-700',
    }[cat] || 'bg-gray-100 text-gray-600';
}

function categoryLabel(cat) {
    return { marketing: 'Marketing', utility: 'Utilidad', authentication: 'Autenticación' }[cat] || cat;
}

// Etiqueta legible del propósito (evento) de una plantilla.
function eventLabelOf(key) {
    return props.events.find(e => e.key === key)?.label || null;
}

// Renderiza el body destacando las variables {{nombre}} o {{1}}.
function renderBodyWithVars(body) {
    if (!body) return '';
    return body.replace(/(\{\{\s*[a-zA-Z0-9_]+\s*\}\})/g,
        '<span class="bg-amber-100 text-amber-800 px-1 rounded font-mono text-[11px]">$1</span>');
}

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

// Ejemplo de body (los meto como var para evitar que Vue interpole los `{{ }}` dentro del template)
const placeholderExample = 'Hola {{nombre_cliente}}, tu factura {{numero_factura}} por {{monto}} vence el {{fecha_vencimiento}}.';
</script>

<template>
    <Head title="Plantillas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Plantillas WhatsApp</h1>
                    <p class="text-sm text-gray-500 mt-1">Mensajes pre-aprobados por Meta para usar fuera de la ventana de 24 horas</p>
                </div>
                <div class="flex gap-2">
                    <button @click="syncWithMeta" :disabled="syncing || !metaConfigured"
                            class="inline-flex items-center px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition disabled:opacity-50 disabled:cursor-not-allowed"
                            :title="!metaConfigured ? 'Configura primero WhatsApp en Settings' : 'Traer plantillas desde Meta'">
                        <svg class="w-4 h-4 mr-2" :class="{ 'animate-spin': syncing }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99"/></svg>
                        {{ syncing ? 'Sincronizando…' : 'Sincronizar con Meta' }}
                    </button>
                    <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                        Nueva plantilla
                    </button>
                </div>
            </div>

            <!-- Aviso si Meta no está configurado -->
            <Link v-if="!metaConfigured" :href="route('settings.index')"
                  class="mb-6 flex items-center gap-2.5 px-4 py-2.5 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900 hover:bg-amber-100 transition">
                <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                <span class="font-medium">Falta configurar WhatsApp / Meta para sincronizar plantillas.</span>
                <span class="text-xs text-amber-700 ml-auto">Ir a configuración →</span>
            </Link>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ stats.synced }} sync de Meta</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Aprobadas</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ stats.approved }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ stats.active }} activas</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Pendientes</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ stats.pending }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Rechazadas</p>
                    <p class="text-2xl font-bold text-red-600 mt-1">{{ stats.rejected }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row gap-3 md:items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar por nombre o contenido…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                        <button v-for="opt in statusChips" :key="opt.value" @click="setStatus(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                :class="statusFilter === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            {{ opt.label }}
                        </button>
                    </div>

                    <button v-if="hasFilters" @click="clearFilters" class="text-xs text-gray-500 hover:text-gray-700 px-2 transition">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- Grid de cards -->
            <div v-if="templates.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-3">
                <div v-for="t in templates" :key="t.id" @click="openDetail(t)"
                     class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-md transition-all cursor-pointer group flex flex-col"
                     :class="{ 'opacity-60': !t.is_active && t.status === 'approved' }">
                    <!-- Top: name + status -->
                    <div class="flex items-start justify-between mb-2 gap-2">
                        <div class="min-w-0 flex-1">
                            <h3 class="text-sm font-semibold text-gray-900 font-mono truncate">{{ t.name }}</h3>
                            <p class="text-[10px] text-gray-400 mt-0.5">{{ t.language || 'es_CO' }}</p>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide shrink-0" :class="statusBadge(t.status)">
                            {{ statusLabel(t.status) }}
                        </span>
                    </div>

                    <!-- Body preview con variables resaltadas -->
                    <p class="text-xs text-gray-600 line-clamp-3 flex-1 whitespace-pre-wrap" v-html="renderBodyWithVars(t.body)"></p>

                    <!-- Footer -->
                    <div class="mt-3 pt-3 border-t border-gray-50 flex items-center justify-between gap-2">
                        <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide" :class="categoryBadge(t.category)">
                            {{ categoryLabel(t.category) }}
                        </span>
                        <div class="flex items-center gap-2">
                            <span v-if="t.meta_id" class="inline-flex items-center gap-1 text-[10px] text-emerald-600" title="Sincronizada con Meta">
                                <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                Meta
                            </span>
                            <button v-if="t.status === 'approved'" @click.stop="toggleActive(t)"
                                    class="flex items-center" :title="t.is_active ? 'Activa' : 'Inactiva'">
                                <span class="relative inline-block w-7 h-4 rounded-full transition-colors" :class="t.is_active ? 'bg-emerald-500' : 'bg-gray-300'">
                                    <span class="absolute top-0.5 w-3 h-3 bg-white rounded-full transition-transform" :class="t.is_active ? 'left-3.5' : 'left-0.5'"></span>
                                </span>
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z"/></svg>
                <h3 class="text-base font-semibold text-gray-700 mb-1">
                    {{ hasFilters ? 'Ninguna plantilla coincide con los filtros' : 'Aún no hay plantillas' }}
                </h3>
                <p class="text-sm text-gray-400 mb-5 max-w-md mx-auto">
                    <template v-if="hasFilters">Ajusta o limpia los filtros.</template>
                    <template v-else>
                        Las plantillas WhatsApp son mensajes pre-aprobados por Meta que puedes enviar a clientes fuera de la ventana de 24h.
                        Si ya tienes plantillas en Meta Business, sincronízalas con un click.
                    </template>
                </p>
                <div v-if="!hasFilters" class="flex justify-center gap-2">
                    <button v-if="metaConfigured" @click="syncWithMeta" :disabled="syncing"
                            class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                        {{ syncing ? 'Sincronizando…' : 'Traer desde Meta' }}
                    </button>
                    <button @click="openCreate" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">
                        Crear manualmente
                    </button>
                </div>
                <button v-else @click="clearFilters" class="text-xs text-accent hover:underline">Limpiar filtros</button>
            </div>
        </div>

        <!-- ═══════════════ Modal: Detalle ═══════════════ -->
        <Teleport to="body">
            <div v-if="showDetail && selected" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showDetail = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-scale-in">
                    <div class="p-6">
                        <!-- Header -->
                        <div class="flex items-start justify-between mb-4">
                            <div class="min-w-0 flex-1">
                                <h3 class="text-lg font-bold text-gray-900 font-mono break-all">{{ selected.name }}</h3>
                                <div class="flex flex-wrap gap-1.5 mt-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase" :class="statusBadge(selected.status)">{{ statusLabel(selected.status) }}</span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium" :class="categoryBadge(selected.category)">{{ categoryLabel(selected.category) }}</span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-700">{{ selected.language || 'es_CO' }}</span>
                                </div>
                            </div>
                            <button @click="showDetail = false" class="p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition shrink-0">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <!-- Sync info -->
                        <div v-if="selected.meta_id" class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 mb-4 text-xs text-emerald-900">
                            <div class="flex items-start gap-2">
                                <svg class="w-4 h-4 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                <div class="min-w-0">
                                    <p class="font-semibold">Sincronizada con Meta</p>
                                    <p class="font-mono text-[10px] text-emerald-700 mt-0.5 break-all">Meta ID: {{ selected.meta_id }}</p>
                                    <p class="text-[10px] text-emerald-700 mt-0.5">Última sync: {{ formatDateTime(selected.last_synced_at) }}</p>
                                </div>
                            </div>
                        </div>

                        <!-- Body con variables resaltadas (preview como WhatsApp) -->
                        <div class="mb-4">
                            <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-2">Vista previa</p>
                            <div class="bg-[#d9fdd3] rounded-2xl rounded-tl-sm p-3 max-w-sm border border-gray-100">
                                <p v-if="selected.header_text" class="text-sm font-semibold text-gray-900 mb-1">{{ selected.header_text }}</p>
                                <p class="text-sm text-gray-900 whitespace-pre-wrap" v-html="renderBodyWithVars(selected.body)"></p>
                                <p v-if="selected.footer_text" class="text-[11px] text-gray-500 mt-1">{{ selected.footer_text }}</p>
                            </div>
                            <div v-if="selected.button_text" class="max-w-sm mt-1">
                                <a :href="selected.button_url" target="_blank" rel="noopener"
                                   class="flex items-center justify-center gap-1.5 bg-white border border-gray-100 rounded-2xl rounded-tl-sm py-2 text-sm text-[#027eb5] font-medium hover:bg-gray-50 transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                    {{ selected.button_text }}
                                </a>
                            </div>
                            <p class="text-[10px] text-gray-400 mt-2">Las variables resaltadas en ámbar se reemplazarán al enviar.</p>
                        </div>

                        <!-- Metadata -->
                        <div class="text-xs text-gray-500 space-y-1 mb-4 pb-4 border-b border-gray-100">
                            <p v-if="selected.event_key"><span class="text-gray-400">Propósito:</span> {{ eventLabelOf(selected.event_key) || selected.event_key }}</p>
                            <p v-if="selected.team_label"><span class="text-gray-400">Equipo:</span> {{ selected.team_label }}</p>
                            <p><span class="text-gray-400">Creada:</span> {{ formatDateTime(selected.created_at) }}</p>
                            <p><span class="text-gray-400">Actualizada:</span> {{ formatDateTime(selected.updated_at) }}</p>
                        </div>

                        <!-- Acciones -->
                        <div class="flex flex-col sm:flex-row gap-2">
                            <!-- Borrador local: enviar a revisión de Meta (primario si hay credenciales) -->
                            <button v-if="!selected.meta_id && metaConfigured" @click="openSubmit(selected)"
                                    class="flex-1 px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition">
                                Enviar a revisión de Meta
                            </button>
                            <button v-if="!selected.meta_id" @click="openEdit(selected)"
                                    class="px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition"
                                    :class="{ 'flex-1': !metaConfigured }">
                                Editar
                            </button>
                            <button v-if="selected.meta_id" disabled class="flex-1 px-4 py-2.5 bg-gray-100 text-gray-400 rounded-xl text-sm font-medium cursor-not-allowed"
                                    title="Plantillas de Meta solo se editan en Meta Business">
                                Editar en Meta
                            </button>
                            <button @click="deleteTemplate(selected)" class="px-4 py-2.5 border border-red-200 text-red-700 rounded-xl text-sm font-medium hover:bg-red-50 transition">
                                Eliminar
                            </button>
                        </div>
                        <p v-if="!selected.meta_id && !metaConfigured" class="text-[11px] text-amber-700 mt-2">
                            Configura WhatsApp (Business Account ID + Access Token) en Configuración para poder enviar este borrador a revisión de Meta.
                        </p>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══════════════ Modal: Enviar a revisión de Meta ═══════════════ -->
        <Teleport to="body">
            <div v-if="showSubmit && submitTarget" class="fixed inset-0 z-[75] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showSubmit = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Enviar a revisión de Meta</h3>
                        <p class="text-xs text-gray-500 mb-4">
                            Se creará <span class="font-mono text-gray-700">{{ submitTarget.name }}</span> en Meta y quedará en estado pendiente hasta su aprobación.
                        </p>

                        <!-- Vista previa del body -->
                        <div class="bg-[#d9fdd3] rounded-2xl rounded-tl-sm p-3 mb-4 border border-gray-100">
                            <p v-if="submitTarget.header_text" class="text-sm font-semibold text-gray-900 mb-1">{{ submitTarget.header_text }}</p>
                            <p class="text-sm text-gray-900 whitespace-pre-wrap" v-html="renderBodyWithVars(submitTarget.body)"></p>
                            <p v-if="submitTarget.footer_text" class="text-[11px] text-gray-500 mt-1">{{ submitTarget.footer_text }}</p>
                        </div>

                        <!-- Muestras de variables posicionales {{n}} (Meta las exige) -->
                        <div v-if="submitVarNumbers.length" class="bg-amber-50 border border-amber-100 rounded-xl p-3 space-y-2.5 mb-4">
                            <div>
                                <p class="text-[11px] text-amber-900 font-semibold uppercase tracking-wider">Muestras de variables</p>
                                <p class="text-[10px] text-amber-700 mt-0.5">Meta exige un ejemplo por cada variable. No uses datos reales de clientes.</p>
                            </div>
                            <div v-for="n in submitVarNumbers" :key="n" class="flex items-center gap-2">
                                <span class="shrink-0 px-2 py-1.5 bg-white border border-amber-200 rounded-lg font-mono text-[11px] text-amber-800 w-14 text-center">
                                    {{ varLabel(n) }}
                                </span>
                                <input v-model="submitForm.variable_examples[n]" type="text" maxlength="1024"
                                       :placeholder="'Ejemplo para ' + varLabel(n)"
                                       class="flex-1 px-3 py-1.5 border rounded-lg text-sm bg-white focus:ring-2 focus:ring-accent/30 focus:border-accent"
                                       :class="submitForm.errors[`variable_examples.${n}`] ? 'border-red-400' : 'border-amber-200'">
                            </div>
                        </div>

                        <!-- Error devuelto por Meta -->
                        <div v-if="submitForm.errors.meta" class="bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-700 mb-4">
                            {{ submitForm.errors.meta }}
                        </div>

                        <div class="flex justify-end gap-3 pt-1">
                            <button type="button" @click="showSubmit = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                            <button @click="confirmSubmit" :disabled="submitForm.processing || submitExamplesIncomplete"
                                    :title="submitExamplesIncomplete ? 'Completa una muestra para cada variable' : ''"
                                    class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed">
                                {{ submitForm.processing ? 'Enviando…' : 'Enviar a revisión' }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══════════════ Modal: Crear/editar ═══════════════ -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-1">{{ editing ? 'Editar plantilla' : 'Nueva plantilla' }}</h3>
                        <p class="text-xs text-gray-500 mb-4">
                            <template v-if="editing">Editas el borrador local. Para que llegue a producción, somete a aprobación en Meta Business.</template>
                            <template v-else>Crea un borrador local. Después tendrás que crearlo en Meta Business y sincronizar.</template>
                        </p>

                        <form @submit.prevent="save" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                                <input v-model="form.name" type="text" placeholder="bienvenida_cliente"
                                       class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': form.errors.name }">
                                <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                                <p v-else class="text-[10px] text-gray-400 mt-1">Formato Meta: solo minúsculas, números y guión bajo. Ej. <code>bienvenida_cliente</code></p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Categoría <span class="text-red-500">*</span></label>
                                    <select v-model="form.category" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                        <option value="utility">Utilidad (transaccional)</option>
                                        <option value="marketing">Marketing (promocional)</option>
                                        <option value="authentication">Autenticación (OTP)</option>
                                    </select>
                                    <p v-if="form.errors.category" class="text-red-500 text-xs mt-1">{{ form.errors.category }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Idioma <span class="text-red-500">*</span></label>
                                    <select v-model="form.language" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                        <option v-for="(label, code) in languages" :key="code" :value="code">{{ label }}</option>
                                    </select>
                                    <p v-if="form.errors.language" class="text-red-500 text-xs mt-1">{{ form.errors.language }}</p>
                                </div>
                            </div>

                            <!-- Propósito (evento): define qué variables hay disponibles -->
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Propósito <span class="text-gray-400 font-normal">(para avisos automáticos)</span></label>
                                <select v-model="form.event_key" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="">— Texto libre (sin variables) —</option>
                                    <option v-for="e in events" :key="e.key" :value="e.key">{{ e.label }}</option>
                                </select>
                                <p v-if="selectedEvent" class="text-[10px] text-gray-500 mt-1">{{ selectedEvent.description }} <span class="text-gray-400">· Se envía: {{ selectedEvent.trigger }}</span></p>
                                <p v-else class="text-[10px] text-gray-400 mt-1">Elige el propósito para habilitar sus variables. «General» trae los datos del cliente, tu empresa y la fecha; los avisos automáticos traen los datos de la factura.</p>
                                <p v-if="form.errors.event_key" class="text-red-500 text-xs mt-1">{{ form.errors.event_key }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Encabezado <span class="text-gray-400 font-normal">(opcional, máx 60)</span></label>
                                <input v-model="form.header_text" type="text" maxlength="60" placeholder="Ej. Recordatorio de pago"
                                       class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"
                                       :class="form.errors.header_text ? 'border-red-400' : 'border-gray-200'">
                                <p v-if="form.errors.header_text" class="text-red-500 text-xs mt-1">{{ form.errors.header_text }}</p>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700">Contenido (body) <span class="text-red-500">*</span></label>
                                    <span class="text-[10px] tabular-nums" :class="bodyOverflow ? 'text-red-500 font-semibold' : 'text-gray-400'">{{ bodyChars }} / 1024</span>
                                </div>
                                <textarea ref="bodyRef" v-model="form.body" rows="5" :placeholder="placeholderExample"
                                          class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"
                                          :class="form.errors.body || bodyOverflow || variableError ? 'border-red-400' : 'border-gray-200'"></textarea>
                                <p v-if="form.errors.body" class="text-red-500 text-xs mt-1">{{ form.errors.body }}</p>
                                <p v-else class="text-[10px] text-gray-400 mt-1">Inserta variables con los botones de abajo. Se reemplazan con el dato real al enviar.</p>
                            </div>

                            <!-- Paleta de variables del propósito elegido -->
                            <div>
                                <div class="flex items-center justify-between mb-1.5">
                                    <label class="block text-sm font-medium text-gray-700">Variables disponibles</label>
                                    <span v-if="selectedEvent" class="text-[10px] text-gray-400">{{ selectedEvent.label }}</span>
                                </div>
                                <div v-if="!form.event_key" class="text-[11px] text-gray-500 bg-gray-50 border border-gray-100 rounded-lg p-2.5">
                                    Elige un <strong>propósito</strong> arriba para ver las variables que puedes insertar (nombre del cliente, monto, número de factura…).
                                </div>
                                <template v-else>
                                    <div class="flex flex-wrap gap-1.5">
                                        <button v-for="v in eventVariables" :key="v.name" type="button" @click="insertVariable(v.name)"
                                                :title="v.description + ' · Ej: ' + v.sample"
                                                class="inline-flex items-center gap-1 px-2.5 py-1.5 bg-accent/10 text-accent rounded-lg text-xs font-medium hover:bg-accent/20 transition">
                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                                            {{ v.label }}
                                        </button>
                                    </div>
                                    <p class="text-[10px] text-gray-400 mt-1.5">Clic para insertar en el cursor. Pasa el mouse para ver un ejemplo del dato.</p>
                                </template>
                                <p v-if="variableError" class="text-amber-800 text-xs mt-2 bg-amber-50 border border-amber-200 rounded-lg p-2">{{ variableError }}</p>
                            </div>

                            <!-- Vista previa con datos de ejemplo (variables nombradas) -->
                            <div v-if="form.event_key && namedTokens.length && !variableError" class="bg-[#d9fdd3]/40 border border-emerald-100 rounded-xl p-3">
                                <p class="text-[10px] text-emerald-700 font-medium mb-1">Así le llegará al cliente (con datos de ejemplo):</p>
                                <p class="text-xs text-gray-800 bg-white border border-emerald-100 rounded-lg p-2 whitespace-pre-wrap">{{ examplePreview }}</p>
                            </div>

                            <!-- Muestras de variables POSICIONALES (legado): Meta exige un ejemplo por cada {{n}} -->
                            <div v-if="variableNumbers.length" class="bg-amber-50 border border-amber-100 rounded-xl p-3 space-y-2.5">
                                <div>
                                    <p class="text-[11px] text-amber-900 font-semibold uppercase tracking-wider">Muestras de variables</p>
                                    <p class="text-[10px] text-amber-700 mt-0.5">
                                        Incluye un ejemplo para cada variable para que Meta pueda revisar la plantilla.
                                        No uses datos reales de clientes.
                                    </p>
                                </div>

                                <div v-for="n in variableNumbers" :key="n" class="flex items-center gap-2">
                                    <span class="shrink-0 px-2 py-1.5 bg-white border border-amber-200 rounded-lg font-mono text-[11px] text-amber-800 w-14 text-center">
                                        {{ varLabel(n) }}
                                    </span>
                                    <input v-model="form.variable_examples[n]" type="text" maxlength="1024"
                                           :placeholder="'Ejemplo para ' + varLabel(n)"
                                           class="flex-1 px-3 py-1.5 border rounded-lg text-sm bg-white focus:ring-2 focus:ring-accent/30 focus:border-accent"
                                           :class="form.errors[`variable_examples.${n}`] ? 'border-red-400' : 'border-amber-200'">
                                </div>

                                <!-- Vista previa con las muestras sustituidas -->
                                <div class="pt-1">
                                    <p class="text-[10px] text-amber-700 font-medium mb-1">Así quedaría el mensaje:</p>
                                    <p class="text-xs text-gray-700 bg-white border border-amber-100 rounded-lg p-2 whitespace-pre-wrap">{{ examplePreview }}</p>
                                </div>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Pie de página <span class="text-gray-400 font-normal">(opcional, máx 60)</span></label>
                                <input v-model="form.footer_text" type="text" maxlength="60" placeholder="Ej. Si ya pagaste, ignora este mensaje."
                                       class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"
                                       :class="form.errors.footer_text ? 'border-red-400' : 'border-gray-200'">
                                <p v-if="form.errors.footer_text" class="text-red-500 text-xs mt-1">{{ form.errors.footer_text }}</p>
                            </div>

                            <!-- Botón de URL (ej. link de pago) — deshabilitado por ahora -->
                            <div class="opacity-60">
                                <label class="block text-sm font-medium text-gray-500 mb-1">Botón de enlace <span class="text-gray-400 font-normal">(próximamente)</span></label>
                                <div class="grid grid-cols-1 sm:grid-cols-3 gap-2">
                                    <input disabled type="text" placeholder="Texto del botón (ej. Pagar)"
                                           class="sm:col-span-1 w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 cursor-not-allowed">
                                    <input disabled type="url" placeholder="https://tu-portal.com/pagar"
                                           class="sm:col-span-2 w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm bg-gray-50 cursor-not-allowed">
                                </div>
                                <p class="text-[11px] text-gray-400 mt-1">El botón con link de pago se habilitará cuando esté listo el portal de pago.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Etiqueta de equipo <span class="text-gray-400 font-normal">(opcional)</span></label>
                                <input v-model="form.team_label" type="text" placeholder="Soporte, Cobranza..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                            </div>

                            <!-- Error devuelto por Meta al enviar a revisión -->
                            <div v-if="form.errors.meta" class="bg-red-50 border border-red-200 rounded-xl p-3 text-xs text-red-700">
                                {{ form.errors.meta }}
                            </div>

                            <!-- Aviso del flujo de envío a revisión -->
                            <p v-if="!editing" class="text-[11px]" :class="metaConfigured ? 'text-gray-500' : 'text-amber-700'">
                                <template v-if="metaConfigured">Al guardar, la plantilla se envía a <strong>revisión de Meta</strong> y queda en estado pendiente hasta su aprobación.</template>
                                <template v-else>WhatsApp no está configurado: la plantilla se guardará solo localmente y NO se enviará a Meta.</template>
                            </p>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="form.processing || bodyOverflow || needsExamples || !!variableError"
                                        :title="variableError || (needsExamples ? 'Completa una muestra para cada variable antes de enviar a Meta' : '')"
                                        class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed">
                                    {{ form.processing ? 'Guardando…' : (editing ? 'Guardar' : (metaConfigured ? 'Enviar a revisión' : 'Guardar')) }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Diálogo de confirmación -->
        <Teleport to="body">
            <div v-if="confirmDialog.show" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="confirmDialog.show = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm animate-scale-in">
                    <div class="p-6">
                        <div class="flex items-start gap-3">
                            <div class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                                 :class="confirmDialog.variant === 'danger' ? 'bg-red-100' : 'bg-accent/10'">
                                <svg v-if="confirmDialog.variant === 'danger'" class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                                </svg>
                                <svg v-else class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-bold text-gray-900">{{ confirmDialog.title }}</h3>
                                <p class="text-sm text-gray-600 mt-1">{{ confirmDialog.message }}</p>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 mt-5">
                            <button @click="confirmDialog.show = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">
                                Cancelar
                            </button>
                            <button @click="acceptConfirm"
                                    class="px-5 py-2.5 text-sm font-medium text-white rounded-xl transition"
                                    :class="confirmDialog.variant === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-accent hover:bg-accent-hover'">
                                {{ confirmDialog.confirmLabel }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
