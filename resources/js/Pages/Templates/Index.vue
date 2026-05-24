<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ templates: Array, filters: Object });
const showModal = ref(false);
const editing = ref(null);
const statusFilter = ref(props.filters?.status || '');

const form = useForm({ name: '', category: 'utility', body: '', team_label: '', is_active: true });

const statusColors = { pending: 'bg-yellow-100 text-yellow-700', approved: 'bg-green-100 text-green-700', rejected: 'bg-red-100 text-red-700' };
const statusLabels = { pending: 'Pendiente', approved: 'Aprobada', rejected: 'Rechazada' };
const categoryLabels = { marketing: 'Marketing', utility: 'Utilidad', authentication: 'Autenticación' };

function openCreate() { editing.value = null; form.reset(); showModal.value = true; }
function openEdit(t) {
    editing.value = t;
    form.name = t.name; form.category = t.category; form.body = t.body;
    form.team_label = t.team_label || ''; form.is_active = t.is_active;
    showModal.value = true;
}
function save() {
    if (editing.value) {
        form.put(route('templates.update', editing.value.id), { onSuccess: () => { showModal.value = false; }, preserveScroll: true });
    } else {
        form.post(route('templates.store'), { onSuccess: () => { showModal.value = false; form.reset(); }, preserveScroll: true });
    }
}
function toggleActive(t) { router.patch(route('templates.toggle', t.id), {}, { preserveScroll: true }); }
function deleteTemplate(t) { if (confirm('¿Eliminar esta plantilla?')) router.delete(route('templates.destroy', t.id), { preserveScroll: true }); }
function filterStatus(s) { statusFilter.value = s; router.get(route('templates.index'), s ? { status: s } : {}, { preserveState: true }); }
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Plantillas</h1>
                    <p class="text-sm text-gray-500 mt-1">Plantillas de WhatsApp para aprobación de Meta</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nueva Plantilla
                </button>
            </div>

            <div class="flex gap-2 mb-6">
                <button @click="filterStatus('')" class="px-4 py-2 rounded-xl text-sm font-medium transition" :class="!statusFilter ? 'bg-accent text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'">Todas</button>
                <button v-for="(label, key) in statusLabels" :key="key" @click="filterStatus(key)" class="px-4 py-2 rounded-xl text-sm font-medium transition" :class="statusFilter === key ? 'bg-accent text-white' : 'bg-white text-gray-600 border border-gray-200 hover:bg-gray-50'">{{ label }}</button>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="templates.length" class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">ID</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Equipo</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Nombre</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase hidden lg:table-cell">Contenido</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Estado</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Activo</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="t in templates" :key="t.id" class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4 text-sm text-gray-500">{{ t.id }}</td>
                                <td class="px-6 py-4"><span class="px-2 py-1 rounded-full text-[10px] font-medium bg-blue-100 text-blue-700">{{ t.team_label || 'Del Equipo' }}</span></td>
                                <td class="px-6 py-4 text-sm font-medium text-gray-900">{{ t.name }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500 max-w-xs truncate hidden lg:table-cell">{{ t.body }}</td>
                                <td class="px-6 py-4"><span class="px-2 py-1 rounded-full text-[10px] font-medium" :class="statusColors[t.status]">{{ statusLabels[t.status] }}</span></td>
                                <td class="px-6 py-4 text-center">
                                    <button @click="toggleActive(t)" class="relative inline-flex h-6 w-11 items-center rounded-full transition-colors" :class="t.is_active ? 'bg-accent' : 'bg-gray-300'">
                                        <span class="inline-block h-4 w-4 transform rounded-full bg-white transition-transform shadow" :class="t.is_active ? 'translate-x-6' : 'translate-x-1'"></span>
                                    </button>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end space-x-1">
                                        <button @click="openEdit(t)" class="p-2 rounded-lg text-blue-500 hover:bg-blue-50 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                                        <button @click="deleteTemplate(t)" class="p-2 rounded-lg text-red-500 hover:bg-red-50 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-12 text-center text-gray-400">
                        <p class="text-sm">No hay plantillas creadas</p>
                    </div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? 'Editar Plantilla' : 'Nueva Plantilla' }}</h3>
                        <form @submit.prevent="save" class="space-y-4">
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label><input v-model="form.name" type="text" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label><select v-model="form.category" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30"><option value="utility">Utilidad</option><option value="marketing">Marketing</option><option value="authentication">Autenticación</option></select></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Etiqueta de Equipo</label><input v-model="form.team_label" type="text" placeholder="Del Equipo" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Contenido *</label><textarea v-model="form.body" rows="4" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea></div>
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
