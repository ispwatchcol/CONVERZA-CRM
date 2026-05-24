<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Link } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, nextTick, watch } from 'vue';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    activeChat: { type: Array, default: () => [] },
    activeConversationId: { type: Number, default: null },
    activePhone: { type: String, default: null },
    activeName: { type: String, default: null },
    quickReplies: { type: Array, default: () => [] },
});

const showNewChatModal = ref(false);
const showQuickReplies = ref(false);
const messagesContainer = ref(null);
const search = ref('');
const mobileShowChat = ref(false);

const form = useForm({ phone: '', message: '', conversation_id: null });
const newChatForm = useForm({ phone: '', message: '' });

onMounted(() => {
    if (props.activePhone) {
        form.phone = props.activePhone;
        form.conversation_id = props.activeConversationId;
        scrollToBottom();
    }
});

watch(() => props.activeConversationId, (val) => {
    form.conversation_id = val;
    form.phone = props.activePhone || '';
    scrollToBottom();
});

watch(() => props.activeChat, () => scrollToBottom(), { deep: true });

function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

const submit = () => {
    if (!form.message.trim()) return;
    form.post(route('chat.send'), {
        onSuccess: () => { form.reset('message'); scrollToBottom(); },
        preserveScroll: true,
    });
};

const startNewChat = () => {
    if (!newChatForm.phone || !newChatForm.message) return;
    newChatForm.post(route('chat.send'), {
        onSuccess: () => { newChatForm.reset(); showNewChatModal.value = false; },
    });
};

function useQuickReply(qr) {
    form.message = qr.body;
    showQuickReplies.value = false;
}

function formatDate(dateString) {
    if (!dateString) return '';
    const date = new Date(dateString);
    const now = new Date();
    const isToday = date.toDateString() === now.toDateString();
    return new Intl.DateTimeFormat('es-ES', {
        hour: '2-digit', minute: '2-digit',
        day: isToday ? undefined : 'numeric',
        month: isToday ? undefined : 'short',
    }).format(date);
}

function formatPhone(phone) {
    if (!phone) return '';
    if (phone.startsWith('57') && phone.length === 12) return phone.substring(2);
    return phone;
}

function selectConversation(conv) {
    mobileShowChat.value = true;
}

const filteredConversations = () => {
    if (!search.value) return props.conversations;
    const s = search.value.toLowerCase();
    return props.conversations.filter(c => c.name?.toLowerCase().includes(s) || c.phone?.includes(s));
};

// Polling
let pollingInterval = null;
onMounted(() => {
    pollingInterval = setInterval(() => {
        router.reload({ only: ['conversations', 'activeChat'], preserveScroll: true, preserveState: true });
    }, 5000);
});
onUnmounted(() => { if (pollingInterval) clearInterval(pollingInterval); });
</script>

