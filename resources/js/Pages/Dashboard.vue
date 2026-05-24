<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';

defineProps({
    stats: Object,
    recentConversations: Array,
    messagesChart: Array,
});
</script>

<template>
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in">
            <!-- Stats Grid -->
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 md:gap-6 mb-8">
                <!-- Total Contacts -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-shadow group">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 rounded-xl bg-blue-50 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-6 h-6 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ stats.totalContacts }}</p>
                    <p class="text-sm text-gray-500 mt-1">Contactos Totales</p>
                </div>

                <!-- Active Conversations -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-shadow group">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 rounded-xl bg-green-50 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-6 h-6 text-green-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ stats.activeConversations }}</p>
                    <p class="text-sm text-gray-500 mt-1">Chats Activos</p>
                </div>

                <!-- Messages Today -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-shadow group">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 rounded-xl bg-purple-50 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-6 h-6 text-purple-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ stats.messagesToday }}</p>
                    <p class="text-sm text-gray-500 mt-1">Mensajes Hoy</p>
                </div>

                <!-- Total Staff -->
                <div class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100 hover:shadow-md transition-shadow group">
                    <div class="flex items-center justify-between mb-3">
                        <div class="w-12 h-12 rounded-xl bg-amber-50 flex items-center justify-center group-hover:scale-110 transition-transform">
                            <svg class="w-6 h-6 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" />
                            </svg>
                        </div>
                    </div>
                    <p class="text-3xl font-bold text-gray-900">{{ stats.totalStaff }}</p>
                    <p class="text-sm text-gray-500 mt-1">Staff Activo</p>
                </div>
            </div>

            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Messages Chart -->
                <div class="lg:col-span-2 bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Mensajes - Últimos 7 días</h3>
                    <div class="space-y-3">
                        <div v-for="day in messagesChart" :key="day.date" class="flex items-center space-x-3">
                            <span class="text-xs text-gray-500 w-8 text-right">{{ day.date }}</span>
                            <div class="flex-1 flex items-center space-x-1 h-6">
                                <div class="bg-accent/80 h-full rounded-r-full transition-all duration-500" :style="{ width: Math.max((day.sent / (Math.max(...messagesChart.map(d => d.sent + d.received)) || 1)) * 100, 2) + '%' }"></div>
                                <div class="bg-blue-400/80 h-full rounded-r-full transition-all duration-500" :style="{ width: Math.max((day.received / (Math.max(...messagesChart.map(d => d.sent + d.received)) || 1)) * 100, 2) + '%' }"></div>
                            </div>
                            <span class="text-xs text-gray-500 w-12 text-right">{{ day.sent + day.received }}</span>
                        </div>
                    </div>
                    <div class="mt-4 flex items-center space-x-4 text-xs text-gray-500">
                        <span class="flex items-center"><span class="w-3 h-3 rounded-full bg-accent/80 mr-1"></span>Enviados</span>
                        <span class="flex items-center"><span class="w-3 h-3 rounded-full bg-blue-400/80 mr-1"></span>Recibidos</span>
                    </div>
                </div>

                <!-- Recent Conversations -->
                <div class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <h3 class="text-lg font-semibold text-gray-900 mb-4">Conversaciones Recientes</h3>
                    <div class="space-y-3" v-if="recentConversations.length">
                        <div v-for="conv in recentConversations" :key="conv.id" class="flex items-center p-3 rounded-xl hover:bg-gray-50 transition-colors">
                            <div class="w-10 h-10 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-sm font-bold shrink-0">
                                {{ (conv.contact_name || '?')[0].toUpperCase() }}
                            </div>
                            <div class="ml-3 flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ conv.contact_name }}</p>
                                <p class="text-xs text-gray-500 truncate">{{ conv.last_message || 'Sin mensajes' }}</p>
                            </div>
                            <span class="text-[10px] px-2 py-1 rounded-full font-medium"
                                :class="conv.status === 'open' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600'">
                                {{ conv.status === 'open' ? 'Abierto' : 'Cerrado' }}
                            </span>
                        </div>
                    </div>
                    <div v-else class="text-center text-gray-400 py-8">
                        <svg class="w-12 h-12 mx-auto mb-2 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" />
                        </svg>
                        <p class="text-sm">No hay conversaciones aún</p>
                    </div>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
