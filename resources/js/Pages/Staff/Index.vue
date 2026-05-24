<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ staff: Array, teams: Array, users: Array });
const showModal = ref(false);
const editing = ref(null);

const form = useForm({ user_id: '', team_id: '', role: 'agent', is_active: true });
const roleLabels = { admin: 'Administrador', agent: 'Agente', viewer: 'Visor' };
const roleColors = { admin: 'bg-purple-100 text-purple-700', agent: 'bg-blue-100 text-blue-700', viewer: 'bg-gray-100 text-gray-600' };

function openCreate() { editing.value = null; form.reset(); form.role = 'agent'; form.is_active = true; showModal.value = true; }
function openEdit(s) { editing.value = s; form.team_id = s.team_id || ''; form.role = s.role; form.is_active = s.is_active; showModal.value = true; }
function save() {
    if (editing.value) {
        form.put(route('staff.update', editing.value.id), { onSuccess: () => showModal.value = false, preserveScroll: true });
    } else {
        form.post(route('staff.store'), { onSuccess: () => { showModal.value = false; form.reset(); }, preserveScroll: true });
    }
}
function deleteStaff(s) { if (confirm('¿Eliminar este miembro?')) router.delete(route('staff.destroy', s.id), { preserveScroll: true }); }
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Staff</h1>
                    <p class="text-sm text-gray-500 mt-1">Usuarios que pueden acceder a Converza</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Agregar Staff
                </button>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="staff.length" class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Miembro</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase hidden md:table-cell">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Equipo</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Rol</th>
                                <th class="px-6 py-3 text-center text-xs font-semibold text-gray-500 uppercase">Estado</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="s in staff" :key="s.id" class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-sm font-bold">{{ s.name[0].toUpperCase() }}</div>
                                        <span class="ml-3 text-sm font-medium text-gray-900">{{ s.name }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-500 hidden md:table-cell">{{ s.email }}</td>
                                <td class="px-6 py-4"><span v-if="s.team" class="px-2 py-1 rounded-full text-[10px] font-medium text-white" :style="{ backgroundColor: s.team_color }">{{ s.team }}</span><span v-else class="text-xs text-gray-400">Sin equipo</span></td>
                                <td class="px-6 py-4"><span class="px-2 py-1 rounded-full text-[10px] font-medium" :class="roleColors[s.role]">{{ roleLabels[s.role] }}</span></td>
                                <td class="px-6 py-4 text-center"><span class="w-2.5 h-2.5 rounded-full inline-block" :class="s.is_active ? 'bg-green-500' : 'bg-gray-300'"></span></td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end space-x-1">
                                        <button @click="openEdit(s)" class="p-2 rounded-lg text-blue-500 hover:bg-blue-50 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                                        <button @click="deleteStaff(s)" class="p-2 rounded-lg text-red-500 hover:bg-red-50 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-12 text-center text-gray-400"><p class="text-sm">No hay miembros del staff</p></div>
                </div>
            </div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? 'Editar Staff' : 'Agregar Staff' }}</h3>
                        <form @submit.prevent="save" class="space-y-4">
                            <div v-if="!editing"><label class="block text-sm font-medium text-gray-700 mb-1">Usuario *</label><select v-model="form.user_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30"><option value="" disabled>Seleccionar usuario</option><option v-for="u in users" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option></select></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Equipo</label><select v-model="form.team_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30"><option value="">Sin equipo</option><option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option></select></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Rol</label><select v-model="form.role" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30"><option value="admin">Administrador</option><option value="agent">Agente</option><option value="viewer">Visor</option></select></div>
                            <div class="flex items-center space-x-2"><input type="checkbox" v-model="form.is_active" id="is-active" class="rounded border-gray-300 text-accent focus:ring-accent"><label for="is-active" class="text-sm text-gray-700">Activo</label></div>
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
