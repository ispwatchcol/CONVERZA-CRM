<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    campaigns: { type: Object, default: () => ({ data: [], links: [] }) },
    tierThresholds: { type: Array, default: () => [250, 1000, 10000, 100000] },
    canManage: { type: Boolean, default: false },
    warmup: {
        type: Object,
        default: () => ({
            enabled: false, start_per_day: 50, daily_increment: 50,
            max_per_day: 1000, started_on: null, used_last_24h: 0, allowance_today: null,
        }),
    },
});

// ── Calentamiento (warm-up) del número ──────────────────────────────────────
const showWarmupForm = ref(false);

const warmupForm = useForm({
    enabled: props.warmup.enabled,
    start_per_day: props.warmup.start_per_day,
    daily_increment: props.warmup.daily_increment,
    max_per_day: props.warmup.max_per_day,
});

const warmupUsedPct = computed(() => {
    if (!props.warmup.enabled || !props.warmup.allowance_today) return 0;
    return Math.min(100, Math.round((props.warmup.used_last_24h / props.warmup.allowance_today) * 100));
});

function saveWarmup() {
    warmupForm.put(route('campaigns.warmup.update'), {
        preserveScroll: true,
        onSuccess: () => { showWarmupForm.value = false; },
    });
}

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
        draft: 'Borrador',
        scheduled: 'Agendada',
        sending: 'Enviando',
        paused: 'Pausada',
        completed: 'Completada',
        cancelled: 'Cancelada',
        failed: 'Fallida',
    }[status] || status;
}

function progressPct(c) {
    if (!c.total_recipients) return 0;
    const done = c.sent_count + c.failed_count + c.skipped_count;
    return Math.min(100, Math.round((done / c.total_recipients) * 100));
}

