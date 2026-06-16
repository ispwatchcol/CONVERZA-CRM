<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, Head, Link } from '@inertiajs/vue3';
import { ref, computed, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    templates: { type: Array, default: () => [] },
    labels: { type: Array, default: () => [] },
    defaultThrottle: { type: Number, default: 20 },
    tierThresholds: { type: Array, default: () => [250, 1000, 10000, 100000] },
});

const step = ref(1);

// ═══════════════ Paso 1: Audiencia ═══════════════
const sourceTabs = [
    { value: 'upload', label: 'Excel / CSV', icon: 'upload' },
    { value: 'crm', label: 'Contactos CRM', icon: 'crm' },
    { value: 'manual', label: 'Pegar lista', icon: 'manual' },
    { value: 'sheet', label: 'Google Sheets', icon: 'sheet' },
];
const sourceType = ref('upload');

const uploadFileName = ref('');
const parsedRows = ref([]);
const phoneColumn = ref('');
const nameColumn = ref('');
const manualText = ref('');
const sheetUrl = ref('');
const labelId = ref('');
const ispwatchFilter = ref('all');

const audiencePreview = ref(null);
const audienceLoading = ref(false);
const audienceError = ref('');

const localColumns = computed(() => (parsedRows.value.length ? Object.keys(parsedRows.value[0]) : []));
const columns = computed(() => (sourceType.value === 'upload' ? localColumns.value : (audiencePreview.value?.columns ?? [])));

function guessColumn(cols, candidates) {
    const lower = cols.map(c => c.toLowerCase().trim());
    for (const cand of candidates) {
        const idx = lower.indexOf(cand);
        if (idx !== -1) return cols[idx];
    }
    return '';
}

async function handleFileChange(e) {
    const file = e.target.files[0];
    if (!file) return;
    uploadFileName.value = file.name;
    audienceError.value = '';

    try {
        const XLSX = await import('xlsx');
        const buf = await file.arrayBuffer();
        const wb = XLSX.read(buf, { type: 'array' });
        const ws = wb.Sheets[wb.SheetNames[0]];
        parsedRows.value = XLSX.utils.sheet_to_json(ws, { defval: '' });
    } catch {
        audienceError.value = 'No se pudo leer el archivo. Verifica que sea un .xlsx, .xls o .csv válido.';
        return;
    }

    phoneColumn.value = guessColumn(localColumns.value, ['telefono', 'teléfono', 'phone', 'celular', 'numero', 'número', 'tel']);
    nameColumn.value = guessColumn(localColumns.value, ['nombre', 'name', 'cliente', 'contacto']);

    await loadAudiencePreview();
}

async function loadAudiencePreview() {
    audienceLoading.value = true;
    audienceError.value = '';

    const payload = { source_type: sourceType.value };

    if (sourceType.value === 'upload') {
        payload.rows = parsedRows.value;
        payload.phone_column = phoneColumn.value || undefined;
        payload.name_column = nameColumn.value || undefined;
    } else if (sourceType.value === 'manual') {
        payload.manual_text = manualText.value;
    } else if (sourceType.value === 'sheet') {
        payload.sheet_url = sheetUrl.value;
        payload.phone_column = phoneColumn.value || undefined;
        payload.name_column = nameColumn.value || undefined;
    } else if (sourceType.value === 'crm') {
        payload.label_id = labelId.value || undefined;
        payload.ispwatch_filter = ispwatchFilter.value;
    }

    try {
        const { data } = await axios.post(route('campaigns.preview-audience'), payload);
        audiencePreview.value = data;
    } catch (e) {
        audienceError.value = e.response?.data?.message ?? 'No se pudo construir la vista previa de la audiencia.';
        audiencePreview.value = null;
    } finally {
        audienceLoading.value = false;
    }
}

function resetAudience() {
    audiencePreview.value = null;
    audienceError.value = '';
    parsedRows.value = [];
    uploadFileName.value = '';
    phoneColumn.value = '';
    nameColumn.value = '';
    manualText.value = '';
    sheetUrl.value = '';
}

watch(sourceType, () => {
    resetAudience();
    if (sourceType.value === 'crm') loadAudiencePreview();
});

const stats = computed(() => audiencePreview.value?.stats ?? null);
const canAdvanceStep1 = computed(() => (stats.value?.valid ?? 0) > 0);
const previewRows = computed(() => (audiencePreview.value?.rows ?? []).slice(0, 20));

