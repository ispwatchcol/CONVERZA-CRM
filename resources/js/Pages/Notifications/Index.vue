<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    logs:    { type: Object, default: () => ({ data: [], links: [] }) },
    filters: { type: Object, default: () => ({}) },
    stats:   { type: Object, default: () => ({}) },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const statusFilter = ref(props.filters?.status || '');
const kindFilter = ref(props.filters?.kind || '');

const statusChips = [
    { value: '',        label: 'Todos' },
    { value: 'sent',    label: 'Enviados' },
    { value: 'skipped', label: 'Omitidos' },
    { value: 'failed',  label: 'Fallidos' },
];

const kindChips = [
    { value: '',                 label: 'Todo' },
    { value: 'invoice_created',  label: 'Factura' },
    { value: 'payment_reminder', label: 'Recordatorio' },
];

function applyFilters() {
    router.get(route('notifications.index'), {
        search: search.value || undefined,
        status: statusFilter.value || undefined,
        kind:   kindFilter.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function setStatus(v) { statusFilter.value = v; applyFilters(); }
function setKind(v)   { kindFilter.value = v; applyFilters(); }

function clearFilters() {
    search.value = '';
    statusFilter.value = '';
    kindFilter.value = '';
    applyFilters();
}

const hasFilters = computed(() => search.value !== '' || statusFilter.value !== '' || kindFilter.value !== '');

// ── Helpers ──────────────────────────────────────────────────────────────────
function statusBadge(status) {
    return {
        sent:    'bg-emerald-100 text-emerald-700',
        skipped: 'bg-amber-100 text-amber-700',
        failed:  'bg-red-100 text-red-700',
    }[status] || 'bg-gray-100 text-gray-600';
}

function statusLabel(status) {
    return { sent: 'Enviado', skipped: 'Omitido', failed: 'Fallido' }[status] || status;
}

function kindBadge(kind) {
    return {
        invoice_created:  'bg-blue-100 text-blue-700',
        payment_reminder: 'bg-purple-100 text-purple-700',
    }[kind] || 'bg-gray-100 text-gray-600';
}

function kindLabel(kind) {
    return { invoice_created: 'Factura generada', payment_reminder: 'Recordatorio' }[kind] || kind;
}

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}
</script>

<template>
    <Head title="Notificaciones" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Notificaciones automáticas</h1>
                <p class="text-sm text-gray-500 mt-1">
                    Bitácora de los avisos de factura generada y recordatorio de pago enviados según las fechas del router en ISPWatch. Aquí ves por qué un aviso no se envió.
                </p>
            </div>

            <!-- Alerta: avisos fallidos en el ciclo en curso. Esta es la "alarma"
                 del panel: si algo no salió este mes, salta aquí para que el ISP
                 lo revise y reenvíe manual si hace falta. -->
            <div v-if="(stats.failed_this_cycle ?? 0) > 0"
                 class="mb-6 flex items-start gap-3 rounded-2xl border border-red-200 bg-red-50 p-4">
                <svg class="w-5 h-5 text-red-500 flex-shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                <div class="flex-1">
                    <p class="text-sm font-semibold text-red-800">
                        {{ stats.failed_this_cycle }} aviso{{ stats.failed_this_cycle === 1 ? '' : 's' }} no se {{ stats.failed_this_cycle === 1 ? 'envió' : 'enviaron' }} este ciclo ({{ stats.cycle_key }}).
                    </p>
                    <p class="text-xs text-red-600 mt-0.5">
                        El sistema reintenta automáticamente durante los días de catch-up. Si persiste, revisa el motivo y reenvía manualmente.
                    </p>
                </div>
                <button @click="setStatus('failed')"
                        class="flex-shrink-0 text-xs font-semibold text-red-700 hover:text-red-900 underline underline-offset-2">
                    Ver fallidos
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total ?? 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Enviados</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ stats.sent ?? 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Omitidos</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ stats.skipped ?? 0 }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Fallidos</p>
                    <p class="text-2xl font-bold text-red-600 mt-1">{{ stats.failed ?? 0 }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row gap-3 md:items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar por cliente, teléfono o factura…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                        <button v-for="opt in kindChips" :key="opt.value" @click="setKind(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                :class="kindFilter === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            {{ opt.label }}
                        </button>
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

            <!-- Tabla -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="logs.data?.length" class="w-full">
                        <thead>
                            <tr class="text-left text-[11px] uppercase tracking-wider text-gray-400 border-b border-gray-100">
                                <th class="px-4 py-3 font-semibold">Fecha</th>
                                <th class="px-4 py-3 font-semibold">Tipo</th>
                                <th class="px-4 py-3 font-semibold">Cliente</th>
                                <th class="px-4 py-3 font-semibold">Factura</th>
                                <th class="px-4 py-3 font-semibold">Estado</th>
                                <th class="px-4 py-3 font-semibold">Motivo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="l in logs.data" :key="l.id" class="hover:bg-gray-50 transition">
                                <td class="px-4 py-3 whitespace-nowrap text-xs text-gray-500">
                                    {{ formatDateTime(l.sent_at || l.created_at) }}
                                    <span class="block text-[10px] text-gray-400">ciclo {{ l.cycle_key }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide" :class="kindBadge(l.kind)">
                                        {{ kindLabel(l.kind) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3">
                                    <p class="text-sm font-medium text-gray-900">{{ l.customer_name || 'Cliente' }}</p>
                                    <p class="text-[11px] text-gray-400">{{ l.phone || '—' }}<span v-if="l.router_name"> · {{ l.router_name }}</span></p>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-600 font-mono">{{ l.invoice_number || '—' }}</td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide" :class="statusBadge(l.status)">
                                        {{ statusLabel(l.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-xs text-gray-500 max-w-xs">
                                    <span v-if="l.reason">{{ l.reason }}</span>
                                    <span v-else-if="l.status === 'sent'" class="text-emerald-600">Enviado correctamente</span>
                                    <span v-else>—</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <!-- Empty -->
                    <div v-else class="text-center py-16 px-4">
                        <svg class="w-12 h-12 text-gray-300 mx-auto mb-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
                        <p class="text-sm text-gray-500 font-medium">Aún no hay notificaciones registradas.</p>
                        <p class="text-xs text-gray-400 mt-1">Los avisos aparecerán aquí cuando el sistema corra según las fechas del router.</p>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="logs.data?.length && logs.last_page > 1" class="border-t border-gray-100 px-4 py-3 flex items-center justify-between">
                    <p class="text-xs text-gray-500">{{ logs.from }}–{{ logs.to }} de {{ logs.total }}</p>
                    <div class="flex gap-1">
                        <Link v-for="link in logs.links" :key="link.label" :href="link.url || '#'"
                              v-html="link.label"
                              class="px-3 py-1 rounded-lg text-xs border transition"
                              :class="link.active
                                  ? 'bg-accent text-white border-accent'
                                  : link.url
                                      ? 'border-gray-200 text-gray-700 hover:bg-gray-50'
                                      : 'border-gray-100 text-gray-300 pointer-events-none'" />
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
