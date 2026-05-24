<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ notes: Object, filters: Object });
const search = ref(props.filters?.search || '');

function doSearch() {
    router.get(route('closing-notes.index'), { search: search.value }, { preserveState: true });
}
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Notas de Cierre</h1>
                    <p class="text-sm text-gray-500 mt-1">Historial de notas al cerrar conversaciones</p>
                </div>
            </div>

            <div class="bg-white rounded-2xl p-4 mb-6 shadow-sm border border-gray-100">
                <div class="relative">
                    <input v-model="search" @keyup.enter="doSearch" type="text" placeholder="Buscar notas..." class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                </div>
            </div>

            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="notes.data?.length" class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Contacto</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Nota</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase hidden md:table-cell">Agente</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Fecha</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="note in notes.data" :key="note.id" class="hover:bg-gray-50 transition">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold">{{ (note.contact_name || '?')[0].toUpperCase() }}</div>
                                        <span class="ml-2 text-sm font-medium text-gray-900">{{ note.contact_name }}</span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600 max-w-md"><p class="line-clamp-2">{{ note.note }}</p></td>
                                <td class="px-6 py-4 text-sm text-gray-500 hidden md:table-cell">{{ note.user_name || '—' }}</td>
                                <td class="px-6 py-4 text-sm text-gray-500">{{ note.created_at }}</td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-12 text-center text-gray-400">
                        <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <p class="text-sm">No hay notas de cierre</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