function formatDateTime(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

// ── Diálogo de confirmación ─────────────────────────────────────────────────
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

function deleteCampaign(c) {
    askConfirm({
        title: 'Eliminar campaña',
        message: `¿Seguro que quieres eliminar "${c.name}"? Esta acción no se puede deshacer.`,
        confirmLabel: 'Eliminar',
        variant: 'danger',
        onConfirm: () => router.delete(route('campaigns.destroy', c.id), { preserveScroll: true }),
    });
}
</script>

<template>
    <Head title="Campañas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Campañas masivas</h1>
                    <p class="text-sm text-gray-500 mt-1">Envía mensajes de WhatsApp a tus prospectos y da seguimiento a la entrega</p>
                </div>
                <Link :href="route('campaigns.create')" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm shrink-0">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Nueva campaña
                </Link>
            </div>

            <!-- Aviso de tiers de Meta -->
            <div class="mb-6 flex items-start gap-2.5 px-4 py-2.5 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-900">
                <svg class="w-4 h-4 text-blue-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                <span>Meta limita los destinatarios únicos por 24h según el tier de tu número ({{ tierThresholds.join(' / ') }}). Manda envíos de prueba y respeta el ritmo configurado para proteger tu calidad.</span>
            </div>

            <!-- Calentamiento (warm-up) del número -->
            <div class="mb-6 bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                <div class="flex items-start justify-between gap-3">
                    <div class="min-w-0">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="inline-flex items-center gap-1.5 text-sm font-semibold text-gray-900">
                                <svg class="w-4 h-4 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.362 5.214A8.252 8.252 0 0112 21 8.25 8.25 0 016.038 7.048 8.287 8.287 0 009 9.6a8.983 8.983 0 013.361-6.867 8.21 8.21 0 003 2.48z"/><path stroke-linecap="round" stroke-linejoin="round" d="M12 18a3.75 3.75 0 00.495-7.467 5.99 5.99 0 00-1.925 3.546 5.974 5.974 0 01-2.133-1A3.75 3.75 0 0012 18z"/></svg>
                                Calentamiento del número
                            </span>
                            <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide"
                                  :class="warmup.enabled ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'">
                                {{ warmup.enabled ? 'Activo' : 'Apagado' }}
                            </span>
                        </div>
                        <p class="text-xs text-gray-500 mt-1">
                            Sube el volumen de a poco para cuidar el quality rating y que Meta te promueva de tier.
                        </p>
                    </div>
                    <button v-if="canManage" @click="showWarmupForm = !showWarmupForm"
                            class="text-xs font-medium text-accent hover:underline shrink-0">
                        {{ showWarmupForm ? 'Cerrar' : 'Configurar' }}
                    </button>
                </div>

                <!-- Uso de las últimas 24h -->
                <div v-if="warmup.enabled" class="mt-3">
                    <div class="flex items-center justify-between text-[11px] text-gray-500 mb-1">
                        <span>Contactados (últimas 24h): <strong class="text-gray-700">{{ warmup.used_last_24h }}</strong> / {{ warmup.allowance_today }} permitidos hoy</span>
                        <span v-if="warmup.used_last_24h >= warmup.allowance_today" class="text-amber-600 font-medium">Tope de hoy alcanzado</span>
                    </div>
                    <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                        <div class="h-full transition-all" :class="warmupUsedPct >= 100 ? 'bg-amber-500' : 'bg-emerald-500'" :style="{ width: warmupUsedPct + '%' }"></div>
                    </div>
                </div>
                <p v-else class="mt-2 text-xs text-gray-400">
                    Apagado: las campañas envían al ritmo de su throttle, sin tope diario.
                </p>

                <!-- Formulario (solo admin) -->
                <div v-if="showWarmupForm && canManage" class="mt-4 pt-4 border-t border-gray-100 space-y-3">
                    <label class="flex items-center gap-2 text-sm text-gray-700">
                        <input type="checkbox" v-model="warmupForm.enabled" class="rounded border-gray-300 text-accent focus:ring-accent" />
                        Activar calentamiento para este número
                    </label>
                    <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                        <div>
                            <label class="block text-[11px] font-medium text-gray-500 mb-1">Envíos el primer día</label>
                            <input type="number" min="1" v-model.number="warmupForm.start_per_day" class="w-full rounded-lg border-gray-300 text-sm focus:border-accent focus:ring-accent" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-gray-500 mb-1">Aumento por día</label>
                            <input type="number" min="0" v-model.number="warmupForm.daily_increment" class="w-full rounded-lg border-gray-300 text-sm focus:border-accent focus:ring-accent" />
                        </div>
                        <div>
                            <label class="block text-[11px] font-medium text-gray-500 mb-1">Tope diario</label>
                            <input type="number" min="1" v-model.number="warmupForm.max_per_day" class="w-full rounded-lg border-gray-300 text-sm focus:border-accent focus:ring-accent" />
                        </div>
                    </div>
                    <p class="text-[11px] text-gray-400">
                        Ejemplo: 50 el primer día, +50 cada día, hasta 1.000/día. La rampa arranca el día que lo activas.
                        El tope es del número (lo comparten todas tus campañas) y se mide sobre las últimas 24h.
                    </p>
                    <div v-if="Object.keys(warmupForm.errors).length" class="text-xs text-red-600">
                        {{ Object.values(warmupForm.errors)[0] }}
                    </div>
                    <div class="flex justify-end gap-2">
                        <button @click="showWarmupForm = false" class="px-3 py-2 text-sm text-gray-600 hover:bg-gray-100 rounded-lg transition">Cancelar</button>
                        <button @click="saveWarmup" :disabled="warmupForm.processing"
                                class="px-4 py-2 text-sm font-medium text-white bg-accent hover:bg-accent-hover rounded-lg transition disabled:opacity-50">
                            Guardar
                        </button>
                    </div>
                </div>
            </div>

            <!-- Lista -->
            <div v-if="campaigns.data.length" class="space-y-3">
                <Link v-for="c in campaigns.data" :key="c.id" :href="route('campaigns.show', c.id)"
                      class="block bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-md transition-all group">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2 flex-wrap">
                                <h3 class="text-sm font-semibold text-gray-900 truncate">{{ c.name }}</h3>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold uppercase tracking-wide shrink-0" :class="statusBadge(c.status)">
                                    {{ statusLabel(c.status) }}
                                </span>
                            </div>
                            <p class="text-xs text-gray-500 mt-1">
                                Plantilla: <span class="font-mono">{{ c.template?.name ?? '—' }}</span>
                                <span class="text-gray-300 mx-1.5">·</span>
                                {{ c.total_recipients }} destinatarios
                                <span v-if="c.scheduled_at" class="text-gray-300 mx-1.5">·</span>
                                <span v-if="c.scheduled_at">Agendada: {{ formatDateTime(c.scheduled_at) }}</span>
                            </p>
                        </div>
                        <button @click.prevent.stop="deleteCampaign(c)" v-if="['draft','cancelled','completed','failed'].includes(c.status)"
                                class="p-1.5 text-gray-300 hover:text-red-600 hover:bg-red-50 rounded-lg transition shrink-0" title="Eliminar">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0"/></svg>
                        </button>
                    </div>

                    <!-- Progress -->
                    <div class="mt-3">
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full bg-accent transition-all" :style="{ width: progressPct(c) + '%' }"></div>
                        </div>
                        <div class="flex items-center gap-4 mt-2 text-[11px] text-gray-500">
                            <span>Enviados: <strong class="text-gray-700">{{ c.sent_count }}</strong></span>
                            <span>Entregados: <strong class="text-gray-700">{{ c.delivered_count }}</strong></span>
                            <span>Leídos: <strong class="text-gray-700">{{ c.read_count }}</strong></span>
                            <span>Respondieron: <strong class="text-emerald-600">{{ c.replied_count }}</strong></span>
                            <span v-if="c.failed_count">Fallidos: <strong class="text-red-600">{{ c.failed_count }}</strong></span>
                        </div>
                    </div>
                </Link>

                <!-- Pagination -->
                <div v-if="campaigns.links?.length > 3" class="flex justify-center gap-1 pt-2">
                    <Link v-for="(link, i) in campaigns.links" :key="i" :href="link.url || '#'"
                          v-html="link.label"
                          class="px-3 py-1.5 rounded-lg text-xs font-medium transition"
                          :class="[link.active ? 'bg-accent text-white' : 'text-gray-600 hover:bg-gray-100', !link.url && 'opacity-40 pointer-events-none']" />
                </div>
            </div>

            <!-- Empty state -->
            <div v-else class="bg-white rounded-2xl p-12 text-center shadow-sm border border-gray-100">
                <svg class="w-16 h-16 mx-auto mb-4 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" /></svg>
                <h3 class="text-base font-semibold text-gray-700 mb-1">Aún no hay campañas</h3>
                <p class="text-sm text-gray-400 mb-5 max-w-md mx-auto">
                    Crea tu primera campaña masiva: carga una lista desde Excel, tus contactos del CRM, un link de
                    Google Sheets o pegando los números a mano.
                </p>
                <Link :href="route('campaigns.create')" class="inline-flex items-center px-4 py-2 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition">
                    Crear campaña
                </Link>
            </div>
        </div>

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
