<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({
    labels: Array,
    filters: Object,
});

const showModal = ref(false);
const editingLabel = ref(null);
const activeFilter = ref(props.filters?.type || '');

const form = useForm({
    name: '',
    color: '#00a884',
    type: 'contact',
    description: '',
});

const colors = ['#00a884','#4fc3f7','#ef4444','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16'];

function openCreate() {
    editingLabel.value = null;
    form.reset();
    showModal.value = true;
}

function openEdit(label) {
    editingLabel.value = label;
    form.name = label.name;
    form.color = label.color;
    form.type = label.type;
    form.description = label.description || '';
    showModal.value = true;
}

function save() {
    if (editingLabel.value) {
        form.put(route('labels.update', editingLabel.value.id), { onSuccess: () => { showModal.value = false; }, preserveScroll: true });
    } else {
        form.post(route('labels.store'), { onSuccess: () => { showModal.value = false; form.reset(); }, preserveScroll: true });
    }
}

function deleteLabel(label) {
    if (confirm('¿Eliminar esta etiqueta?')) {
        router.delete(route('labels.destroy', label.id), { preserveScroll: true });
    }
}

function filterType(type) {
    activeFilter.value = type;
    router.get(route('labels.index'), type ? { type } : {}, { preserveState: true });
}
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Etiquetas</h1>
                    <p class="text-sm text-gray-500 mt-1">Organiza contactos y plantillas con etiquetas</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nueva Etiqueta
                </button>
            </div>

            <!-- Type filter -->
            <div class="flex gap-2 mb-6">
                <button @click="filterType('')" class="px-4 py-2 rounded-xl text-sm font-medium transition" :class="!activeFilter ? 'bg-accent text-white' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'">Todas</button>
                <button @click="filterType('contact')" class="px-4 py-2 rounded-xl text-sm font-medium transition" :class="activeFilter === 'contact' ? 'bg-accent text-white' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'">Contactos</button>
                <button @click="filterType('template')" class="px-4 py-2 rounded-xl text-sm font-medium transition" :class="activeFilter === 'template' ? 'bg-accent text-white' : 'bg-white text-gray-600 hover:bg-gray-50 border border-gray-200'">Plantillas</button>
            </div>

            <!-- Labels Grid -->
            <div v-if="labels.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-4">
                <div v-for="label in labels" :key="label.id" class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-all group">
                    <div class="flex items-start justify-between mb-3">
                        <div class="w-10 h-10 rounded-xl flex items-center justify-center" :style="{ backgroundColor: label.color + '20' }">
                            <svg class="w-5 h-5" :style="{ color: label.color }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" />
                            </svg>
                        </div>
                        <div class="flex space-x-1 opacity-0 group-hover:opacity-100 transition">
                            <button @click="openEdit(label)" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                            <button @click="deleteLabel(label)" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                        </div>
                    </div>
                    <h3 class="font-semibold text-gray-900">{{ label.name }}</h3>
                    <p class="text-xs text-gray-500 mt-1">{{ label.description || 'Sin descripción' }}</p>
                    <div class="flex items-center justify-between mt-3">
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-medium" :class="label.type === 'contact' ? 'bg-blue-100 text-blue-700' : 'bg-purple-100 text-purple-700'">
                            {{ label.type === 'contact' ? 'Contacto' : 'Plantilla' }}
                        </span>
                        <span class="text-xs text-gray-400">{{ label.contacts_count || 0 }} contactos</span>
                    </div>
                </div>
            </div>
            <div v-else class="bg-white rounded-2xl p-12 text-center text-gray-400 shadow-sm">
                <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" /></svg>
                <p>No hay etiquetas creadas</p>
            </div>
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editingLabel ? 'Editar Etiqueta' : 'Nueva Etiqueta' }}</h3>
                        <form @submit.prevent="save" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label>
                                <input v-model="form.name" type="text" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Color</label>
                                <div class="flex flex-wrap gap-2">
                                    <button v-for="c in colors" :key="c" type="button" @click="form.color = c" class="w-8 h-8 rounded-full transition-transform hover:scale-110" :class="form.color === c ? 'ring-2 ring-offset-2 ring-gray-400 scale-110' : ''" :style="{ backgroundColor: c }"></button>
                                </div>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Tipo</label>
                                <select v-model="form.type" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="contact">Contacto</option>
                                    <option value="template">Plantilla</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                                <textarea v-model="form.description" rows="2" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea>
                            </div>
                            <div class="flex justify-end space-x-3 pt-2">
                                <button type="button" @click="showModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">Guardar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
