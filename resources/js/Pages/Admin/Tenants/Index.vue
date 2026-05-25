<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Head } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    tenants: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({}) },
    stats:   { type: Object, default: () => ({}) },
});

// ── Filtros ──────────────────────────────────────────────────────────────────
const search = ref(props.filters?.search || '');

function applyFilters() {
    router.get(route('admin.tenants.index'), {
        search: search.value || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

function clearFilters() {
    search.value = '';
    applyFilters();
}

// ── Modal: crear tenant + admin ──────────────────────────────────────────────
const showCreateModal = ref(false);
const createForm = useForm({
    name: '',
    slug: '',
    ispwatch_tenant_id: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',
});

const slugSuggestion = computed(() => {
    return (createForm.name || '')
        .toLowerCase()
        .normalize('NFD').replace(/[̀-ͯ]/g, '') // quita acentos combinantes
        .replace(/[^a-z0-9-]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .substring(0, 60);
});

// URL pública para el blue-info-box (window no está disponible en templates Vue).
const publicUrl = typeof window !== 'undefined' ? window.location.origin : '';

function openCreate() {
    createForm.reset();
    showCreateModal.value = true;
}

function autoFillSlug() {
    if (!createForm.slug || createForm.slug === slugSuggestion.value) {
        createForm.slug = slugSuggestion.value;
    }
}

function submitCreate() {
    createForm.post(route('admin.tenants.store'), {
        preserveScroll: true,
        onSuccess: () => { showCreateModal.value = false; createForm.reset(); },
    });
}

// ── Modal: editar ────────────────────────────────────────────────────────────
const showEditModal = ref(false);
const editing = ref(null);
const editForm = useForm({ name: '', slug: '', is_active: true, ispwatch_tenant_id: '' });

function openEdit(t) {
    editing.value = t;
    editForm.name = t.name;
    editForm.slug = t.slug;
    editForm.is_active = t.is_active;
    editForm.ispwatch_tenant_id = t.ispwatch_tenant_id || '';
    showEditModal.value = true;
}

function saveEdit() {
    editForm.put(route('admin.tenants.update', editing.value.id), {
        preserveScroll: true,
        onSuccess: () => { showEditModal.value = false; editing.value = null; },
    });
}

function deactivateTenant(t) {
    if (!confirm(`¿Desactivar el tenant "${t.name}"? Los usuarios perderán acceso pero los datos quedan intactos para auditoría.`)) return;
    router.delete(route('admin.tenants.destroy', t.id), { preserveScroll: true });
}

// ── Helpers ──────────────────────────────────────────────────────────────────
function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(iso));
}
</script>

<template>
    <Head title="Tenants — SaaS Admin" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <div class="inline-flex items-center gap-2 px-2 py-0.5 rounded-full bg-amber-500/20 text-amber-700 text-[10px] font-bold uppercase tracking-wider mb-1">
                        <span class="w-1.5 h-1.5 rounded-full bg-amber-500"></span>
                        SaaS Admin
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900">Tenants</h1>
                    <p class="text-sm text-gray-500 mt-1">Workspaces de clientes en Converza</p>
                </div>
                <button @click="openCreate" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Crear tenant
                </button>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Total</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ stats.active }} activos</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">WhatsApp</p>
                    <p class="text-2xl font-bold text-emerald-600 mt-1">{{ stats.with_whatsapp }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">configurados</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">ISPWatch</p>
                    <p class="text-2xl font-bold text-blue-600 mt-1">{{ stats.linked_to_ispwatch }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">vinculados</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Sin onboardear</p>
                    <p class="text-2xl font-bold text-amber-600 mt-1">{{ stats.total - stats.with_whatsapp }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">falta WhatsApp</p>
                </div>
            </div>

            <!-- Filtros -->
            <div class="bg-white rounded-2xl p-4 mb-4 shadow-sm border border-gray-100">
                <div class="flex gap-3 items-center">
                    <div class="relative flex-1">
                        <input v-model="search" @keyup.enter="applyFilters" type="text" placeholder="Buscar por nombre o slug…" class="w-full pl-9 pr-4 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                        <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"/></svg>
                    </div>
                    <button v-if="search" @click="clearFilters" class="text-xs text-gray-500 hover:text-gray-700 px-2 transition">Limpiar</button>
                </div>
            </div>

            <!-- Lista de tenants (cards) -->
            <div v-if="tenants.length" class="grid grid-cols-1 lg:grid-cols-2 gap-4">
                <div v-for="t in tenants" :key="t.id"
                     class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-all group flex flex-col"
                     :class="{ 'opacity-60': !t.is_active }">
                    <!-- Top: name + status -->
                    <div class="flex items-start justify-between gap-3 mb-3">
                        <div class="min-w-0">
                            <h3 class="text-base font-bold text-gray-900 truncate">{{ t.name }}</h3>
                            <p class="text-xs text-gray-500 font-mono">{{ t.slug }}</p>
                        </div>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide shrink-0"
                              :class="t.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-200 text-gray-600'">
                            {{ t.is_active ? 'Activo' : 'Inactivo' }}
                        </span>
                    </div>

                    <!-- Integraciones -->
                    <div class="flex flex-wrap gap-2 mb-3">
                        <span class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium"
                              :class="t.has_whatsapp ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-500'">
                            <span class="w-1.5 h-1.5 rounded-full" :class="t.has_whatsapp ? 'bg-green-500' : 'bg-gray-400'"></span>
                            WhatsApp {{ t.has_whatsapp ? '✓' : '✗' }}
                        </span>
                        <span v-if="t.ispwatch_status" class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium"
                              :class="t.ispwatch_status.connected ? 'bg-blue-100 text-blue-700' : 'bg-red-100 text-red-700'">
                            <span class="w-1.5 h-1.5 rounded-full" :class="t.ispwatch_status.connected ? 'bg-blue-500' : 'bg-red-500'"></span>
                            <template v-if="t.ispwatch_status.connected">
                                ISPWatch · {{ t.ispwatch_status.name }}
                            </template>
                            <template v-else>
                                ISPWatch roto
                            </template>
                        </span>
                        <span v-else class="inline-flex items-center gap-1 px-2 py-0.5 rounded-full text-[10px] font-medium bg-gray-100 text-gray-500">
                            <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                            Sin ISPWatch
                        </span>
                    </div>

                    <!-- Stats del tenant -->
                    <div class="grid grid-cols-3 gap-2 bg-gray-50 rounded-xl p-2 text-center mb-3">
                        <div>
                            <p class="text-sm font-bold text-gray-900">{{ t.users_count }}</p>
                            <p class="text-[9px] text-gray-500 uppercase tracking-wider">Users</p>
                        </div>
                        <div class="border-x border-gray-200">
                            <p class="text-sm font-bold text-gray-900">{{ t.contacts_count }}</p>
                            <p class="text-[9px] text-gray-500 uppercase tracking-wider">Contactos</p>
                        </div>
                        <div>
                            <p class="text-sm font-bold text-gray-900">{{ t.conversations_count }}</p>
                            <p class="text-[9px] text-gray-500 uppercase tracking-wider">Chats</p>
                        </div>
                    </div>

                    <!-- Footer -->
                    <div class="mt-auto pt-3 border-t border-gray-50 flex items-center justify-between">
                        <span class="text-[10px] text-gray-400">Creado {{ formatDate(t.created_at) }}</span>
                        <div class="flex gap-1">
                            <button @click="openEdit(t)" class="px-3 py-1.5 border border-gray-200 text-gray-700 rounded-lg text-xs hover:bg-gray-50 transition">
                                Editar
                            </button>
                            <button v-if="t.is_active" @click="deactivateTenant(t)" class="px-3 py-1.5 border border-red-200 text-red-700 rounded-lg text-xs hover:bg-red-50 transition">
                                Desactivar
                            </button>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 21h19.5m-18-18v18m10.5-18v18m6-13.5V21M6.75 6.75h.75m-.75 3h.75m-.75 3h.75m3-6h.75m-.75 3h.75m-.75 3h.75M6.75 21v-3.375c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125V21M3 3h12m-.75 4.5H21m-3.75 3.75h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008zm0 3h.008v.008h-.008v-.008z"/></svg>
                <h3 class="text-base font-semibold text-gray-700 mb-1">
                    {{ search ? 'Ningún tenant coincide con la búsqueda' : 'Aún no hay tenants' }}
                </h3>
                <p class="text-sm text-gray-400 mb-5">{{ search ? 'Limpia la búsqueda para ver todos.' : 'Crea el primer tenant para empezar a onboardear clientes en Converza.' }}</p>
                <button v-if="!search" @click="openCreate" class="px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition">
                    Crear el primero
                </button>
                <button v-else @click="clearFilters" class="text-xs text-accent hover:underline">Limpiar búsqueda</button>
            </div>
        </div>

        <!-- ═══════════════ Modal: Crear tenant + admin ═══════════════ -->
        <Teleport to="body">
            <div v-if="showCreateModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="showCreateModal = false"></div>
                <div class="relative bg-white border border-gray-200 rounded-2xl shadow-2xl w-full max-w-md animate-scale-in max-h-[92vh] overflow-y-auto">

                    <!-- Header con icono + gradient sutil -->
                    <div class="px-6 pt-6 pb-4 border-b border-gray-100">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-amber-400 to-amber-500 flex items-center justify-center shadow-md shrink-0">
                                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6m0 0v6m0-6h6m-6 0H6"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-bold text-gray-900">Nuevo tenant</h3>
                                <p class="text-xs text-gray-500 mt-0.5">Workspace + admin inicial en una sola operación</p>
                            </div>
                            <button @click="showCreateModal = false" class="p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="submitCreate" class="px-6 py-5 space-y-5">
                        <!-- ── Sección 1: Workspace ── -->
                        <section>
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-6 h-6 rounded-full bg-accent/10 flex items-center justify-center text-[11px] font-bold text-accent shrink-0">1</div>
                                <h4 class="text-sm font-semibold text-gray-900">Workspace</h4>
                            </div>

                            <div class="space-y-3 pl-8">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Nombre comercial <span class="text-red-500">*</span></label>
                                    <input v-model="createForm.name" @blur="autoFillSlug" type="text" placeholder="ISP Acme"
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition"
                                           :class="createForm.errors.name ? 'border-red-400' : 'border-gray-200'">
                                    <p v-if="createForm.errors.name" class="text-red-500 text-[11px] mt-1">{{ createForm.errors.name }}</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Slug <span class="text-red-500">*</span></label>
                                    <div class="flex items-center border rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-accent/30 focus-within:border-accent transition"
                                         :class="createForm.errors.slug ? 'border-red-400' : 'border-gray-200'">
                                        <span class="px-3 text-gray-400 text-xs font-mono select-none">/</span>
                                        <input v-model="createForm.slug" type="text" placeholder="isp-acme"
                                               class="flex-1 px-1 py-2 border-0 text-sm font-mono focus:ring-0 bg-transparent">
                                    </div>
                                    <p v-if="createForm.errors.slug" class="text-red-500 text-[11px] mt-1">{{ createForm.errors.slug }}</p>
                                    <p v-else class="text-[10px] text-gray-400 mt-1">Auto-generado del nombre. Solo `a-z 0-9 -`</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">
                                        ID en ISPWatch
                                        <span class="text-gray-400 font-normal ml-1">opcional</span>
                                    </label>
                                    <input v-model="createForm.ispwatch_tenant_id" type="number" min="1" placeholder="ej. 17"
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition"
                                           :class="createForm.errors.ispwatch_tenant_id ? 'border-red-400' : 'border-gray-200'">
                                    <p v-if="createForm.errors.ispwatch_tenant_id" class="text-red-500 text-[11px] mt-1">{{ createForm.errors.ispwatch_tenant_id }}</p>
                                    <p v-else class="text-[10px] text-gray-400 mt-1">Se valida en vivo contra ISPWatch</p>
                                </div>
                            </div>
                        </section>

                        <!-- ── Sección 2: Admin ── -->
                        <section>
                            <div class="flex items-center gap-2 mb-3">
                                <div class="w-6 h-6 rounded-full bg-accent/10 flex items-center justify-center text-[11px] font-bold text-accent shrink-0">2</div>
                                <h4 class="text-sm font-semibold text-gray-900">Administrador inicial</h4>
                            </div>

                            <div class="space-y-3 pl-8">
                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Nombre <span class="text-red-500">*</span></label>
                                    <input v-model="createForm.admin_name" type="text" placeholder="Pedro Pérez"
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition"
                                           :class="createForm.errors.admin_name ? 'border-red-400' : 'border-gray-200'">
                                    <p v-if="createForm.errors.admin_name" class="text-red-500 text-[11px] mt-1">{{ createForm.errors.admin_name }}</p>
                                </div>

                                <div>
                                    <label class="block text-xs font-medium text-gray-700 mb-1">Email <span class="text-red-500">*</span></label>
                                    <input v-model="createForm.admin_email" type="email" placeholder="admin@empresa.com"
                                           class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition"
                                           :class="createForm.errors.admin_email ? 'border-red-400' : 'border-gray-200'">
                                    <p v-if="createForm.errors.admin_email" class="text-red-500 text-[11px] mt-1">{{ createForm.errors.admin_email }}</p>
                                </div>

                                <div class="grid grid-cols-2 gap-2">
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Contraseña <span class="text-red-500">*</span></label>
                                        <input v-model="createForm.admin_password" type="password" autocomplete="new-password" placeholder="••••••••"
                                               class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition"
                                               :class="createForm.errors.admin_password ? 'border-red-400' : 'border-gray-200'">
                                    </div>
                                    <div>
                                        <label class="block text-xs font-medium text-gray-700 mb-1">Confirmar <span class="text-red-500">*</span></label>
                                        <input v-model="createForm.admin_password_confirmation" type="password" autocomplete="new-password" placeholder="••••••••"
                                               class="w-full px-3 py-2 border border-gray-200 rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition">
                                    </div>
                                </div>
                                <p v-if="createForm.errors.admin_password" class="text-red-500 text-[11px] -mt-1">{{ createForm.errors.admin_password }}</p>
                                <p v-else class="text-[10px] text-gray-400 -mt-1">Mín 8 caracteres. Comparte por canal seguro.</p>
                            </div>
                        </section>

                        <!-- Info box compacto -->
                        <div class="bg-blue-50 border-l-4 border-blue-400 rounded-lg p-3 text-[11px] text-blue-900 leading-relaxed">
                            <svg class="w-4 h-4 inline mr-1 -mt-0.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                            El admin entrará a <span class="font-mono font-semibold">{{ publicUrl || 'tu URL' }}</span> con su email/contraseña y desde <strong>Configuración</strong> conectará su WhatsApp.
                        </div>
                    </form>

                    <!-- Footer fijo -->
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-2 rounded-b-2xl">
                        <button type="button" @click="showCreateModal = false" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-200 rounded-lg transition">Cancelar</button>
                        <button @click="submitCreate" :disabled="createForm.processing" class="inline-flex items-center px-5 py-2 bg-accent text-white rounded-lg text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50 shadow-sm">
                            <svg v-if="createForm.processing" class="animate-spin w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            {{ createForm.processing ? 'Creando…' : 'Crear tenant' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- ═══════════════ Modal: Editar ═══════════════ -->
        <Teleport to="body">
            <div v-if="showEditModal && editing" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/60 backdrop-blur-sm" @click="showEditModal = false"></div>
                <div class="relative bg-white border border-gray-200 rounded-2xl shadow-2xl w-full max-w-md animate-scale-in max-h-[92vh] overflow-y-auto">

                    <!-- Header -->
                    <div class="px-6 pt-6 pb-4 border-b border-gray-100">
                        <div class="flex items-start gap-3">
                            <div class="w-10 h-10 rounded-xl bg-gradient-to-br from-blue-400 to-blue-500 flex items-center justify-center shadow-md shrink-0">
                                <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z"/></svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-bold text-gray-900 truncate">{{ editing.name }}</h3>
                                <p class="text-xs text-gray-500 mt-0.5 font-mono">#{{ editing.id }} · /{{ editing.slug }}</p>
                            </div>
                            <button @click="showEditModal = false" class="p-1 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                            </button>
                        </div>
                    </div>

                    <form @submit.prevent="saveEdit" class="px-6 py-5 space-y-4">
                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Nombre comercial <span class="text-red-500">*</span></label>
                            <input v-model="editForm.name" type="text"
                                   class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition"
                                   :class="editForm.errors.name ? 'border-red-400' : 'border-gray-200'">
                            <p v-if="editForm.errors.name" class="text-red-500 text-[11px] mt-1">{{ editForm.errors.name }}</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">Slug <span class="text-red-500">*</span></label>
                            <div class="flex items-center border rounded-lg overflow-hidden focus-within:ring-2 focus-within:ring-accent/30 focus-within:border-accent transition"
                                 :class="editForm.errors.slug ? 'border-red-400' : 'border-gray-200'">
                                <span class="px-3 text-gray-400 text-xs font-mono select-none">/</span>
                                <input v-model="editForm.slug" type="text" class="flex-1 px-1 py-2 border-0 text-sm font-mono focus:ring-0 bg-transparent">
                            </div>
                            <p v-if="editForm.errors.slug" class="text-red-500 text-[11px] mt-1">{{ editForm.errors.slug }}</p>
                            <p v-else class="text-[10px] text-amber-600 mt-1">⚠ Cambiar el slug puede romper links externos guardados.</p>
                        </div>

                        <div>
                            <label class="block text-xs font-medium text-gray-700 mb-1">
                                ID en ISPWatch
                                <span class="text-gray-400 font-normal ml-1">opcional</span>
                            </label>
                            <input v-model="editForm.ispwatch_tenant_id" type="number" min="1" placeholder="ej. 17"
                                   class="w-full px-3 py-2 border rounded-lg text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent transition"
                                   :class="editForm.errors.ispwatch_tenant_id ? 'border-red-400' : 'border-gray-200'">
                            <p v-if="editForm.errors.ispwatch_tenant_id" class="text-red-500 text-[11px] mt-1">{{ editForm.errors.ispwatch_tenant_id }}</p>
                            <p v-else class="text-[10px] text-gray-400 mt-1">Se valida en vivo si lo cambias</p>
                        </div>

                        <!-- Toggle activo destacado -->
                        <div class="bg-gray-50 border border-gray-100 rounded-lg p-3 flex items-center justify-between">
                            <div class="min-w-0">
                                <p class="text-sm font-medium text-gray-900">Tenant activo</p>
                                <p class="text-[11px] text-gray-500 mt-0.5">Si lo desactivas, sus usuarios pierden acceso</p>
                            </div>
                            <button type="button" @click="editForm.is_active = !editForm.is_active"
                                    class="relative inline-block w-11 h-6 rounded-full transition-colors shrink-0"
                                    :class="editForm.is_active ? 'bg-emerald-500' : 'bg-gray-300'">
                                <span class="absolute top-0.5 w-5 h-5 bg-white rounded-full transition-transform shadow"
                                      :class="editForm.is_active ? 'left-5' : 'left-0.5'"></span>
                            </button>
                        </div>
                    </form>

                    <!-- Footer fijo -->
                    <div class="px-6 py-4 bg-gray-50 border-t border-gray-100 flex justify-end gap-2 rounded-b-2xl">
                        <button type="button" @click="showEditModal = false" class="px-4 py-2 text-sm text-gray-600 hover:bg-gray-200 rounded-lg transition">Cancelar</button>
                        <button @click="saveEdit" :disabled="editForm.processing" class="inline-flex items-center px-5 py-2 bg-accent text-white rounded-lg text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50 shadow-sm">
                            <svg v-if="editForm.processing" class="animate-spin w-3.5 h-3.5 mr-1.5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            {{ editForm.processing ? 'Guardando…' : 'Guardar cambios' }}
                        </button>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
