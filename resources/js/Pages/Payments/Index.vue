<script setup>
// «Pagos reportados»: los comprobantes que mandaron los clientes por WhatsApp.
// La IA los lee y el código los verifica; aquí una persona revisa lo que no
// pasó solo y decide: registrarlo en ispwatch, marcarlo como ya registrado o
// descartarlo. Nada de esto escribe en ispwatch por la base: el registro va por
// la API de ispwatch (docs/agente-ia.md).
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import axios from 'axios';

const props = defineProps({
    reports:     { type: Object, default: () => ({ data: [], links: [] }) },
    filter:      { type: String, default: 'revision' },
    counts:      { type: Object, default: () => ({}) },
    channels:    { type: Array, default: () => [] },
    canRegister: { type: Boolean, default: false },
    aiReason:    { type: String, default: null },
    reasons:     { type: Object, default: () => ({}) },
    checkLabels: { type: Object, default: () => ({}) },
});

const tabs = [
    { key: 'revision', label: 'Por revisar' },
    { key: 'registrados', label: 'Registrados' },
    { key: 'descartados', label: 'Descartados' },
    { key: 'todos', label: 'Todos' },
];

const STATUS = {
    reading:        { label: 'Leyendo…', class: 'bg-sky-100 text-sky-700' },
    pending_review: { label: 'Por revisar', class: 'bg-amber-100 text-amber-800' },
    registering:    { label: 'Registrando…', class: 'bg-sky-100 text-sky-700' },
    registered:     { label: 'Registrado', class: 'bg-emerald-100 text-emerald-700' },
    rejected:       { label: 'Descartado', class: 'bg-gray-100 text-gray-600' },
    duplicate:      { label: 'Repetido', class: 'bg-gray-100 text-gray-600' },
    not_receipt:    { label: 'No es comprobante', class: 'bg-gray-100 text-gray-600' },
};

function setTab(key) {
    router.get(route('payments.index'), { estado: key }, { preserveScroll: true, replace: true });
}

// Mientras algo se esté leyendo o registrando, la lista se refresca sola.
const busy = computed(() => (props.reports.data ?? []).some((r) => ['reading', 'registering'].includes(r.status)));
let timer = null;
onMounted(() => {
    timer = setInterval(() => {
        if (busy.value && !document.hidden) router.reload({ only: ['reports', 'counts'], preserveScroll: true });
    }, 5000);
});
onUnmounted(() => clearInterval(timer));

// ── Detalle ──────────────────────────────────────────────────────────────────
const selected = ref(null);
const pendingInvoices = ref(null); // null = no se pudo consultar (≠ no debe nada)
const loadingDetail = ref(false);

async function open(report) {
    selected.value = report;
    pendingInvoices.value = null;
    resetForms(report);
    loadingDetail.value = true;

    try {
        const { data } = await axios.get(route('payments.show', report.id));
        selected.value = data.report;
        pendingInvoices.value = data.pending_invoices ?? null;
        resetForms(data.report);
    } finally {
        loadingDetail.value = false;
    }
}

function close() {
    selected.value = null;
    searchTerm.value = '';
    searchResults.value = [];
}

// El detalle abierto se actualiza con la lista (p. ej. cuando termina de leer).
watch(() => props.reports.data, (data) => {
    if (!selected.value) return;
    const fresh = (data ?? []).find((r) => r.id === selected.value.id);
    if (fresh && (fresh.status !== selected.value.status || fresh.customer?.id !== selected.value.customer?.id)) open(fresh);
});

const registerForm = useForm({ amount: '', paid_on: '', reference: '', payment_channel_id: null });
const markForm = useForm({ ispwatch_payment_id: '', note: '' });
const rejectForm = useForm({ note: '' });
const action = ref(null); // 'register' | 'mark' | 'reject'

function resetForms(r) {
    registerForm.amount = r.amount ?? '';
    registerForm.paid_on = r.paid_on ?? '';
    registerForm.reference = r.reference ?? '';
    registerForm.payment_channel_id = r.payment_channel?.id ?? null;
    registerForm.clearErrors();
    markForm.reset();
    rejectForm.reset();
    action.value = null;
}

