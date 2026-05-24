<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    contacts: Object,
    labels: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
    hasIspwatch: { type: Boolean, default: false },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const labelId = ref(props.filters?.label || '');
const ispwatchFilter = ref(props.filters?.ispwatch || 'all');

function applyFilters() {
    router.get(route('contacts.index'), {
        search:   search.value || undefined,
        label:    labelId.value || undefined,
        ispwatch: ispwatchFilter.value !== 'all' ? ispwatchFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function setIspwatch(v) {
    ispwatchFilter.value = v;
    applyFilters();
}

function clearFilters() {
    search.value = '';
    labelId.value = '';
    ispwatchFilter.value = 'all';
    applyFilters();
}

const hasFilters = computed(() =>
    search.value !== '' || labelId.value !== '' || ispwatchFilter.value !== 'all'
);

const ispwatchChips = [
    { value: 'all',       label: 'Todos' },
    { value: 'customers', label: 'Clientes ISP' },
    { value: 'prospects', label: 'Prospectos' },
];

// ── Modal create / edit ──────────────────────────────────────────────────────
const showModal = ref(false);
const editingContact = ref(null);
const form = useForm({ phone: '', name: '', email: '', notes: '', labels: [] });

function openCreate() {
    editingContact.value = null;
    form.reset();
    showModal.value = true;
}

function openEdit(contact) {
    editingContact.value = contact;
    form.phone  = contact.phone;
    form.name   = contact.name || '';
    form.email  = contact.email || '';
    form.notes  = contact.notes || '';
    form.labels = contact.labels?.map(l => l.id) || [];
    showModal.value = true;
    showDetail.value = false;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; form.reset(); } };
    if (editingContact.value) {
        form.put(route('contacts.update', editingContact.value.id), opts);
    } else {
        form.post(route('contacts.store'), opts);
    }
}

function deleteContact(contact, fromDetail = false) {
    if (!confirm(`¿Eliminar el contacto "${contact.name || contact.phone}"? Esto borra solo el contacto, no las conversaciones.`)) return;
    router.delete(route('contacts.destroy', contact.id), {
        preserveScroll: true,
        onSuccess: () => { if (fromDetail) showDetail.value = false; },
    });
}

// ── Drawer / modal de detalle ────────────────────────────────────────────────
const showDetail = ref(false);
const selectedContact = ref(null);

function openDetail(contact) {
    selectedContact.value = contact;
    showDetail.value = true;
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function formatPhone(phone) {
    if (!phone) return '';
    if (phone.startsWith('57') && phone.length === 12) {
        const n = phone.substring(2);
        return `+57 ${n.substring(0, 3)} ${n.substring(3, 6)} ${n.substring(6)}`;
    }
    return phone;
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(iso));
}

function formatRelative(iso) {
    if (!iso) return '';
    const diffMin = (Date.now() - new Date(iso).getTime()) / 60000;
    if (diffMin < 1) return 'ahora';
    if (diffMin < 60) return `hace ${Math.floor(diffMin)} min`;
    if (diffMin < 1440) return `hace ${Math.floor(diffMin / 60)} h`;
    return `hace ${Math.floor(diffMin / 1440)} d`;
}

function formatMoney(amount) {
    if (amount == null) return '—';
    const n = Number(amount);
    if (!Number.isFinite(n)) return amount;
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(n);
}

function serviceBadge(status) {
    const s = (status || '').toLowerCase();
    if (['activo', 'active'].includes(s))         return 'bg-emerald-100 text-emerald-700';
    if (['suspendido', 'suspended'].includes(s))  return 'bg-red-100 text-red-700';
    if (['pendiente', 'pending'].includes(s))     return 'bg-amber-100 text-amber-700';
    return 'bg-gray-100 text-gray-600';
}
</script>

<template>
    <Head title="Contactos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Contactos</h1>
                    <p class="text-sm text-gray-500 mt-1">Personas que han escrito o que vas a contactar por WhatsApp</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Nuevo contacto
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                    <p class="text-[10px] text-emerald-600 mt-0.5">+{{ stats.this_month }} este mes</p>
                </div>
                <div v-if="hasIspwatch" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Clientes ISP</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ stats.customers }}</p>
                </div>
                <div v-if="hasIspwatch" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Prospectos</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ stats.prospects }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Con etiquetas</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.with_labels }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex flex-col lg:flex-row gap-3 lg:items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar por nombre, teléfono o email…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <select v-if="labels.length" v-model="labelId" @change="applyFilters" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <option value="">Todas las etiquetas</option>
                        <option v-for="l in labels" :key="l.id" :value="l.id">{{ l.name }}</option>
                    </select>

                    <div v-if="hasIspwatch" class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                        <button v-for="opt in ispwatchChips" :key="opt.value" @click="setIspwatch(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition whitespace-nowrap"
                                :class="ispwatchFilter === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
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
                    <table v-if="contacts.data?.length" class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Contacto</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Etiquetas</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Conversaciones</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Actividad</th>
                                <th class="px-4 py-3 text-right text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="c in contacts.data" :key="c.id" @click="openDetail(c)" class="hover:bg-gray-50 transition cursor-pointer">
                                <td class="px-4 py-3">
                                    <div class="flex items-center">
                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold shrink-0">
                                            {{ (c.name || c.phone || '?')[0].toUpperCase() }}
                                        </div>
                                        <div class="ml-3 min-w-0">
                                            <div class="flex items-center gap-2">
                                                <p class="text-sm font-medium text-gray-900 truncate">{{ c.name || 'Sin nombre' }}</p>
                                                <span v-if="hasIspwatch && c.ispwatch_customer" class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide bg-emerald-100 text-emerald-700 shrink-0">ISP</span>
                                                <span v-else-if="hasIspwatch" class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide bg-amber-50 text-amber-700 shrink-0">Prospecto</span>
                                            </div>
                                            <p class="text-xs text-gray-500 truncate">{{ formatPhone(c.phone) }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 hidden md:table-cell">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="l in c.labels" :key="l.id" class="px-2 py-0.5 rounded-full text-[10px] font-medium text-white" :style="{ backgroundColor: l.color }">
                                            {{ l.name }}
                                        </span>
                                        <span v-if="!c.labels.length" class="text-[10px] text-gray-300">—</span>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-600 hidden lg:table-cell whitespace-nowrap">
                                    <span class="font-semibold text-gray-900">{{ c.conversations_count }}</span>
                                    <span class="text-gray-400 text-xs"> · {{ c.messages_count }} msg</span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 hidden lg:table-cell whitespace-nowrap">{{ formatRelative(c.updated_at) }}</td>
                                <td class="px-4 py-3 text-right whitespace-nowrap" @click.stop>
                                    <div class="flex items-center justify-end gap-1">
                                        <Link :href="route('contacts.chat', c.id)" :title="`Ir al chat con ${c.name || c.phone}`"
                                              class="p-2 rounded-lg text-accent hover:bg-accent/10 transition">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                                        </Link>
                                        <button @click="openEdit(c)" class="p-2 rounded-lg text-blue-500 hover:bg-blue-50 transition" title="Editar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        </button>
                                        <button @click="deleteContact(c)" class="p-2 rounded-lg text-red-500 hover:bg-red-50 transition" title="Eliminar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-12 text-center text-gray-400">
                        <svg class="w-14 h-14 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                        <p class="text-sm">{{ hasFilters ? 'Ningún contacto coincide con los filtros' : 'No hay contactos registrados' }}</p>
                        <button v-if="!hasFilters" @click="openCreate" class="mt-3 text-xs text-accent hover:underline">+ Crear el primero</button>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="contacts.data?.length && contacts.last_page > 1" class="border-t border-gray-100 px-4 py-3 flex items-center justify-between">
                    <p class="text-xs text-gray-500">{{ contacts.from }}–{{ contacts.to }} de {{ contacts.total }}</p>
                    <div class="flex gap-1">
                        <Link v-for="link in contacts.links" :key="link.label" :href="link.url || '#'"
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

        <!-- ═══════════════ Modal: Detalle ═══════════════ -->
        <Teleport to="body">
            <div v-if="showDetail && selectedContact" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showDetail = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-scale-in">
                    <div class="p-6">
                        <!-- Header del contacto -->
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-14 h-14 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white font-bold text-lg shrink-0">
                                {{ (selectedContact.name || selectedContact.phone || '?')[0].toUpperCase() }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-semibold text-gray-900 truncate">{{ selectedContact.name || 'Sin nombre' }}</h3>
                                <p class="text-sm text-gray-500 font-mono">{{ formatPhone(selectedContact.phone) }}</p>
                                <div class="mt-1.5 flex flex-wrap items-center gap-1">
                                    <span v-if="hasIspwatch && selectedContact.ispwatch_customer" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-emerald-100 text-emerald-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span>
                                        Cliente ISPWatch
                                    </span>
                                    <span v-else-if="hasIspwatch" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-amber-100 text-amber-700">
                                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                                        Prospecto
                                    </span>
                                </div>
                            </div>
                            <button @click="showDetail = false" class="p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <!-- ISPWatch info -->
                        <div v-if="selectedContact.ispwatch_customer" class="bg-blue-50 border border-blue-100 rounded-xl p-3 mb-4">
                            <p class="text-[10px] text-blue-700 uppercase tracking-wider font-semibold mb-2">Datos en ISPWatch</p>
                            <div class="space-y-1.5 text-xs">
                                <div class="flex justify-between"><span class="text-gray-600">Nombre completo</span><span class="font-medium text-gray-900">{{ selectedContact.ispwatch_customer.name }}</span></div>
                                <div class="flex justify-between items-center">
                                    <span class="text-gray-600">Servicio</span>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold capitalize" :class="serviceBadge(selectedContact.ispwatch_customer.service_status)">
                                        {{ selectedContact.ispwatch_customer.service_status || '—' }}
                                    </span>
                                </div>
                                <div v-if="selectedContact.ispwatch_customer.pppoe_username" class="flex justify-between"><span class="text-gray-600">PPPoE</span><span class="font-mono text-gray-900">{{ selectedContact.ispwatch_customer.pppoe_username }}</span></div>
                                <div v-if="selectedContact.ispwatch_customer.ip_user" class="flex justify-between"><span class="text-gray-600">IP</span><span class="font-mono text-gray-900">{{ selectedContact.ispwatch_customer.ip_user }}</span></div>
                                <div class="flex justify-between"><span class="text-gray-600">Saldo</span><span class="font-semibold" :class="Number(selectedContact.ispwatch_customer.credit_balance) > 0 ? 'text-emerald-600' : 'text-gray-900'">{{ formatMoney(selectedContact.ispwatch_customer.credit_balance) }}</span></div>
                            </div>
                        </div>

                        <!-- Email / Notas -->
                        <div v-if="selectedContact.email || selectedContact.notes" class="bg-gray-50 rounded-xl p-3 mb-4 space-y-2 text-sm">
                            <div v-if="selectedContact.email"><span class="text-gray-500 text-xs">Email: </span><span class="text-gray-900">{{ selectedContact.email }}</span></div>
                            <div v-if="selectedContact.notes"><p class="text-gray-500 text-xs mb-1">Notas:</p><p class="text-gray-800 whitespace-pre-wrap text-xs">{{ selectedContact.notes }}</p></div>
                        </div>

                        <!-- Etiquetas -->
                        <div v-if="selectedContact.labels.length" class="mb-4">
                            <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-1.5">Etiquetas</p>
                            <div class="flex flex-wrap gap-1.5">
                                <span v-for="l in selectedContact.labels" :key="l.id" class="px-2 py-0.5 rounded-full text-[10px] font-medium text-white" :style="{ backgroundColor: l.color }">
                                    {{ l.name }}
                                </span>
                            </div>
                        </div>

                        <!-- Actividad -->
                        <div class="grid grid-cols-3 gap-3 mb-5 text-center bg-gray-50 rounded-xl p-3">
                            <div>
                                <p class="text-2xl font-bold text-gray-900">{{ selectedContact.conversations_count }}</p>
                                <p class="text-[10px] text-gray-500 uppercase tracking-wider">Conversaciones</p>
                            </div>
                            <div class="border-x border-gray-200">
                                <p class="text-2xl font-bold text-gray-900">{{ selectedContact.messages_count }}</p>
                                <p class="text-[10px] text-gray-500 uppercase tracking-wider">Mensajes</p>
                            </div>
                            <div>
                                <p class="text-sm font-bold text-gray-900 mt-1">{{ formatDate(selectedContact.created_at) }}</p>
                                <p class="text-[10px] text-gray-500 uppercase tracking-wider">Registrado</p>
                            </div>
                        </div>

                        <!-- Acciones -->
                        <div class="flex flex-col sm:flex-row gap-2">
                            <Link :href="route('contacts.chat', selectedContact.id)"
                                  class="flex-1 px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition text-center">
                                Ir al chat
                            </Link>
                            <button @click="openEdit(selectedContact)" class="px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">
                                Editar
                            </button>
                            <button @click="deleteContact(selectedContact, true)" class="px-4 py-2.5 border border-red-200 text-red-700 rounded-xl text-sm font-medium hover:bg-red-50 transition">
                                Eliminar
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
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editingContact ? 'Editar contacto' : 'Nuevo contacto' }}</h3>
                        <form @submit.prevent="save" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono <span class="text-red-500">*</span></label>
                                <input v-model="form.phone" type="text" placeholder="3001234567 o 573001234567" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent font-mono" :class="{ 'border-red-400': form.errors.phone }">
                                <p v-if="form.errors.phone" class="text-red-500 text-xs mt-1">{{ form.errors.phone }}</p>
                                <p v-else class="text-[10px] text-gray-400 mt-1">Se normaliza automáticamente: 10 dígitos colombianos → se les agrega +57.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                                <input v-model="form.name" type="text" placeholder="Juan Pérez" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input v-model="form.email" type="email" placeholder="correo@ejemplo.com" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': form.errors.email }">
                                <p v-if="form.errors.email" class="text-red-500 text-xs mt-1">{{ form.errors.email }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                                <textarea v-model="form.notes" rows="3" placeholder="Información adicional…" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea>
                            </div>

                            <div v-if="labels.length">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Etiquetas</label>
                                <div class="flex flex-wrap gap-2">
                                    <label v-for="l in labels" :key="l.id" class="flex items-center px-3 py-1.5 rounded-lg text-xs font-medium cursor-pointer transition"
                                           :class="form.labels.includes(l.id) ? 'text-white' : 'text-gray-600'"
                                           :style="{ backgroundColor: form.labels.includes(l.id) ? l.color : l.color + '20' }">
                                        <input type="checkbox" :value="l.id" v-model="form.labels" class="sr-only">
                                        {{ l.name }}
                                    </label>
                                </div>
                            </div>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="form.processing" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ form.processing ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
