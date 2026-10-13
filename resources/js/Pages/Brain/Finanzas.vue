<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    month:       String,
    summary:     Object,
    by_category: { type: Array, default: () => [] },
    by_app:      { type: Array, default: () => [] },
    trend:       { type: Array, default: () => [] },
    unit:        { type: Object, default: () => ({ mrr: {}, fixed_monthly: {} }) },
    income:      { type: Array, default: () => [] },
    expenses:    { type: Array, default: () => [] },
    recurring:   { type: Array, default: () => [] },
    categories:  { type: Object, default: () => ({}) },
    apps:        { type: Array, default: () => [] },
});

// Todo por moneda, sin convertir: una tasa inventada haría parecer exacto lo que no lo es.
function formatAmount(amount, currency) {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency, minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(amount));
}
function money(map) {
    const entries = Object.entries(map ?? {});
    if (entries.length === 0) return '—';
    return entries.map(([cur, amt]) => formatAmount(amt, cur)).join(' · ');
}
function fmtDate(d) { return d ? new Date(d + 'T00:00:00').toLocaleDateString('es-CO') : '—'; }
function monthLabel(ym) {
    const [y, m] = ym.split('-').map(Number);
    return new Date(y, m - 1, 1).toLocaleDateString('es-CO', { month: 'long', year: 'numeric' });
}
function shiftMonth(ym, delta) {
    const [y, m] = ym.split('-').map(Number);
    const d = new Date(y, m - 1 + delta, 1);
    return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}`;
}
function goToMonth(ym) { router.get(route('brain.finance.index'), { month: ym }, { preserveScroll: true }); }

const appLabel = (a) => ({ converza: 'Converza', ispwatch: 'ISPWatch', general: 'General' }[a] ?? a);
const methodLabel = { transfer: 'Transferencia', cash: 'Efectivo', card: 'Tarjeta', other: 'Otro' };
const cycleLabel = { monthly: 'Mensual', yearly: 'Anual' };
const monthNames = ['enero', 'febrero', 'marzo', 'abril', 'mayo', 'junio', 'julio', 'agosto', 'septiembre', 'octubre', 'noviembre', 'diciembre'];

// Resultado del mes por moneda: ingresos − egresos.
const result = computed(() => {
    const out = {};
    const currencies = new Set([...Object.keys(props.summary?.income ?? {}), ...Object.keys(props.summary?.expenses ?? {})]);
    for (const cur of currencies) {
        out[cur] = Number(props.summary?.income?.[cur] ?? 0) - Number(props.summary?.expenses?.[cur] ?? 0);
    }
    return out;
});

// MRR contra gastos fijos llevados a mensual: ¿el negocio se paga solo?
const margin = computed(() => {
    const out = {};
    const currencies = new Set([...Object.keys(props.unit?.mrr ?? {}), ...Object.keys(props.unit?.fixed_monthly ?? {})]);
    for (const cur of currencies) {
        out[cur] = Number(props.unit?.mrr?.[cur] ?? 0) - Number(props.unit?.fixed_monthly?.[cur] ?? 0);
    }
    return out;
});

// ── Egresos ────────────────────────────────────────────────────────────────
const showExpense = ref(false);
const editingExpense = ref(null);
const today = () => new Date().toISOString().split('T')[0];
const expenseForm = useForm({ concept: '', vendor: '', category: 'infraestructura', app: 'general', kind: 'variable', amount: '', currency: 'USD', spent_at: '', method: 'card', reference: '', notes: '' });
function openAddExpense() {
    editingExpense.value = null;
    expenseForm.reset();
    expenseForm.clearErrors();
    expenseForm.spent_at = today();
    showExpense.value = true;
}
function openEditExpense(e) {
    editingExpense.value = e;
    expenseForm.clearErrors();
    Object.assign(expenseForm, { concept: e.concept, vendor: e.vendor ?? '', category: e.category, app: e.app, kind: e.kind, amount: e.amount, currency: e.currency, spent_at: e.spent_at, method: e.method ?? '', reference: e.reference ?? '', notes: e.notes ?? '' });
    showExpense.value = true;
}
function submitExpense() {
    const opts = { preserveScroll: true, onSuccess: () => { showExpense.value = false; } };
    editingExpense.value
        ? expenseForm.put(route('brain.finance.expenses.update', editingExpense.value.id), opts)
        : expenseForm.post(route('brain.finance.expenses.store'), opts);
}
function deleteExpense(e) {
    if (!confirm(`¿Eliminar el egreso "${e.concept}"?`)) return;
    router.delete(route('brain.finance.expenses.destroy', e.id), { preserveScroll: true });
}

// ── Gastos fijos ───────────────────────────────────────────────────────────
const showRecurring = ref(false);
const editingRecurring = ref(null);
const recurringForm = useForm({ concept: '', vendor: '', category: 'infraestructura', app: 'general', amount: '', currency: 'USD', cycle: 'monthly', day_of_month: 1, month_of_year: '', starts_on: '', ends_on: '', active: true, notes: '' });
function openAddRecurring() {
    editingRecurring.value = null;
    recurringForm.reset();
    recurringForm.clearErrors();
    recurringForm.starts_on = today();
    recurringForm.day_of_month = new Date().getDate();
    showRecurring.value = true;
}
function openEditRecurring(r) {
    editingRecurring.value = r;
    recurringForm.clearErrors();
    Object.assign(recurringForm, { concept: r.concept, vendor: r.vendor ?? '', category: r.category, app: r.app, amount: r.amount, currency: r.currency, cycle: r.cycle, day_of_month: r.day_of_month, month_of_year: r.month_of_year ?? '', starts_on: r.starts_on, ends_on: r.ends_on ?? '', active: r.active, notes: r.notes ?? '' });
    showRecurring.value = true;
}
function submitRecurring() {
    const opts = { preserveScroll: true, onSuccess: () => { showRecurring.value = false; } };
    editingRecurring.value
        ? recurringForm.put(route('brain.finance.recurring.update', editingRecurring.value.id), opts)
        : recurringForm.post(route('brain.finance.recurring.store'), opts);
}
function toggleRecurring(r) {
    router.put(route('brain.finance.recurring.update', r.id), { ...r, vendor: r.vendor ?? '', month_of_year: r.month_of_year ?? '', ends_on: r.ends_on ?? '', notes: r.notes ?? '', active: !r.active }, { preserveScroll: true });
}
function deleteRecurring(r) {
    if (!confirm(`¿Eliminar el gasto fijo "${r.concept}"? Los egresos que ya registró se conservan.`)) return;
    router.delete(route('brain.finance.recurring.destroy', r.id), { preserveScroll: true });
}

const inputClass = 'w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400';
</script>

<template>
    <AppLayout>
        <div class="p-6 max-w-6xl mx-auto space-y-6">

            <!-- Header -->
            <div class="flex flex-wrap items-center justify-between gap-4">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Finanzas</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Lo que entra por cobros contra lo que cuestan las apps</p>
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <div class="flex items-center bg-white border border-gray-200 rounded-lg">
                        <button @click="goToMonth(shiftMonth(month, -1))" class="px-2.5 py-2 text-gray-500 hover:text-gray-900" title="Mes anterior">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                        </button>
                        <span class="px-2 text-sm font-medium text-gray-900 capitalize min-w-[9rem] text-center">{{ monthLabel(month) }}</span>
                        <button @click="goToMonth(shiftMonth(month, 1))" class="px-2.5 py-2 text-gray-500 hover:text-gray-900" title="Mes siguiente">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                        </button>
                    </div>
                    <button @click="openAddRecurring" class="px-3 py-2 text-sm font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">Nuevo gasto fijo</button>
                    <button @click="openAddExpense" class="inline-flex items-center gap-1.5 px-3 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 transition">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Registrar egreso
                    </button>
                </div>
            </div>

            <!-- Resultado del mes -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Ingresos</p>
                    <p class="text-2xl font-bold text-green-600 mt-1">{{ money(summary.income) }}</p>
                    <p class="text-xs text-gray-400 mt-1">pagos recibidos de los ISP</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Egresos</p>
                    <p class="text-2xl font-bold text-red-600 mt-1">{{ money(summary.expenses) }}</p>
                    <p class="text-xs text-gray-400 mt-1">fijos {{ money(summary.fixed) }} · variables {{ money(summary.variable) }}</p>
                </div>
                <div class="rounded-xl p-5 text-white shadow-sm bg-gradient-to-br from-gray-800 to-gray-900">
                    <p class="text-xs uppercase tracking-wider text-gray-300">Resultado del mes</p>
                    <p class="text-2xl font-bold mt-1">
                        <span v-if="!Object.keys(result).length">—</span>
                        <span v-for="(v, cur, i) in result" :key="cur" :class="v < 0 ? 'text-red-300' : 'text-emerald-300'">{{ i ? ' · ' : '' }}{{ formatAmount(v, cur) }}</span>
                    </p>
                    <p class="text-xs text-gray-400 mt-1">ingresos − egresos, por moneda</p>
                </div>
            </div>

            <!-- ¿El negocio se paga solo? -->
            <div class="bg-white rounded-xl border border-gray-200 p-5">
                <h2 class="text-sm font-semibold text-gray-900">Mes típico</h2>
                <p class="text-xs text-gray-500 mt-0.5">Lo que entra cada mes de las cuentas activas (MRR) contra los gastos fijos llevados a mensual. No depende de quién pagó cuándo.</p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mt-4">
                    <div><p class="text-xs text-gray-500">MRR</p><p class="text-lg font-bold text-gray-900">{{ money(unit.mrr) }}</p></div>
                    <div><p class="text-xs text-gray-500">Gastos fijos al mes</p><p class="text-lg font-bold text-gray-900">{{ money(unit.fixed_monthly) }}</p></div>
                    <div>
                        <p class="text-xs text-gray-500">Margen</p>
                        <p class="text-lg font-bold">
                            <span v-if="!Object.keys(margin).length" class="text-gray-400">—</span>
                            <span v-for="(v, cur, i) in margin" :key="cur" :class="v < 0 ? 'text-red-600' : 'text-emerald-600'">{{ i ? ' · ' : '' }}{{ formatAmount(v, cur) }}</span>
                        </p>
                    </div>
                </div>
            </div>

            <!-- Últimos seis meses -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="px-5 py-3 border-b border-gray-100"><h2 class="text-sm font-semibold text-gray-900">Últimos seis meses</h2></div>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead class="bg-gray-50 text-xs text-gray-500 uppercase tracking-wider">
                            <tr><th class="text-left px-5 py-2">Mes</th><th class="text-right px-5 py-2">Ingresos</th><th class="text-right px-5 py-2">Egresos</th><th class="text-right px-5 py-2">Resultado</th></tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="row in trend" :key="row.month" class="cursor-pointer hover:bg-amber-50/50" :class="row.month === month ? 'bg-amber-50' : ''" @click="goToMonth(row.month)">
                                <td class="px-5 py-2 capitalize text-gray-900">{{ monthLabel(row.month) }}</td>
                                <td class="px-5 py-2 text-right text-green-700">{{ money(row.income) }}</td>
                                <td class="px-5 py-2 text-right text-red-600">{{ money(row.expenses) }}</td>
                                <td class="px-5 py-2 text-right font-semibold">
                                    <span v-if="!Object.keys(row.result).length" class="text-gray-400">—</span>
                                    <span v-for="(v, cur, i) in row.result" :key="cur" :class="Number(v) < 0 ? 'text-red-600' : 'text-emerald-700'">{{ i ? ' · ' : '' }}{{ formatAmount(v, cur) }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>

            <!-- En qué se va la plata -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3">Egresos por categoría</h2>
                    <p v-if="!by_category.length" class="text-sm text-gray-400">Sin egresos este mes.</p>
                    <div v-for="row in by_category" :key="row.key" class="flex items-center justify-between py-1.5 text-sm">
                        <span class="text-gray-700">{{ categories[row.key] ?? row.key }}</span>
                        <span class="font-medium text-gray-900">{{ money(row.amounts) }}</span>
                    </div>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <h2 class="text-sm font-semibold text-gray-900 mb-3">Egresos por app</h2>
                    <p v-if="!by_app.length" class="text-sm text-gray-400">Sin egresos este mes.</p>
                    <div v-for="row in by_app" :key="row.key" class="flex items-center justify-between py-1.5 text-sm">
                        <span class="text-gray-700">{{ appLabel(row.key) }}</span>
                        <span class="font-medium text-gray-900">{{ money(row.amounts) }}</span>
                    </div>
                </div>
            </div>

            <!-- Egresos del mes -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-gray-900">Egresos de {{ monthLabel(month) }} ({{ expenses.length }})</h2>
                    <button @click="openAddExpense" class="text-xs text-amber-700 hover:underline">Registrar egreso</button>
                </div>
                <div v-if="!expenses.length" class="py-8 text-center text-gray-400 text-sm bg-white rounded-xl border border-dashed border-gray-200">
                    Sin egresos este mes. Los gastos fijos aparecen solos cuando les llega el día.
                </div>
                <div v-else class="bg-white rounded-xl border border-gray-200 overflow-hidden divide-y divide-gray-100">
                    <div v-for="e in expenses" :key="e.id" class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-gray-900">{{ e.concept }}</span>
                                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full" :class="e.kind === 'fixed' ? 'bg-sky-100 text-sky-700' : 'bg-orange-100 text-orange-700'">{{ e.kind === 'fixed' ? 'FIJO' : 'VARIABLE' }}</span>
                                <span class="text-xs text-gray-500">{{ appLabel(e.app) }} · {{ categories[e.category] ?? e.category }}</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ fmtDate(e.spent_at) }}<template v-if="e.vendor"> · {{ e.vendor }}</template><template v-if="e.method"> · {{ methodLabel[e.method] ?? e.method }}</template><template v-if="e.reference"> · {{ e.reference }}</template>
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="font-bold text-red-600">{{ formatAmount(e.amount, e.currency) }}</span>
                            <button @click="openEditExpense(e)" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition" title="Editar"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                            <button @click="deleteExpense(e)" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition" title="Eliminar"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Ingresos del mes -->
            <div>
                <h2 class="text-sm font-semibold text-gray-900 mb-3">Ingresos de {{ monthLabel(month) }} ({{ income.length }})</h2>
                <div v-if="!income.length" class="py-8 text-center text-gray-400 text-sm bg-white rounded-xl border border-dashed border-gray-200">
                    Sin pagos registrados este mes. Los pagos se registran en la pestaña Cobros de cada cuenta.
                </div>
                <div v-else class="bg-white rounded-xl border border-gray-200 overflow-hidden divide-y divide-gray-100">
                    <div v-for="p in income" :key="p.id" class="flex items-center justify-between gap-4 px-5 py-3">
                        <div class="min-w-0">
                            <Link :href="route('brain.accounts.show', p.account_id)" class="font-medium text-gray-900 hover:text-amber-700">{{ p.account_name ?? 'Cuenta eliminada' }}</Link>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ fmtDate(p.paid_at) }} · {{ methodLabel[p.method] ?? p.method }}<template v-if="p.reference"> · {{ p.reference }}</template><template v-if="p.invoice_number"> · #{{ p.invoice_number }}</template>
                            </p>
                        </div>
                        <span class="font-bold text-green-600 shrink-0">{{ formatAmount(p.amount, p.currency) }}</span>
                    </div>
                </div>
            </div>

            <!-- Gastos fijos -->
            <div>
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-gray-900">Gastos fijos ({{ recurring.length }})</h2>
                    <button @click="openAddRecurring" class="text-xs text-amber-700 hover:underline">Nuevo gasto fijo</button>
                </div>
                <div v-if="!recurring.length" class="py-8 text-center text-gray-400 text-sm bg-white rounded-xl border border-dashed border-gray-200">
                    Sin gastos fijos. Carga lo que se paga todos los meses (servidor, base de datos, dominio) y se registra solo cuando le llega el día.
                </div>
                <div v-else class="bg-white rounded-xl border border-gray-200 overflow-hidden divide-y divide-gray-100">
                    <div v-for="r in recurring" :key="r.id" class="flex items-center justify-between gap-4 px-5 py-3" :class="r.active ? '' : 'opacity-60'">
                        <div class="min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <span class="font-medium text-gray-900">{{ r.concept }}</span>
                                <span v-if="!r.active" class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-gray-100 text-gray-500">PAUSADO</span>
                                <span class="text-xs text-gray-500">{{ appLabel(r.app) }} · {{ categories[r.category] ?? r.category }}</span>
                            </div>
                            <p class="text-xs text-gray-400 mt-0.5">
                                {{ cycleLabel[r.cycle] }}, el día {{ r.day_of_month }}<template v-if="r.cycle === 'yearly' && r.month_of_year"> de {{ monthNames[r.month_of_year - 1] }}</template>
                                <template v-if="r.vendor"> · {{ r.vendor }}</template>
                                <template v-if="r.cycle === 'yearly'"> · {{ formatAmount(r.monthly, r.currency) }} al mes</template>
                                <template v-if="r.ends_on"> · hasta {{ fmtDate(r.ends_on) }}</template>
                            </p>
                        </div>
                        <div class="flex items-center gap-2 shrink-0">
                            <span class="font-bold text-gray-900">{{ formatAmount(r.amount, r.currency) }}</span>
                            <button @click="toggleRecurring(r)" class="px-2 py-1 text-xs rounded border border-gray-200 text-gray-600 hover:bg-gray-50">{{ r.active ? 'Pausar' : 'Reanudar' }}</button>
                            <button @click="openEditRecurring(r)" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition" title="Editar"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                            <button @click="deleteRecurring(r)" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition" title="Eliminar"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sugerencias de app: Converza, ISPWatch, General y cualquiera que ya se haya usado -->
        <datalist id="finance-apps"><option v-for="a in apps" :key="a" :value="a">{{ appLabel(a) }}</option></datalist>

        <!-- Egreso -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showExpense" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showExpense=false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between rounded-t-2xl"><h2 class="text-lg font-bold text-gray-900">{{ editingExpense ? 'Editar egreso' : 'Registrar egreso' }}</h2><button @click="showExpense=false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button></div>
                    <form @submit.prevent="submitExpense" class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Concepto *</label><input v-model="expenseForm.concept" required placeholder="Droplet DigitalOcean, saldo Meta…" :class="inputClass" /><p v-if="expenseForm.errors.concept" class="text-xs text-red-500 mt-1">{{ expenseForm.errors.concept }}</p></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Tipo *</label><select v-model="expenseForm.kind" :class="inputClass"><option value="variable">Variable</option><option value="fixed">Fijo</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">App *</label><input v-model="expenseForm.app" list="finance-apps" required :class="inputClass" /><p v-if="expenseForm.errors.app" class="text-xs text-red-500 mt-1">{{ expenseForm.errors.app }}</p></div>
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Categoría *</label><select v-model="expenseForm.category" :class="inputClass"><option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Valor *</label><input v-model="expenseForm.amount" type="number" min="0.01" step="0.01" required :class="inputClass" /><p v-if="expenseForm.errors.amount" class="text-xs text-red-500 mt-1">{{ expenseForm.errors.amount }}</p></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Moneda</label><select v-model="expenseForm.currency" :class="inputClass"><option value="USD">USD</option><option value="COP">COP</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Fecha *</label><input v-model="expenseForm.spent_at" type="date" required :class="inputClass" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Medio de pago</label><select v-model="expenseForm.method" :class="inputClass"><option value="">—</option><option value="card">Tarjeta</option><option value="transfer">Transferencia</option><option value="cash">Efectivo</option><option value="other">Otro</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Proveedor</label><input v-model="expenseForm.vendor" placeholder="DigitalOcean, Meta…" :class="inputClass" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Referencia</label><input v-model="expenseForm.reference" placeholder="Nº de factura o recibo" :class="inputClass" /></div>
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Notas</label><textarea v-model="expenseForm.notes" rows="2" :class="inputClass + ' resize-none'"></textarea></div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2"><button type="button" @click="showExpense=false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button><button type="submit" :disabled="expenseForm.processing" class="px-6 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-60 transition">{{ expenseForm.processing ? 'Guardando...' : 'Guardar' }}</button></div>
                    </form>
                </div>
            </div>
        </Transition>

        <!-- Gasto fijo -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showRecurring" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showRecurring=false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between rounded-t-2xl"><h2 class="text-lg font-bold text-gray-900">{{ editingRecurring ? 'Editar gasto fijo' : 'Nuevo gasto fijo' }}</h2><button @click="showRecurring=false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button></div>
                    <form @submit.prevent="submitRecurring" class="p-6 space-y-4">
                        <p class="text-xs text-gray-500">Se registra solo como egreso fijo cada vez que le llega el día. Cambiarlo afecta lo que viene, no lo ya registrado.</p>
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Concepto *</label><input v-model="recurringForm.concept" required placeholder="Droplet DigitalOcean 1 GB" :class="inputClass" /><p v-if="recurringForm.errors.concept" class="text-xs text-red-500 mt-1">{{ recurringForm.errors.concept }}</p></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">App *</label><input v-model="recurringForm.app" list="finance-apps" required :class="inputClass" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Proveedor</label><input v-model="recurringForm.vendor" :class="inputClass" /></div>
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Categoría *</label><select v-model="recurringForm.category" :class="inputClass"><option v-for="(label, key) in categories" :key="key" :value="key">{{ label }}</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Valor *</label><input v-model="recurringForm.amount" type="number" min="0.01" step="0.01" required :class="inputClass" /><p v-if="recurringForm.errors.amount" class="text-xs text-red-500 mt-1">{{ recurringForm.errors.amount }}</p></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Moneda</label><select v-model="recurringForm.currency" :class="inputClass"><option value="USD">USD</option><option value="COP">COP</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Se paga</label><select v-model="recurringForm.cycle" :class="inputClass"><option value="monthly">Cada mes</option><option value="yearly">Cada año</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Día del mes *</label><input v-model="recurringForm.day_of_month" type="number" min="1" max="31" required :class="inputClass" /></div>
                            <div v-if="recurringForm.cycle === 'yearly'" class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Mes *</label><select v-model="recurringForm.month_of_year" :class="inputClass"><option value="">Elegir…</option><option v-for="(n, i) in monthNames" :key="n" :value="i + 1" class="capitalize">{{ n }}</option></select><p v-if="recurringForm.errors.month_of_year" class="text-xs text-red-500 mt-1">{{ recurringForm.errors.month_of_year }}</p></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Desde *</label><input v-model="recurringForm.starts_on" type="date" required :class="inputClass" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Hasta</label><input v-model="recurringForm.ends_on" type="date" :class="inputClass" /><p v-if="recurringForm.errors.ends_on" class="text-xs text-red-500 mt-1">{{ recurringForm.errors.ends_on }}</p></div>
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Notas</label><textarea v-model="recurringForm.notes" rows="2" :class="inputClass + ' resize-none'"></textarea></div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2"><button type="button" @click="showRecurring=false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button><button type="submit" :disabled="recurringForm.processing" class="px-6 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-60 transition">{{ recurringForm.processing ? 'Guardando...' : 'Guardar' }}</button></div>
                    </form>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>
