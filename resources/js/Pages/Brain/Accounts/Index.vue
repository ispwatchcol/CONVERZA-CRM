<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = defineProps({
    accounts:       Array,
    filters:        Object,
    internal_users: Array,
});

// ── Filtros ────────────────────────────────────────────────────────────────
const search = ref(props.filters.search ?? '');
const status = ref(props.filters.status ?? '');

let searchTimer = null;
watch([search, status], () => {
    clearTimeout(searchTimer);
    searchTimer = setTimeout(() => {
        router.get(route('brain.accounts.index'), { search: search.value, status: status.value }, { preserveState: true, replace: true });
    }, 300);
});

// ── Modal nueva cuenta ─────────────────────────────────────────────────────
const showCreate = ref(false);

const form = useForm({
    name:               '',
    legal_name:         '',
    ispwatch_tenant_id: '',
    tenant_id:          '',
    status:             'active',
    health:             '',
    owner_user_id:      '',
    contact_name:       '',
    contact_email:      '',
    contact_phone:      '',
    country:            'CO',
    onboarding_at:      '',
    renewal_at:         '',
    notes:              '',
});

function openCreate() { form.reset(); showCreate.value = true; }
function closeCreate() { showCreate.value = false; }

function submitCreate() {
    form.post(route('brain.accounts.store'), {
        onSuccess: () => closeCreate(),
    });
}

// ── UI helpers ─────────────────────────────────────────────────────────────
const statusLabel = {
    prospect:  'Prospecto',
    active:    'Activo',
    past_due:  'En mora',
    suspended: 'Suspendido',
    churned:   'Cancelado',
};
const statusColor = {
    prospect:  'bg-gray-100 text-gray-700',
    active:    'bg-green-100 text-green-700',
    past_due:  'bg-red-100 text-red-700',
    suspended: 'bg-yellow-100 text-yellow-700',
    churned:   'bg-gray-200 text-gray-500',
};
const healthColor = {
    good:     'text-green-500',
    at_risk:  'text-yellow-500',
    critical: 'text-red-500',
};
const healthIcon = {
    good:     '●',
    at_risk:  '◐',
    critical: '○',
};
const productLabel = { ispwatch: 'ispwatch', converza: 'Converza' };

function formatAmount(amount, currency) {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency, minimumFractionDigits: 0 }).format(Number(amount));
}
</script>

