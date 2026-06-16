<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    stats:            Object,
    renewing_soon:    Array,
    recent_accounts:  Array,
});

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

const productLabel = { ispwatch: 'ispwatch', converza: 'Converza' };

function formatAmount(amount, currency) {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency, minimumFractionDigits: 0 }).format(Number(amount));
}
</script>

<template>
    <AppLayout>
        <div class="p-6 max-w-7xl mx-auto space-y-8">

            <!-- Header -->
            <div class="flex items-center justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Core Brain</h1>
                    <p class="text-sm text-gray-500 mt-0.5">Centro de mando interno</p>
                </div>
                <Link :href="route('brain.accounts.index')"
                    class="inline-flex items-center gap-2 px-4 py-2 bg-violet-600 text-white text-sm font-medium rounded-lg hover:bg-violet-700 transition">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                    Nueva cuenta
                </Link>
            </div>

            <!-- Stats -->
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-4">
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Total</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ stats.total }}</p>
                    <p class="text-xs text-gray-400 mt-1">cuentas</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Activas</p>
                    <p class="text-3xl font-bold text-green-600 mt-1">{{ stats.active }}</p>
                    <p class="text-xs text-gray-400 mt-1">al día</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Con Converza</p>
                    <p class="text-3xl font-bold text-violet-600 mt-1">{{ stats.with_converza }}</p>
                    <p class="text-xs text-gray-400 mt-1">workspaces</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">Con ispwatch</p>
                    <p class="text-3xl font-bold text-blue-600 mt-1">{{ stats.with_ispwatch }}</p>
                    <p class="text-xs text-gray-400 mt-1">tenants</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-4">
                    <p class="text-xs text-gray-500 uppercase tracking-wider">En mora</p>
                    <p class="text-3xl font-bold mt-1" :class="stats.at_risk > 0 ? 'text-red-600' : 'text-gray-400'">{{ stats.at_risk }}</p>
                    <p class="text-xs text-gray-400 mt-1">past_due / suspended</p>
                </div>
            </div>

            <!-- Renovaciones próximas -->
            <div v-if="renewing_soon.length > 0" class="bg-amber-50 border border-amber-200 rounded-xl p-5">
                <h2 class="text-sm font-semibold text-amber-800 mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    Renovaciones en los próximos 30 días ({{ renewing_soon.length }})
                </h2>
                <div class="space-y-2">
                    <div v-for="a in renewing_soon" :key="a.id"
                        class="flex items-center justify-between bg-white rounded-lg px-4 py-2.5 border border-amber-100">
                        <div>
                            <Link :href="route('brain.accounts.show', a.id)" class="font-medium text-gray-900 hover:text-violet-700 transition text-sm">{{ a.name }}</Link>
                            <p class="text-xs text-amber-700 mt-0.5">Vence {{ a.renewal_at }}</p>
                        </div>
                        <div class="text-right">
                            <p v-for="p in a.products" :key="p.product" class="text-xs text-gray-600">
                                <span class="capitalize">{{ productLabel[p.product] }}</span>
                                <span class="font-semibold text-gray-900 ml-1">{{ formatAmount(p.amount, p.currency) }}</span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Cuentas recientes -->
            <div class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                <div class="flex items-center justify-between px-5 py-4 border-b border-gray-100">
                    <h2 class="text-sm font-semibold text-gray-900">Cuentas recientes</h2>
                    <Link :href="route('brain.accounts.index')" class="text-xs text-violet-600 hover:underline">Ver todas</Link>
                </div>

                <div v-if="recent_accounts.length === 0" class="px-5 py-12 text-center text-gray-400 text-sm">
                    <svg class="w-12 h-12 mx-auto mb-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 21h16.5M4.5 3h15M5.25 3v18m13.5-18v18M9 6.75h1.5m-1.5 3h1.5m-1.5 3h1.5m3-6H15m-1.5 3H15m-1.5 3H15M9 21v-3.375c0-.621.504-1.125 1.125-1.125h3.75c.621 0 1.125.504 1.125 1.125V21" /></svg>
                    No hay cuentas aún.
                    <Link :href="route('brain.accounts.index')" class="block mt-2 text-violet-600 hover:underline">Crear la primera</Link>
                </div>

                <table v-else class="min-w-full divide-y divide-gray-100 text-sm">
                    <thead class="bg-gray-50">
                        <tr>
                            <th class="px-5 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Empresa</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Estado</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Productos</th>
                            <th class="px-4 py-3 text-left text-xs font-semibold text-gray-500 uppercase tracking-wider">Renovación</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-50">
                        <tr v-for="a in recent_accounts" :key="a.id" class="hover:bg-gray-50 transition">
                            <td class="px-5 py-3">
                                <Link :href="route('brain.accounts.show', a.id)" class="font-medium text-gray-900 hover:text-violet-700 transition">{{ a.name }}</Link>
                            </td>
                            <td class="px-4 py-3">
                                <span class="inline-flex items-center px-2 py-0.5 rounded-full text-xs font-medium" :class="statusColor[a.status]">
                                    {{ statusLabel[a.status] }}
                                </span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="flex flex-wrap gap-1">
                                    <span v-for="p in a.products" :key="p.product"
                                        class="inline-flex items-center px-1.5 py-0.5 rounded text-[11px] font-medium bg-violet-50 text-violet-700">
                                        {{ productLabel[p.product] }}
                                    </span>
                                </div>
                            </td>
                            <td class="px-4 py-3 text-gray-500 text-xs">
                                {{ a.renewal_at ?? '—' }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>
    </AppLayout>
</template>
