<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps({ quickReplies: Array, filters: Object });
const showModal = ref(false);
const editing = ref(null);
const search = ref(props.filters?.search || '');

const form = useForm({ title: '', body: '', shortcut: '', category: '', is_active: true });

function openCreate() { editing.value = null; form.reset(); form.is_active = true; showModal.value = true; }
function openEdit(qr) { editing.value = qr; form.title = qr.title; form.body = qr.body; form.shortcut = qr.shortcut || ''; form.category = qr.category || ''; form.is_active = qr.is_active; showModal.value = true; }
function save() {
    if (editing.value) {
        form.put(route('quick-replies.update', editing.value.id), { onSuccess: () => showModal.value = false, preserveScroll: true });
    } else {
        form.post(route('quick-replies.store'), { onSuccess: () => { showModal.value = false; form.reset(); }, preserveScroll: true });
    }
}
function deleteQR(qr) { if (confirm('¿Eliminar esta respuesta rápida?')) router.delete(route('quick-replies.destroy', qr.id), { preserveScroll: true }); }
function doSearch() { router.get(route('quick-replies.index'), { search: search.value }, { preserveState: true }); }
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Respuestas Rápidas</h1>
                    <p class="text-sm text-gray-500 mt-1">Mensajes predefinidos para responder rápidamente</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nueva Respuesta
                </button>
            </div>

            <div class="bg-white rounded-2xl p-4 mb-6 shadow-sm border border-gray-100">
                <div class="relative">
                    <input v-model="search" @keyup.enter="doSearch" type="text" placeholder="Buscar respuestas rápidas..." class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                    <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                </div>
            </div>

            <div v-if="quickReplies.length" class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="qr in quickReplies" :key="qr.id" class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-all group">
                    <div class="flex items-start justify-between mb-3">
                        <div class="flex items-center space-x-2">
                            <div class="w-8 h-8 rounded-lg bg-amber-50 flex items-center justify-center">
                                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                            </div>
                            <span v-if="qr.shortcut" class="px-2 py-0.5 rounded bg-gray-100 text-[10px] font-mono text-gray-600">/{{ qr.shortcut }}</span>
                        </div>
                        <div class="flex space-x-1 opacity-0 group-hover:opacity-100 transition">
                            <button @click="openEdit(qr)" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                            <button @click="deleteQR(qr)" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                        </div>
                    </div>
                    <h3 class="font-semibold text-gray-900 text-sm">{{ qr.title }}</h3>
                    <p class="text-xs text-gray-500 mt-2 line-clamp-3">{{ qr.body }}</p>
                    <div class="mt-3 flex items-center justify-between">
                        <span v-if="qr.category" class="px-2 py-0.5 rounded-full text-[10px] font-medium bg-purple-100 text-purple-700">{{ qr.category }}</span>
                        <span class="w-2 h-2 rounded-full" :class="qr.is_active ? 'bg-green-500' : 'bg-gray-300'"></span>
                    </div>
                </div>
            </div>
            <div v-else class="bg-white rounded-2xl p-12 text-center text-gray-400 shadow-sm"><p class="text-sm">No hay respuestas rápidas</p></div>
        </div>

        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? 'Editar Respuesta' : 'Nueva Respuesta' }}</h3>
                        <form @submit.prevent="save" class="space-y-4">
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Título *</label><input v-model="form.title" type="text" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Atajo</label><div class="flex items-center"><span class="text-gray-400 mr-1">/</span><input v-model="form.shortcut" type="text" placeholder="saludo" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"></div></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Categoría</label><input v-model="form.category" type="text" placeholder="Ventas, Soporte..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"></div>
                            <div><label class="block text-sm font-medium text-gray-700 mb-1">Contenido *</label><textarea v-model="form.body" rows="4" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea></div>
                            <div class="flex items-center space-x-2"><input type="checkbox" v-model="form.is_active" id="qr-active" class="rounded border-gray-300 text-accent focus:ring-accent"><label for="qr-active" class="text-sm text-gray-700">Activo</label></div>
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
