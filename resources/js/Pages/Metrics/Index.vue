<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    tenant: { type: Object, default: () => ({ name: '' }) },
    stats: { type: Object, default: () => ({}) },
    responseTime:   { type: Object, default: () => ({}) },
    messagesChart:  { type: Array, default: () => [] },
    contactsGrowth: { type: Array, default: () => [] },
    messagesByHour: { type: Array, default: () => [] },
    messagesByType: { type: Array, default: () => [] },
    topContacts:    { type: Array, default: () => [] },
    perAgent:       { type: Array, default: () => [] },
});

// ── Helpers ──────────────────────────────────────────────────────────────────
const maxMessagesPerDay = computed(() =>
    Math.max(1, ...props.messagesChart.map(d => d.sent + d.received))
);
const maxContactsPerWeek = computed(() =>
    Math.max(1, ...props.contactsGrowth.map(w => w.count))
);
const maxMessagesPerHour = computed(() =>
    Math.max(1, ...props.messagesByHour.map(h => h.count))
);
const totalMessagesByType = computed(() =>
    props.messagesByType.reduce((acc, t) => acc + t.count, 0) || 1
);

function pctOfType(count) {
    return Math.round((count / totalMessagesByType.value) * 1000) / 10;
}

function formatPhone(phone) {
    if (!phone) return '';
    if (phone.startsWith('57') && phone.length === 12) return phone.substring(2);
    return phone;
}

// Humaniza segundos a "5min 12s" / "2h 30min" / "1d 4h"
function humanizeSeconds(seconds) {
    if (seconds == null) return '—';
    const s = Math.round(seconds);
    if (s < 60) return `${s}s`;
    if (s < 3600) {
        const m = Math.floor(s / 60);
        const rest = s % 60;
        return rest > 0 ? `${m}min ${rest}s` : `${m}min`;
    }
    if (s < 86400) {
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        return m > 0 ? `${h}h ${m}min` : `${h}h`;
    }
    const d = Math.floor(s / 86400);
    const h = Math.floor((s % 86400) / 3600);
    return h > 0 ? `${d}d ${h}h` : `${d}d`;
}

const responseQuality = computed(() => {
    const avg = props.responseTime.avg_seconds;
    if (avg == null) return { label: '—', cls: 'text-gray-400' };
    if (avg < 5 * 60)   return { label: 'Excelente', cls: 'text-emerald-600' };
    if (avg < 30 * 60)  return { label: 'Bueno',     cls: 'text-blue-600' };
    if (avg < 2 * 3600) return { label: 'Regular',   cls: 'text-amber-600' };
    return { label: 'Lento', cls: 'text-red-600' };
});

const typeMeta = {
    text:        { label: 'Texto',      color: 'bg-accent',       icon: '💬' },
    image:       { label: 'Imágenes',   color: 'bg-blue-500',     icon: '🖼' },
    audio:       { label: 'Audios',     color: 'bg-purple-500',   icon: '🎙' },
    video:       { label: 'Videos',     color: 'bg-pink-500',     icon: '🎬' },
    document:    { label: 'Documentos', color: 'bg-amber-500',    icon: '📄' },
    sticker:     { label: 'Stickers',   color: 'bg-emerald-500',  icon: '🎭' },
    location:    { label: 'Ubicación',  color: 'bg-red-500',      icon: '📍' },
    unsupported: { label: 'Otros',      color: 'bg-gray-400',     icon: '❓' },
};
function metaFor(type) {
    return typeMeta[type] || { label: type, color: 'bg-gray-400', icon: '•' };
}
</script>

