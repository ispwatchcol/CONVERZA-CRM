<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    campaign: { type: Object, required: true },
    recipients: { type: Object, default: () => ({ data: [], links: [] }) },
    steps: { type: Array, default: () => [] },
    filters: { type: Object, default: () => ({ status: '' }) },
});

// ── Auto-refresco mientras está enviando ────────────────────────────────────
let pollTimer = null;
onMounted(() => {
    if (props.campaign.status === 'sending') {
        pollTimer = setInterval(() => {
            router.reload({ only: ['campaign', 'recipients', 'steps'], preserveScroll: true, preserveState: true });
        }, 6000);
    }
});
onUnmounted(() => { if (pollTimer) clearInterval(pollTimer); });

// ── Helpers de estado ────────────────────────────────────────────────────────
function statusBadge(status) {
    return {
        draft: 'bg-gray-100 text-gray-600',
        scheduled: 'bg-blue-100 text-blue-700',
        sending: 'bg-amber-100 text-amber-700',
        paused: 'bg-orange-100 text-orange-700',
        completed: 'bg-emerald-100 text-emerald-700',
        cancelled: 'bg-gray-100 text-gray-500',
        failed: 'bg-red-100 text-red-700',
    }[status] || 'bg-gray-100 text-gray-600';
}

function statusLabel(status) {
    return {
        draft: 'Borrador', scheduled: 'Agendada', sending: 'Enviando', paused: 'Pausada',
        completed: 'Completada', cancelled: 'Cancelada', failed: 'Fallida',
        pending: 'Pendiente', queued: 'En cola', sent: 'Enviado', delivered: 'Entregado',
        read: 'Leído', skipped: 'Saltado', opted_out: 'De baja',
    }[status] || status;
}

function recipientBadge(status) {
    return {
        pending: 'bg-gray-100 text-gray-600',
        queued: 'bg-blue-100 text-blue-700',
        sent: 'bg-amber-100 text-amber-700',
        delivered: 'bg-cyan-100 text-cyan-700',
        read: 'bg-emerald-100 text-emerald-700',
        failed: 'bg-red-100 text-red-700',
        skipped: 'bg-gray-100 text-gray-500',
        opted_out: 'bg-orange-100 text-orange-700',
    }[status] || 'bg-gray-100 text-gray-600';
}

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

function conditionLabel(cond) {
    return {
        always: 'Envío inicial', if_not_replied: 'Si no respondió',
        if_not_read: 'Si no leyó', if_not_delivered: 'Si no se entregó',
    }[cond] || cond;
}

function delayLabel(hours) {
    if (!hours) return 'inmediato';
    const d = Math.floor(hours / 24);
    const h = hours % 24;
    return [d ? `${d}d` : null, h ? `${h}h` : null].filter(Boolean).join(' ') || 'inmediato';
}

const c = computed(() => props.campaign);

const funnel = computed(() => [
    { label: 'Total', value: c.value.total_recipients, color: 'bg-gray-100 text-gray-700' },
    { label: 'Enviados', value: c.value.sent_count, color: 'bg-amber-100 text-amber-700' },
    { label: 'Entregados', value: c.value.delivered_count, color: 'bg-cyan-100 text-cyan-700' },
    { label: 'Leídos', value: c.value.read_count, color: 'bg-emerald-100 text-emerald-700' },
    { label: 'Respondieron', value: c.value.replied_count, color: 'bg-purple-100 text-purple-700' },
]);

const progressPct = computed(() => {
    if (!c.value.total_recipients) return 0;
    const done = c.value.sent_count + c.value.failed_count + c.value.skipped_count;
    return Math.min(100, Math.round((done / c.value.total_recipients) * 100));
});

// ── Filtro de destinatarios ──────────────────────────────────────────────────
const statusFilter = ref(props.filters.status || '');
const statusChips = [
    { value: '', label: 'Todos' },
    { value: 'pending', label: 'Pendientes' },
    { value: 'queued', label: 'En cola' },
    { value: 'sent', label: 'Enviados' },
    { value: 'delivered', label: 'Entregados' },
    { value: 'read', label: 'Leídos' },
    { value: 'failed', label: 'Fallidos' },
    { value: 'skipped', label: 'Saltados' },
    { value: 'opted_out', label: 'De baja' },
];

function setStatusFilter(v) {
    statusFilter.value = v;
    router.get(route('campaigns.show', c.value.id), { status: v || undefined }, { preserveState: true, preserveScroll: true, replace: true });
}

// ── Acciones ─────────────────────────────────────────────────────────────────
const actionPending = ref(false);

function runAction(routeName) {
    actionPending.value = true;
    router.post(route(routeName, c.value.id), {}, {
        preserveScroll: true,
        onFinish: () => { actionPending.value = false; },
    });
}

