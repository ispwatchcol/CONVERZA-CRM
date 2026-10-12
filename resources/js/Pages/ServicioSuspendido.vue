<script setup>
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// La pinta EnsureServicioActivo en lugar de cualquier pantalla del panel cuando
// la cuenta del ISP está suspendida. Sin AppLayout a propósito: su menú lleva a
// pantallas que no se pueden abrir.
const props = defineProps({
    motivo:             { type: String, default: null },
    desde:              { type: String, default: null },
    facturas:           { type: Array, default: () => [] },
    instrucciones:      { type: String, default: '' },
    puede_abrir_ticket: { type: Boolean, default: false },
});

const page = usePage();
const tenantName = computed(() => page.props.tenant?.name ?? page.props.auth?.user?.tenant_name ?? 'tu workspace');

// Mismo número de soporte que el botón flotante del panel (AppLayout).
const SUPPORT_PHONE = '573125759381';
const whatsappLink = computed(() => {
    const texto = `¡Hola! Escribo del workspace "${tenantName.value}" en Converza: el servicio aparece suspendido y quiero ponerme al día.`;
    return `https://wa.me/${SUPPORT_PHONE}?text=${encodeURIComponent(texto)}`;
});

const porFactura = computed(() => props.motivo === 'billing');

function formatAmount(amount, currency) {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency, minimumFractionDigits: 0, maximumFractionDigits: 2 }).format(Number(amount));
}
function fmtDate(d) { return d ? new Date(d.length === 10 ? d + 'T00:00:00' : d).toLocaleDateString('es-CO') : '—'; }
function logout() { router.post(route('logout')); }
</script>

<template>
    <Head title="Servicio suspendido" />
    <div class="min-h-screen bg-gray-50 flex items-center justify-center px-4 py-12">
        <div class="w-full max-w-lg bg-white rounded-2xl border border-gray-200 shadow-sm p-8">
            <div class="w-12 h-12 rounded-full bg-amber-100 text-amber-700 flex items-center justify-center">
                <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
            </div>

            <h1 class="text-xl font-bold text-gray-900 mt-4">El servicio de Converza está suspendido</h1>
            <p class="text-sm text-gray-600 mt-2">
                <template v-if="porFactura">Hay una factura vencida sin pagar. En cuanto se registre el pago, el panel se reactiva solo.</template>
                <template v-else>Comunícate con soporte de Converza para reactivarlo.</template>
            </p>
            <p class="text-sm text-gray-600 mt-2">
                Tus clientes no se ven afectados: los mensajes que te escriben se siguen guardando y los vas a encontrar al volver.
            </p>

            <div v-if="facturas.length" class="mt-6 rounded-xl border border-gray-200 divide-y divide-gray-100">
                <div v-for="f in facturas" :key="f.number" class="flex items-center justify-between gap-4 px-4 py-3">
                    <div class="min-w-0">
                        <p class="text-sm font-medium text-gray-900 truncate">{{ f.concept }}</p>
                        <p class="text-xs" :class="f.overdue ? 'text-red-600' : 'text-gray-500'">
                            Factura {{ f.number }} · {{ f.overdue ? 'venció' : 'vence' }} el {{ fmtDate(f.due_at) }}
                        </p>
                    </div>
                    <span class="font-bold text-gray-900 shrink-0">{{ formatAmount(f.balance_due, f.currency) }}</span>
                </div>
            </div>

            <div v-if="instrucciones" class="mt-6 rounded-xl bg-amber-50 border border-amber-200 px-4 py-3">
                <p class="text-xs font-semibold text-amber-800 uppercase tracking-wider">Cómo pagar</p>
                <p class="text-sm text-amber-900 mt-1 whitespace-pre-line">{{ instrucciones }}</p>
            </div>

            <div class="mt-6 flex flex-wrap gap-3">
                <a :href="whatsappLink" target="_blank" rel="noopener"
                    class="inline-flex items-center gap-2 px-4 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 transition">
                    Escribir a soporte por WhatsApp
                </a>
                <Link v-if="puede_abrir_ticket" :href="route('support.index')"
                    class="px-4 py-2 text-sm font-medium text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">
                    Abrir un ticket
                </Link>
                <button @click="logout" class="px-4 py-2 text-sm text-gray-500 hover:text-gray-800 transition">Cerrar sesión</button>
            </div>

            <p v-if="desde" class="text-xs text-gray-400 mt-6">Suspendido desde el {{ fmtDate(desde) }}.</p>
        </div>
    </div>
</template>
