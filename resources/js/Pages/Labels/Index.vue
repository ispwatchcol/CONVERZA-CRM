<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    labels:  { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats:   { type: Object, default: () => ({}) },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const type = ref(props.filters?.type || '');

function applyFilters() {
    router.get(route('labels.index'), {
        search: search.value || undefined,
        type:   type.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function setType(v) {
    type.value = v;
    applyFilters();
}

function clearFilters() {
    search.value = '';
    type.value = '';
    applyFilters();
}

const hasFilters = computed(() => search.value !== '' || type.value !== '');

const typeChips = [
    { value: '',         label: 'Todas' },
    { value: 'contact',  label: 'Contactos' },
    { value: 'template', label: 'Plantillas' },
];

// ── Modal create/edit ────────────────────────────────────────────────────────
const showModal = ref(false);
const editing = ref(null);
const form = useForm({
    name: '',
    color: '#10b981',
    type: 'contact',
    description: '',
});

const presetColors = [
    '#ef4444', '#f97316', '#f59e0b', '#eab308',
    '#84cc16', '#10b981', '#14b8a6', '#06b6d4',
    '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7',
    '#ec4899', '#f43f5e', '#64748b', '#0f172a',
];

const isValidHex = computed(() => /^#[0-9a-fA-F]{6}$/.test(form.color));

function openCreate() {
    editing.value = null;
    form.reset();
    form.color = '#10b981';
    form.type = 'contact';
    showModal.value = true;
}

function openEdit(label) {
    editing.value = label;
    form.name = label.name;
    form.color = label.color;
    form.type = label.type;
    form.description = label.description || '';
    showModal.value = true;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; form.reset(); } };
    if (editing.value) {
        form.put(route('labels.update', editing.value.id), opts);
    } else {
        form.post(route('labels.store'), opts);
    }
}

function deleteLabel(label) {
    const inUseMsg = label.contacts_count > 0
        ? `Esta etiqueta está aplicada a ${label.contacts_count} ${label.contacts_count === 1 ? 'contacto' : 'contactos'}. Eliminarla los desetiquetará. `
        : '';
    if (!confirm(`${inUseMsg}¿Eliminar "${label.name}"?`)) return;
    router.delete(route('labels.destroy', label.id), { preserveScroll: true });
}

function loadSamples() {
    if (!confirm('Se van a crear 5 etiquetas de ejemplo (VIP, Cliente activo, Moroso, Prospecto frío, Reclamo abierto). ¿Continuar?')) return;
    router.post(route('labels.load-samples'), {}, { preserveScroll: true });
}
</script>

<template>
    <Head title="Etiquetas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Etiquetas</h1>
                    <p class="text-sm text-gray-500 mt-1">Organiza tus contactos y plantillas con etiquetas de color</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Nueva etiqueta
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Contactos</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1">{{ stats.contact }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Plantillas</p>
                    <p class="text-2xl font-bold text-purple-600 mt-1">{{ stats.template }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">En uso</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ stats.in_use }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">con contactos</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row gap-3 md:items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar por nombre o descripción…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                        <button v-for="opt in typeChips" :key="opt.value" @click="setType(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                :class="type === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            {{ opt.label }}
                        </button>
                    </div>

                    <button v-if="hasFilters" @click="clearFilters" class="text-xs text-gray-500 hover:text-gray-700 px-2 transition">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- Grid de cards -->
            <div v-if="labels.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3">
                <div v-for="label in labels" :key="label.id"
                     class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-md transition-all group relative flex flex-col"
                     :style="{ borderLeftColor: label.color, borderLeftWidth: '4px' }">
                    <!-- Top: color chip + actions -->
                    <div class="flex items-start justify-between mb-2">
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium text-white shadow-sm" :style="{ backgroundColor: label.color }">
                            <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                            {{ label.name }}
                        </span>
                        <div class="flex space-x-0.5 opacity-0 group-hover:opacity-100 transition shrink-0">
                            <button @click="openEdit(label)" title="Editar" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                            </button>
                            <button @click="deleteLabel(label)" title="Eliminar" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                            </button>
                        </div>
                    </div>

                    <p v-if="label.description" class="text-xs text-gray-500 line-clamp-2 flex-1">{{ label.description }}</p>
                    <p v-else class="text-xs text-gray-300 italic flex-1">Sin descripción</p>

                    <div class="mt-3 pt-3 border-t border-gray-50 flex items-center justify-between">
                        <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide"
                              :class="label.type === 'contact' ? 'bg-blue-50 text-blue-700' : 'bg-purple-50 text-purple-700'">
                            {{ label.type === 'contact' ? 'Contacto' : 'Plantilla' }}
                        </span>
                        <span class="text-[11px] text-gray-500">
                            <span class="font-semibold text-gray-700">{{ label.contacts_count || 0 }}</span>
                            {{ label.contacts_count === 1 ? 'contacto' : 'contactos' }}
                        </span>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z"/></svg>
                <h3 class="text-base font-semibold text-gray-700 mb-1">
                    {{ hasFilters ? 'Ninguna etiqueta coincide con los filtros' : 'Aún no hay etiquetas' }}
                </h3>
                <p class="text-sm text-gray-400 mb-5">
                    {{ hasFilters ? 'Ajusta o limpia los filtros.' : 'Las etiquetas te ayudan a clasificar contactos (VIP, moroso, prospecto, etc.) para filtrar fácil después.' }}
                </p>
                <div v-if="!hasFilters" class="flex justify-center gap-2">
                    <button @click="openCreate" class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition">Crear la primera</button>
                    <button @click="loadSamples" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">Cargar 5 ejemplos</button>
                </div>
                <button v-else @click="clearFilters" class="text-xs text-accent hover:underline">Limpiar filtros</button>
            </div>
        </div>

        <!-- ═══════════════ Modal create / edit ═══════════════ -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? 'Editar etiqueta' : 'Nueva etiqueta' }}</h3>

                        <!-- Preview en vivo -->
                        <div class="bg-gray-50 rounded-xl p-3 mb-4">
                            <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-2">Vista previa</p>
                            <span class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-medium text-white shadow-sm"
                                  :style="{ backgroundColor: isValidHex ? form.color : '#94a3b8' }">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 20 20"><path fill-rule="evenodd" d="M17.707 9.293a1 1 0 010 1.414l-7 7a1 1 0 01-1.414 0l-7-7A.997.997 0 012 10V5a3 3 0 013-3h5c.256 0 .512.098.707.293l7 7zM5 6a1 1 0 100-2 1 1 0 000 2z" clip-rule="evenodd"/></svg>
                                {{ form.name || 'Mi etiqueta' }}
                            </span>
                        </div>

                        <form @submit.prevent="save" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                                <input v-model="form.name" type="text" placeholder="Ej. VIP, Moroso, Soporte…" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': form.errors.name }">
                                <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Color</label>
                                <!-- Presets -->
                                <div class="flex flex-wrap gap-2 mb-2">
                                    <button v-for="c in presetColors" :key="c" type="button" @click="form.color = c"
                                            class="w-7 h-7 rounded-full transition-transform hover:scale-110"
                                            :class="form.color.toLowerCase() === c.toLowerCase() ? 'ring-2 ring-offset-2 ring-gray-400 scale-110' : ''"
                                            :style="{ backgroundColor: c }"></button>
                                </div>
                                <!-- Custom hex input -->
                                <div class="flex items-center gap-2">
                                    <input v-model="form.color" type="color" class="w-10 h-10 rounded-lg cursor-pointer border border-gray-200">
                                    <input v-model="form.color" type="text" placeholder="#10b981" maxlength="7"
                                           class="flex-1 px-3 py-2 border rounded-lg text-sm font-mono uppercase focus:ring-2 focus:ring-accent/30 focus:border-accent"
                                           :class="form.errors.color || (!isValidHex && form.color) ? 'border-red-400' : 'border-gray-200'">
                                </div>
                                <p v-if="form.errors.color" class="text-red-500 text-xs mt-1">{{ form.errors.color }}</p>
                                <p v-else-if="!isValidHex && form.color" class="text-amber-600 text-xs mt-1">El color debe ser hex válido (#a1b2c3).</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                                <select v-model="form.type" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="contact">Contacto — aparece en /contacts</option>
                                    <option value="template">Plantilla — para agrupar plantillas WhatsApp</option>
                                </select>
                                <p v-if="form.errors.type" class="text-red-500 text-xs mt-1">{{ form.errors.type }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Descripción <span class="text-gray-400 font-normal">(opcional)</span></label>
                                <textarea v-model="form.description" rows="2" placeholder="¿Cuándo se usa esta etiqueta?" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none" :class="{ 'border-red-400': form.errors.description }"></textarea>
                                <p v-if="form.errors.description" class="text-red-500 text-xs mt-1">{{ form.errors.description }}</p>
                            </div>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="form.processing || !isValidHex" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
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