<template>
    <AppLayout>
        <div class="flex h-[calc(100vh-4rem)] overflow-hidden">
            <!-- Conversation List Sidebar -->
            <div class="w-full md:w-80 lg:w-96 border-r border-gray-200 flex flex-col bg-white shrink-0" :class="{ 'hidden md:flex': mobileShowChat }">
                <!-- Search Header -->
                <div class="p-3 border-b border-gray-100 shrink-0">
                    <div class="flex items-center space-x-2">
                        <div class="relative flex-1">
                            <input v-model="search" type="text" placeholder="Buscar conversación..." class="w-full pl-9 pr-4 py-2 bg-gray-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-accent/30">
                            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        </div>
                        <button @click="showNewChatModal = true" class="p-2 bg-accent text-white rounded-xl hover:bg-accent-hover transition">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        </button>
                    </div>
                </div>

                <!-- Conversation List -->
                <div class="flex-1 overflow-y-auto">
                    <template v-if="conversations.length">
                        <Link
                            v-for="conv in filteredConversations()"
                            :key="conv.id"
                            :href="route('chat.index', { conversation: conv.id })"
                            @click="selectConversation(conv)"
                            class="flex items-center p-3 hover:bg-gray-50 cursor-pointer border-b border-gray-50 transition"
                            :class="{ 'bg-accent/5 border-l-3 border-l-accent': activeConversationId === conv.id }"
                        >
                            <div class="w-11 h-11 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white font-bold text-sm shrink-0">
                                {{ (conv.name || '?')[0].toUpperCase() }}
                            </div>
                            <div class="ml-3 flex-1 min-w-0">
                                <div class="flex justify-between items-baseline mb-0.5">
                                    <h3 class="text-sm font-medium text-gray-900 truncate">{{ conv.name }}</h3>
                                    <span class="text-[10px] text-gray-400 ml-2 shrink-0">{{ formatDate(conv.last_message_at) }}</span>
                                </div>
                                <p class="text-xs text-gray-500 truncate">
                                    <span v-if="conv.last_message_status === 'sent'" class="mr-1">
                                        <svg class="h-3 w-3 inline text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    </span>
                                    {{ conv.last_message || 'Sin mensajes' }}
                                </p>
                            </div>
                        </Link>
                    </template>
                    <div v-else class="p-8 text-center text-gray-400">
                        <svg class="w-12 h-12 mx-auto mb-2 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
                        <p class="text-sm">No hay conversaciones</p>
                    </div>
                </div>
            </div>

            <!-- Chat Area -->
            <div class="flex-1 flex flex-col bg-[#efeae2] relative" :class="{ 'hidden md:flex': !mobileShowChat && conversations.length }">
                <!-- Chat Pattern BG -->
                <div class="absolute inset-0 opacity-5 pointer-events-none" style="background-image: url('data:image/svg+xml,%3Csvg width=&quot;60&quot; height=&quot;60&quot; viewBox=&quot;0 0 60 60&quot; xmlns=&quot;http://www.w3.org/2000/svg&quot;%3E%3Cg fill=&quot;none&quot; fill-rule=&quot;evenodd&quot;%3E%3Cg fill=&quot;%239C92AC&quot; fill-opacity=&quot;0.4&quot;%3E%3Cpath d=&quot;M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zm0-30V0h-2v4h-4v2h4v4h2V6h4V4h-4zM6 34v-4H4v4H0v2h4v4h2v-4h4v-2H6zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z&quot;/%3E%3C/g%3E%3C/g%3E%3C/svg%3E')"></div>

                <template v-if="activeConversationId">
                    <!-- Chat Header -->
                    <div class="bg-white p-3 border-b border-gray-200 flex items-center shadow-sm z-10 shrink-0">
                        <button @click="mobileShowChat = false" class="md:hidden p-1 mr-2 text-gray-500">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                        </button>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white font-bold shrink-0">
                            {{ (activeName || '?')[0].toUpperCase() }}
                        </div>
                        <div class="ml-3">
                            <h2 class="text-sm font-semibold text-gray-900">{{ activeName }}</h2>
                            <p class="text-[11px] text-accent">En línea</p>
                        </div>
                    </div>

                    <!-- Messages -->
                    <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-2 z-0 relative">
                        <div v-for="msg in activeChat" :key="msg.id" class="flex animate-fade-in" :class="{ 'justify-end': msg.status === 'sent' }">
                            <div class="max-w-[75%] rounded-2xl px-4 py-2 shadow-sm text-sm" :class="msg.status === 'sent' ? 'bg-[#d9fdd3] rounded-tr-sm' : 'bg-white rounded-tl-sm'">
                                <p class="text-gray-900 whitespace-pre-wrap break-words pb-4">{{ msg.body }}</p>
                                <div class="flex items-center justify-end space-x-1 -mt-3">
                                    <span class="text-[10px] text-gray-500">{{ new Date(msg.created_at).toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'}) }}</span>
                                    <svg v-if="msg.status === 'sent'" class="h-3.5 w-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Input Area -->
                    <div class="bg-[#f0f2f5] p-3 z-10 shrink-0">
                        <form @submit.prevent="submit" class="flex items-end space-x-2">
                            <!-- Quick replies button -->
                            <div class="relative">
                                <button type="button" @click="showQuickReplies = !showQuickReplies" class="p-2.5 text-gray-500 hover:text-accent transition rounded-lg hover:bg-white">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                </button>
                                <!-- Quick replies dropdown -->
                                <div v-if="showQuickReplies && quickReplies.length" class="absolute bottom-12 left-0 w-72 bg-white rounded-xl shadow-xl border border-gray-200 max-h-60 overflow-y-auto z-20 animate-scale-in">
                                    <div class="p-2">
                                        <button v-for="qr in quickReplies" :key="qr.id" type="button" @click="useQuickReply(qr)" class="w-full text-left p-3 rounded-lg hover:bg-gray-50 transition">
                                            <p class="text-sm font-medium text-gray-900">{{ qr.title }}</p>
                                            <p class="text-xs text-gray-500 truncate">{{ qr.body }}</p>
                                        </button>
                                    </div>
                                </div>
                            </div>

                            <div class="flex-1 bg-white rounded-xl flex items-center shadow-sm border border-gray-100 focus-within:ring-2 focus-within:ring-accent/30">
                                <textarea v-model="form.message" rows="1" placeholder="Escribe un mensaje" class="w-full border-none focus:ring-0 rounded-xl resize-none py-3 px-4 text-sm max-h-32 bg-transparent" @keydown.enter.exact.prevent="submit"></textarea>
                            </div>
                            <button type="submit" :disabled="form.processing || !form.message" class="p-3 bg-accent text-white rounded-xl hover:bg-accent-hover disabled:opacity-50 transition shadow-sm flex items-center justify-center h-11 w-11">
                                <svg v-if="!form.processing" class="w-5 h-5 rotate-90" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" /></svg>
                                <svg v-else class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                            </button>
                        </form>
                    </div>
                </template>

                <div v-else class="flex-1 flex flex-col items-center justify-center text-center p-8 bg-white/50">
                    <div class="w-24 h-24 mb-6 rounded-full bg-accent/10 flex items-center justify-center">
                        <svg class="w-12 h-12 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
                    </div>
                    <h2 class="text-2xl font-light text-gray-600 mb-2">Converza Chat</h2>
                    <p class="text-gray-400 max-w-md text-sm">Selecciona una conversación o inicia un nuevo chat</p>
                </div>
            </div>
        </div>

        <!-- New Chat Modal -->
        <Teleport to="body">
            <div v-if="showNewChatModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showNewChatModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md animate-scale-in">
                    <div class="p-6">
                        <h3 class="text-lg font-bold text-gray-900 mb-4">Nuevo Chat</h3>
                        <form @submit.prevent="startNewChat" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Teléfono</label>
                                <input v-model="newChatForm.phone" type="text" placeholder="573001234567" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <p class="text-xs text-gray-400 mt-1">Incluye código de país (57 para Colombia)</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Mensaje</label>
                                <textarea v-model="newChatForm.message" rows="3" placeholder="Hola..." class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"></textarea>
                            </div>
                            <div class="flex justify-end space-x-3 pt-2">
                                <button type="button" @click="showNewChatModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="newChatForm.processing" class="px-6 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">Enviar</button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>
    </AppLayout>
</template>
