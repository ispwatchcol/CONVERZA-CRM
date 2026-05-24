<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    contacts: Object,
    labels: Array,
    filters: Object,
});

const search = ref(props.filters?.search || '');
const showModal = ref(false);
const editingContact = ref(null);

const form = useForm({
    phone: '',
    name: '',
    email: '',
    notes: '',
    labels: [],
});

function openCreate() {
    editingContact.value = null;
    form.reset();
    showModal.value = true;
}

function openEdit(contact) {
    editingContact.value = contact;
    form.phone = contact.phone;
    form.name = contact.name || '';
    form.email = contact.email || '';
    form.notes = contact.notes || '';
    form.labels = contact.labels?.map(l => l.id) || [];
    showModal.value = true;
}

function save() {
    if (editingContact.value) {
        form.put(route('contacts.update', editingContact.value.id), {
            onSuccess: () => { showModal.value = false; form.reset(); },
            preserveScroll: true,
        });
    } else {
        form.post(route('contacts.store'), {
            onSuccess: () => { showModal.value = false; form.reset(); },
            preserveScroll: true,
        });
    }
}

function deleteContact(contact) {
    if (confirm('¿Eliminar este contacto?')) {
        router.delete(route('contacts.destroy', contact.id), { preserveScroll: true });
    }
}

function doSearch() {
    router.get(route('contacts.index'), { search: search.value }, { preserveState: true, preserveScroll: true });
}

function filterByLabel(labelId) {
    router.get(route('contacts.index'), { label: labelId }, { preserveState: true });
}

function formatPhone(phone) {
    if (!phone) return '';
    if (phone.startsWith('57') && phone.length === 12) return phone.substring(2);
    return phone;
}
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Contactos</h1>
                    <p class="text-sm text-gray-500 mt-1">Gestiona los contactos de WhatsApp</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition-colors shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                    Nuevo Contacto
                </button>
            </div>

            <!-- Search & Filter -->
            <div class="bg-white rounded-2xl p-4 mb-6 shadow-sm border border-gray-100">
                <div class="flex flex-col sm:flex-row gap-3">
                    <div class="flex-1 relative">
                        <input v-model="search" @keyup.enter="doSearch" type="text" placeholder="Buscar por nombre, teléfono o email..." class="w-full pl-10 pr-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-3" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                    </div>
                    <div class="flex flex-wrap gap-2">
                        <button @click="filterByLabel(null)" class="px-3 py-1.5 rounded-lg text-xs font-medium transition" :class="!filters?.label ? 'bg-accent text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">Todos</button>
                        <button v-for="label in labels" :key="label.id" @click="filterByLabel(label.id)" class="px-3 py-1.5 rounded-lg text-xs font-medium transition" :class="filters?.label == label.id ? 'text-white' : 'text-gray-600 hover:opacity-80'" :style="filters?.label == label.id ? { backgroundColor: label.color } : { backgroundColor: label.color + '20', color: label.color }">
                            {{ label.name }}
                        </button>
                    </div>
                </div>
            </div>

            <!-- Contacts Table -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="contacts.data?.length" class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Contacto</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Teléfono</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider hidden lg:table-cell">Email</th>
                                <th class="px-6 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Etiquetas</th>
                                <th class="px-6 py-3 text-right text-xs font-semibold text-gray-500 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                            <tr v-for="contact in contacts.data" :key="contact.id" class="hover:bg-gray-50 transition-colors">
                                <td class="px-6 py-4">
                                    <div class="flex items-center">
                                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-sm font-bold shrink-0">
                                            {{ (contact.name || contact.phone || '?')[0].toUpperCase() }}
                                        </div>
                                        <div class="ml-3">
                                            <p class="text-sm font-medium text-gray-900">{{ contact.name || 'Sin nombre' }}</p>
                                            <p class="text-xs text-gray-500 md:hidden">{{ formatPhone(contact.phone) }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-sm text-gray-600 hidden md:table-cell">{{ formatPhone(contact.phone) }}</td>
                                <td class="px-6 py-4 text-sm text-gray-600 hidden lg:table-cell">{{ contact.email || '—' }}</td>
                                <td class="px-6 py-4">
                                    <div class="flex flex-wrap gap-1">
                                        <span v-for="label in contact.labels" :key="label.id" class="px-2 py-0.5 rounded-full text-[10px] font-medium text-white" :style="{ backgroundColor: label.color }">
                                            {{ label.name }}
                                        </span>
                                    </div>
                                </td>
                                <td class="px-6 py-4 text-right">
                                    <div class="flex items-center justify-end space-x-2">
                                        <button @click="openEdit(contact)" class="p-2 rounded-lg text-blue-500 hover:bg-blue-50 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931zm0 0L19.5 7.125M18 14v4.75A2.25 2.25 0 0115.75 21H5.25A2.25 2.25 0 013 18.75V8.25A2.25 2.25 0 015.25 6H10" /></svg></button>
                                        <button @click="deleteContact(contact)" class="p-2 rounded-lg text-red-500 hover:bg-red-50 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-12 text-center text-gray-400">
                        <svg class="w-16 h-16 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                        <p class="text-sm">No hay contactos registrados</p>
                    </div>
                </div>
            </div>
        </div>

        <!-- Modal -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editingContact ? 'Editar Contacto' : 'Nuevo Contacto' }}</h3>
                        <form @submit.prevent="save" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono *</label>
                                <input v-model="form.phone" type="text" placeholder="573001234567" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{'border-red-400': form.errors.phone}">
                                <p v-if="form.errors.phone" class="text-red-500 text-xs mt-1">{{ form.errors.phone }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                                <input v-model="form.name" type="text" placeholder="Juan Pérez" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Email</label>
                                <input v-model="form.email" type="email" placeholder="correo@ejemplo.com" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Notas</label>
                                <textarea v-model="form.notes" rows="2" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea>
                            </div>
                            <div v-if="labels.length">
                                <label class="block text-sm font-medium text-gray-700 mb-2">Etiquetas</label>
                                <div class="flex flex-wrap gap-2">
                                    <label v-for="label in labels" :key="label.id" class="flex items-center px-3 py-1.5 rounded-lg text-xs font-medium cursor-pointer transition" :class="form.labels.includes(label.id) ? 'text-white' : 'text-gray-600'" :style="{ backgroundColor: form.labels.includes(label.id) ? label.color : label.color + '20' }">
                                        <input type="checkbox" :value="label.id" v-model="form.labels" class="sr-only">
                                        {{ label.name }}
                                    </label>
                                </div>
                            </div>
                            <div class="flex justify-end space-x-3 pt-2">
                                <button type="button" @click="showModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="form.processing" class="px-6 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ form.processing ? 'Guardando...' : 'Guardar' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
