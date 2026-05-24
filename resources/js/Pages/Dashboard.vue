<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    user:                { type: Object, default: () => ({}) },
    tenant:              { type: Object, default: () => ({}) },
    stats:               { type: Object, default: () => ({}) },
    messagesChart:       { type: Array, default: () => [] },
    needsAttention:      { type: Array, default: () => [] },
    recentConversations: { type: Array, default: () => [] },
    health:              { type: Object, default: () => ({}) },
});

const greeting = computed(() => {
    const h = new Date().getHours();
    if (h < 12) return 'Buenos días';
    if (h < 19) return 'Buenas tardes';
    return 'Buenas noches';
});

const today = computed(() =>
    new Intl.DateTimeFormat('es-CO', { weekday: 'long', day: 'numeric', month: 'long' }).format(new Date())
);

const maxMessagesPerDay = computed(() =>
    Math.max(1, ...props.messagesChart.map(d => d.sent + d.received))
);

function formatMoney(amount) {
    if (amount == null) return '—';
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency: 'COP', maximumFractionDigits: 0 }).format(Number(amount));
}

function formatPhone(phone) {
    if (!phone) return '';
    if (phone.startsWith('57') && phone.length === 12) return phone.substring(2);
    return phone;
}

function formatTime(iso) {
    if (!iso) return '';
    return new Date(iso).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatRelative(iso) {
    if (!iso) return '';
    const d = (Date.now() - new Date(iso).getTime()) / 60000;
    if (d < 1) return 'ahora';
    if (d < 60) return `hace ${Math.floor(d)}m`;
    if (d < 1440) return `hace ${Math.floor(d / 60)}h`;
    return `hace ${Math.floor(d / 1440)}d`;
}

function formatWaitTime(minutes) {
    if (minutes < 60) return `${minutes}m`;
    const h = Math.floor(minutes / 60);
    const m = minutes % 60;
    return m > 0 ? `${h}h ${m}m` : `${h}h`;
}

function previewBody(msg) {
    if (!msg.last_message) {
        const t = msg.last_message_type;
        if (t === 'image') return '🖼 Imagen';
        if (t === 'audio') return '🎙 Audio';
        if (t === 'video') return '🎬 Video';
        if (t === 'document') return '📄 Documento';
        if (t === 'sticker') return '🎭 Sticker';
        if (t === 'location') return '📍 Ubicación';
        return 'Sin mensajes';
    }
    return msg.last_message;
}

const healthIssues = computed(() => {
    const issues = [];
    if (!props.health.whatsapp_configured) {
        issues.push({ key: 'wa', label: 'WhatsApp sin configurar', link: route('settings.index') });
    } else if (props.health.whatsapp_status === 'error') {
        issues.push({ key: 'wa', label: 'WhatsApp con error de conexión', link: route('settings.index') });
    }
    if (props.health.ispwatch_linked && !props.health.ispwatch_connected) {
        issues.push({ key: 'isp', label: 'ISPWatch vinculado pero no responde', link: route('settings.index') });
    }
    return issues;
});
</script>

<template>
    <Head title="Dashboard" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- ─── Greeting ─── -->
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">{{ greeting }}{{ user.name ? ', ' + user.name.split(' ')[0] : '' }} 👋</h1>
                <p class="text-sm text-gray-500 mt-1 capitalize">{{ today }} · <span class="text-gray-700">{{ tenant.name }}</span></p>
            </div>

            <!-- ─── Health alerts ─── -->
            <div v-if="healthIssues.length" class="mb-6 space-y-2">
                <Link v-for="issue in healthIssues" :key="issue.key" :href="issue.link"
                      class="flex items-center gap-2.5 px-4 py-2.5 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900 hover:bg-amber-100 transition">
                    <svg class="w-4 h-4 text-amber-600 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    <span class="font-medium">{{ issue.label }}</span>
                    <span class="text-xs text-amber-700 ml-auto">Ir a configuración →</span>
                </Link>
            </div>

            <!-- ─── Stats Grid ─── -->
            <div class="grid grid-cols-2 lg:grid-cols-4 gap-3 mb-6">
                <Link :href="route('contacts.index')" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-md transition-all">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Contactos</p>
                        <svg class="w-4 h-4 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ stats.totalContacts }}</p>
                </Link>

                <Link :href="route('chat.index')" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-md transition-all">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Chats abiertos</p>
                        <svg class="w-4 h-4 text-emerald-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951"/></svg>
                    </div>
                    <p class="text-3xl font-bold text-emerald-600">{{ stats.openConversations }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ stats.closedConversations }} cerrados</p>
                </Link>

                <Link :href="route('metrics.index')" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 hover:shadow-md transition-all">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Mensajes hoy</p>
                        <svg class="w-4 h-4 text-purple-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z"/></svg>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ stats.messagesToday }}</p>
                </Link>

                <div v-if="health.ispwatch_connected" class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-2">
                        <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Facturas vencidas</p>
                        <svg class="w-4 h-4 text-red-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 18.75a60.07 60.07 0 0115.797 2.101c.727.198 1.453-.342 1.453-1.096V18.75M3.75 4.5v.75A.75.75 0 013 6h-.75m0 0v-.375c0-.621.504-1.125 1.125-1.125H20.25M2.25 6v9m18-10.5v.75c0 .414.336.75.75.75h.75m-1.5-1.5h.375c.621 0 1.125.504 1.125 1.125v9.75c0 .621-.504 1.125-1.125 1.125h-.375m1.5-1.5H21a.75.75 0 00-.75.75v.75m0 0H3.75m0 0h-.375a1.125 1.125 0 01-1.125-1.125V15m1.5 1.5v-.75A.75.75 0 003 15h-.75M15 10.5a3 3 0 11-6 0 3 3 0 016 0zm3 0h.008v.008H18V10.5zm-12 0h.008v.008H6V10.5z"/></svg>
                    </div>
                    <p class="text-lg font-bold text-red-600">{{ formatMoney(stats.overdueAmount) }}</p>
                    <p class="text-[10px] text-gray-400 mt-0.5">{{ stats.overdueInvoices }} {{ stats.overdueInvoices === 1 ? 'factura' : 'facturas' }} en ISPWatch</p>
                </div>
                <div v-else class="bg-white rounded-2xl p-4 shadow-sm border border-gray-100 opacity-50">
                    <p class="text-[11px] text-gray-500 uppercase tracking-wider font-semibold">Facturas vencidas</p>
                    <p class="text-sm text-gray-400 mt-3">Sin vínculo ISPWatch</p>
                </div>
            </div>

            <!-- ─── Necesitan atención ─── -->
            <div v-if="needsAttention.length" class="bg-amber-50 border border-amber-200 rounded-2xl p-5 mb-6">
                <div class="flex items-center justify-between mb-3">
                    <h2 class="text-sm font-semibold text-amber-900 flex items-center gap-2">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        Necesitan atención ({{ needsAttention.length }})
                    </h2>
                    <span class="text-[10px] text-amber-700">Más de 30 min sin respuesta</span>
                </div>
                <div class="space-y-2">
                    <Link v-for="conv in needsAttention" :key="conv.id" :href="route('chat.index', { conversation: conv.id })"
                          class="flex items-center gap-3 p-2.5 bg-white rounded-xl border border-amber-100 hover:border-amber-300 hover:shadow-sm transition">
                        <div class="w-9 h-9 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold shrink-0">
                            {{ (conv.contact_name || '?')[0].toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ conv.contact_name }}</p>
                                <span v-if="conv.is_ispwatch_customer" class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide bg-emerald-100 text-emerald-700 shrink-0">ISP</span>
                                <span v-if="conv.service_status === 'suspendido'" class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide bg-red-100 text-red-700 shrink-0">Suspendido</span>
                            </div>
                            <p class="text-xs text-gray-500 truncate">{{ previewBody(conv) }}</p>
                        </div>
                        <div class="text-right shrink-0">
                            <p class="text-sm font-bold text-amber-700 tabular-nums">{{ formatWaitTime(conv.waiting_minutes) }}</p>
                            <p class="text-[10px] text-amber-600">esperando</p>
                        </div>
                    </Link>
                </div>
            </div>

            <!-- ─── Quick actions ─── -->
            <div class="grid grid-cols-2 md:grid-cols-4 gap-3 mb-6">
                <Link :href="route('chat.index')" class="flex items-center gap-2 px-4 py-3 bg-white rounded-xl border border-gray-100 hover:border-accent hover:bg-accent/5 transition group">
                    <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.625 12a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H8.25m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0H12m4.125 0a.375.375 0 11-.75 0 .375.375 0 01.75 0zm0 0h-.375M21 12c0 4.556-4.03 8.25-9 8.25a9.764 9.764 0 01-2.555-.337A5.972 5.972 0 015.41 20.97a5.969 5.969 0 01-.474-.065 4.48 4.48 0 00.978-2.025c.09-.457-.133-.901-.467-1.226C3.93 16.178 3 14.189 3 12c0-4.556 4.03-8.25 9-8.25s9 3.694 9 8.25z"/></svg>
                    <span class="text-sm font-medium text-gray-700 group-hover:text-accent">Ir al chat</span>
                </Link>
                <Link :href="route('contacts.index')" class="flex items-center gap-2 px-4 py-3 bg-white rounded-xl border border-gray-100 hover:border-accent hover:bg-accent/5 transition group">
                    <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7.5v3m0 0v3m0-3h3m-3 0h-3m-2.25-4.125a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zM4 19.235v-.11a6.375 6.375 0 0112.75 0v.109A12.318 12.318 0 0110.374 21c-2.331 0-4.512-.645-6.374-1.766z"/></svg>
                    <span class="text-sm font-medium text-gray-700 group-hover:text-accent">Nuevo contacto</span>
                </Link>
                <Link :href="route('quick-replies.index')" class="flex items-center gap-2 px-4 py-3 bg-white rounded-xl border border-gray-100 hover:border-accent hover:bg-accent/5 transition group">
                    <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z"/></svg>
                    <span class="text-sm font-medium text-gray-700 group-hover:text-accent">Respuestas rápidas</span>
                </Link>
                <Link :href="route('metrics.index')" class="flex items-center gap-2 px-4 py-3 bg-white rounded-xl border border-gray-100 hover:border-accent hover:bg-accent/5 transition group">
                    <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z"/></svg>
                    <span class="text-sm font-medium text-gray-700 group-hover:text-accent">Métricas</span>
                </Link>
            </div>

            <!-- ─── Chart + Recent ─── -->
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-4">
                <!-- Chart -->
                <div class="lg:col-span-2 bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-4">
                        <h3 class="text-sm font-semibold text-gray-900">Mensajes — últimos 7 días</h3>
                        <div class="flex items-center gap-3 text-[10px] text-gray-500">
                            <span class="flex items-center"><span class="w-2 h-2 rounded-full bg-accent mr-1"></span>Enviados</span>
                            <span class="flex items-center"><span class="w-2 h-2 rounded-full bg-blue-400 mr-1"></span>Recibidos</span>
                        </div>
                    </div>
                    <div class="space-y-2">
                        <div v-for="day in messagesChart" :key="day.date" class="flex items-center gap-2">
                            <span class="text-[10px] text-gray-500 w-10 capitalize">{{ day.label }}</span>
                            <div class="flex-1 flex items-center gap-0.5 h-5">
                                <div class="bg-accent h-full rounded-sm transition-all" :style="{ width: ((day.sent / maxMessagesPerDay) * 100) + '%' }"></div>
                                <div class="bg-blue-400 h-full rounded-sm transition-all" :style="{ width: ((day.received / maxMessagesPerDay) * 100) + '%' }"></div>
                            </div>
                            <span class="text-[10px] text-gray-400 w-8 text-right tabular-nums">{{ day.sent + day.received }}</span>
                        </div>
                    </div>
                </div>

                <!-- Recent conversations -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Recientes</h3>
                        <Link :href="route('chat.index')" class="text-[11px] text-accent hover:underline">Ver todas →</Link>
                    </div>
                    <div v-if="recentConversations.length" class="space-y-1">
                        <Link v-for="conv in recentConversations" :key="conv.id" :href="route('chat.index', { conversation: conv.id })"
                              class="flex items-center gap-2.5 p-2 rounded-xl hover:bg-gray-50 transition">
                            <div class="w-8 h-8 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-xs font-bold shrink-0">
                                {{ (conv.contact_name || '?')[0].toUpperCase() }}
                            </div>
                            <div class="flex-1 min-w-0">
                                <div class="flex items-center gap-1.5">
                                    <p class="text-xs font-medium text-gray-900 truncate">{{ conv.contact_name }}</p>
                                    <span v-if="conv.is_ispwatch_customer" class="w-1.5 h-1.5 rounded-full bg-emerald-500 shrink-0" title="Cliente ISP"></span>
                                    <span v-if="conv.status === 'closed'" class="px-1 py-0 rounded text-[8px] uppercase font-semibold tracking-wide bg-gray-100 text-gray-500">Cerrada</span>
                                </div>
                                <p class="text-[11px] text-gray-500 truncate">
                                    <svg v-if="conv.last_message_status === 'sent'" class="h-2.5 w-2.5 inline text-blue-500 mr-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
                                    {{ previewBody(conv) }}
                                </p>
                            </div>
                            <span class="text-[10px] text-gray-400 shrink-0">{{ formatRelative(conv.last_message_at) }}</span>
                        </Link>
                    </div>
                    <div v-else class="text-center text-gray-400 py-8">
                        <p class="text-xs">No hay conversaciones aún</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
