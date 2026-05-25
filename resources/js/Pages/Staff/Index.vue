<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    staff: { type: Array, default: () => [] },
    teams: { type: Array, default: () => [] },
    availableUsers: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats: { type: Object, default: () => ({}) },
});

const roleLabels = { admin: 'Administrador', agent: 'Agente', viewer: 'Visor' };
const roleColors = {
    admin:  'bg-purple-100 text-purple-700',
    agent:  'bg-blue-100 text-blue-700',
    viewer: 'bg-gray-100 text-gray-600',
};
const roleDescriptions = {
    admin:  'Acceso completo (configuración, plantillas Meta, gestión de staff)',
    agent:  'Puede chatear, asignarse conversaciones, cerrar chats',
    viewer: 'Solo lectura (no puede responder ni modificar)',
};

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');
const roleFilter = ref(props.filters?.role || '');
const statusFilter = ref(props.filters?.status || 'all');

function applyFilters() {
    router.get(route('staff.index'), {
        search: search.value || undefined,
        role:   roleFilter.value || undefined,
        status: statusFilter.value !== 'all' ? statusFilter.value : undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function setStatus(v) {
    statusFilter.value = v;
    applyFilters();
}

function clearFilters() {
    search.value = '';
    roleFilter.value = '';
    statusFilter.value = 'all';
    applyFilters();
}

const hasFilters = computed(() =>
    search.value !== '' || roleFilter.value !== '' || statusFilter.value !== 'all'
);

const statusChips = [
    { value: 'all',      label: 'Todos' },
    { value: 'active',   label: 'Activos' },
    { value: 'inactive', label: 'Inactivos' },
];

// ── Modal: invitar nuevo ─────────────────────────────────────────────────────
const showInviteModal = ref(false);
const inviteForm = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
    team_id: '',
    role: 'agent',
});

function openInvite() {
    inviteForm.reset();
    inviteForm.role = 'agent';
    showInviteModal.value = true;
}

function submitInvite() {
    inviteForm.post(route('staff.invite'), {
        preserveScroll: true,
        onSuccess: () => { showInviteModal.value = false; inviteForm.reset(); },
    });
}

// ── Modal: agregar staff existente ───────────────────────────────────────────
const showAddModal = ref(false);
const addForm = useForm({
    user_id: '',
    team_id: '',
    role: 'agent',
    is_active: true,
});

function openAdd() {
    addForm.reset();
    addForm.role = 'agent';
    addForm.is_active = true;
    showAddModal.value = true;
}

function submitAdd() {
    addForm.post(route('staff.store'), {
        preserveScroll: true,
        onSuccess: () => { showAddModal.value = false; addForm.reset(); },
    });
}

// ── Modal: editar ────────────────────────────────────────────────────────────
const showEditModal = ref(false);
const editing = ref(null);
const editForm = useForm({ team_id: '', role: 'agent', is_active: true });

function openEdit(s) {
    editing.value = s;
    editForm.team_id = s.team_id || '';
    editForm.role = s.role;
    editForm.is_active = s.is_active;
    showEditModal.value = true;
}

function saveEdit() {
    editForm.put(route('staff.update', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => { showEditModal.value = false; editing.value = null; },
    });
}

// ── Acciones inline ──────────────────────────────────────────────────────────
function toggleActive(s) {
    router.patch(route('staff.toggle', s.id), {}, { preserveScroll: true });
}

function deleteStaff(s) {
    if (!confirm(`¿Quitar a "${s.name}" del staff? Su cuenta de usuario sigue existiendo, solo pierde acceso al CRM.`)) return;
    router.delete(route('staff.destroy', s.id), { preserveScroll: true });
}
</script>

<template>
    <Head title="Staff" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Staff</h1>
                    <p class="text-sm text-gray-500 mt-1">Agentes que pueden acceder al CRM</p>
                </div>
                <div class="flex gap-2">
                    <button v-if="availableUsers.length" @click="openAdd" class="inline-flex items-center px-4 py-2.5 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM3 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 019.374 21c-2.331 0-4.512-.645-6.374-1.766z"/></svg>
                        Agregar existente
                    </button>
                    <button @click="openInvite" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                        <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z"/></svg>
                        Invitar nuevo agente
                    </button>
                </div>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ stats.active }} activos</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Administradores</p>
                    <p class="text-2xl font-bold text-purple-600 mt-1">{{ stats.admins }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Agentes</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1">{{ stats.agents }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Visores</p>
                    <p class="text-2xl font-bold text-gray-600 mt-1">{{ stats.viewers }}</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex flex-col md:flex-row gap-3 md:items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar por nombre o email…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>

                    <select v-model="roleFilter" @change="applyFilters" class="px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <option value="">Todos los roles</option>
                        <option value="admin">Administradores</option>
                        <option value="agent">Agentes</option>
                        <option value="viewer">Visores</option>
                    </select>

                    <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1">
                        <button v-for="opt in statusChips" :key="opt.value" @click="setStatus(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                :class="statusFilter === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            {{ opt.label }}
                        </button>
                    </div>

                    <button v-if="hasFilters" @click="clearFilters" class="text-xs text-gray-500 hover:text-gray-700 px-2 transition">
                        Limpiar
                    </button>
                </div>
            </div>

            <!-- Tabla -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100 overflow-hidden">
                <div class="overflow-x-auto">
                    <table v-if="staff.length" class="w-full">
                        <thead class="bg-gray-50">
                            <tr>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Miembro</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider hidden md:table-cell">Email</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Equipo</th>
                                <th class="px-4 py-3 text-left text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Rol</th>
                                <th class="px-4 py-3 text-center text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Activo</th>
                                <th class="px-4 py-3 text-right text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Acciones</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="s in staff" :key="s.id" class="hover:bg-gray-50 transition" :class="{ 'opacity-60': !s.is_active }">
                                <td class="px-4 py-3">
                                    <div class="flex items-center">
                                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold shrink-0">
                                            {{ (s.name || '?')[0].toUpperCase() }}
                                        </div>
                                        <div class="ml-3 min-w-0">
                                            <div class="flex items-center gap-1.5">
                                                <p class="text-sm font-medium text-gray-900 truncate">{{ s.name }}</p>
                                                <span v-if="s.is_me" class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide bg-accent/10 text-accent">Tú</span>
                                            </div>
                                            <p class="text-xs text-gray-500 md:hidden truncate">{{ s.email }}</p>
                                        </div>
                                    </div>
                                </td>
                                <td class="px-4 py-3 text-sm text-gray-500 hidden md:table-cell truncate max-w-xs">{{ s.email }}</td>
                                <td class="px-4 py-3">
                                    <span v-if="s.team" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium text-white" :style="{ backgroundColor: s.team_color }">
                                        {{ s.team }}
                                    </span>
                                    <span v-else class="text-[10px] text-gray-400">Sin equipo</span>
                                </td>
                                <td class="px-4 py-3">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium" :class="roleColors[s.role]">
                                        {{ roleLabels[s.role] }}
                                    </span>
                                </td>
                                <td class="px-4 py-3 text-center">
                                    <button @click="toggleActive(s)" :disabled="s.is_me && s.is_active" :title="s.is_me && s.is_active ? 'No puedes desactivarte a ti mismo' : ''">
                                        <span class="relative inline-block w-9 h-5 rounded-full transition-colors" :class="[s.is_active ? 'bg-emerald-500' : 'bg-gray-300', { 'cursor-not-allowed opacity-50': s.is_me && s.is_active }]">
                                            <span class="absolute top-0.5 w-4 h-4 bg-white rounded-full transition-transform" :class="s.is_active ? 'left-4' : 'left-0.5'"></span>
                                        </span>
                                    </button>
                                </td>
                                <td class="px-4 py-3 text-right">
                                    <div class="flex items-center justify-end gap-1">
                                        <button @click="openEdit(s)" class="p-2 rounded-lg text-blue-500 hover:bg-blue-50 transition" title="Editar">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                                        </button>
                                        <button v-if="!s.is_me" @click="deleteStaff(s)" class="p-2 rounded-lg text-red-500 hover:bg-red-50 transition" title="Quitar del staff">
                                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                    <div v-else class="p-12 text-center text-gray-400">
                        <svg class="w-14 h-14 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                        <p class="text-sm">{{ hasFilters ? 'Ningún miembro coincide con los filtros' : 'Aún no hay staff' }}</p>
                        <button v-if="!hasFilters" @click="openInvite" class="mt-3 text-xs text-accent hover:underline">+ Invitar al primero</button>
                    </div>
                </div>
            </div>

            <!-- Link a equipos -->
            <div class="mt-4 text-center text-xs text-gray-400">
                ¿Necesitas organizar el staff por departamentos?
                <Link :href="route('teams.index')" class="text-accent hover:underline">Gestionar equipos →</Link>
            </div>
        </div>

        <!-- ═══════════════ Modal: Invitar nuevo ═══════════════ -->
        <Teleport to="body">
            <div v-if="showInviteModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showInviteModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Invitar nuevo agente</h3>
                        <p class="text-xs text-gray-500 mb-4">Crea una cuenta nueva y lo agrega al staff de tu workspace.</p>

                        <form @submit.prevent="submitInvite" class="space-y-4">
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                                    <input v-model="inviteForm.name" type="text" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': inviteForm.errors.name }">
                                    <p v-if="inviteForm.errors.name" class="text-red-500 text-xs mt-1">{{ inviteForm.errors.name }}</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                                    <input v-model="inviteForm.email" type="email" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': inviteForm.errors.email }">
                                    <p v-if="inviteForm.errors.email" class="text-red-500 text-xs mt-1">{{ inviteForm.errors.email }}</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Contraseña <span class="text-red-500">*</span></label>
                                    <input v-model="inviteForm.password" type="password" autocomplete="new-password" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': inviteForm.errors.password }">
                                    <p v-if="inviteForm.errors.password" class="text-red-500 text-xs mt-1">{{ inviteForm.errors.password }}</p>
                                    <p v-else class="text-[10px] text-gray-400 mt-1">Mínimo 8 caracteres</p>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Confirmar contraseña <span class="text-red-500">*</span></label>
                                    <input v-model="inviteForm.password_confirmation" type="password" autocomplete="new-password" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Equipo</label>
                                    <select v-model="inviteForm.team_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                        <option value="">Sin equipo</option>
                                        <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-sm font-medium text-gray-700 mb-1">Rol <span class="text-red-500">*</span></label>
                                    <select v-model="inviteForm.role" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                        <option value="admin">Administrador</option>
                                        <option value="agent">Agente</option>
                                        <option value="viewer">Visor</option>
                                    </select>
                                </div>
                            </div>

                            <p class="text-[10px] text-gray-500 bg-gray-50 rounded-lg p-2 italic">{{ roleDescriptions[inviteForm.role] }}</p>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showInviteModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="inviteForm.processing" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ inviteForm.processing ? 'Invitando…' : 'Invitar' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══════════════ Modal: Agregar existente ═══════════════ -->
        <Teleport to="body">
            <div v-if="showAddModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showAddModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Agregar al staff</h3>
                        <p class="text-xs text-gray-500 mb-4">Selecciona un usuario existente que aún no está en el staff.</p>

                        <form @submit.prevent="submitAdd" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Usuario <span class="text-red-500">*</span></label>
                                <select v-model="addForm.user_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': addForm.errors.user_id }">
                                    <option value="">— Selecciona un usuario —</option>
                                    <option v-for="u in availableUsers" :key="u.id" :value="u.id">{{ u.name }} ({{ u.email }})</option>
                                </select>
                                <p v-if="addForm.errors.user_id" class="text-red-500 text-xs mt-1">{{ addForm.errors.user_id }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Equipo</label>
                                <select v-model="addForm.team_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="">Sin equipo</option>
                                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                                <select v-model="addForm.role" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="admin">Administrador</option>
                                    <option value="agent">Agente</option>
                                    <option value="viewer">Visor</option>
                                </select>
                                <p class="text-[10px] text-gray-500 mt-1 italic">{{ roleDescriptions[addForm.role] }}</p>
                            </div>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showAddModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="addForm.processing || !addForm.user_id" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ addForm.processing ? 'Agregando…' : 'Agregar' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══════════════ Modal: Editar ═══════════════ -->
        <Teleport to="body">
            <div v-if="showEditModal && editing" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showEditModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in">
                    <div class="p-6">
                        <div class="flex items-center gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-sm font-bold shrink-0">
                                {{ (editing.name || '?')[0].toUpperCase() }}
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-base font-bold text-gray-900 truncate">{{ editing.name }}</h3>
                                <p class="text-xs text-gray-500 truncate">{{ editing.email }}</p>
                            </div>
                        </div>

                        <form @submit.prevent="saveEdit" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Equipo</label>
                                <select v-model="editForm.team_id" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="">Sin equipo</option>
                                    <option v-for="t in teams" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Rol</label>
                                <select v-model="editForm.role" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="admin">Administrador</option>
                                    <option value="agent">Agente</option>
                                    <option value="viewer">Visor</option>
                                </select>
                                <p class="text-[10px] text-gray-500 mt-1 italic">{{ roleDescriptions[editForm.role] }}</p>
                            </div>

                            <label class="flex items-center cursor-pointer">
                                <input type="checkbox" v-model="editForm.is_active" class="rounded border-gray-300 text-accent focus:ring-accent/30" :disabled="editing.is_me && editForm.is_active">
                                <span class="ml-2 text-sm text-gray-700">Activo</span>
                                <span v-if="editing.is_me" class="ml-2 text-[10px] text-amber-600">(No puedes desactivarte)</span>
                            </label>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showEditModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="editForm.processing" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ editForm.processing ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
