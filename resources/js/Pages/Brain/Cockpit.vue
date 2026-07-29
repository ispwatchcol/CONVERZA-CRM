<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link } from '@inertiajs/vue3';

const props = defineProps({
    stats:             Object,
    renewing_soon:     Array,
    overdue_services:  { type: Array, default: () => [] },
    plan_limit_alerts: { type: Array, default: () => [] },
    recent_accounts:   Array,
});

// Semáforo de uso vs tope del plan. Informativo: no bloquea altas en ispwatch.
const usageChip = {
    warn: 'bg-yellow-100 text-yellow-800',
    high: 'bg-orange-100 text-orange-800',
    over: 'bg-red-100 text-red-800',
};
// Solo se listan las dimensiones que ya están apretadas; 'ok'/'unlimited' no molestan.
function tightUsages(a) {
    return [
        { label: 'clientes', unit: 'clientes', u: a.clients },
        { label: 'agentes',  unit: 'agentes',  u: a.agents  },
    ].filter(r => r.u && ['warn', 'high', 'over'].includes(r.u.state));
}

// Convierte un mapa {moneda: monto} a texto "US$88 · $1.200.000" (o "—" si vacío).
function money(map) {
    const entries = Object.entries(map ?? {});
    if (entries.length === 0) return '—';
    return entries.map(([cur, amt]) => formatAmount(amt, cur)).join(' · ');
}

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

            <!-- Finanzas -->
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
                <div class="bg-gradient-to-br from-violet-600 to-violet-700 rounded-xl p-5 text-white shadow-sm">
                    <p class="text-xs uppercase tracking-wider text-violet-100">MRR — ingreso mensual</p>
                    <p class="text-2xl font-bold mt-1">{{ money(stats.mrr) }}</p>
                    <p class="text-xs text-violet-200 mt-1">recurrente de cuentas activas</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Por cobrar</p>
                    <p class="text-2xl font-bold mt-1" :class="Object.keys(stats.pending ?? {}).length ? 'text-red-600' : 'text-gray-900'">{{ money(stats.pending) }}</p>
                    <p class="text-xs text-gray-400 mt-1">saldo de facturas abiertas</p>
                </div>
                <div class="bg-white rounded-xl border border-gray-200 p-5">
                    <p class="text-xs uppercase tracking-wider text-gray-500">Facturas vencidas</p>
                    <p class="text-2xl font-bold mt-1" :class="stats.overdue_count > 0 ? 'text-red-600' : 'text-gray-400'">{{ stats.overdue_count ?? 0 }}</p>
                    <p class="text-xs text-gray-400 mt-1">pasadas de la fecha de pago</p>
                </div>
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

            <!-- Servicios vencidos (candidatos a corte) -->
            <div v-if="overdue_services.length > 0" class="bg-red-50 border border-red-200 rounded-xl p-5">
                <h2 class="text-sm font-semibold text-red-800 mb-3 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" /></svg>
                    Servicios vencidos — candidatos a corte ({{ overdue_services.length }})
                </h2>
                <div class="space-y-2">
                    <div v-for="a in overdue_services" :key="a.id"
                        class="flex items-center justify-between bg-white rounded-lg px-4 py-2.5 border border-red-100">
                        <div>
                            <Link :href="route('brain.accounts.show', a.id)" class="font-medium text-gray-900 hover:text-violet-700 transition text-sm">{{ a.name }}</Link>
                            <p class="text-xs text-red-700 mt-0.5">
                                <span v-for="(p, i) in a.products" :key="p.product">
                                    <span class="capitalize">{{ productLabel[p.product] }}</span> venció hace {{ p.days_over }} d{{ i < a.products.length - 1 ? ' · ' : '' }}
                                </span>
                            </p>
                        </div>
                        <Link :href="route('brain.accounts.show', a.id)" class="text-xs font-medium text-red-700 bg-red-50 border border-red-200 px-3 py-1.5 rounded-lg hover:bg-red-100 transition">Revisar</Link>
                    </div>
                </div>
            </div>

            <!-- Cerca del tope del plan (oportunidad de upgrade) -->
            <div v-if="plan_limit_alerts.length > 0" class="bg-orange-50 border border-orange-200 rounded-xl p-5">
                <h2 class="text-sm font-semibold text-orange-900 mb-1 flex items-center gap-2">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                    Cerca del tope del plan ({{ plan_limit_alerts.length }})
                </h2>
                <p class="text-xs text-orange-700/80 mb-3">Momento de ofrecer el upgrade. El tope de clientes no se bloquea desde acá: ispwatch se lee en modo solo lectura.</p>
                <div class="space-y-2">
                    <div v-for="a in plan_limit_alerts" :key="a.id"
                        class="flex items-center justify-between gap-3 bg-white rounded-lg px-4 py-2.5 border border-orange-100">
                        <div class="min-w-0">
                            <Link :href="route('brain.accounts.show', a.id)" class="font-medium text-gray-900 hover:text-violet-700 transition text-sm">{{ a.name }}</Link>
                            <p class="text-xs text-gray-600 mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1">
                                <span v-for="r in tightUsages(a)" :key="r.label" class="inline-flex items-center gap-1">
                                    <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded" :class="usageChip[r.u.state]">{{ r.u.pct }}%</span>
                                    {{ r.u.used }}/{{ r.u.limit }} {{ r.unit }}
                                    <span class="text-gray-400">· {{ r.u.plan_name }}</span>
                                </span>
                            </p>
                        </div>
                        <Link :href="route('brain.accounts.show', a.id)" class="shrink-0 text-xs font-medium text-orange-800 bg-orange-50 border border-orange-200 px-3 py-1.5 rounded-lg hover:bg-orange-100 transition">Ver cuenta</Link>
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
