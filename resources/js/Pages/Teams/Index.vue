<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ teams: Array });
const showModal = ref(false);
const editing = ref(null);
const colors = ['#4fc3f7','#00a884','#ef4444','#f59e0b','#8b5cf6','#ec4899','#14b8a6','#f97316','#6366f1','#84cc16'];

const form = useForm({ name: '', description: '', color: '#4fc3f7' });

function openCreate() { editing.value = null; form.reset(); form.color = '#4fc3f7'; showModal.value = true; }
function openEdit(t) { editing.value = t; form.name = t.name; form.description = t.description || ''; form.color = t.color; showModal.value = true; }
function save() {
    if (editing.value) {
        form.put(route('teams.update', editing.value.id), { onSuccess: () => showModal.value = false, preserveScroll: true });
    } else {
        form.post(route('teams.store'), { onSuccess: () => { showModal.value = false; form.reset(); }, preserveScroll: true });
    }
}
function deleteTeam(t) { if (confirm('¿Eliminar este equipo?')) router.delete(route('teams.destroy', t.id), { preserveScroll: true }); }
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Equipos</h1>
                    <p class="text-sm text-gray-500 mt-1">Organiza tu staff en equipos (soporte, ventas, etc.)</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nuevo Equipo
                </button>
            </div>

            <div v-if="teams.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="team in teams" :key="team.id" class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100 hover:shadow-md transition-all group">
                    <div class="flex items-start justify-between mb-4">
                        <div class="w-12 h-12 rounded-xl flex items-center justify-center" :style="{ backgroundColor: team.color + '20' }">
                            <svg class="w-6 h-6" :style="{ color: team.color }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" />
                            </svg>
                        </div>
                        <div class="flex space-x-1 opacity-0 group-hover:opacity-100 transition">
                            <button @click="openEdit(team)" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                            <button @click="deleteTeam(team)" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                        </div>
                    </div>
                    <h3 class="text-lg font-semibold text-gray-900">{{ team.name }}</h3>
                    <p class="text-sm text-gray-500 mt-1">{{ team.description || 'Sin descripción' }}</p>
                    <div class="mt-4 flex items-center justify-between">
                        <div class="flex items-center space-x-1">
                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                            <span class="text-sm text-gray-500">{{ team.staff_members_count }} miembros</span>
                        </div>
                        <div class="w-4 h-4 rounded-full" :style="{ backgroundColor: team.color }"></div>
                    </div>
                </div>
            </div>
            <div v-else class="bg-white rounded-2xl p-12 text-center text-gray-400 shadow-sm"><p class="text-sm">No hay equipos creados</p></div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? 'Editar Equipo' : 'Nuevo Equipo' }}</h3>
                        <form @submit.prevent="save" class="space-y-4">
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Nombre *</label><input v-model="form.name" type="text" placeholder="Soporte, Ventas..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label><textarea v-model="form.description" rows="2" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-2">Color</label><div class="flex flex-wrap gap-2"><button v-for="c in colors" :key="c" type="button" @click="form.color = c" class="w-8 h-8 rounded-full transition-transform hover:scale-110" :class="form.color === c ? 'ring-2 ring-offset-2 ring-gray-400 scale-110' : ''" :style="{ backgroundColor: c }"></button></div></div>
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