<template>
    <Head title="Métricas" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Header -->
            <div class="mb-6 flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Métricas</h1>
                    <p class="text-sm text-gray-500 mt-1">Análisis del rendimiento de tu CRM</p>
                </div>
                <span class="inline-flex items-center gap-1.5 px-3 py-1 rounded-full bg-accent/10 text-accent text-xs font-medium">
                    <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 11.25v1.5m0 2.25v.008m9-3.758a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                    Ámbito: {{ tenant.name }}
                </span>
            </div>

            <!-- ═══════════════ Stats Grid ═══════════════ -->
            <div class="grid grid-cols-2 md:grid-cols-3 lg:grid-cols-4 gap-3 mb-6">
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Contactos</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.totalContacts }}</p>
                    <p class="text-[10px] text-emerald-600 mt-0.5">+{{ stats.contactsThisWeek }} esta semana</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Conversaciones</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.totalConversations }}</p>
                    <p class="text-[10px] text-gray-500 mt-0.5">
                        <span class="text-accent font-semibold">{{ stats.openConversations }}</span> abiertas ·
                        <span>{{ stats.closedConversations }}</span> cerradas
                    </p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Mensajes</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.totalMessages }}</p>
                    <p class="text-[10px] text-blue-600 mt-0.5">{{ stats.messagesToday }} hoy</p>
                </div>
                <div class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Msgs / Conv</p>
                    <p class="text-2xl font-bold text-gray-900 mt-1">{{ stats.avgMessagesPerConversation }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">promedio</p>
                </div>
            </div>

            <!-- ═══════════════ Respuesta del staff ═══════════════ -->
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 mb-4">
                <div class="flex items-center justify-between mb-4">
                    <div>
                        <h3 class="text-sm font-semibold text-gray-900">Respuesta del staff</h3>
                        <p class="text-[10px] text-gray-400 mt-0.5">Tiempo desde que un cliente escribe hasta que recibe respuesta · últimos 30 días</p>
                    </div>
                    <span class="px-2.5 py-1 rounded-full text-[10px] font-semibold uppercase tracking-wider" :class="responseQuality.cls + ' ' + (responseTime.avg_seconds == null ? 'bg-gray-50' : responseTime.avg_seconds < 5 * 60 ? 'bg-emerald-50' : responseTime.avg_seconds < 30 * 60 ? 'bg-blue-50' : responseTime.avg_seconds < 7200 ? 'bg-amber-50' : 'bg-red-50')">
                        {{ responseQuality.label }}
                    </span>
                </div>

                <div v-if="responseTime.sample_size > 0" class="grid grid-cols-2 md:grid-cols-4 gap-4">
                    <!-- Promedio (big number) -->
                    <div class="md:col-span-2 md:border-r md:border-gray-100 md:pr-4">
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold">Promedio</p>
                        <p class="text-3xl font-bold mt-1" :class="responseQuality.cls">{{ humanizeSeconds(responseTime.avg_seconds) }}</p>
                        <p class="text-[11px] text-gray-500 mt-1">
                            basado en
                            <span class="font-semibold text-gray-700">{{ responseTime.sample_size }}</span>
                            {{ responseTime.sample_size === 1 ? 'respuesta medida' : 'respuestas medidas' }}
                        </p>
                    </div>

                    <div>
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold">Más rápido</p>
                        <p class="text-xl font-bold text-emerald-600 mt-1">{{ humanizeSeconds(responseTime.fastest_seconds) }}</p>
                    </div>

                    <div>
                        <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold">Más lento</p>
                        <p class="text-xl font-bold text-gray-700 mt-1">{{ humanizeSeconds(responseTime.slowest_seconds) }}</p>
                    </div>

                    <!-- Tasa de respuesta (full width row) -->
                    <div class="md:col-span-4 pt-4 border-t border-gray-50">
                        <div class="flex items-center justify-between mb-1.5">
                            <span class="text-xs text-gray-600">Tasa de respuesta</span>
                            <span class="text-xs font-semibold text-gray-900 tabular-nums">{{ responseTime.responded_rate }}%</span>
                        </div>
                        <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                            <div class="h-full rounded-full transition-all"
                                 :class="responseTime.responded_rate >= 80 ? 'bg-emerald-500' : responseTime.responded_rate >= 50 ? 'bg-blue-500' : responseTime.responded_rate >= 20 ? 'bg-amber-500' : 'bg-red-500'"
                                 :style="{ width: responseTime.responded_rate + '%' }"></div>
                        </div>
                        <p class="text-[10px] text-gray-400 mt-1">
                            {{ responseTime.sample_size }} de {{ responseTime.total_received }} mensajes recibidos tuvieron respuesta dentro de 7 días.
                        </p>
                    </div>
                </div>

                <div v-else class="text-center py-6 text-xs text-gray-400">
                    <p v-if="responseTime.total_received > 0">
                        {{ responseTime.total_received }} mensajes recibidos sin respuesta medida en el periodo.
                    </p>
                    <p v-else>Sin actividad para calcular tiempos de respuesta.</p>
                </div>
            </div>

            <!-- ═══════════════ Row 1: Día + Hora ═══════════════ -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <!-- Mensajes por día -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Mensajes — últimos 30 días</h3>
                        <div class="flex items-center gap-3 text-[10px] text-gray-500">
                            <span class="flex items-center"><span class="w-2 h-2 rounded-full bg-accent mr-1"></span>Enviados</span>
                            <span class="flex items-center"><span class="w-2 h-2 rounded-full bg-blue-400 mr-1"></span>Recibidos</span>
                        </div>
                    </div>
                    <div class="space-y-1 max-h-80 overflow-y-auto pr-2">
                        <div v-for="day in messagesChart" :key="day.date" class="flex items-center gap-2">
                            <span class="text-[10px] text-gray-500 w-12 text-right shrink-0">{{ day.label }}</span>
                            <div class="flex-1 flex items-center gap-0.5 h-4">
                                <div class="bg-accent h-full rounded-sm transition-all" :style="{ width: ((day.sent / maxMessagesPerDay) * 100) + '%' }"></div>
                                <div class="bg-blue-400 h-full rounded-sm transition-all" :style="{ width: ((day.received / maxMessagesPerDay) * 100) + '%' }"></div>
                            </div>
                            <span class="text-[10px] text-gray-400 w-8 text-right tabular-nums">{{ day.sent + day.received }}</span>
                        </div>
                    </div>
                </div>

                <!-- Distribución por hora del día -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Cuándo te escriben</h3>
                        <span class="text-[10px] text-gray-400">por hora · últimos 30 días</span>
                    </div>
                    <div class="flex items-end gap-1 h-48 mt-6">
                        <div v-for="hour in messagesByHour" :key="hour.hour" class="flex-1 flex flex-col items-center group relative">
                            <div class="absolute -top-6 opacity-0 group-hover:opacity-100 transition pointer-events-none bg-gray-900 text-white text-[10px] px-1.5 py-0.5 rounded whitespace-nowrap z-10">
                                {{ hour.count }}
                            </div>
                            <div class="w-full bg-purple-400 rounded-t transition-all hover:bg-purple-500"
                                 :style="{ height: hour.count > 0 ? Math.max((hour.count / maxMessagesPerHour) * 100, 5) + '%' : '2px' }"
                                 :class="hour.count === 0 ? 'bg-gray-100' : ''"></div>
                        </div>
                    </div>
                    <div class="flex justify-between mt-2 text-[9px] text-gray-400 tabular-nums">
                        <span>00</span><span>06</span><span>12</span><span>18</span><span>23</span>
                    </div>
                </div>
            </div>

            <!-- ═══════════════ Row 2: Contactos + Tipos ═══════════════ -->
            <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 mb-4">
                <!-- Crecimiento de contactos -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Crecimiento de contactos</h3>
                    <p class="text-[10px] text-gray-400 -mt-2 mb-3">nuevos por semana · últimas 12</p>
                    <div class="space-y-1.5">
                        <div v-for="week in contactsGrowth" :key="week.week" class="flex items-center gap-2">
                            <span class="text-[10px] text-gray-500 w-12 text-right shrink-0">{{ week.label }}</span>
                            <div class="flex-1 h-4 bg-gray-50 rounded-sm overflow-hidden">
                                <div class="bg-gradient-to-r from-emerald-400 to-emerald-500 h-full rounded-sm transition-all"
                                     :style="{ width: week.count > 0 ? Math.max((week.count / maxContactsPerWeek) * 100, 3) + '%' : '0%' }"></div>
                            </div>
                            <span class="text-[10px] text-gray-400 w-8 text-right tabular-nums">{{ week.count }}</span>
                        </div>
                    </div>
                </div>

                <!-- Distribución por tipo de mensaje -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <h3 class="text-sm font-semibold text-gray-900 mb-3">Tipos de mensaje</h3>
                    <p class="text-[10px] text-gray-400 -mt-2 mb-3">últimos 30 días</p>
                    <div v-if="messagesByType.length" class="space-y-2.5">
                        <div v-for="item in messagesByType" :key="item.type">
                            <div class="flex items-center justify-between mb-0.5">
                                <span class="text-xs text-gray-700 flex items-center gap-1.5">
                                    <span>{{ metaFor(item.type).icon }}</span>
                                    {{ metaFor(item.type).label }}
                                </span>
                                <span class="text-xs text-gray-500 tabular-nums">{{ item.count }} <span class="text-gray-400">· {{ pctOfType(item.count) }}%</span></span>
                            </div>
                            <div class="h-1.5 bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all" :class="metaFor(item.type).color" :style="{ width: (item.count / totalMessagesByType * 100) + '%' }"></div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="text-center py-8 text-xs text-gray-400">Sin mensajes en el periodo</div>
                </div>
            </div>

            <!-- ═══════════════ Top contactos ═══════════════ -->
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-900">Top contactos activos</h3>
                    <span class="text-[10px] text-gray-400">por # de mensajes · últimos 30 días</span>
                </div>
                <div v-if="topContacts.length" class="divide-y divide-gray-50">
                    <div v-for="(contact, i) in topContacts" :key="contact.phone" class="flex items-center py-2.5">
                        <div class="w-7 h-7 rounded-full bg-gray-100 flex items-center justify-center text-[10px] font-bold text-gray-500 shrink-0">
                            {{ i + 1 }}
                        </div>
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold mx-3 shrink-0">
                            {{ (contact.name || '?')[0].toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <p class="text-sm font-medium text-gray-900 truncate">{{ contact.name }}</p>
                            <p class="text-[11px] text-gray-500 truncate">{{ formatPhone(contact.phone) }}</p>
                        </div>
                        <span class="text-sm font-semibold text-gray-900 tabular-nums">{{ contact.message_count }}</span>
                        <span class="text-[10px] text-gray-400 ml-1">msgs</span>
                    </div>
                </div>
                <div v-else class="text-center py-8 text-xs text-gray-400">Sin actividad reciente</div>
            </div>

            <!-- ═══════════════ Rendimiento por agente ═══════════════ -->
            <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 mt-4">
                <div class="flex items-center justify-between mb-3">
                    <h3 class="text-sm font-semibold text-gray-900">Rendimiento por agente</h3>
                    <span class="text-[10px] text-gray-400">últimos 30 días</span>
                </div>
                <div v-if="perAgent.length" class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="text-[10px] uppercase tracking-wider text-gray-400 border-b border-gray-100">
                                <th class="text-left font-semibold py-2 pr-3">Agente</th>
                                <th class="text-right font-semibold py-2 px-3" title="Mensajes enviados al cliente">Enviados</th>
                                <th class="text-right font-semibold py-2 px-3" title="Conversaciones cerradas (notas de cierre)">Cerrados</th>
                                <th class="text-right font-semibold py-2 px-3" title="Conversaciones abiertas asignadas ahora">Abiertos</th>
                                <th class="text-right font-semibold py-2 pl-3" title="Tiempo de respuesta promedio">T. respuesta</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-50">
                            <tr v-for="agent in perAgent" :key="agent.user_id" class="hover:bg-gray-50">
                                <td class="py-2.5 pr-3">
                                    <div class="flex items-center gap-2.5 min-w-0">
                                        <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-[11px] font-bold shrink-0">
                                            {{ (agent.name || '?')[0].toUpperCase() }}
                                        </div>
                                        <span class="font-medium text-gray-900 truncate">{{ agent.name }}</span>
                                    </div>
                                </td>
                                <td class="py-2.5 px-3 text-right tabular-nums font-semibold text-gray-900">{{ agent.messages_sent }}</td>
                                <td class="py-2.5 px-3 text-right tabular-nums text-gray-700">{{ agent.conversations_closed }}</td>
                                <td class="py-2.5 px-3 text-right tabular-nums text-gray-700">{{ agent.open_assigned }}</td>
                                <td class="py-2.5 pl-3 text-right tabular-nums" :class="agent.avg_response_seconds == null ? 'text-gray-400' : 'text-gray-900'">{{ humanizeSeconds(agent.avg_response_seconds) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div v-else class="text-center py-8 text-xs text-gray-400">Sin agentes activos o sin actividad reciente</div>
            </div>
        </div>
    </AppLayout>
</template>