const canLaunch = computed(() => ['draft', 'scheduled'].includes(c.value.status));
const canPause = computed(() => ['sending', 'scheduled'].includes(c.value.status));
const canResume = computed(() => c.value.status === 'paused');
const canCancel = computed(() => !['completed', 'cancelled'].includes(c.value.status));
const canRetry = computed(() => c.value.failed_count > 0);
const canDestroy = computed(() => c.value.status !== 'sending');

function deleteCampaign() {
    askConfirm({
        title: 'Eliminar campaña',
        message: `¿Seguro que quieres eliminar "${c.value.name}"? Esta acción no se puede deshacer.`,
        confirmLabel: 'Eliminar',
        variant: 'danger',
        onConfirm: () => router.delete(route('campaigns.destroy', c.value.id)),
    });
}

function cancelCampaign() {
    askConfirm({
        title: 'Cancelar campaña',
        message: 'Los destinatarios pendientes o en cola se marcarán como saltados. No se puede reanudar después.',
        confirmLabel: 'Cancelar campaña',
        variant: 'danger',
        onConfirm: () => runAction('campaigns.cancel'),
    });
}

// ── Diálogo de confirmación genérico ─────────────────────────────────────────
const confirmDialog = ref({ show: false, title: '', message: '', confirmLabel: 'Confirmar', variant: 'accent', onConfirm: null });

function askConfirm(opts) {
    confirmDialog.value = {
        show: true,
        title: opts.title ?? '¿Confirmar?',
        message: opts.message ?? '',
        confirmLabel: opts.confirmLabel ?? 'Confirmar',
        variant: opts.variant ?? 'accent',
        onConfirm: opts.onConfirm ?? null,
    };
}

function acceptConfirm() {
    const cb = confirmDialog.value.onConfirm;
    confirmDialog.value.show = false;
    if (cb) cb();
}

// ── Envío de prueba ──────────────────────────────────────────────────────────
const showTestModal = ref(false);
const testForm = useForm({ phone: '' });

function sendTest() {
    testForm.post(route('campaigns.test', c.value.id), {
        preserveScroll: true,
        onSuccess: () => { showTestModal.value = false; testForm.reset(); },
    });
}
</script>