// ═══════════════ Paso 2: Mensaje ═══════════════
const templateId = ref('');
const variables = ref([]);
const mapping = ref({});
const messagePreviewText = ref('');
const messageLoading = ref(false);

const selectedTemplate = computed(() => props.templates.find(t => t.id === templateId.value) || null);
const sampleRow = computed(() => (audiencePreview.value?.rows ?? []).find(r => r.valid) || audiencePreview.value?.rows?.[0] || null);

function guessMapping(varKey) {
    if (/nombre|name/i.test(varKey)) return { type: 'contact_field', value: 'name' };
    if (/telefono|tel[eé]fono|phone|celular/i.test(varKey)) return { type: 'contact_field', value: 'phone' };
    const match = columns.value.find(c => c.toLowerCase() === varKey.toLowerCase());
    if (match) return { type: 'column', value: match };
    return { type: 'static', value: '' };
}

async function loadTemplateVariables() {
    if (!templateId.value || !sampleRow.value) return;
    messageLoading.value = true;
    try {
        const { data } = await axios.post(route('campaigns.preview-message'), {
            template_id: templateId.value,
            mapping: {},
            row: sampleRow.value.variables ?? {},
            name: sampleRow.value.name,
            phone: sampleRow.value.phone,
        });
        variables.value = data.variables;
        const next = {};
        for (const v of variables.value) {
            next[v] = mapping.value[v] ?? guessMapping(v);
        }
        mapping.value = next;
        await refreshMessagePreview();
    } finally {
        messageLoading.value = false;
    }
}

async function refreshMessagePreview() {
    if (!templateId.value || !sampleRow.value) return;
    try {
        const { data } = await axios.post(route('campaigns.preview-message'), {
            template_id: templateId.value,
            mapping: mapping.value,
            row: sampleRow.value.variables ?? {},
            name: sampleRow.value.name,
            phone: sampleRow.value.phone,
        });
        messagePreviewText.value = data.preview;
    } catch {
        messagePreviewText.value = '';
    }
}

watch(templateId, loadTemplateVariables);
watch(mapping, refreshMessagePreview, { deep: true });

const mappingComplete = computed(() =>
    variables.value.length > 0 &&
    variables.value.every(v => {
        const m = mapping.value[v];
        return m && String(m.value ?? '').trim() !== '';
    })
);

// ═══════════════ Paso 3: Revisar y enviar ═══════════════
const form = useForm({
    name: '',
    template_id: '',
    source_type: '',
    source_meta: {},
    variable_mapping: {},
    throttle_per_minute: props.defaultThrottle,
    scheduled_at: '',
    rows: [],
});

function goToStep3() {
    form.name = form.name || (selectedTemplate.value ? `Campaña ${selectedTemplate.value.name}` : '');
    form.template_id = templateId.value;
    form.source_type = sourceType.value;
    form.source_meta = sourceType.value === 'upload'
        ? { filename: uploadFileName.value }
        : sourceType.value === 'sheet'
            ? { sheet_url: sheetUrl.value }
            : sourceType.value === 'crm'
                ? { label_id: labelId.value || null, ispwatch_filter: ispwatchFilter.value }
                : {};
    form.variable_mapping = mapping.value;
    form.rows = audiencePreview.value?.rows ?? [];
    step.value = 3;
}

const tierWarning = computed(() => {
    const valid = stats.value?.valid ?? 0;
    const exceeded = props.tierThresholds.filter(t => valid > t);
    if (exceeded.length === 0) return null;
    const nextTier = exceeded[exceeded.length - 1];
    return `Tu audiencia (${valid} destinatarios únicos) supera el umbral de ${nextTier}/24h de algunos tiers de Meta. Si tu número aún no alcanzó ese tier, los envíos por encima del límite podrían fallar.`;
});

function submit() {
    form.post(route('campaigns.store'));
}

function backTo(n) {
    step.value = n;
}
</script>

