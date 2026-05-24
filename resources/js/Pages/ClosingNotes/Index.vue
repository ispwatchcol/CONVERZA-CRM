<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { router, useForm, Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    notes: Object,
    filters: Object,
    agents: { type: Array, default: () => [] },
    openConversations: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
    currentUserId: { type: Number, default: null },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const agentId = ref(props.filters?.agent_id || '');
const range = ref(props.filters?.range || 'all');

function applyFilters() {
    router.get(route('closing-notes.index'), {
        search: search.value || undefined,
        agent_id: agentId.value || undefined,
        range: range.value !== 'all' ? range.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function setRange(value) {
    range.value = value;
    applyFilters();
}

function clearFilters() {
    search.value = '';
    agentId.value = '';
    range.value = 'all';
    applyFilters();
}

const rangeButtons = [
    { value: 'all', label: 'Todas' },
    { value: '7',   label: '7 días' },
    { value: '30',  label: '30 días' },
    { value: '90',  label: '90 días' },
];

const hasActiveFilters = computed(() =>
    search.value !== '' || agentId.value !== '' || range.value !== 'all'
);

// ── Modal: Nueva nota ────────────────────────────────────────────────────────
const showNewModal = ref(false);
const newForm = useForm({
    conversation_id: '',
    note: '',
    close_conversation: true,
});

const templates = [
    'Cliente atendido, solicitud resuelta.',
    'Sin respuesta del cliente. Cerrado por inactividad.',
    'Problema técnico escalado al equipo correspondiente.',
    'Información proporcionada exitosamente.',
    'Cliente confirmó pago de factura pendiente.',
];

function openNewModal() {
    newForm.reset();
    newForm.close_conversation = true;
    showNewModal.value = true;
}

function applyTemplate(text) {
    newForm.note = newForm.note ? (newForm.note + ' ' + text).trim() : text;
}

function saveNew() {
    newForm.post(route('closing-notes.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showNewModal.value = false;
            newForm.reset();
        },
    });
}

// ── Modal: Detalle / edición ─────────────────────────────────────────────────
const showDetailModal = ref(false);
const selectedNote = ref(null);
const editingNote = ref(false);

const editForm = useForm({ note: '' });

function openDetail(note) {
    selectedNote.value = note;
    editingNote.value = false;
    editForm.note = note.note;
    showDetailModal.value = true;
}

function closeDetail() {
    showDetailModal.value = false;
    editingNote.value = false;
    selectedNote.value = null;
}

function saveEdit() {
    editForm.put(route('closing-notes.update', selectedNote.value.id), {
        preserveScroll: true,
        onSuccess: () => {
            editingNote.value = false;
            selectedNote.value = { ...selectedNote.value, note: editForm.note, was_edited: true };
        },
    });
}

function deleteNote() {
    if (!confirm('¿Eliminar esta nota? La conversación NO se reabrirá automáticamente.')) return;
    router.delete(route('closing-notes.destroy', selectedNote.value.id), {
        preserveScroll: true,
        onSuccess: () => closeDetail(),
    });
}

function reopenConversation() {
    if (!confirm('¿Reabrir la conversación? La nota queda como histórico.')) return;
    router.post(route('closing-notes.reopen', selectedNote.value.id), {}, {
        preserveScroll: true,
        onSuccess: () => closeDetail(),
    });
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function formatDateTime(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return new Intl.DateTimeFormat('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(d);
}

function formatRelative(iso) {
    if (!iso) return '';
    const d = new Date(iso);
    const diffMin = (Date.now() - d.getTime()) / 60000;
    if (diffMin < 1) return 'hace unos segundos';
    if (diffMin < 60) return `hace ${Math.floor(diffMin)} min`;
    if (diffMin < 1440) return `hace ${Math.floor(diffMin / 60)} h`;
    return `hace ${Math.floor(diffMin / 1440)} d`;
}

function formatPhone(phone) {
    if (!phone) return '';
    if (phone.startsWith('57') && phone.length === 12) return phone.substring(2);
    return phone;
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
    <Head title="Notas de Cierre" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Notas de Cierre</h1>
                    <p class="text-sm text-gray-500 mt-1">Histórico de notas al cerrar conversaciones</p>
                </div>
                <button @click="openNewModal" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Nueva nota
                </button>
            </div>

            <!-- Stats grid -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Este mes</p>
                    <p class="text-2xl font-bold text-accent mt-1">{{ stats.this_month }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Mis notas</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.my_notes }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Reabiertas</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ stats.reopened }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">conversaciones</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row gap-3 md:items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar en notas…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <select v-model="agentId" @change="applyFilters" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <option value="">Todos los agentes</option>
                        <option v-for="agent in agents" :key="agent.id" :value="agent.id">{{ agent.name }}</option>
                    </select>

                    <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                        <button v-for="opt in rangeButtons" :key="opt.value" @click="setRange(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                :class="range === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            {{ opt.label }}
                        </button>
                    </div>

                    <button v-if="hasActiveFilters" @click="clearFilters" class="text-xs text-gray-500 hover:text-gray-700 px-2 transition">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- Tabla -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="notes.data?.length" class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Contacto</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Nota</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Agente</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Estado</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Cuándo</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="note in notes.data" :key="note.id" @click="openDetail(note)"
                                class="hover:bg-gray-50 transition cursor-pointer">
                                <td class="px-4 py-3">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold shrink-0">
                                            {{ (note.contact_name || '?')[0].toUpperCase() }}
                                        </div>
                                        <div class="ml-2.5 min-w-0">
                                            <p class="text-sm font-medium text-gray-900 truncate">{{ note.contact_name }}</p>
                                            <p v-if="note.ispwatch_customer" class="text-[10px] text-emerald-600 truncate">
                                                ISPWatch: {{ note.ispwatch_customer.name }}
                                            </p>
                                            <p v-else class="text-[10px] text-gray-400 truncate">{{ formatPhone(note.contact_phone) }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 max-w-md">
                                    <p class="text-sm text-gray-700 line-clamp-2">{{ note.note }}</p>
                                    <p v-if="note.was_edited" class="text-[10px] text-gray-400 italic mt-0.5">editada</p>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 hidden md:table-cell">
                                    <span :class="{ 'font-semibold text-gray-900': note.user_id === currentUserId }">{{ note.user_name }}</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium"
                                          :class="note.conversation_status === 'open' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600'">
                                        {{ note.conversation_status === 'open' ? 'Reabierta' : 'Cerrada' }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 whitespace-nowrap">
                                    <p>{{ formatRelative(note.created_at) }}</p>
                                    <p class="text-[10px] text-gray-400">{{ formatDateTime(note.created_at) }}</p>
                                </td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-else class="p-12 text-center text-gray-400">
                        <svg class="w-14 h-14 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <p class="text-sm">{{ hasActiveFilters ? 'Ninguna nota coincide con los filtros' : 'Aún no hay notas de cierre' }}</p>
                        <button v-if="!hasActiveFilters" @click="openNewModal" class="mt-3 text-xs text-accent hover:underline">+ Crear la primera</button>
                    </div>
                </div>

                <!-- Pagination -->
                <div v-if="notes.data?.length && notes.last_page > 1" class="border-t border-gray-100 px-4 py-3 flex items-center justify-between">
                    <p class="text-xs text-gray-500">{{ notes.from }}–{{ notes.to }} de {{ notes.total }}</p>
                    <div class="flex gap-1">
                        <Link v-for="link in notes.links" :key="link.label" :href="link.url || '#'"
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

        <!-- ═══════════════ Modal: Nueva nota ═══════════════ -->
        <Teleport to="body">
            <div v-if="showNewModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showNewModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">Nueva nota de cierre</h3>

                        <form @submit.prevent="saveNew" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Conversación</label>
                                <select v-model="newForm.conversation_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': newForm.errors.conversation_id }">
                                    <option value="">— Selecciona una conversación abierta —</option>
                                    <option v-for="conv in openConversations" :key="conv.id" :value="conv.id">
                                        {{ conv.contact_name }} ({{ formatPhone(conv.contact_phone) }})
                                    </option>
                                </select>
                                <p v-if="newForm.errors.conversation_id" class="text-red-500 text-xs mt-1">{{ newForm.errors.conversation_id }}</p>
                                <p v-else-if="!openConversations.length" class="text-amber-600 text-xs mt-1">No hay conversaciones abiertas para cerrar.</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nota</label>
                                <textarea v-model="newForm.note" rows="4" placeholder="¿Cómo se resolvió? ¿Qué quedó pendiente?"
                                          class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"
                                          :class="{ 'border-red-400': newForm.errors.note }"></textarea>
                                <p v-if="newForm.errors.note" class="text-red-500 text-xs mt-1">{{ newForm.errors.note }}</p>
                            </div>

                            <div>
                                <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-1.5">Plantillas rápidas</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <button v-for="(tpl, i) in templates" :key="i" type="button" @click="applyTemplate(tpl)"
                                            class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 rounded-lg text-[11px] text-gray-700 transition">
                                        {{ tpl.slice(0, 40) }}{{ tpl.length > 40 ? '…' : '' }}
                                    </button>
                                </div>
                            </div>

                            <label class="flex items-center cursor-pointer pt-1">
                                <input v-model="newForm.close_conversation" type="checkbox" class="rounded border-gray-300 text-accent focus:ring-accent/30">
                                <span class="ml-2 text-sm text-gray-700">Cerrar la conversación al guardar</span>
                            </label>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showNewModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="newForm.processing || !newForm.conversation_id" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ newForm.processing ? 'Guardando…' : 'Guardar nota' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══════════════ Modal: Detalle ═══════════════ -->
        <Teleport to="body">
            <div v-if="showDetailModal && selectedNote" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="closeDetail"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-scale-in">
                    <div class="p-6">
                        <!-- Header del contacto -->
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white font-bold shrink-0">
                                {{ (selectedNote.contact_name || '?')[0].toUpperCase() }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-base font-semibold text-gray-900 truncate">{{ selectedNote.contact_name }}</h3>
                                <p class="text-xs text-gray-500">{{ formatPhone(selectedNote.contact_phone) }}</p>
                                <span class="inline-block mt-1 px-2 py-0.5 rounded-full text-[10px] font-medium"
                                      :class="selectedNote.conversation_status === 'open' ? 'bg-amber-100 text-amber-700' : 'bg-gray-100 text-gray-600'">
                                    {{ selectedNote.conversation_status === 'open' ? 'Conversación reabierta' : 'Conversación cerrada' }}
                                </span>
                            </div>
                            <button @click="closeDetail" class="p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <!-- Contexto ISPWatch -->
                        <div v-if="selectedNote.ispwatch_customer" class="bg-blue-50 border border-blue-100 rounded-xl p-3 mb-4">
                            <p class="text-[10px] text-blue-700 uppercase tracking-wider font-semibold mb-2">Cliente en ISPWatch</p>
                            <div class="flex items-center justify-between text-sm">
                                <span class="text-gray-900 font-medium">{{ selectedNote.ispwatch_customer.name }}</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold capitalize" :class="serviceBadge(selectedNote.ispwatch_customer.service_status)">
                                    {{ selectedNote.ispwatch_customer.service_status || '—' }}
                                </span>
                            </div>
                            <p v-if="selectedNote.ispwatch_customer.pppoe_username" class="text-[11px] text-gray-600 font-mono mt-1">PPPoE: {{ selectedNote.ispwatch_customer.pppoe_username }}</p>
                        </div>

                        <!-- Nota -->
                        <div class="mb-4">
                            <div class="flex items-center justify-between mb-2">
                                <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold">Nota</p>
                                <button v-if="!editingNote" @click="editingNote = true" class="text-xs text-accent hover:underline">Editar</button>
                            </div>
                            <div v-if="!editingNote" class="bg-gray-50 rounded-xl p-3 text-sm text-gray-800 whitespace-pre-wrap">{{ selectedNote.note }}</div>
                            <div v-else>
                                <textarea v-model="editForm.note" rows="5" class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none" :class="{ 'border-red-400': editForm.errors.note }"></textarea>
                                <p v-if="editForm.errors.note" class="text-red-500 text-xs mt-1">{{ editForm.errors.note }}</p>
                                <div class="flex justify-end gap-2 mt-2">
                                    <button @click="editingNote = false; editForm.note = selectedNote.note" class="px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-100 rounded-lg transition">Cancelar</button>
                                    <button @click="saveEdit" :disabled="editForm.processing" class="px-3 py-1.5 bg-accent text-white text-xs rounded-lg hover:bg-accent-hover transition disabled:opacity-50">
                                        {{ editForm.processing ? 'Guardando…' : 'Guardar' }}
                                    </button>
                                </div>
                            </div>
                        </div>

                        <!-- Metadata -->
                        <div class="text-xs text-gray-500 space-y-1 mb-4 pb-4 border-b border-gray-100">
                            <p><span class="text-gray-400">Agente:</span> <span class="font-medium text-gray-700">{{ selectedNote.user_name }}</span></p>
                            <p><span class="text-gray-400">Creada:</span> {{ formatDateTime(selectedNote.created_at) }}</p>
                            <p v-if="selectedNote.was_edited"><span class="text-gray-400">Editada:</span> {{ formatDateTime(selectedNote.updated_at) }}</p>
                        </div>

                        <!-- Acciones -->
                        <div class="flex flex-col sm:flex-row gap-2">
                            <button v-if="selectedNote.conversation_status !== 'open'" @click="reopenConversation"
                                    class="flex-1 px-4 py-2.5 border border-amber-200 bg-amber-50 text-amber-800 rounded-xl text-sm font-medium hover:bg-amber-100 transition">
                                Reabrir conversación
                            </button>
                            <button @click="deleteNote" class="px-4 py-2.5 border border-red-200 text-red-700 rounded-xl text-sm font-medium hover:bg-red-50 transition">
                                Eliminar nota
                            </button>
                            <Link v-if="selectedNote.conversation_id" :href="route('chat.index', { conversation: selectedNote.conversation_id })"
                                  class="px-4 py-2.5 bg-gray-100 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-200 transition text-center">
                                Ver chat
                            </Link>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
