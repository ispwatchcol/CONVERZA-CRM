<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    quickReplies: { type: Array, default: () => [] },
    categories:   { type: Array, default: () => [] },
    filters:      { type: Object, default: () => ({}) },
    stats:        { type: Object, default: () => ({}) },
    bodyMax:      { type: Number, default: 4096 },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const category = ref(props.filters?.category || '');
const status = ref(props.filters?.status || 'all');

function applyFilters() {
    router.get(route('quick-replies.index'), {
        search: search.value || undefined,
        category: category.value || undefined,
        status: status.value !== 'all' ? status.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function setStatus(v) {
    status.value = v;
    applyFilters();
}

function clearFilters() {
    search.value = '';
    category.value = '';
    status.value = 'all';
    applyFilters();
}

const hasFilters = computed(() => search.value !== '' || category.value !== '' || status.value !== 'all');

const statusChips = [
    { value: 'all',      label: 'Todas' },
    { value: 'active',   label: 'Activas' },
    { value: 'inactive', label: 'Inactivas' },
];

// ── Modal create / edit ──────────────────────────────────────────────────────
const showModal = ref(false);
const editing = ref(null);
const form = useForm({ title: '', body: '', shortcut: '', category: '', is_active: true });

const bodyChars = computed(() => (form.body || '').length);
const bodyOverflow = computed(() => bodyChars.value > props.bodyMax);

function openCreate() {
    editing.value = null;
    form.reset();
    form.is_active = true;
    showModal.value = true;
}

function openEdit(qr) {
    editing.value = qr;
    form.title = qr.title;
    form.body = qr.body;
    form.shortcut = qr.shortcut || '';
    form.category = qr.category || '';
    form.is_active = qr.is_active;
    showModal.value = true;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; form.reset(); } };
    if (editing.value) {
        form.put(route('quick-replies.update', editing.value.id), opts);
    } else {
        form.post(route('quick-replies.store'), opts);
    }
}

// ── Acciones inline ──────────────────────────────────────────────────────────
function toggleQR(qr) {
    router.patch(route('quick-replies.toggle', qr.id), {}, { preserveScroll: true });
}

function duplicateQR(qr) {
    router.post(route('quick-replies.duplicate', qr.id), {}, { preserveScroll: true });
}

function deleteQR(qr) {
    if (!confirm(`¿Eliminar la respuesta "${qr.title}"? Esto no se puede deshacer.`)) return;
    router.delete(route('quick-replies.destroy', qr.id), { preserveScroll: true });
}

function loadSamples() {
    if (!confirm('Se van a crear 5 respuestas rápidas de ejemplo para arrancar. ¿Continuar?')) return;
    router.post(route('quick-replies.load-samples'), {}, { preserveScroll: true });
}

// ── Copiar al portapapeles ───────────────────────────────────────────────────
const copiedId = ref(null);
function copyBody(qr) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(qr.body).then(() => {
        copiedId.value = qr.id;
        setTimeout(() => { if (copiedId.value === qr.id) copiedId.value = null; }, 1500);
    });
}

// ── Helpers ──────────────────────────────────────────────────────────────────
const categoryColors = {
    'Generales':   'bg-gray-100 text-gray-700',
    'Soporte':     'bg-blue-100 text-blue-700',
    'Ventas':      'bg-emerald-100 text-emerald-700',
    'Facturación': 'bg-amber-100 text-amber-700',
};

function categoryClass(cat) {
    return categoryColors[cat] || 'bg-purple-100 text-purple-700';
}
</script>

