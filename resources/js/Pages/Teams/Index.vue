<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    teams: { type: Array, default: () => [] },
    stats: { type: Object, default: () => ({}) },
});

const presetColors = [
    '#ef4444', '#f97316', '#f59e0b', '#eab308',
    '#84cc16', '#10b981', '#14b8a6', '#06b6d4',
    '#3b82f6', '#6366f1', '#8b5cf6', '#a855f7',
    '#ec4899', '#f43f5e', '#64748b', '#0f172a',
];

// ── Modal create/edit ────────────────────────────────────────────────────────
const showModal = ref(false);
const editing = ref(null);
const form = useForm({ name: '', description: '', color: '#3b82f6' });

const isValidHex = computed(() => /^#[0-9a-fA-F]{6}$/.test(form.color));

function openCreate() {
    editing.value = null;
    form.reset();
    form.color = '#3b82f6';
    showModal.value = true;
}

function openEdit(t) {
    editing.value = t;
    form.name = t.name;
    form.description = t.description || '';
    form.color = t.color;
    showModal.value = true;
    showDetail.value = false;
}

function save() {
    const opts = { preserveScroll: true, onSuccess: () => { showModal.value = false; form.reset(); } };
    if (editing.value) {
        form.put(route('teams.update', editing.value.id), opts);
    } else {
        form.post(route('teams.store'), opts);
    }
}

function deleteTeam(t) {
    const msg = t.staff_members_count > 0
        ? `Este equipo tiene ${t.staff_members_count} ${t.staff_members_count === 1 ? 'miembro' : 'miembros'}. Eliminarlo los deja sin equipo asignado. `
        : '';
    if (!confirm(`${msg}¿Eliminar "${t.name}"?`)) return;
    router.delete(route('teams.destroy', t.id), {
        preserveScroll: true,
        onSuccess: () => { showDetail.value = false; },
    });
}

function loadSamples() {
    if (!confirm('Se van a crear 4 equipos de ejemplo (Soporte, Ventas, Cobranza, Instalación). ¿Continuar?')) return;
    router.post(route('teams.load-samples'), {}, { preserveScroll: true });
}

// ── Modal: detalle (con miembros via AJAX) ───────────────────────────────────
const showDetail = ref(false);
const selected = ref(null);
const detailLoading = ref(false);
const detail = ref(null); // { members, open_conversations, closed_conversations }

async function openDetail(team) {
    selected.value = team;
    showDetail.value = true;
    detailLoading.value = true;
    detail.value = null;
    try {
        const { data } = await axios.get(route('teams.members', team.id));
        detail.value = data;
    } finally {
        detailLoading.value = false;
    }
}

const roleColors = {
    admin:  'bg-purple-100 text-purple-700',
    agent:  'bg-blue-100 text-blue-700',
    viewer: 'bg-gray-100 text-gray-600',
};

const roleLabels = { admin: 'Admin', agent: 'Agente', viewer: 'Visor' };
</script>

<template>
    <Head title="Equipos" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Equipos</h1>
                    <p class="text-sm text-gray-500 mt-1">Organiza tu staff en departamentos (soporte, ventas, cobranza...)</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Nuevo equipo
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Equipos</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Con miembros</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ stats.with_members }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Staff total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.staff_total }}</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Sin equipo</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ stats.unassigned }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">agentes huérfanos</p>
                </div>
            </div>

            <!-- Grid -->
            <div v-if="teams.length" class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4">
                <div v-for="team in teams" :key="team.id" @click="openDetail(team)"
                     class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-all group relative cursor-pointer flex flex-col"
                     :style="{ borderLeftColor: team.color, borderLeftWidth: '4px' }">
                    <div class="flex items-start justify-between mb-3">
                        <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0" :style="{ backgroundColor: team.color + '20' }">
                            <svg class="w-6 h-6" :style="{ color: team.color }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/>
                            </svg>
                        </div>
                        <div class="flex space-x-0.5 opacity-0 group-hover:opacity-100 transition" @click.stop>
                            <button @click="openEdit(team)" class="p-1.5 rounded-lg text-blue-500 hover:bg-blue-50 transition" title="Editar">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                            </button>
                            <button @click="deleteTeam(team)" class="p-1.5 rounded-lg text-red-500 hover:bg-red-50 transition" title="Eliminar">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                            </button>
                        </div>
                    </div>

                    <h3 class="text-base font-semibold text-gray-900">{{ team.name }}</h3>
                    <p class="text-xs text-gray-500 mt-1 line-clamp-2 flex-1">{{ team.description || 'Sin descripción' }}</p>

                    <div class="mt-3 pt-3 border-t border-gray-50 flex items-center justify-between">
                        <div class="flex items-center gap-1 text-xs text-gray-500">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                            <span><span class="font-semibold text-gray-700">{{ team.staff_members_count }}</span> {{ team.staff_members_count === 1 ? 'miembro' : 'miembros' }}</span>
                        </div>
                        <span class="text-[10px] text-accent hover:underline">Ver detalle →</span>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                <h3 class="text-base font-semibold text-gray-700 mb-1">Aún no hay equipos</h3>
                <p class="text-sm text-gray-400 mb-5 max-w-md mx-auto">Los equipos te permiten agrupar agentes por departamento y dirigir conversaciones (ej. "Soporte", "Ventas").</p>
                <div class="flex justify-center gap-2">
                    <button @click="openCreate" class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition">Crear el primero</button>
                    <button @click="loadSamples" class="px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">Cargar 4 ejemplos</button>
                </div>
            </div>

            <!-- Link a staff -->
            <div class="mt-4 text-center text-xs text-gray-400">
                ¿Necesitas agregar gente a los equipos?
                <Link :href="route('staff.index')" class="text-accent hover:underline">Gestionar staff →</Link>
            </div>
        </div>

        <!-- ═══════════════ Modal: Detalle del equipo ═══════════════ -->
        <Teleport to="body">
            <div v-if="showDetail && selected" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showDetail = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[90vh] overflow-y-auto animate-scale-in">
                    <div class="p-6">
                        <!-- Header -->
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-12 h-12 rounded-xl flex items-center justify-center shrink-0" :style="{ backgroundColor: selected.color + '20' }">
                                <svg class="w-6 h-6" :style="{ color: selected.color }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <h3 class="text-lg font-bold text-gray-900 truncate">{{ selected.name }}</h3>
                                <p v-if="selected.description" class="text-xs text-gray-500 mt-0.5">{{ selected.description }}</p>
                            </div>
                            <button @click="showDetail = false" class="p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>

                        <!-- Loading -->
                        <div v-if="detailLoading" class="py-8 text-center text-xs text-gray-400">Cargando miembros…</div>

                        <!-- Detail -->
                        <template v-else-if="detail">
                            <!-- Conversaciones del equipo -->
                            <div class="grid grid-cols-2 gap-3 mb-4">
                                <div class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 text-center">
                                    <p class="text-2xl font-bold text-emerald-600">{{ detail.open_conversations }}</p>
                                    <p class="text-[10px] text-emerald-700 uppercase tracking-wider">Chats abiertos</p>
                                </div>
                                <div class="bg-gray-50 border border-gray-100 rounded-xl p-3 text-center">
                                    <p class="text-2xl font-bold text-gray-600">{{ detail.closed_conversations }}</p>
                                    <p class="text-[10px] text-gray-500 uppercase tracking-wider">Chats cerrados</p>
                                </div>
                            </div>

                            <!-- Miembros -->
                            <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-2">Miembros ({{ detail.members.length }})</p>
                            <div v-if="detail.members.length" class="divide-y divide-gray-50 max-h-72 overflow-y-auto -mx-2">
                                <div v-for="m in detail.members" :key="m.id" class="flex items-center gap-3 px-2 py-2" :class="{ 'opacity-50': !m.is_active }">
                                    <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold shrink-0">
                                        {{ (m.name || '?')[0].toUpperCase() }}
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ m.name }}</p>
                                        <p class="text-[11px] text-gray-500 truncate">{{ m.email }}</p>
                                    </div>
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-medium shrink-0" :class="roleColors[m.role]">{{ roleLabels[m.role] }}</span>
                                </div>
                            </div>
                            <div v-else class="bg-amber-50 border border-amber-100 rounded-xl p-3 text-xs text-amber-800 text-center">
                                Este equipo no tiene miembros aún.
                                <Link :href="route('staff.index')" class="text-amber-900 underline ml-1">Agregar staff</Link>
                            </div>

                            <!-- Acciones -->
                            <div class="flex flex-col sm:flex-row gap-2 mt-5 pt-4 border-t border-gray-100">
                                <button @click="openEdit(selected)" class="flex-1 px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition">
                                    Editar equipo
                                </button>
                                <button @click="deleteTeam(selected)" class="px-4 py-2.5 border border-red-200 text-red-700 rounded-xl text-sm font-medium hover:bg-red-50 transition">
                                    Eliminar
                                </button>
                            </div>
                        </template>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══════════════ Modal: Crear/editar ═══════════════ -->
        <Teleport to="body">
            <div v-if="showModal" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in max-h-[90vh] overflow-y-auto">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">{{ editing ? 'Editar equipo' : 'Nuevo equipo' }}</h3>

                        <!-- Preview -->
                        <div class="bg-gray-50 rounded-xl p-3 mb-4">
                            <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-2">Vista previa</p>
                            <div class="flex items-center gap-2">
                                <div class="w-8 h-8 rounded-lg flex items-center justify-center" :style="{ backgroundColor: (isValidHex ? form.color : '#94a3b8') + '20' }">
                                    <svg class="w-4 h-4" :style="{ color: isValidHex ? form.color : '#94a3b8' }" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z"/></svg>
                                </div>
                                <span class="text-sm font-medium text-gray-900">{{ form.name || 'Mi equipo' }}</span>
                            </div>
                        </div>

                        <form @submit.prevent="save" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                                <input v-model="form.name" type="text" placeholder="Soporte, Ventas..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': form.errors.name }">
                                <p v-if="form.errors.name" class="text-red-500 text-xs mt-1">{{ form.errors.name }}</p>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Descripción</label>
                                <textarea v-model="form.description" rows="2" placeholder="¿Para qué sirve este equipo?" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea>
                            </div>

                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">Color</label>
                                <div class="flex flex-wrap gap-2 mb-2">
                                    <button v-for="c in presetColors" :key="c" type="button" @click="form.color = c"
                                            class="w-7 h-7 rounded-full transition-transform hover:scale-110"
                                            :class="form.color.toLowerCase() === c.toLowerCase() ? 'ring-2 ring-offset-2 ring-gray-400 scale-110' : ''"
                                            :style="{ backgroundColor: c }"></button>
                                </div>
                                <div class="flex items-center gap-2">
                                    <input v-model="form.color" type="color" class="w-10 h-10 rounded-lg cursor-pointer border border-gray-200">
                                    <input v-model="form.color" type="text" placeholder="#3b82f6" maxlength="7" class="flex-1 px-3 py-2 border rounded-lg text-sm font-mono uppercase focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="form.errors.color || (!isValidHex && form.color) ? 'border-red-400' : 'border-gray-200'">
                                </div>
                                <p v-if="form.errors.color" class="text-red-500 text-xs mt-1">{{ form.errors.color }}</p>
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