const reviewable = computed(() => selected.value && !['reading', 'registering', 'registered'].includes(selected.value.status));
const failedChecks = (r) => (r.checks ?? []).filter((c) => c.ok !== true).length;

function submitRegister() {
    registerForm.post(route('payments.register', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => { action.value = null; router.reload({ only: ['reports', 'counts'] }); },
    });
}

function submitMark() {
    markForm.post(route('payments.mark-registered', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

function submitReject() {
    rejectForm.post(route('payments.reject', selected.value.id), {
        preserveScroll: true,
        onSuccess: () => close(),
    });
}

// ── Cambiar el cliente ───────────────────────────────────────────────────────
const searchTerm = ref('');
const searchResults = ref([]);
const searching = ref(false);
let searchTimer = null;

watch(searchTerm, (term) => {
    clearTimeout(searchTimer);
    if (term.trim().length < 3) { searchResults.value = []; return; }
    searchTimer = setTimeout(async () => {
        searching.value = true;
        try {
            const { data } = await axios.get(route('payments.customers.search'), { params: { q: term.trim() } });
            searchResults.value = data.customers ?? [];
        } catch {
            searchResults.value = [];
        } finally {
            searching.value = false;
        }
    }, 350);
});

function assignCustomer(customer) {
    router.put(route('payments.customer', selected.value.id), { customer_id: customer.user_id }, {
        preserveScroll: true,
        onSuccess: () => {
            searchTerm.value = '';
            searchResults.value = [];
            open(selected.value);
        },
    });
}

// ── Formato ──────────────────────────────────────────────────────────────────
function money(value) {
    if (value === null || value === undefined || value === '') return '—';
    return '$' + Number(value).toLocaleString('es-CO', { maximumFractionDigits: 0 });
}

function day(iso) {
    if (!iso) return '—';
    const [y, m, d] = iso.slice(0, 10).split('-');
    return `${d}/${m}/${y}`;
}

function when(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

const matchLabel = { telefono: 'por su teléfono', telefono_ambiguo: 'por teléfono (varios clientes lo comparten)', manual: 'elegido a mano' };
</script>

<template>
    <Head title="Pagos reportados" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Pagos reportados</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Comprobantes que tus clientes mandaron por WhatsApp. La IA los lee y Converza verifica cuenta, valor, fecha y
                    repetidos; lo que no pasa solo queda aquí para que lo revises.
                </p>
            </div>

            <!-- Avisos de configuración -->
            <div v-if="aiReason" class="mb-4 flex items-start gap-3 rounded-2xl border border-amber-200 bg-amber-50 p-4">
                <svg class="w-5 h-5 text-amber-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                <div class="text-sm text-amber-800">
                    <p class="font-semibold">Los comprobantes nuevos no se están leyendo con IA.</p>
                    <p class="text-xs mt-0.5">{{ reasons[aiReason] ?? aiReason }}. Igual llegan aquí para revisarlos a mano.
                        <Link :href="route('settings.index')" class="underline">Configuración → Inteligencia artificial</Link></p>
                </div>
            </div>
            <div v-if="!canRegister" class="mb-4 flex items-start gap-3 rounded-2xl border border-gray-200 bg-white p-4">
                <svg class="w-5 h-5 text-gray-400 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.19 8.688a4.5 4.5 0 011.242 7.244l-4.5 4.5a4.5 4.5 0 01-6.364-6.364l1.757-1.757m13.35-.622l1.757-1.757a4.5 4.5 0 00-6.364-6.364l-4.5 4.5a4.5 4.5 0 001.242 7.244"/></svg>
                <p class="text-sm text-gray-600">
                    Tu empresa todavía no tiene la <strong>conexión de pagos con ispwatch</strong>: registra cada pago allá y márcalo
                    aquí como «Ya lo registré». Cuando la tengas, lo registras desde aquí con un clic. Pídela por Soporte.
                </p>
            </div>

            <!-- Pestañas -->
            <div class="flex items-center gap-1 bg-gray-100 rounded-xl p-1 mb-4 overflow-x-auto">
                <button v-for="t in tabs" :key="t.key" type="button" @click="setTab(t.key)"
                        class="px-3 py-1.5 rounded-lg text-xs font-medium transition whitespace-nowrap"
                        :class="filter === t.key ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                    {{ t.label }}
                    <span class="ml-1 text-[10px] rounded-full px-1.5 py-0.5" :class="t.key === 'revision' && counts[t.key] ? 'bg-amber-500 text-white' : 'bg-gray-200 text-gray-600'">{{ counts[t.key] ?? 0 }}</span>
                </button>
            </div>

            <!-- Lista -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <ul v-if="reports.data?.length" class="divide-y divide-gray-50">
                    <li v-for="r in reports.data" :key="r.id">
                        <button type="button" class="w-full text-left px-4 py-3 flex items-center gap-3 hover:bg-gray-50 transition" @click="open(r)">
                            <div class="w-12 h-12 rounded-lg bg-gray-100 overflow-hidden flex items-center justify-center shrink-0">
                                <img v-if="r.image_url && !r.is_pdf" :src="r.image_url" alt="" class="w-full h-full object-cover" loading="lazy" />
                                <span v-else class="text-[10px] font-bold text-gray-500">{{ r.is_pdf ? 'PDF' : '—' }}</span>
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <p class="text-sm font-semibold text-gray-900 truncate">{{ r.customer?.name || r.contact?.name || 'Cliente sin identificar' }}</p>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold" :class="STATUS[r.status]?.class">{{ STATUS[r.status]?.label ?? r.status }}</span>
                                    <span v-if="r.source === 'flow'" class="text-[10px] text-gray-400">desde el bot</span>
                                </div>
                                <p class="text-xs text-gray-500 truncate">
                                    {{ money(r.amount) }}<span v-if="r.channel"> · {{ r.channel }}</span><span v-if="r.reference"> · ref {{ r.reference }}</span><span v-if="r.paid_on"> · {{ day(r.paid_on) }}</span>
                                </p>
                                <p v-if="r.status === 'pending_review' && r.reason" class="text-[11px] text-amber-700 truncate">
                                    {{ reasons[r.reason] ?? r.reason }}<span v-if="r.reason === 'verificacion'"> ({{ failedChecks(r) }})</span>
                                </p>
                            </div>
                            <p class="text-[11px] text-gray-400 whitespace-nowrap shrink-0 hidden sm:block">{{ when(r.created_at) }}</p>
                        </button>
                    </li>
                </ul>

                <div v-else class="text-center py-16 px-4">
                    <p class="text-sm text-gray-500 font-medium">No hay comprobantes en esta pestaña.</p>
                    <p class="text-xs text-gray-400 mt-1 max-w-md mx-auto">
                        Llegan cuando un cliente manda su comprobante a un flujo con el bloque «Comprobante de pago», o cuando
                        alguien toca «Leer comprobante» en una imagen del chat.
                    </p>
                </div>

                <div v-if="reports.data?.length && reports.last_page > 1" class="border-t border-gray-100 px-4 py-3 flex items-center justify-between">
                    <p class="text-xs text-gray-500">{{ reports.from }}–{{ reports.to }} de {{ reports.total }}</p>
                    <div class="flex gap-1">
                        <Link v-for="link in reports.links" :key="link.label" :href="link.url || '#'" v-html="link.label"
                              class="px-3 py-1 rounded-lg text-xs border transition"
                              :class="link.active ? 'bg-accent text-white border-accent' : link.url ? 'border-gray-200 text-gray-700 hover:bg-gray-50' : 'border-gray-100 text-gray-300 pointer-events-none'" />
                    </div>
                </div>
            </div>
        </div>

        <!-- Detalle -->
        <Teleport to="body">
            <div v-if="selected" class="fixed inset-0 z-50 flex justify-end bg-black/30" @click.self="close">
                <div class="w-full max-w-2xl h-full bg-white shadow-xl overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-5 py-4 flex items-start justify-between gap-3 z-10">
                        <div class="min-w-0">
                            <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold">Comprobante #{{ selected.id }}</p>
                            <h2 class="text-lg font-semibold text-gray-900 truncate">{{ money(selected.amount) }} · {{ selected.customer?.name || selected.contact?.name || 'Sin identificar' }}</h2>
                            <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-semibold" :class="STATUS[selected.status]?.class">{{ STATUS[selected.status]?.label ?? selected.status }}</span>
                            <span v-if="selected.reason" class="ml-2 text-xs text-gray-500">{{ reasons[selected.reason] ?? selected.reason }}</span>
                        </div>
                        <button type="button" class="p-2 rounded-lg text-gray-400 hover:text-gray-700 hover:bg-gray-100" @click="close">✕</button>
                    </div>

                    <div class="p-5 space-y-5">
                        <!-- Imagen -->
                        <div v-if="selected.image_url" class="rounded-xl bg-gray-50 border border-gray-100 overflow-hidden">
                            <a :href="selected.image_url" target="_blank" rel="noopener">
                                <img v-if="!selected.is_pdf" :src="selected.image_url" alt="Comprobante" class="w-full max-h-[420px] object-contain" />
                                <p v-else class="p-6 text-center text-sm text-accent font-medium">Abrir el PDF ↗</p>
                            </a>
                        </div>
                        <p v-else class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">La imagen ya no está disponible.</p>

                        <p v-if="selected.last_error" class="text-xs text-red-700 bg-red-50 rounded-lg px-3 py-2">{{ selected.last_error }}</p>

                        <!-- Lo que leyó la IA -->
                        <div class="grid grid-cols-2 gap-3 text-sm">
                            <div><p class="text-[11px] text-gray-400">Valor</p><p class="font-semibold text-gray-900">{{ money(selected.amount) }}</p></div>
                            <div><p class="text-[11px] text-gray-400">Fecha</p><p class="text-gray-900">{{ day(selected.paid_on) }}</p></div>
                            <div><p class="text-[11px] text-gray-400">Referencia</p><p class="text-gray-900 font-mono break-all">{{ selected.reference || '—' }}</p></div>
                            <div><p class="text-[11px] text-gray-400">Medio</p><p class="text-gray-900">{{ selected.channel || '—' }}</p></div>
                            <div><p class="text-[11px] text-gray-400">Pagado a</p><p class="text-gray-900">{{ selected.destination_account || '—' }}<span v-if="selected.destination_holder" class="block text-xs text-gray-500">{{ selected.destination_holder }}</span></p></div>
                            <div><p class="text-[11px] text-gray-400">Pagó</p><p class="text-gray-900">{{ selected.payer_name || '—' }}</p></div>
                        </div>
                        <p v-if="selected.ai_confidence !== null" class="text-[11px] text-gray-400 -mt-2">
                            Lectura de la IA con {{ Math.round(selected.ai_confidence * 100) }} % de seguridad. Compárala siempre con la imagen.
                        </p>

                        <!-- Verificaciones -->
                        <div v-if="selected.checks?.length" class="rounded-xl border border-gray-100">
                            <p class="px-3 pt-3 text-xs font-semibold text-gray-700">Verificaciones</p>
                            <ul class="p-3 space-y-1.5">
                                <li v-for="c in selected.checks" :key="c.code" class="flex items-start gap-2 text-xs">
                                    <span class="w-4 shrink-0 font-bold" :class="c.ok === true ? 'text-emerald-600' : c.ok === false ? 'text-red-600' : 'text-gray-400'">{{ c.ok === true ? '✓' : c.ok === false ? '✗' : '?' }}</span>
                                    <span class="text-gray-700">{{ checkLabels[c.code] ?? c.code }}<span v-if="c.detail" class="text-gray-500"> — {{ c.detail }}</span></span>
                                </li>
                            </ul>
                        </div>

                        <!-- Cliente -->
                        <div class="rounded-xl border border-gray-100 p-3 space-y-2">
                            <div class="flex items-center justify-between gap-2">
                                <p class="text-xs font-semibold text-gray-700">Cliente en ispwatch</p>
                                <Link v-if="selected.conversation_id" :href="route('chat.index', { conversation: selected.conversation_id })" class="text-xs text-accent hover:underline">Abrir el chat →</Link>
                            </div>
                            <p v-if="selected.customer" class="text-sm text-gray-900">
                                {{ selected.customer.name }} <span class="text-xs text-gray-500">({{ matchLabel[selected.customer.match] ?? selected.customer.match }})</span>
                            </p>
                            <p v-else class="text-sm text-amber-700">Sin identificar: el teléfono del chat no es de ningún cliente.</p>
                            <p class="text-[11px] text-gray-500">Escribió desde {{ selected.contact?.phone || 'un número oculto' }}<span v-if="selected.contact?.name"> ({{ selected.contact.name }})</span>.</p>

                            <div v-if="loadingDetail" class="text-[11px] text-gray-400">Consultando su deuda…</div>
                            <p v-else-if="selected.customer && pendingInvoices === null" class="text-xs text-amber-700">No se pudo consultar su deuda en ispwatch: revísala allá antes de registrar.</p>
                            <ul v-else-if="pendingInvoices?.length" class="text-xs text-gray-600 space-y-0.5">
                                <li v-for="inv in pendingInvoices" :key="inv.id">• {{ inv.number }} · {{ money(inv.balance_due) }}<span v-if="inv.due_date"> · vence {{ day(inv.due_date) }}</span></li>
                            </ul>
                            <p v-else-if="selected.customer" class="text-xs text-gray-500">No tiene facturas pendientes.</p>

                            <div v-if="reviewable" class="pt-1">
                                <input v-model="searchTerm" type="text" placeholder="Cambiar cliente: nombre, cédula o teléfono"
                                       class="w-full px-3 py-2 border border-gray-200 rounded-lg text-xs" />
                                <p v-if="searching" class="text-[11px] text-gray-400 mt-1">Buscando…</p>
                                <ul v-if="searchResults.length" class="mt-1 border border-gray-100 rounded-lg divide-y divide-gray-50">
                                    <li v-for="c in searchResults" :key="c.user_id">
                                        <button type="button" class="w-full text-left px-3 py-1.5 text-xs hover:bg-gray-50" @click="assignCustomer(c)">
                                            <span class="font-medium text-gray-900">{{ c.name }}</span>
                                            <span class="text-gray-500"> · {{ c.cedula || 'sin cédula' }} · {{ c.tel || 'sin teléfono' }}</span>
                                        </button>
                                    </li>
                                </ul>
                            </div>
                        </div>

                        <!-- Registro -->
                        <div v-if="selected.status === 'registered'" class="rounded-xl bg-emerald-50 border border-emerald-100 p-3 text-sm text-emerald-800 space-y-1">
                            <p class="font-semibold">Registrado<span v-if="selected.ispwatch_payment_id"> en ispwatch (pago #{{ selected.ispwatch_payment_id }})</span>.</p>
                            <p class="text-xs">{{ selected.registered_by }} · {{ when(selected.registered_at) }}</p>
                            <p v-for="(a, i) in (selected.registration?.allocations ?? [])" :key="i" class="text-xs">Abonado a {{ a.invoice_number ?? ('factura #' + a.invoice_id) }}: {{ money(a.amount) }}</p>
                            <p v-if="selected.registration?.reactivation?.was_suspended" class="text-xs" :class="selected.registration.reactivation.router_ok ? '' : 'text-red-700 font-semibold'">
                                {{ selected.registration.reactivation.router_ok ? 'Estaba cortado y quedó reconectado.' : '⚠️ Estaba cortado y el router NO confirmó la reconexión: revísalo en ispwatch.' }}
                            </p>
                            <p v-if="selected.review_note" class="text-xs">Nota: {{ selected.review_note }}</p>
                        </div>
                        <div v-else-if="['rejected'].includes(selected.status)" class="rounded-xl bg-gray-50 border border-gray-100 p-3 text-xs text-gray-600">
                            Descartado por {{ selected.reviewed_by || '—' }} · {{ when(selected.reviewed_at) }}<span v-if="selected.review_note">: {{ selected.review_note }}</span>
                        </div>

                        <!-- Acciones -->
                        <div v-if="reviewable" class="space-y-3">
                            <div class="flex flex-wrap gap-2">
                                <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium bg-accent text-white disabled:opacity-40"
                                        :disabled="!canRegister || !selected.customer" @click="action = action === 'register' ? null : 'register'"
                                        :title="!canRegister ? 'Falta la conexión de pagos con ispwatch' : (!selected.customer ? 'Primero elige el cliente' : '')">
                                    Registrar en ispwatch
                                </button>
                                <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-200 text-gray-700 hover:bg-gray-50" @click="action = action === 'mark' ? null : 'mark'">
                                    Ya lo registré en ispwatch
                                </button>
                                <button type="button" class="px-4 py-2 rounded-lg text-sm font-medium border border-red-200 text-red-600 hover:bg-red-50" @click="action = action === 'reject' ? null : 'reject'">
                                    Descartar
                                </button>
                            </div>

                            <form v-if="action === 'register'" class="rounded-xl border border-gray-200 p-3 space-y-3" @submit.prevent="submitRegister">
                                <p class="text-xs text-gray-600">Revisa los datos contra la imagen. ispwatch abona a las facturas más viejas y, si estaba cortado y queda al día, lo reconecta.</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-700 mb-1">Valor</label>
                                        <input v-model="registerForm.amount" type="number" min="1" step="1" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                                        <p v-if="registerForm.errors.amount" class="text-[11px] text-red-600 mt-1">{{ registerForm.errors.amount }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-700 mb-1">Fecha del pago</label>
                                        <input v-model="registerForm.paid_on" type="date" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                                        <p v-if="registerForm.errors.paid_on" class="text-[11px] text-red-600 mt-1">{{ registerForm.errors.paid_on }}</p>
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-700 mb-1">Referencia</label>
                                        <input v-model="registerForm.reference" type="text" maxlength="80" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm font-mono" />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-700 mb-1">Medio de pago</label>
                                        <select v-model="registerForm.payment_channel_id" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm">
                                            <option :value="null">—</option>
                                            <option v-for="c in channels" :key="c.id" :value="c.id">{{ c.name }}</option>
                                        </select>
                                    </div>
                                </div>
                                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-accent text-white disabled:opacity-50" :disabled="registerForm.processing">
                                    {{ registerForm.processing ? 'Enviando…' : `Registrar ${money(registerForm.amount)}` }}
                                </button>
                            </form>

                            <form v-if="action === 'mark'" class="rounded-xl border border-gray-200 p-3 space-y-3" @submit.prevent="submitMark">
                                <p class="text-xs text-gray-600">Para cuando el pago ya se registró directamente en ispwatch: aquí solo queda la constancia.</p>
                                <div class="grid grid-cols-2 gap-3">
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-700 mb-1"># del pago en ispwatch <span class="text-gray-400">(opcional)</span></label>
                                        <input v-model="markForm.ispwatch_payment_id" type="number" min="1" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                                    </div>
                                    <div>
                                        <label class="block text-[11px] font-medium text-gray-700 mb-1">Nota <span class="text-gray-400">(opcional)</span></label>
                                        <input v-model="markForm.note" type="text" maxlength="500" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                                    </div>
                                </div>
                                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium border border-gray-300 text-gray-800 hover:bg-gray-50" :disabled="markForm.processing">Marcar como registrado</button>
                            </form>

                            <form v-if="action === 'reject'" class="rounded-xl border border-red-100 p-3 space-y-2" @submit.prevent="submitReject">
                                <label class="block text-[11px] font-medium text-gray-700">¿Por qué se descarta? <span class="text-gray-400">(queda en el registro)</span></label>
                                <input v-model="rejectForm.note" type="text" maxlength="500" placeholder="Ej. el valor no coincide con el extracto" class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm" />
                                <p v-if="rejectForm.errors.note" class="text-[11px] text-red-600">{{ rejectForm.errors.note }}</p>
                                <button type="submit" class="px-4 py-2 rounded-lg text-sm font-medium bg-red-600 text-white disabled:opacity-50" :disabled="rejectForm.processing">Descartar comprobante</button>
                                <p class="text-[11px] text-gray-500">Descartar no le escribe al cliente: si hay que avisarle, hazlo desde el chat.</p>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