<template>
    <Head title="Respuestas Rápidas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Respuestas Rápidas</h1>
                    <p class="text-sm text-gray-500 mt-1">Mensajes predefinidos disponibles desde el chat</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Nueva respuesta
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Activas</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ stats.active }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Inactivas</p>
                    <p class="text-2xl font-bold text-gray-400 mt-1">{{ stats.inactive }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Categorías</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.categories }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row gap-3 md:items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar por título, atajo o contenido…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <select v-model="category" @change="applyFilters" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <option value="">Todas las categorías</option>
                        <option v-for="cat in categories" :key="cat" :value="cat">{{ cat }}</option>
                    </select>

                    <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                        <button v-for="opt in statusChips" :key="opt.value" @click="setStatus(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                :class="status === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            {{ opt.label }}
                        </button>
                    </div>

                    <button v-if="hasFilters" @click="clearFilters" class="text-xs text-gray-500 hover:text-gray-700 px-2 transition">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- Grid de cards -->
            <div v-if="quickReplies.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="qr in quickReplies" :key="qr.id"
                     class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-all group relative flex flex-col"
                     :class="{ 'opacity-60': !qr.is_active }">
                    <!-- Top row: shortcut + actions -->
                    <div class="flex items-start justify-between mb-3 gap-2">
                        <div class="flex items-center gap-2 flex-wrap min-h-[24px]">
                            <span v-if="qr.shortcut" class="px-2 py-0.5 rounded bg-gray-900 text-white text-[10px] font-mono">/{{ qr.shortcut }}</span>
                            <span v-if="qr.category" class="px-2 py-0.5 rounded-full text-[10px] font-medium" :class="categoryClass(qr.category)">{{ qr.category }}</span>
                        </div>
                        <div class="flex space-x-0.5 opacity-0 group-hover:opacity-100 transition shrink-0">
                            <button @click="copyBody(qr)" :title="copiedId === qr.id ? 'Copiado!' : 'Copiar contenido'" class="p-1.5 rounded-lg transition" :class="copiedId === qr.id ? 'text-emerald-600 bg-emerald-50' : 'text-gray-500 hover:bg-gray-50'">
                                <svg v-if="copiedId !== qr.id" class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h3.75M9 15h3.75M9 18h3.75m3 .75H18a2.25 2.25 0 002.25-2.25V6.108c0-1.135-.845-2.098-1.976-2.192a48.424 48.424 0 00-1.123-.08m-5.801 0c-.065.21-.1.433-.1.664 0 .414.336.75.75.75h4.5a.75.75 0 00.75-.75 2.25 2.25 0 00-.1-.664m-5.8 0A2.251 2.251 0 0113.5 2.25H15c1.012 0 1.867.668 2.15 1.586m-5.8 0c-.376.023-.75.05-1.124.08C9.095 4.01 8.25 4.973 8.25 6.108V8.25m0 0H4.875c-.621 0-1.125.504-1.125 1.125v11.25c0 .621.504 1.125 1.125 1.125h9.75c.621 0 1.125-.504 1.125-1.125V9.375c0-.621-.504-1.125-1.125-1.125H8.25zM6.75 12h.008v.008H6.75V12zm0 3h.008v.008H6.75V15zm0 3h.008v.008H6.75V18z"/></svg>
                                <svg v-else class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                            </button>
                            <button @click="duplicateQR(qr)" title="Duplicar" class="p-1.5 rounded-lg text-gray-500 hover:bg-gray-50 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 17.25v3.375c0 .621-.504 1.125-1.125 1.125h-9.75a1.125 1.125 0 01-1.125-1.125V7.875c0-.621.504-1.125 1.125-1.125H6.75a9.06 9.06 0 011.5.124m7.5 10.376h3.375c.621 0 1.125-.504 1.125-1.125V11.25c0-4.46-3.243-8.161-7.5-8.876a9.06 9.06 0 00-1.5-.124H9.375c-.621 0-1.125.504-1.125 1.125v3.5m7.5 10.375H9.375a1.125 1.125 0 01-1.125-1.125v-9.25m12 6.625v-1.875a3.375 3.375 0 00-3.375-3.375h-1.5a1.125 1.125 0 01-1.125-1.125v-1.5a3.375 3.375 0 00-3.375-3.375H9.75"/></svg>
                            </button>
                            <button @click="openEdit(qr)" title="Editar" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                            </button>
                            <button @click="deleteQR(qr)" title="Eliminar" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                            </button>
                        </div>
                    </div>

                    <h3 class="font-semibold text-gray-900 text-sm mb-2">{{ qr.title }}</h3>
                    <p class="text-xs text-gray-600 line-clamp-4 flex-1 whitespace-pre-wrap">{{ qr.body }}</p>

                    <!-- Footer -->
                    <div class="mt-3 pt-3 border-t border-gray-50 flex items-center justify-between">
                        <button @click="toggleQR(qr)"
                                class="flex items-center gap-1.5 text-[11px] font-medium transition"
                                :class="qr.is_active ? 'text-emerald-600 hover:text-emerald-700' : 'text-gray-400 hover:text-gray-600'">
                            <span class="relative inline-block w-7 h-4 rounded-full transition-colors" :class="qr.is_active ? 'bg-emerald-500' : 'bg-gray-300'">
                                <span class="absolute top-0.5 w-3 h-3 bg-white rounded-full transition-transform" :class="qr.is_active ? 'left-3.5' : 'left-0.5'"></span>
                            </span>
                            {{ qr.is_active ? 'Activa' : 'Inactiva' }}
                        </button>
                        <span class="text-[10px] text-gray-400 tabular-nums">{{ qr.body.length }} chars</span>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                <h3 class="text-base font-semibold text-gray-700 mb-1">
                    {{ hasFilters ? 'Ninguna respuesta coincide con los filtros' : 'Aún no hay respuestas rápidas' }}
                </h3>
                <p class="text-sm text-gray-400 mb-5">
                    {{ hasFilters ? 'Ajusta o limpia los filtros para ver más resultados.' : 'Crea respuestas predefinidas para enviar con un click desde el chat.' }}
                </p>
                <div v-if="!hasFilters" class="flex justify-center gap-2">
                    <button @click="openCreate" class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition">
                        Crear la primera
                    </button>
                    <button @click="loadSamples" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">
                        Cargar 5 ejemplos
                    </button>
                </div>
                <button v-else @click="clearFilters" class="text-xs text-accent hover:underline">
                    Limpiar filtros
                </button>
            </div>
        </div>

        <!-- ═══════════════ Modal create / edit ═══════════════ -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? 'Editar respuesta' : 'Nueva respuesta' }}</h3>

                        <form @submit.prevent="save" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Título <span class="text-red-500">*</span></label>
                                <input v-model="form.title" type="text" placeholder="Ej. Saludo inicial" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': form.errors.title }">
                                <p v-if="form.errors.title" class="text-red-500 text-xs mt-1">{{ form.errors.title }}</p>
                            </div>

                            <div class="grid grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Atajo</label>
                                    <div class="flex items-center border rounded-xl overflow-hidden focus-within:ring-2 focus-within:ring-accent/30" :class="form.errors.shortcut ? 'border-red-400' : 'border-gray-200'">
                                        <span class="text-gray-400 font-mono pl-3">/</span>
                                        <input v-model="form.shortcut" type="text" placeholder="saludo" class="flex-1 px-2 py-2.5 text-sm font-mono border-0 focus:ring-0">
                                    </div>
                                    <p v-if="form.errors.shortcut" class="text-red-500 text-xs mt-1">{{ form.errors.shortcut }}</p>
                                    <p v-else class="text-[10px] text-gray-400 mt-1">Solo letras, números, _ y -</p>
                                </div>

                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label>
                                    <input v-model="form.category" type="text" list="qr-categories" placeholder="Soporte, Ventas..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <datalist id="qr-categories">
                                        <option v-for="cat in categories" :key="cat" :value="cat" />
                                    </datalist>
                                    <p v-if="form.errors.category" class="text-red-500 text-xs mt-1">{{ form.errors.category }}</p>
                                </div>
                            </div>

                            <div>
                                <div class="flex items-center justify-between mb-1">
                                    <label class="block text-sm font-medium text-gray-700">Contenido <span class="text-red-500">*</span></label>
                                    <span class="text-[10px] tabular-nums" :class="bodyOverflow ? 'text-red-500 font-semibold' : 'text-gray-400'">{{ bodyChars }} / {{ bodyMax }}</span>
                                </div>
                                <textarea v-model="form.body" rows="5" placeholder="El mensaje que se enviará cuando uses esta respuesta…" class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none" :class="form.errors.body || bodyOverflow ? 'border-red-400' : 'border-gray-200'"></textarea>
                                <p v-if="form.errors.body" class="text-red-500 text-xs mt-1">{{ form.errors.body }}</p>
                            </div>

                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" v-model="form.is_active" class="rounded border-gray-300 text-accent focus:ring-accent/30">
                                <span class="ml-2 text-sm text-gray-700">Activa <span class="text-gray-400 text-xs">(visible en el chat)</span></span>
                            </label>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="form.processing || bodyOverflow" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
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
