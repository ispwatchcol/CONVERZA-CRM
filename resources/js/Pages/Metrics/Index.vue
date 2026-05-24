<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

const props = defineProps({
    stats: Object,
    messagesChart: Array,
    contactsGrowth: Array,
});
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <div class="mb-6">
                <h1 class="text-2xl font-bold text-gray-900">Métricas</h1>
                <p class="text-sm text-gray-500 mt-1">Análisis del rendimiento de tu CRM</p>
            </div>

            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-4 mb-8">
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500">Total Contactos</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ stats.totalContacts }}</p>
                </div>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500">Total Conversaciones</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ stats.totalConversations }}</p>
                </div>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500">Total Mensajes</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ stats.totalMessages }}</p>
                </div>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500">Conversaciones Abiertas</p>
                    <p class="text-3xl font-bold text-accent mt-1">{{ stats.openConversations }}</p>
                </div>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500">Conversaciones Cerradas</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ stats.closedConversations }}</p>
                </div>
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <p class="text-sm text-gray-500">Promedio Msgs/Conv.</p>
                    <p class="text-3xl font-bold text-gray-900 mt-1">{{ stats.avgMessagesPerConversation }}</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Messages Chart -->
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Mensajes - Últimos 30 días</h3>
                    <div class="space-y-2 max-h-80 overflow-y-auto">
                        <div v-for="day in messagesChart" :key="day.date" class="flex items-center space-x-3">
                            <span class="text-[10px] text-gray-500 w-12 text-right shrink-0">{{ day.date }}</span>
                            <div class="flex-1 flex items-center space-x-0.5 h-5">
                                <div class="bg-accent/70 h-full rounded-r transition-all" :style="{ width: Math.max((day.sent / (Math.max(...messagesChart.map(d => d.sent + d.received)) || 1)) * 100, 1) + '%' }"></div>
                                <div class="bg-blue-400/70 h-full rounded-r transition-all" :style="{ width: Math.max((day.received / (Math.max(...messagesChart.map(d => d.sent + d.received)) || 1)) * 100, 1) + '%' }"></div>
                            </div>
                            <span class="text-[10px] text-gray-400 w-8 text-right">{{ day.sent + day.received }}</span>
                        </div>
                    </div>
                    <div class="mt-3 flex items-center space-x-4 text-xs text-gray-500">
                        <span class="flex items-center"><span class="w-2.5 h-2.5 rounded-full bg-accent/70 mr-1"></span>Enviados</span>
                        <span class="flex items-center"><span class="w-2.5 h-2.5 rounded-full bg-blue-400/70 mr-1"></span>Recibidos</span>
                    </div>
                </div>

                <!-- Contacts Growth -->
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Crecimiento de Contactos</h3>
                    <div class="space-y-2">
                        <div v-for="week in contactsGrowth" :key="week.week" class="flex items-center space-x-3">
                            <span class="text-[10px] text-gray-500 w-12 text-right shrink-0">{{ week.week }}</span>
                            <div class="flex-1 h-5">
                                <div class="bg-purple-400/70 h-full rounded-r transition-all" :style="{ width: Math.max((week.count / (Math.max(...contactsGrowth.map(w => w.count)) || 1)) * 100, 2) + '%' }"></div>
                            </div>
                            <span class="text-[10px] text-gray-400 w-8 text-right">{{ week.count }}</span>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