<template>
    <AppLayout>
        <div class="p-6 max-w-7xl mx-auto space-y-6">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Cuentas</h1>
                    <p class="text-sm text-gray-500 mt-0.5">{{ accounts.length }} empresa{{ accounts.length !== 1 ? 's' : '' }}</p>
                </div>
                <button @click="openCreate"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-violet-600 text-white text-sm font-medium rounded-lg hover:bg-violet-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nueva cuenta
                </button>
            </div>

            <!-- Filtros -->
            <div class="flex flex-wrap gap-3">
                <input v-model="search" type="search" placeholder="Buscar por nombre, contacto..."
                    class="flex-1 min-w-[220px] px-4 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                <select v-model="status" class="px-4 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500">
                    <option value="">Todos los estados</option>
                    <option value="prospect">Prospecto</option>
                    <option value="active">Activo</option>
                    <option value="past_due">En mora</option>
                    <option value="suspended">Suspendido</option>
                    <option value="churned">Cancelado</option>
                </select>
            </div>

            <!-- Tabla -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div v-if="accounts.length === 0" class="py-16 text-center text-gray-400 text-sm">
                    <svg class="w-14 h-14 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" />
                    </svg>
                    <p>No se encontraron cuentas.</p>
                    <button @click="openCreate" class="mt-3 text-violet-600 hover:underline text-sm">Crear la primera</button>
                </div>
                <table v-else class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Empresa</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Estado</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Productos</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Contacto</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Renovación</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase">Manager</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="a in accounts" :key="a.id" class="hover:bg-gray-50 transition">
                            <td class="px-5 py-3.5">
                                <div class="flex items-center gap-2">
                                    <span v-if="a.health" class="text-sm" :class="healthColor[a.health]" :title="a.health">{{ healthIcon[a.health] }}</span>
                                    <Link :href="route('brain.accounts.show', a.id)"
                                        class="font-semibold text-gray-900 hover:text-violet-700 transition">
                                        {{ a.name }}
                                    </Link>
                                </div>
                                <p v-if="a.contact_name" class="text-xs text-gray-400 mt-0.5 pl-5">{{ a.contact_name }}</p>
                            </td>
                            <td class="px-4 py-3.5">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="statusColor[a.status]">
                                    {{ statusLabel[a.status] }}
                                </span>
                            </td>
                            <td class="px-4 py-3.5">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="p in a.products" :key="p.product"
                                        class="inline-flex items-center gap-1 px-2 py-0.5 rounded-md text-[11px] font-medium bg-violet-50 text-violet-700">
                                        {{ productLabel[p.product] }}
                                        <span class="text-violet-500">{{ formatAmount(p.amount, p.currency) }}</span>
                                    </span>
                                    <span v-if="a.products.length === 0" class="text-gray-400 text-xs">—</span>
                                </div>
                            </td>
                            <td class="px-4 py-3.5 text-gray-500 text-xs">
                                <p v-if="a.contact_email">{{ a.contact_email }}</p>
                                <p v-if="a.contact_phone">{{ a.contact_phone }}</p>
                                <span v-if="!a.contact_email && !a.contact_phone">—</span>
                            </td>
                            <td class="px-4 py-3.5 text-gray-500 text-xs">{{ a.renewal_at ?? '—' }}</td>
                            <td class="px-4 py-3.5 text-gray-500 text-xs">{{ a.owner?.name ?? '—' }}</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- ── Modal nueva cuenta ─────────────────────────────────────────── -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showCreate" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4">
                <div class="absolute inset-0 bg-black/40" @click="closeCreate"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between rounded-t-2xl">
                        <h2 class="text-lg font-bold text-gray-900">Nueva cuenta</h2>
                        <button @click="closeCreate" class="p-2 rounded-lg hover:bg-gray-100 transition text-gray-400">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>

                    <form @submit.prevent="submitCreate" class="p-6 space-y-5">
                        <!-- Nombre -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Nombre comercial *</label>
                                <input v-model="form.name" type="text" placeholder="ISP Alpha SAS" required
                                    class="w-full px-3 py-2 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500"
                                    :class="form.errors.name ? 'border-red-400' : 'border-gray-300'" />
                                <p v-if="form.errors.name" class="text-xs text-red-500 mt-1">{{ form.errors.name }}</p>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Razón social</label>
                                <input v-model="form.legal_name" type="text" placeholder="ISP Alpha S.A.S."
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                        </div>

                        <!-- Vínculos -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">ispwatch Tenant ID</label>
                                <input v-model="form.ispwatch_tenant_id" type="text" placeholder="Ej: 5"
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Tenant Converza ID</label>
                                <input v-model="form.tenant_id" type="text" placeholder="ID interno de tenant"
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                        </div>

                        <!-- Estado / Salud -->
                        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Estado *</label>
                                <select v-model="form.status" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500">
                                    <option value="prospect">Prospecto</option>
                                    <option value="active">Activo</option>
                                    <option value="past_due">En mora</option>
                                    <option value="suspended">Suspendido</option>
                                    <option value="churned">Cancelado</option>
                                </select>
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Salud</label>
                                <select v-model="form.health" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500">
                                    <option value="">Sin definir</option>
                                    <option value="good">Buena</option>
                                    <option value="at_risk">En riesgo</option>
                                    <option value="critical">Crítica</option>
                                </select>
                            </div>
                        </div>

                        <!-- Contacto -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Nombre contacto</label>
                                <input v-model="form.contact_name" type="text" placeholder="Juan Pérez"
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Email</label>
                                <input v-model="form.contact_email" type="email" placeholder="juan@isp.co"
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Teléfono</label>
                                <input v-model="form.contact_phone" type="text" placeholder="573001234567"
                                    class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                        </div>

                        <!-- Fechas / Manager -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Inicio</label>
                                <input v-model="form.onboarding_at" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Renovación</label>
                                <input v-model="form.renewal_at" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500" />
                            </div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Manager</label>
                                <select v-model="form.owner_user_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500">
                                    <option value="">Sin asignar</option>
                                    <option v-for="u in internal_users" :key="u.id" :value="u.id">{{ u.name }}</option>
                                </select>
                            </div>
                        </div>

                        <!-- Notas -->
                        <div>
                            <label class="block text-xs font-semibold text-gray-700 mb-1">Notas internas</label>
                            <textarea v-model="form.notes" rows="3" placeholder="Contexto relevante sobre esta cuenta..."
                                class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-violet-500 resize-none"></textarea>
                        </div>

                        <!-- Acciones -->
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="closeCreate"
                                class="px-4 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                                Cancelar
                            </button>
                            <button type="submit" :disabled="form.processing"
                                class="px-6 py-2 text-sm font-medium text-white bg-violet-600 rounded-lg hover:bg-violet-700 disabled:opacity-60 transition">
                                {{ form.processing ? 'Guardando...' : 'Crear cuenta' }}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>
    </AppLayout>
</template>