<template>
    <Head title="Nueva campaña" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-4xl mx-auto">
            <!-- Header -->
            <div class="flex items-center gap-3 mb-6">
                <Link :href="route('campaigns.index')" class="p-2 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                </Link>
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Nueva campaña</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Carga tu audiencia, elige el mensaje y revisa antes de enviar</p>
                </div>
            </div>

            <!-- Stepper -->
            <div class="flex items-center gap-2 mb-6">
                <div v-for="(label, i) in ['Audiencia', 'Mensaje', 'Revisar y enviar']" :key="i" class="flex items-center gap-2 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="w-7 h-7 rounded-full flex items-center justify-center text-xs font-bold shrink-0"
                              :class="step === i + 1 ? 'bg-accent text-white' : step > i + 1 ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-400'">
                            <svg v-if="step > i + 1" class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            <template v-else>{{ i + 1 }}</template>
                        </span>
                        <span class="text-sm font-medium" :class="step === i + 1 ? 'text-gray-900' : 'text-gray-400'">{{ label }}</span>
                    </div>
                    <div v-if="i < 2" class="flex-1 h-px bg-gray-200"></div>
                </div>
            </div>

            <!-- ═══════════════ Paso 1: Audiencia ═══════════════ -->
            <div v-if="step === 1" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1 mb-5 flex-wrap">
                    <button v-for="t in sourceTabs" :key="t.value" @click="sourceType = t.value"
                            class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                            :class="sourceType === t.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                        {{ t.label }}
                    </button>
                </div>

                <!-- Upload -->
                <div v-if="sourceType === 'upload'" class="space-y-3">
                    <label class="flex flex-col items-center justify-center gap-2 border-2 border-dashed border-gray-200 rounded-xl p-8 cursor-pointer hover:border-accent/50 hover:bg-accent/5 transition">
                        <svg class="w-8 h-8 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 16.5V9.75m0 0l-3.75 3.75M12 9.75l3.75 3.75M6.75 19.5a4.5 4.5 0 01-1.41-8.775 5.25 5.25 0 0110.233-2.33 3 3 0 013.758 3.848A3.752 3.752 0 0118 19.5H6.75z"/></svg>
                        <span class="text-sm text-gray-600">{{ uploadFileName || 'Haz clic para subir un archivo .xlsx o .csv' }}</span>
                        <input type="file" accept=".xlsx,.xls,.csv" class="hidden" @change="handleFileChange">
                    </label>

                    <div v-if="parsedRows.length" class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Columna de teléfono</label>
                            <select v-model="phoneColumn" @change="loadAudiencePreview" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <option value="">Detectar automáticamente</option>
                                <option v-for="c in localColumns" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Columna de nombre</label>
                            <select v-model="nameColumn" @change="loadAudiencePreview" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <option value="">Detectar automáticamente</option>
                                <option v-for="c in localColumns" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- CRM -->
                <div v-else-if="sourceType === 'crm'" class="space-y-3">
                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Etiqueta <span class="text-gray-400 font-normal">(opcional)</span></label>
                            <select v-model="labelId" @change="loadAudiencePreview" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <option value="">Todos los contactos</option>
                                <option v-for="l in labels" :key="l.id" :value="l.id">{{ l.name }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Filtro ISPwatch</label>
                            <select v-model="ispwatchFilter" @change="loadAudiencePreview" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <option value="all">Todos</option>
                                <option value="customers">Solo clientes activos</option>
                                <option value="non_customers">Solo no clientes</option>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Manual -->
                <div v-else-if="sourceType === 'manual'" class="space-y-2">
                    <label class="block text-xs font-medium text-gray-700">Pega una lista, un contacto por línea</label>
                    <textarea v-model="manualText" rows="6" placeholder="Juan Pérez, 3001234567&#10;3009876543&#10;María López, 3015551234"
                              class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea>
                    <button @click="loadAudiencePreview" :disabled="!manualText.trim() || audienceLoading"
                            class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                        {{ audienceLoading ? 'Procesando…' : 'Vista previa' }}
                    </button>
                </div>

                <!-- Sheet -->
                <div v-else-if="sourceType === 'sheet'" class="space-y-3">
                    <div>
                        <label class="block text-xs font-medium text-gray-700 mb-1">Link de Google Sheets</label>
                        <input v-model="sheetUrl" type="url" placeholder="https://docs.google.com/spreadsheets/d/..."
                               class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <p class="text-[10px] text-gray-400 mt-1">La hoja debe ser pública ("Cualquiera con el link puede ver").</p>
                    </div>
                    <button @click="loadAudiencePreview" :disabled="!sheetUrl.trim() || audienceLoading"
                            class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                        {{ audienceLoading ? 'Cargando…' : 'Cargar hoja' }}
                    </button>

                    <div v-if="audiencePreview && columns.length" class="grid grid-cols-2 gap-3 pt-2">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Columna de teléfono</label>
                            <select v-model="phoneColumn" @change="loadAudiencePreview" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <option value="">Detectar automáticamente</option>
                                <option v-for="c in columns" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </div>
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Columna de nombre</label>
                            <select v-model="nameColumn" @change="loadAudiencePreview" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <option value="">Detectar automáticamente</option>
                                <option v-for="c in columns" :key="c" :value="c">{{ c }}</option>
                            </select>
                        </div>
                    </div>
                </div>

                <p v-if="audienceError" class="mt-3 text-sm text-red-600 bg-red-50 border border-red-200 rounded-xl p-3">{{ audienceError }}</p>

                <!-- Stats -->
                <div v-if="stats" class="mt-5 grid grid-cols-2 sm:grid-cols-5 gap-2">
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-gray-900">{{ stats.total }}</p>
                        <p class="text-[10px] text-gray-500 uppercase">Total</p>
                    </div>
                    <div class="bg-emerald-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-emerald-700">{{ stats.valid }}</p>
                        <p class="text-[10px] text-emerald-600 uppercase">Válidos</p>
                    </div>
                    <div class="bg-amber-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-amber-700">{{ stats.duplicates }}</p>
                        <p class="text-[10px] text-amber-600 uppercase">Duplicados</p>
                    </div>
                    <div class="bg-red-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-red-700">{{ stats.invalid }}</p>
                        <p class="text-[10px] text-red-600 uppercase">Inválidos</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-gray-700">{{ stats.opted_out }}</p>
                        <p class="text-[10px] text-gray-500 uppercase">De baja</p>
                    </div>
                </div>

                <!-- Preview table -->
                <div v-if="previewRows.length" class="mt-4 border border-gray-100 rounded-xl overflow-hidden">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                            <tr>
                                <th class="px-3 py-2 text-left">Teléfono</th>
                                <th class="px-3 py-2 text-left">Nombre</th>
                                <th class="px-3 py-2 text-left">Estado</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="(r, i) in previewRows" :key="i">
                                <td class="px-3 py-2 font-mono">{{ r.phone || '—' }}</td>
                                <td class="px-3 py-2">{{ r.name || '—' }}</td>
                                <td class="px-3 py-2">
                                    <span v-if="r.valid" class="text-emerald-600">Válido</span>
                                    <span v-else class="text-gray-400">{{ r.skip_reason }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <p v-if="stats.total > previewRows.length" class="text-[10px] text-gray-400 px-3 py-2 bg-gray-50">
                        Mostrando {{ previewRows.length }} de {{ stats.total }} filas.
                    </p>
                </div>

                <div class="flex justify-end mt-5">
                    <button @click="step = 2" :disabled="!canAdvanceStep1"
                            class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed">
                        Siguiente
                    </button>
                </div>
            </div>

            <!-- ═══════════════ Paso 2: Mensaje ═══════════════ -->
            <div v-else-if="step === 2" class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Plantilla <span class="text-red-500">*</span></label>
                    <select v-model="templateId" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <option value="">Elige una plantilla aprobada…</option>
                        <option v-for="t in templates" :key="t.id" :value="t.id">{{ t.name }}</option>
                    </select>
                    <p v-if="!templates.length" class="text-xs text-amber-700 bg-amber-50 border border-amber-200 rounded-lg p-2 mt-2">
                        No tienes plantillas aprobadas y activas. Crea o sincroniza una en la sección de Plantillas antes de continuar.
                    </p>
                </div>

                <div v-if="selectedTemplate" class="mb-4">
                    <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-2">Contenido original</p>
                    <div class="bg-gray-50 rounded-xl p-3 text-sm text-gray-700 whitespace-pre-wrap">{{ selectedTemplate.body }}</div>
                </div>

                <div v-if="variables.length" class="space-y-3 mb-4">
                    <p class="text-sm font-medium text-gray-700">Mapeo de variables</p>
                    <div v-for="v in variables" :key="v" class="flex items-center gap-2 bg-gray-50 rounded-xl p-2.5">
                        <span class="shrink-0 px-2 py-1.5 bg-white border border-gray-200 rounded-lg font-mono text-[11px] text-gray-700 w-28 truncate" :title="v">
                            {{ '{' + '{' + v + '}' + '}' }}
                        </span>
                        <select v-model="mapping[v].type" class="px-2 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
                            <option value="column">Columna</option>
                            <option value="contact_field">Campo de contacto</option>
                            <option value="static">Texto fijo</option>
                        </select>
                        <select v-if="mapping[v].type === 'column'" v-model="mapping[v].value" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
                            <option value="">Elige columna…</option>
                            <option v-for="c in columns" :key="c" :value="c">{{ c }}</option>
                        </select>
                        <select v-else-if="mapping[v].type === 'contact_field'" v-model="mapping[v].value" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs bg-white">
                            <option value="name">Nombre</option>
                            <option value="phone">Teléfono</option>
                        </select>
                        <input v-else v-model="mapping[v].value" type="text" placeholder="Texto fijo…" class="flex-1 px-2 py-1.5 border border-gray-200 rounded-lg text-xs">
                    </div>
                </div>

                <div v-if="messagePreviewText" class="mb-4">
                    <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-2">Vista previa en vivo</p>
                    <div class="bg-[#d9fdd3] rounded-2xl rounded-tl-sm p-3 max-w-sm border border-gray-100">
                        <p class="text-sm text-gray-900 whitespace-pre-wrap">{{ messagePreviewText }}</p>
                    </div>
                </div>

                <div class="flex justify-between mt-5">
                    <button @click="step = 1" class="px-5 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Atrás</button>
                    <button @click="goToStep3" :disabled="!templateId || !mappingComplete"
                            class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed">
                        Siguiente
                    </button>
                </div>
            </div>

            <!-- ═══════════════ Paso 3: Revisar y enviar ═══════════════ -->
            <div v-else class="bg-white rounded-2xl shadow-sm border border-gray-100 p-5">
                <div class="mb-4">
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre de la campaña <span class="text-red-500">*</span></label>
                    <input v-model="form.name" type="text" placeholder="Promo de fin de año"
                           class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"
                           :class="form.errors.name ? 'border-red-400' : 'border-gray-200'">
                    <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                </div>

                <div class="grid grid-cols-2 gap-3 mb-4">
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Ritmo de envío (mensajes/min)</label>
                        <input v-model.number="form.throttle_per_minute" type="number" min="1" max="1000"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                    </div>
                    <div>
                        <label class="block text-sm font-medium text-gray-700 mb-1">Agendar para <span class="text-gray-400 font-normal">(opcional)</span></label>
                        <input v-model="form.scheduled_at" type="datetime-local"
                               class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                    </div>
                </div>

                <p v-if="tierWarning" class="text-xs text-amber-800 bg-amber-50 border border-amber-200 rounded-xl p-3 mb-4">{{ tierWarning }}</p>

                <div class="grid grid-cols-2 sm:grid-cols-5 gap-2 mb-5">
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-gray-900">{{ stats?.total ?? 0 }}</p>
                        <p class="text-[10px] text-gray-500 uppercase">Total</p>
                    </div>
                    <div class="bg-emerald-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-emerald-700">{{ stats?.valid ?? 0 }}</p>
                        <p class="text-[10px] text-emerald-600 uppercase">Se enviará a</p>
                    </div>
                    <div class="bg-amber-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-amber-700">{{ stats?.duplicates ?? 0 }}</p>
                        <p class="text-[10px] text-amber-600 uppercase">Duplicados</p>
                    </div>
                    <div class="bg-red-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-red-700">{{ stats?.invalid ?? 0 }}</p>
                        <p class="text-[10px] text-red-600 uppercase">Inválidos</p>
                    </div>
                    <div class="bg-gray-50 rounded-xl p-3 text-center">
                        <p class="text-lg font-bold text-gray-700">{{ stats?.opted_out ?? 0 }}</p>
                        <p class="text-[10px] text-gray-500 uppercase">De baja</p>
                    </div>
                </div>

                <div class="bg-gray-50 rounded-xl p-3 mb-5 text-xs text-gray-600">
                    <p><span class="text-gray-400">Plantilla:</span> <span class="font-mono">{{ selectedTemplate?.name }}</span></p>
                    <p class="mt-1"><span class="text-gray-400">Fuente:</span> {{ sourceTabs.find(t => t.value === sourceType)?.label }}</p>
                </div>

                <p v-if="form.errors.rows" class="text-red-500 text-xs mb-3">{{ form.errors.rows }}</p>
                <p v-if="form.errors.template_id" class="text-red-500 text-xs mb-3">{{ form.errors.template_id }}</p>

                <p class="text-[11px] text-gray-400 mb-4">
                    La campaña se crea como <strong>borrador</strong>. Podrás enviar un mensaje de prueba antes de lanzarla desde la pantalla siguiente.
                </p>

                <div class="flex justify-between">
                    <button @click="backTo(2)" type="button" class="px-5 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Atrás</button>
                    <button @click="submit" :disabled="form.processing || !form.name.trim()"
                            class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50 disabled:cursor-not-allowed">
                        {{ form.processing ? 'Creando…' : 'Crear campaña' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