<template>
    <Head :title="campaign.name" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-5xl mx-auto">
            <!-- Header -->
            <div class="flex items-start gap-3 mb-6">
                <Link :href="route('campaigns.index')" class="p-2 text-gray-400 hover:text-gray-700 hover:bg-gray-100 rounded-lg transition shrink-0 mt-1">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M10.5 19.5L3 12m0 0l7.5-7.5M3 12h18"/></svg>
                </Link>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2 flex-wrap">
                        <h1 class="text-xl font-bold text-gray-900 truncate">{{ campaign.name }}</h1>
                        <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide shrink-0" :class="statusBadge(campaign.status)">
                            {{ statusLabel(campaign.status) }}
                        </span>
                    </div>
                    <p class="text-xs text-gray-500 mt-1">
                        Plantilla: <span class="font-mono">{{ campaign.template?.name ?? '—' }}</span>
                        <span class="text-gray-300 mx-1.5">·</span>
                        Creada por {{ campaign.creator?.name ?? '—' }} el {{ formatDateTime(campaign.created_at) }}
                        <template v-if="campaign.scheduled_at">
                            <span class="text-gray-300 mx-1.5">·</span> Agendada: {{ formatDateTime(campaign.scheduled_at) }}
                        </template>
                    </p>
                </div>
            </div>

            <!-- Acciones -->
            <div class="flex flex-wrap gap-2 mb-6">
                <button v-if="canLaunch" @click="runAction('campaigns.launch')" :disabled="actionPending"
                        class="inline-flex items-center px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                    <svg class="w-4 h-4 mr-1.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.347a1.125 1.125 0 010 1.972l-11.54 6.347a1.125 1.125 0 01-1.667-.986V5.653z"/></svg>
                    Lanzar campaña
                </button>
                <button v-if="canPause" @click="runAction('campaigns.pause')" :disabled="actionPending"
                        class="inline-flex items-center px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition disabled:opacity-50">
                    Pausar
                </button>
                <button v-if="canResume" @click="runAction('campaigns.resume')" :disabled="actionPending"
                        class="inline-flex items-center px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                    Reanudar
                </button>
                <button @click="showTestModal = true"
                        class="inline-flex items-center px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">
                    Envío de prueba
                </button>
                <button v-if="canRetry" @click="runAction('campaigns.retry-failed')" :disabled="actionPending"
                        class="inline-flex items-center px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition disabled:opacity-50">
                    Reintentar fallidos ({{ campaign.failed_count }})
                </button>
                <a :href="route('campaigns.export', campaign.id)"
                   class="inline-flex items-center px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition">
                    Exportar CSV
                </a>
                <button @click="runAction('campaigns.duplicate')" :disabled="actionPending"
                        class="inline-flex items-center px-4 py-2 border border-gray-200 text-gray-700 rounded-xl text-sm font-medium hover:bg-gray-50 transition disabled:opacity-50">
                    Duplicar
                </button>
                <button v-if="canCancel" @click="cancelCampaign" :disabled="actionPending"
                        class="inline-flex items-center px-4 py-2 border border-red-200 text-red-700 rounded-xl text-sm font-medium hover:bg-red-50 transition disabled:opacity-50">
                    Cancelar
                </button>
                <button v-if="canDestroy" @click="deleteCampaign" :disabled="actionPending"
                        class="inline-flex items-center px-4 py-2 text-gray-400 rounded-xl text-sm font-medium hover:bg-red-50 hover:text-red-600 transition disabled:opacity-50">
                    Eliminar
                </button>
            </div>

            <!-- Progreso -->
            <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 mb-6">
                <div class="flex items-center justify-between mb-2">
                    <p class="text-sm font-medium text-gray-700">Progreso del envío</p>
                    <p class="text-xs text-gray-400">{{ progressPct }}%</p>
                </div>
                <div class="h-2 bg-gray-100 rounded-full overflow-hidden">
                    <div class="h-full bg-accent transition-all" :style="{ width: progressPct + '%' }"></div>
                </div>
                <p v-if="campaign.failed_count" class="text-[11px] text-red-600 mt-2">{{ campaign.failed_count }} fallidos · {{ campaign.skipped_count }} saltados</p>
            </div>

            <!-- Embudo (totales de la campaña, sumando todos los pasos) -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-6">
                <div v-for="f in funnel" :key="f.label" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 text-center">
                    <p class="text-2xl font-bold" :class="f.color.split(' ')[1]">{{ f.value }}</p>
                    <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mt-1">{{ f.label }}</p>
                </div>
            </div>

            <!-- Secuencia: embudo por paso (solo si hay seguimientos) -->
            <div v-if="steps.length > 1" class="bg-white rounded-2xl shadow-sm border border-gray-100 mb-6 p-4">
                <p class="text-sm font-medium text-gray-700 mb-3">Secuencia · {{ steps.length }} pasos</p>
                <div class="space-y-2">
                    <div v-for="s in steps" :key="s.step_order" class="border border-gray-100 rounded-xl p-3">
                        <div class="flex items-center justify-between gap-2 mb-2 flex-wrap">
                            <div class="flex items-center gap-2 min-w-0">
                                <span class="w-6 h-6 rounded-full bg-accent/10 text-accent text-[11px] font-bold flex items-center justify-center shrink-0">{{ s.step_order }}</span>
                                <span class="text-xs font-medium text-gray-700 truncate font-mono">{{ s.template || '—' }}</span>
                            </div>
                            <div class="flex items-center gap-1.5 text-[10px] text-gray-500">
                                <span class="px-2 py-0.5 bg-gray-100 rounded-full">{{ s.step_order === 1 ? 'Envío inicial' : conditionLabel(s.send_condition) }}</span>
                                <span v-if="s.step_order > 1" class="px-2 py-0.5 bg-gray-100 rounded-full">Espera {{ delayLabel(s.delay_hours) }}</span>
                            </div>
                        </div>
                        <div class="grid grid-cols-3 sm:grid-cols-5 gap-2 text-center">
                            <div><p class="text-sm font-bold text-amber-700">{{ s.metrics.sent }}</p><p class="text-[9px] text-gray-400 uppercase">Enviados</p></div>
                            <div><p class="text-sm font-bold text-cyan-700">{{ s.metrics.delivered }}</p><p class="text-[9px] text-gray-400 uppercase">Entregados</p></div>
                            <div><p class="text-sm font-bold text-emerald-700">{{ s.metrics.read }}</p><p class="text-[9px] text-gray-400 uppercase">Leídos</p></div>
                            <div><p class="text-sm font-bold text-purple-700">{{ s.metrics.replied }}</p><p class="text-[9px] text-gray-400 uppercase">Respondieron</p></div>
                            <div><p class="text-sm font-bold text-red-600">{{ s.metrics.failed }}</p><p class="text-[9px] text-gray-400 uppercase">Fallidos</p></div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Destinatarios -->
            <div class="bg-white rounded-2xl shadow-sm border border-gray-100">
                <div class="p-4 border-b border-gray-50">
                    <div class="flex items-center gap-1 bg-gray-50 rounded-xl p-1 flex-wrap">
                        <button v-for="opt in statusChips" :key="opt.value" @click="setStatusFilter(opt.value)"
                                class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                                :class="statusFilter === opt.value ? 'bg-white text-gray-900 shadow-sm' : 'text-gray-500 hover:text-gray-700'">
                            {{ opt.label }}
                        </button>
                    </div>
                </div>

                <div v-if="recipients.data.length" class="overflow-x-auto">
                    <table class="w-full text-xs">
                        <thead class="bg-gray-50 text-gray-500 uppercase text-[10px]">
                            <tr>
                                <th class="px-4 py-2 text-left">Teléfono</th>
                                <th class="px-4 py-2 text-left">Nombre</th>
                                <th class="px-4 py-2 text-left">Estado</th>
                                <th class="px-4 py-2 text-left">Enviado</th>
                                <th class="px-4 py-2 text-left">Entregado</th>
                                <th class="px-4 py-2 text-left">Leído</th>
                                <th class="px-4 py-2 text-left">Error</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="r in recipients.data" :key="r.id">
                                <td class="px-4 py-2 font-mono">{{ r.phone }}</td>
                                <td class="px-4 py-2">{{ r.name || '—' }}</td>
                                <td class="px-4 py-2">
                                    <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase" :class="recipientBadge(r.status)">
                                        {{ statusLabel(r.status) }}
                                    </span>
                                </td>
                                <td class="px-4 py-2 text-gray-500">{{ formatDateTime(r.sent_at) }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ formatDateTime(r.delivered_at) }}</td>
                                <td class="px-4 py-2 text-gray-500">{{ formatDateTime(r.read_at) }}</td>
                                <td class="px-4 py-2 text-red-500 max-w-[200px] truncate" :title="r.error">{{ r.error || r.skip_reason || '—' }}</td>
                            </tr>
                        </tbody>
                    </table>

                    <div v-if="recipients.links?.length > 3" class="flex justify-center gap-1 p-3 border-t border-gray-50">
                        <Link v-for="(link, i) in recipients.links" :key="i" :href="link.url || '#'"
                              v-html="link.label"
                              class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                              :class="[link.active ? 'bg-accent text-white' : 'text-gray-600 hover:bg-gray-100', !link.url && 'opacity-40 pointer-events-none']" />
                    </div>
                </div>

                <div v-else class="p-12 text-center text-sm text-gray-400">
                    No hay destinatarios con este estado.
                </div>
            </div>
        </div>

        <!-- Modal: envío de prueba -->
        <Teleport to="body">
            <div v-if="showTestModal" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showTestModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-1">Envío de prueba</h3>
                        <p class="text-xs text-gray-500 mb-4">Envía la plantilla con datos de un destinatario de muestra a un número de tu elección, sin afectar las estadísticas de la campaña.</p>
                        <form @submit.prevent="sendTest" class="space-y-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Número de WhatsApp</label>
                                <input v-model="testForm.phone" type="text" placeholder="573001234567"
                                       class="w-full px-4 py-2.5 border rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent"
                                       :class="testForm.errors.phone ? 'border-red-400' : 'border-gray-200'">
                                <p v-if="testForm.errors.phone" class="text-red-500 text-xs mt-1">{{ testForm.errors.phone }}</p>
                            </div>
                            <div class="flex justify-end gap-2 pt-2">
                                <button type="button" @click="showTestModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="testForm.processing || !testForm.phone.trim()"
                                        class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ testForm.processing ? 'Enviando…' : 'Enviar prueba' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Diálogo de confirmación -->
        <Teleport to="body">
            <div v-if="confirmDialog.show" class="fixed inset-0 z-[80] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="confirmDialog.show = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm animate-scale-in">
                    <div class="p-6">
                        <div class="flex items-start gap-3">
                            <div class="shrink-0 w-10 h-10 rounded-full flex items-center justify-center"
                                 :class="confirmDialog.variant === 'danger' ? 'bg-red-100' : 'bg-accent/10'">
                                <svg v-if="confirmDialog.variant === 'danger'" class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/>
                                </svg>
                                <svg v-else class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/>
                                </svg>
                            </div>
                            <div class="min-w-0 flex-1">
                                <h3 class="text-base font-bold text-gray-900">{{ confirmDialog.title }}</h3>
                                <p class="text-sm text-gray-600 mt-1">{{ confirmDialog.message }}</p>
                            </div>
                        </div>
                        <div class="flex justify-end gap-2 mt-5">
                            <button @click="confirmDialog.show = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">
                                Cancelar
                            </button>
                            <button @click="acceptConfirm"
                                    class="px-5 py-2.5 text-sm font-medium text-white rounded-xl transition"
                                    :class="confirmDialog.variant === 'danger' ? 'bg-red-600 hover:bg-red-700' : 'bg-accent hover:bg-accent-hover'">
                                {{ confirmDialog.confirmLabel }}
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
