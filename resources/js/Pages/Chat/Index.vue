<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, router, Link, Head, usePage } from '@inertiajs/vue3';
import { ref, onMounted, onUnmounted, nextTick, watch, computed } from 'vue';

const props = defineProps({
    conversations: { type: Array, default: () => [] },
    activeChat: { type: Array, default: () => [] },
    activeConversationId: { type: Number, default: null },
    activePhone: { type: String, default: null },
    activeName: { type: String, default: null },
    activeStatus: { type: String, default: null },
    activeAssignedTo: { type: Object, default: null },
    quickReplies: { type: Array, default: () => [] },
    ispwatchCustomer: { type: Object, default: null },
    ispwatchInvoices: { type: Array, default: () => [] },
    staffMembers: { type: Array, default: () => [] },
    myStaffMemberId: { type: Number, default: null },
    filter: { type: String, default: 'all' },
    filterCounts: { type: Object, default: () => ({}) },
    presence: { type: Object, default: () => ({ viewers: [] }) },
});

// Nombres de otros agentes viendo este chat ahora (detección de colisión).
const viewerNames = computed(() => (props.presence?.viewers ?? []).map(v => v.name).join(', '));

// ── Permisos según rol (viewer < agent < admin) ──────────────────────────────
// El backend ya bloquea estas acciones; aquí solo ocultamos la UI para no
// mostrar botones que devolverían 403.
const page = usePage();
const myRole = computed(() => page.props.auth?.user?.role ?? 'agent');
const canWrite = computed(() => myRole.value !== 'viewer'); // agent o admin
const isAdmin = computed(() => myRole.value === 'admin');

const showNewChatModal = ref(false);
const showQuickReplies = ref(false);
const messagesContainer = ref(null);
const search = ref('');
const mobileShowChat = ref(false);

const form = useForm({ phone: '', message: '', conversation_id: null });
const newChatForm = useForm({ phone: '', message: '' });
const mediaForm = useForm({ phone: '', file: null, caption: '', conversation_id: null });

const fileInputRef = ref(null);
const selectedFile = ref(null);
const filePreviewUrl = ref(null);

onMounted(() => {
    if (props.activePhone) {
        form.phone = props.activePhone;
        form.conversation_id = props.activeConversationId;
        scrollToBottom();
    }
    // Inicializar el rastreo del scroll inteligente con el estado actual,
    // así el primer polling no arrastra al usuario hacia abajo.
    trackedConvForScroll = props.activeConversationId;
    lastChatSignature = chatSignature(props.activeChat);
});

watch(() => props.activeConversationId, (val) => {
    form.conversation_id = val;
    form.phone = props.activePhone || '';
    clearSelectedFile();
    if (recording.value) cancelRecording();
    noteMode.value = false;
    // El scroll al cambiar de conversación lo maneja el watch de activeChat
    // (detecta convChanged), para no competir con el scroll inteligente.
});

// Auto-scroll inteligente: solo bajamos al final cuando el usuario ya estaba
// abajo, cuando cambia de conversación, o cuando llega un mensaje nuevo estando
// cerca del final. Así el polling de cada 5s no lo arrastra hacia abajo mientras
// lee mensajes antiguos.
let lastChatSignature = null;
let trackedConvForScroll = null;

function chatSignature(chat) {
    return chat.length ? `${chat.length}:${chat[chat.length - 1]?.id}` : '0';
}

function isNearBottom(threshold = 140) {
    const el = messagesContainer.value;
    if (!el) return true;
    return el.scrollHeight - el.scrollTop - el.clientHeight < threshold;
}

watch(() => props.activeChat, (newChat) => {
    const convChanged = props.activeConversationId !== trackedConvForScroll;
    const sig = chatSignature(newChat);
    const appended = sig !== lastChatSignature;
    const wasNearBottom = isNearBottom();
    trackedConvForScroll = props.activeConversationId;
    lastChatSignature = sig;
    if (convChanged || (appended && wasNearBottom)) {
        scrollToBottom();
    }
}, { deep: true });

function scrollToBottom() {
    nextTick(() => {
        if (messagesContainer.value) {
            messagesContainer.value.scrollTop = messagesContainer.value.scrollHeight;
        }
    });
}

// ── Nota interna vs. mensaje al cliente ──────────────────────────────────────
// Cuando noteMode está activo, el composer escribe una nota interna (solo el
// equipo la ve) en vez de enviar un WhatsApp al cliente.
const noteMode = ref(false);
const noteSending = ref(false);

// ¿Hay un envío en curso? Mientras lo haya, pausamos el polling para que un
// router.reload no cancele el request en vuelo (causa de imágenes que "se
// quedaban" sin enviarse).
const sending = computed(() => form.processing || mediaForm.processing || noteSending.value);

const submit = () => {
    if (!form.message.trim()) return;

    if (noteMode.value) {
        noteSending.value = true;
        router.post(route('chat.notes.store', props.activeConversationId), { note: form.message }, {
            preserveScroll: true,
            onSuccess: () => { form.reset('message'); scrollToBottom(); },
            onFinish: () => { noteSending.value = false; },
        });
        return;
    }

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

// Al elegir una respuesta rápida se envía directamente (un solo clic), sin
// pasar por nota interna: siempre va al cliente.
function useQuickReply(qr) {
    showQuickReplies.value = false;
    if (!qr.body?.trim() || form.processing) return;
    form.message = qr.body;
    form.post(route('chat.send'), {
        onSuccess: () => { form.reset('message'); scrollToBottom(); },
        preserveScroll: true,
    });
}

// ── Adjuntar medios (imagen / audio) ─────────────────────────────────────────
function openFileInput(accept) {
    if (fileInputRef.value) {
        fileInputRef.value.accept = accept;
        fileInputRef.value.click();
    }
}

function setSelectedFile(file) {
    if (!file) return;
    if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value);
    selectedFile.value = file;
    filePreviewUrl.value = file.type.startsWith('image/') ? URL.createObjectURL(file) : null;
    mediaForm.clearErrors();
    mediaForm.phone = props.activePhone || '';
    mediaForm.conversation_id = props.activeConversationId;
}

function onFileSelected(event) {
    setSelectedFile(event.target.files[0]);
    // Reset the input so the same file can be re-selected after clearing
    event.target.value = '';
}

// Pegar imagen desde el portapapeles (Ctrl+V dentro del cuadro de mensaje).
function onPaste(event) {
    const items = event.clipboardData?.items;
    if (!items) return;
    for (const item of items) {
        if (item.type && item.type.startsWith('image/')) {
            const blob = item.getAsFile();
            if (blob) {
                event.preventDefault();
                const ext = (item.type.split('/')[1] || 'png').replace('jpeg', 'jpg');
                const file = new File([blob], `pegado-${Date.now()}.${ext}`, { type: item.type });
                setSelectedFile(file);
            }
            break;
        }
    }
}

// ── Grabación de audio con el micrófono ───────────────────────────────────────
// Graba con MediaRecorder (webm/opus en Chrome/Edge). El backend lo transcodifica
// a OGG/opus con ffmpeg antes de enviarlo a WhatsApp.
const recording = ref(false);
const recordingTime = ref(0);
const recordError = ref(null);
let mediaRecorder = null;
let recordedChunks = [];
let recordTimer = null;
let recordStream = null;

function pickRecordMime() {
    if (typeof MediaRecorder === 'undefined') return '';
    const candidates = ['audio/webm;codecs=opus', 'audio/webm', 'audio/ogg;codecs=opus', 'audio/mp4'];
    for (const c of candidates) {
        try { if (MediaRecorder.isTypeSupported(c)) return c; } catch (e) {}
    }
    return '';
}

async function startRecording() {
    recordError.value = null;
    if (!navigator.mediaDevices?.getUserMedia || typeof MediaRecorder === 'undefined') {
        recordError.value = 'Tu navegador no permite grabar audio.';
        return;
    }
    try {
        recordStream = await navigator.mediaDevices.getUserMedia({ audio: true });
    } catch (e) {
        recordError.value = 'No se pudo acceder al micrófono. Revisa los permisos.';
        return;
    }
    const mime = pickRecordMime();
    recordedChunks = [];
    try {
        mediaRecorder = mime ? new MediaRecorder(recordStream, { mimeType: mime }) : new MediaRecorder(recordStream);
    } catch (e) {
        mediaRecorder = new MediaRecorder(recordStream);
    }
    mediaRecorder.ondataavailable = (e) => { if (e.data && e.data.size > 0) recordedChunks.push(e.data); };
    mediaRecorder.onstop = () => finalizeRecording();
    mediaRecorder.start();
    recording.value = true;
    recordingTime.value = 0;
    recordTimer = setInterval(() => { recordingTime.value += 1; }, 1000);
}

function stopTracks() {
    if (recordStream) { recordStream.getTracks().forEach(t => t.stop()); recordStream = null; }
    if (recordTimer) { clearInterval(recordTimer); recordTimer = null; }
}

function stopRecording() {
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.stop(); // dispara onstop → finalizeRecording
    }
    recording.value = false;
    if (recordTimer) { clearInterval(recordTimer); recordTimer = null; }
}

function cancelRecording() {
    recordedChunks = [];
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
        mediaRecorder.onstop = null;
        try { mediaRecorder.stop(); } catch (e) {}
    }
    mediaRecorder = null;
    recording.value = false;
    stopTracks();
}

function finalizeRecording() {
    const type = (mediaRecorder?.mimeType || recordedChunks[0]?.type || 'audio/webm').split(';')[0];
    const blob = new Blob(recordedChunks, { type });
    stopTracks();
    mediaRecorder = null;
    recordedChunks = [];
    if (!blob.size) return;
    const ext = type.includes('ogg') ? 'ogg' : type.includes('mp4') ? 'm4a' : 'webm';
    const file = new File([blob], `nota-voz-${Date.now()}.${ext}`, { type });
    setSelectedFile(file);
}

function fmtRecordTime(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return `${m}:${s.toString().padStart(2, '0')}`;
}

onUnmounted(() => { stopTracks(); });

function clearSelectedFile() {
    if (filePreviewUrl.value) URL.revokeObjectURL(filePreviewUrl.value);
    selectedFile.value = null;
    filePreviewUrl.value = null;
    mediaForm.reset();
}

function submitMedia() {
    if (!selectedFile.value) return;
    mediaForm.file = selectedFile.value;
    mediaForm.phone = props.activePhone || '';
    mediaForm.conversation_id = props.activeConversationId;
    mediaForm.post(route('chat.send-media'), {
        forceFormData: true,
        onSuccess: () => { clearSelectedFile(); scrollToBottom(); },
        preserveScroll: true,
    });
}

function isAudioFile(file) {
    return file?.type?.startsWith('audio/');
}

// Normaliza el MIME para el atributo type del <source>.
// WhatsApp envía p.ej. "audio/ogg; codecs=opus" que algunos navegadores
// no reconocen correctamente en el atributo type.
function normalizeAudioMime(mime) {
    if (!mime) return 'audio/ogg';
    // Quitar parámetros (codecs=opus, etc.)
    const base = mime.split(';')[0].trim();
    return base || 'audio/ogg';
}
// ─────────────────────────────────────────────────────────────────────────────

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

function formatTime(dateString) {
    if (!dateString) return '';
    return new Date(dateString).toLocaleTimeString([], { hour: '2-digit', minute: '2-digit' });
}

function formatPhone(phone) {
    if (!phone) return '';
    if (phone.startsWith('57') && phone.length === 12) return phone.substring(2);
    return phone;
}

function selectConversation(conv) {
    mobileShowChat.value = true;
    delete unreadConvIds.value[conv.id];
}

const filteredConversations = () => {
    if (!search.value) return props.conversations;
    const s = search.value.toLowerCase();
    return props.conversations.filter(c => c.name?.toLowerCase().includes(s) || c.phone?.includes(s));
};

const deleteConfirm = ref({ show: false, id: null, name: '' });

function confirmDelete(conv) {
    deleteConfirm.value = { show: true, id: conv.id, name: conv.name || conv.phone || 'este chat' };
}

function executeDelete() {
    router.delete(route('chat.conversations.destroy', { conversation: deleteConfirm.value.id }), {
        onSuccess: () => { deleteConfirm.value = { show: false, id: null, name: '' }; },
    });
}

// ── Notificaciones: sonido + título + badge de no leídos ─────────────────────
let sharedAudioCtx = null;
const unreadConvIds = ref({});
let _unreadTotal = 0;
const prevConvTimestamps = ref({});
const docTitle = typeof document !== 'undefined' ? document.title : 'Converza';
let titleBlinker = null;

function unlockAudio() {
    try {
        if (!sharedAudioCtx) sharedAudioCtx = new (window.AudioContext || window.webkitAudioContext)();
        if (sharedAudioCtx.state === 'suspended') sharedAudioCtx.resume();
    } catch (e) {}
}

function playNotificationSound() {
    if (!sharedAudioCtx || sharedAudioCtx.state !== 'running') return;
    try {
        const ctx = sharedAudioCtx;
        const t = ctx.currentTime;
        [{ f: 523.25, s: 0, d: 0.3 }, { f: 659.25, s: 0.12, d: 0.4 }].forEach(({ f, s, d }) => {
            const osc = ctx.createOscillator();
            const g = ctx.createGain();
            osc.type = 'sine';
            osc.frequency.value = f;
            g.gain.setValueAtTime(0.4, t + s);
            g.gain.exponentialRampToValueAtTime(0.001, t + s + d);
            osc.connect(g);
            g.connect(ctx.destination);
            osc.start(t + s);
            osc.stop(t + s + d);
        });
    } catch (e) {}
}

function blinkTitle(count) {
    if (titleBlinker) clearInterval(titleBlinker);
    let show = true;
    titleBlinker = setInterval(() => {
        document.title = show ? `(${count}) Nuevo mensaje` : docTitle;
        show = !show;
    }, 1000);
}

function clearNotifications() {
    _unreadTotal = 0;
    unreadConvIds.value = {};
    if (titleBlinker) { clearInterval(titleBlinker); titleBlinker = null; }
    document.title = docTitle;
}

watch(() => props.conversations, (newConvs) => {
    let incoming = 0;
    newConvs.forEach(c => {
        const prev = prevConvTimestamps.value[c.id];
        if (prev !== undefined && c.last_message_at !== prev && c.last_message_status !== 'sent') {
            incoming++;
            unreadConvIds.value[c.id] = true;
        }
        prevConvTimestamps.value[c.id] = c.last_message_at;
    });
    if (incoming > 0) {
        _unreadTotal += incoming;
        playNotificationSound();
        blinkTitle(_unreadTotal);
    }
}, { deep: true });

onMounted(() => {
    props.conversations.forEach(c => { prevConvTimestamps.value[c.id] = c.last_message_at; });
    document.addEventListener('click', unlockAudio, { once: true });
    window.addEventListener('focus', clearNotifications);
});

onUnmounted(() => {
    window.removeEventListener('focus', clearNotifications);
    clearNotifications();
});
// ─────────────────────────────────────────────────────────────────────────────

// ── Audio player ─────────────────────────────────────────────────────────────
const audioStates = ref({});

function audioState(id) {
    if (!audioStates.value[id]) {
        audioStates.value[id] = { playing: false, currentTime: 0, duration: 0, error: false };
    }
    return audioStates.value[id];
}

function toggleAudio(id) {
    const el = document.getElementById('audio-' + id);
    if (!el) return;
    if (audioState(id).playing) {
        el.pause();
    } else {
        // pause any other playing audio
        Object.keys(audioStates.value).forEach(otherId => {
            if (otherId !== String(id) && audioStates.value[otherId].playing) {
                document.getElementById('audio-' + otherId)?.pause();
                audioStates.value[otherId].playing = false;
            }
        });
        el.playbackRate = playbackRate.value;
        el.play().catch(() => { audioState(id).error = true; });
    }
}

function onAudioPlay(id)        { audioState(id).playing = true; audioState(id).error = false; }
function onAudioPause(id)       { audioState(id).playing = false; }
function onAudioEnded(id)       { audioState(id).playing = false; audioState(id).currentTime = 0; }
function onAudioError(id)       {
    const el = document.getElementById('audio-' + id);
    // Solo marcar error si realmente no se puede reproducir
    // (ignorar errores de precarga de metadatos cuando la fuente aún no cargó)
    if (el && el.networkState === HTMLMediaElement.NETWORK_NO_SOURCE) {
        audioState(id).error = true;
    } else if (el && el.error) {
        audioState(id).error = true;
        console.warn(`Audio ${id} error:`, el.error.code, el.error.message);
    }
}
function onAudioTimeUpdate(id)  { const el = document.getElementById('audio-' + id); if (el) audioState(id).currentTime = el.currentTime; }
function onAudioMetadata(id)    { const el = document.getElementById('audio-' + id); if (el) { audioState(id).duration = el.duration; audioState(id).error = false; } }

function audioProgress(id) {
    const s = audioState(id);
    return s.duration ? (s.currentTime / s.duration) * 100 : 0;
}

function fmtAudioTime(sec) {
    if (!sec || isNaN(sec)) return '0:00';
    const m = Math.floor(sec / 60);
    const s = Math.floor(sec % 60);
    return `${m}:${s.toString().padStart(2, '0')}`;
}

// ── Velocidad de reproducción (x1 / x1.5 / x2) + adelantar (seek) ─────────────
const playbackRate = ref(1);
const RATE_STEPS = [1, 1.5, 2];

function cyclePlaybackRate() {
    const idx = RATE_STEPS.indexOf(playbackRate.value);
    playbackRate.value = RATE_STEPS[(idx + 1) % RATE_STEPS.length];
    // Aplicar de inmediato a cualquier audio que esté sonando.
    Object.keys(audioStates.value).forEach((id) => {
        const el = document.getElementById('audio-' + id);
        if (el) el.playbackRate = playbackRate.value;
    });
}

function seekAudio(id, event) {
    const el = document.getElementById('audio-' + id);
    if (!el || !el.duration || isNaN(el.duration)) return;
    const rect = event.currentTarget.getBoundingClientRect();
    const ratio = Math.min(1, Math.max(0, (event.clientX - rect.left) / rect.width));
    el.currentTime = ratio * el.duration;
    audioState(id).currentTime = el.currentTime;
}

// ── Image error tracking ──────────────────────────────────────────────────────
const imgErrors = ref({});
function onImgError(id) { imgErrors.value[id] = true; }
// ─────────────────────────────────────────────────────────────────────────────

// ── Filtros de lista de conversaciones ───────────────────────────────────────
const filterChips = [
    { value: 'all',        label: 'Todas' },
    { value: 'open',       label: 'Abiertas' },
    { value: 'mine',       label: 'Mías' },
    { value: 'unassigned', label: 'Sin asignar' },
    { value: 'closed',     label: 'Cerradas' },
];

function setFilter(value) {
    router.get(route('chat.index'), {
        filter: value !== 'all' ? value : undefined,
        conversation: props.activeConversationId || undefined,
    }, { preserveState: true, preserveScroll: true, replace: true });
}

// ── Asignación de agente ─────────────────────────────────────────────────────
const showAssignMenu = ref(false);

function assignTo(staffMemberId) {
    router.patch(route('chat.conversations.assign', props.activeConversationId), {
        staff_member_id: staffMemberId,
    }, {
        preserveScroll: true,
        onSuccess: () => { showAssignMenu.value = false; },
    });
}

function unassign() {
    assignTo(null);
}

// ── Reabrir conversación ─────────────────────────────────────────────────────
function reopenConversation() {
    if (!confirm('¿Reabrir esta conversación? Vas a poder enviar mensajes de nuevo.')) return;
    router.post(route('chat.conversations.reopen', props.activeConversationId), {}, {
        preserveScroll: true,
    });
}

// ── Cerrar conversación (closing note) ───────────────────────────────────────
const showCloseModal = ref(false);
const closingForm = useForm({
    conversation_id: null,
    note: '',
    close_conversation: true,
});

const closingTemplates = [
    'Cliente atendido, solicitud resuelta.',
    'Sin respuesta del cliente. Cerrado por inactividad.',
    'Problema técnico escalado al equipo correspondiente.',
    'Información proporcionada exitosamente.',
    'Cliente confirmó pago de factura pendiente.',
];

function openCloseModal() {
    closingForm.reset();
    closingForm.conversation_id = props.activeConversationId;
    closingForm.close_conversation = true;
    showCloseModal.value = true;
}

function applyClosingTemplate(text) {
    closingForm.note = closingForm.note ? (closingForm.note + ' ' + text).trim() : text;
}

function submitClosing() {
    closingForm.post(route('closing-notes.store'), {
        preserveScroll: true,
        onSuccess: () => {
            showCloseModal.value = false;
            closingForm.reset();
            // Refrescar conversaciones + status para que el badge cambie a "Cerrada"
            router.reload({ only: ['conversations', 'activeStatus'], preserveScroll: true });
        },
    });
}

// ── ISPWatch sidebar helpers ─────────────────────────────────────────────────
// En desktop (lg+) el panel se muestra al lado del chat por defecto.
// En mobile/tablet (< lg) se abre como drawer overlay desde la derecha,
// cerrado por defecto para no robar espacio al chat.
const showCustomerPanel = ref(typeof window !== 'undefined' ? window.innerWidth >= 1024 : true);

function formatMoney(amount, currency = 'COP') {
    if (amount == null) return '—';
    const n = Number(amount);
    if (!Number.isFinite(n)) return amount;
    try {
        return new Intl.NumberFormat('es-CO', { style: 'currency', currency, maximumFractionDigits: 0 }).format(n);
    } catch (e) {
        return n.toLocaleString('es-CO') + ' ' + currency;
    }
}

function formatDueDate(iso) {
    if (!iso) return '—';
    const d = new Date(iso);
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }).format(d);
}

function isOverdue(iso) {
    if (!iso) return false;
    return new Date(iso).getTime() < Date.now();
}

function serviceStatusClass(status) {
    const s = (status || '').toLowerCase();
    if (['activo', 'active', 'enabled'].includes(s)) return 'bg-green-100 text-green-700';
    if (['suspendido', 'suspended', 'inactivo'].includes(s)) return 'bg-red-100 text-red-700';
    if (['pendiente', 'pending'].includes(s)) return 'bg-yellow-100 text-yellow-700';
    return 'bg-gray-100 text-gray-700';
}
// ─────────────────────────────────────────────────────────────────────────────

// ── Location helpers ─────────────────────────────────────────────────────────
const COORDS_REGEX = /(-?\d+\.\d+)\s*,\s*(-?\d+\.\d+)/;

function locationCoords(body) {
    const m = body?.match(COORDS_REGEX);
    return m ? `${m[1]}, ${m[2]}` : null;
}

function googleMapsUrl(body) {
    const m = body?.match(COORDS_REGEX);
    return m ? `https://www.google.com/maps?q=${m[1]},${m[2]}` : null;
}
// ─────────────────────────────────────────────────────────────────────────────

// Polling
let pollingInterval = null;
onMounted(() => {
    pollingInterval = setInterval(() => {
        // No recargar mientras hay un envío en curso: un router.reload cancelaría
        // el request en vuelo (por eso una imagen podía "quedarse" sin enviarse).
        if (sending.value) return;
        // 'presence' + 'staffMembers' también registran el heartbeat de presencia
        // en el backend (el partial reload ejecuta ChatController::index completo).
        router.reload({ only: ['conversations', 'activeChat', 'staffMembers', 'presence'], preserveScroll: true, preserveState: true });
    }, 5000);
});
onUnmounted(() => { if (pollingInterval) clearInterval(pollingInterval); });

// Cerrar el drawer del cliente al pasar de mobile a desktop para evitar
// que el backdrop quede atrapado en una capa que ya no debería verse.
function handleResize() {
    if (typeof window === 'undefined') return;
    if (window.innerWidth >= 1024) {
        // En desktop, abrir por defecto si hay conversación activa
        showCustomerPanel.value = true;
    } else {
        // En mobile/tablet, cerrar para no robar espacio al chat
        showCustomerPanel.value = false;
    }
}
onMounted(() => window.addEventListener('resize', handleResize));
onUnmounted(() => window.removeEventListener('resize', handleResize));
</script>

<template>
    <Head title="Chat" />
    <AppLayout>
        <div class="flex h-[calc(100vh-4rem)] overflow-hidden">
            <!-- Conversation List Sidebar -->
            <div class="w-full md:w-80 lg:w-96 border-r border-gray-200 flex flex-col bg-white shrink-0" :class="{ 'hidden md:flex': mobileShowChat }">
                <!-- Search Header -->
                <div class="p-3 border-b border-gray-100 shrink-0 space-y-2">
                    <div class="flex items-center space-x-2">
                        <div class="relative flex-1">
                            <input v-model="search" type="text" placeholder="Buscar conversación..." class="w-full pl-9 pr-4 py-2 bg-gray-50 border-none rounded-xl text-sm focus:ring-2 focus:ring-accent/30">
                            <svg class="w-4 h-4 text-gray-400 absolute left-3 top-2.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" /></svg>
                        </div>
                        <button @click="showNewChatModal = true" class="p-2 bg-accent text-white rounded-xl hover:bg-accent-hover transition" title="Nuevo chat">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4" /></svg>
                        </button>
                    </div>

                    <!-- Filter chips -->
                    <div class="flex gap-1 overflow-x-auto pb-1 -mx-0.5 px-0.5">
                        <button v-for="opt in filterChips" :key="opt.value" @click="setFilter(opt.value)"
                                class="shrink-0 inline-flex items-center gap-1 px-2.5 py-1 rounded-full text-[11px] font-medium transition whitespace-nowrap"
                                :class="filter === opt.value ? 'bg-accent text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'">
                            {{ opt.label }}
                            <span v-if="filterCounts[opt.value] !== undefined"
                                  class="px-1.5 py-0 rounded-full text-[9px] font-semibold tabular-nums"
                                  :class="filter === opt.value ? 'bg-white/25' : 'bg-white text-gray-500'">
                                {{ filterCounts[opt.value] }}
                            </span>
                        </button>
                    </div>
                </div>

                <!-- Conversation List -->
                <div class="flex-1 overflow-y-auto">
                    <template v-if="conversations.length">
                        <div
                            v-for="conv in filteredConversations()"
                            :key="conv.id"
                            class="relative group border-b border-gray-50"
                        >
                            <Link
                                :href="route('chat.index', { conversation: conv.id })"
                                @click="selectConversation(conv)"
                                class="flex items-center p-3 hover:bg-gray-50 cursor-pointer transition pr-9"
                                :class="{ 'bg-accent/5 border-l-3 border-l-accent': activeConversationId === conv.id }"
                            >
                                <div class="relative shrink-0">
                                    <div class="w-11 h-11 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white font-bold text-sm">
                                        {{ (conv.name || '?')[0].toUpperCase() }}
                                    </div>
                                    <!-- Mini avatar del agente asignado -->
                                    <div v-if="conv.assigned_to"
                                         class="absolute -bottom-0.5 -right-0.5 w-4 h-4 rounded-full bg-emerald-500 ring-2 ring-white flex items-center justify-center text-[8px] font-bold text-white"
                                         :title="`Asignado a ${conv.assigned_to.name}`">
                                        {{ conv.assigned_to.initial?.toUpperCase() }}
                                    </div>
                                    <!-- Sin asignar dot -->
                                    <div v-else-if="conv.status === 'open'"
                                         class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-amber-400 ring-2 ring-white"
                                         title="Sin asignar"></div>
                                </div>
                                <div class="ml-3 flex-1 min-w-0">
                                    <div class="flex justify-between items-baseline mb-0.5">
                                        <h3 class="text-sm font-medium text-gray-900 truncate flex items-center gap-1.5">
                                            {{ conv.name }}
                                            <span v-if="conv.status === 'closed'" class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide bg-gray-100 text-gray-500">
                                                Cerrada
                                            </span>
                                        </h3>
                                        <div class="flex items-center gap-1.5 ml-2 shrink-0">
                                            <span class="text-[10px] text-gray-400">{{ formatDate(conv.last_message_at) }}</span>
                                            <span v-if="unreadConvIds[conv.id]" class="w-2 h-2 rounded-full bg-emerald-500"></span>
                                        </div>
                                    </div>
                                    <p class="text-xs truncate" :class="unreadConvIds[conv.id] ? 'text-gray-900 font-semibold' : 'text-gray-500'">
                                        <span v-if="conv.last_message_status === 'sent'" class="mr-1">
                                            <svg class="h-3 w-3 inline text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        </span>
                                        {{ conv.last_message || 'Sin mensajes' }}
                                    </p>
                                </div>
                            </Link>
                            <button
                                v-if="isAdmin"
                                @click.prevent.stop="confirmDelete(conv)"
                                class="absolute top-1/2 -translate-y-1/2 right-2 p-1.5 rounded-full text-gray-400 hover:text-red-500 hover:bg-red-50 opacity-0 group-hover:opacity-100 transition-opacity"
                                title="Eliminar conversación"
                            >
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </button>
                        </div>
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
                    <div class="bg-white p-3 border-b border-gray-200 flex items-center shadow-sm z-10 shrink-0 gap-2">
                        <button @click="mobileShowChat = false" class="md:hidden p-1 text-gray-500">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5" /></svg>
                        </button>
                        <div class="w-10 h-10 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white font-bold shrink-0">
                            {{ (activeName || '?')[0].toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <h2 class="text-sm font-semibold text-gray-900 truncate">{{ activeName }}</h2>
                            <p v-if="activeStatus === 'closed'" class="text-[11px] text-gray-500 flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-gray-400"></span>
                                Conversación cerrada
                            </p>
                            <p v-else class="text-[11px] text-accent flex items-center gap-1">
                                <span class="w-1.5 h-1.5 rounded-full bg-accent"></span>
                                En línea
                            </p>
                        </div>

                        <!-- ── Asignación (dropdown) ── -->
                        <div v-if="canWrite" class="relative">
                            <button @click="showAssignMenu = !showAssignMenu"
                                    class="inline-flex items-center gap-1.5 px-2.5 py-1.5 border border-gray-200 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-300 transition">
                                <span v-if="activeAssignedTo"
                                      class="w-5 h-5 rounded-full bg-emerald-500 flex items-center justify-center text-[9px] font-bold text-white">
                                    {{ activeAssignedTo.initial?.toUpperCase() }}
                                </span>
                                <svg v-else class="w-3.5 h-3.5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z"/></svg>
                                <span class="hidden sm:inline truncate max-w-[90px]">{{ activeAssignedTo ? activeAssignedTo.name : 'Sin asignar' }}</span>
                                <svg class="w-3 h-3 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 8.25l-7.5 7.5-7.5-7.5"/></svg>
                            </button>

                            <!-- Dropdown -->
                            <Teleport to="body">
                                <div v-if="showAssignMenu" class="fixed inset-0 z-[55]" @click="showAssignMenu = false"></div>
                            </Teleport>
                            <div v-if="showAssignMenu"
                                 class="absolute right-0 mt-1 w-60 bg-white rounded-xl shadow-xl border border-gray-200 py-1 z-[56] animate-scale-in">
                                <p class="px-3 py-1.5 text-[10px] font-semibold text-gray-500 uppercase tracking-wider">Asignar a</p>

                                <button v-if="myStaffMemberId !== activeAssignedTo?.id" @click="assignTo(myStaffMemberId)"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-accent/10 transition">
                                    <span class="w-6 h-6 rounded-full bg-accent flex items-center justify-center text-[10px] font-bold text-white">YO</span>
                                    <span class="flex-1 text-left">Asignarme</span>
                                </button>

                                <button v-for="staff in staffMembers.filter(s => !s.is_me)" :key="staff.id"
                                        @click="assignTo(staff.id)"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition"
                                        :class="{ 'bg-emerald-50': activeAssignedTo?.id === staff.id }">
                                    <span class="relative shrink-0">
                                        <span class="w-6 h-6 rounded-full bg-emerald-500 flex items-center justify-center text-[10px] font-bold text-white">
                                            {{ staff.initial?.toUpperCase() }}
                                        </span>
                                        <span v-if="staff.is_online" class="absolute -bottom-0.5 -right-0.5 w-2.5 h-2.5 rounded-full bg-green-400 ring-2 ring-white" title="En línea"></span>
                                    </span>
                                    <span class="flex-1 text-left">
                                        {{ staff.name }}
                                        <span v-if="staff.is_online" class="block text-[9px] text-green-600 font-medium leading-tight">en línea</span>
                                    </span>
                                    <svg v-if="activeAssignedTo?.id === staff.id" class="w-4 h-4 text-emerald-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                </button>

                                <button v-if="activeAssignedTo" @click="unassign"
                                        class="w-full flex items-center gap-2 px-3 py-2 text-sm text-gray-500 hover:bg-red-50 hover:text-red-700 transition border-t border-gray-100 mt-1">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                    <span>Quitar asignación</span>
                                </button>
                            </div>
                        </div>

                        <button v-if="canWrite && activeStatus !== 'closed'" @click="openCloseModal"
                                class="hidden sm:inline-flex items-center gap-1.5 px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-300 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            Cerrar
                        </button>

                        <!-- Ficha toggle (visible en todos los tamaños) -->
                        <button @click="showCustomerPanel = !showCustomerPanel"
                                class="inline-flex items-center gap-1.5 px-2.5 sm:px-3 py-1.5 border border-gray-200 rounded-lg text-xs font-medium text-gray-700 hover:bg-gray-50 hover:border-gray-300 transition"
                                :class="{ 'bg-accent/10 border-accent/30 text-accent': showCustomerPanel }"
                                :title="showCustomerPanel ? 'Ocultar ficha' : 'Mostrar ficha'">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
                            <span class="hidden sm:inline">Ficha</span>
                        </button>
                    </div>

                    <!-- Banner de colisión: otros agentes viendo este chat ahora -->
                    <div v-if="presence.viewers.length" class="bg-amber-50 border-b border-amber-200 px-4 py-1.5 flex items-center gap-2 text-[11px] text-amber-800 shrink-0 z-10">
                        <svg class="w-3.5 h-3.5 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                        <span><strong class="font-semibold">{{ viewerNames }}</strong> {{ presence.viewers.length > 1 ? 'también están' : 'también está' }} viendo este chat ahora.</span>
                    </div>

                    <!-- Messages -->
                    <div ref="messagesContainer" class="flex-1 overflow-y-auto p-4 space-y-2 z-0 relative">
                        <div v-for="msg in activeChat" :key="msg.id" class="flex animate-fade-in"
                             :class="{ 'justify-end': msg.status === 'sent' && msg.type !== 'system' && msg.type !== 'note', 'justify-center': msg.type === 'system' || msg.type === 'note' }">

                            <!-- ─── Sistema (transferencias / eventos) ──────────────── -->
                            <div v-if="msg.type === 'system'" class="max-w-[85%] my-1">
                                <div class="inline-block rounded-lg bg-black/5 px-3 py-1 text-[11px] text-gray-500 text-center">
                                    {{ msg.body }}
                                </div>
                            </div>

                            <!-- ─── Nota interna (solo el equipo, NO se envía al cliente) ─── -->
                            <div v-else-if="msg.type === 'note'" class="max-w-[85%] my-1">
                                <div class="rounded-xl bg-amber-50 border border-amber-200 px-3 py-2 shadow-sm">
                                    <div class="flex items-center gap-1.5 mb-0.5">
                                        <svg class="w-3.5 h-3.5 text-amber-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                        <span class="text-[10px] font-semibold text-amber-700 uppercase tracking-wide">Nota interna</span>
                                        <span v-if="msg.sender_name" class="text-[10px] text-amber-600">· {{ msg.sender_name }}</span>
                                        <span class="text-[9px] text-amber-500/80 italic ml-auto">solo el equipo</span>
                                    </div>
                                    <p class="text-sm text-amber-900 whitespace-pre-wrap break-words">{{ msg.body }}</p>
                                    <div class="flex items-center justify-end mt-0.5">
                                        <span class="text-[10px] text-amber-500">{{ formatTime(msg.created_at) }}</span>
                                    </div>
                                </div>
                            </div>

                            <!-- ─── Sticker (badge mínimo, no se descarga) ──────────── -->
                            <div v-else-if="msg.type === 'sticker'" class="max-w-[75%] flex flex-col" :class="msg.status === 'sent' ? 'items-end' : 'items-start'">
                                <div class="inline-flex items-center gap-1.5 rounded-xl px-3 py-1.5 shadow-sm text-xs text-gray-500 italic"
                                     :class="msg.status === 'sent' ? 'bg-[#d9fdd3]' : 'bg-white'">
                                    <span>🎭</span><span>Sticker</span>
                                </div>
                                <div class="mt-1 flex items-center gap-1 px-1">
                                    <span class="text-[10px] text-gray-400">{{ formatTime(msg.created_at) }}</span>
                                    <svg v-if="msg.status === 'sent'" class="h-3 w-3 text-blue-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </div>
                            </div>

                            <!-- ─── Imagen ─────────────────────────────────────────── -->
                            <div
                                v-else-if="msg.type === 'image'"
                                class="max-w-[75%] rounded-2xl shadow-sm overflow-hidden"
                                :class="msg.status === 'sent' ? 'bg-[#d9fdd3] rounded-tr-sm' : 'bg-white rounded-tl-sm'"
                            >
                                <p v-if="msg.status === 'sent' && msg.sender_name" class="px-3 pt-1.5 text-[11px] font-semibold text-emerald-600">
                                    {{ msg.sender_name }}
                                </p>
                                <!-- Imagen disponible y sin error de carga -->
                                <div v-if="msg.media_url && !imgErrors[msg.id]" class="relative p-1">
                                    <a :href="msg.media_url" target="_blank" rel="noopener noreferrer" class="block">
                                        <img
                                            :src="msg.media_url"
                                            alt="imagen"
                                            class="max-w-full max-h-80 rounded-xl object-cover block"
                                            @error="onImgError(msg.id)"
                                        />
                                    </a>
                                    <div v-if="!msg.caption" class="absolute bottom-2 right-2 inline-flex items-center space-x-1 bg-black/55 backdrop-blur-sm rounded-full px-2 py-0.5">
                                        <span class="text-[10px] text-white/95">{{ formatTime(msg.created_at) }}</span>
                                        <svg v-if="msg.status === 'sent'" class="h-3 w-3 text-blue-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    </div>
                                </div>
                                <!-- Imagen no disponible o error de carga -->
                                <div v-else class="flex items-center gap-2.5 px-3 py-3 min-w-[180px]">
                                    <div class="w-9 h-9 rounded-lg bg-gray-200 flex items-center justify-center shrink-0">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z" /></svg>
                                    </div>
                                    <p class="text-sm text-gray-400 italic">Imagen no disponible</p>
                                </div>
                                <!-- Caption -->
                                <div v-if="msg.caption" class="px-3 pb-1.5 pt-0.5">
                                    <p class="text-sm text-gray-900 whitespace-pre-wrap break-words">{{ msg.caption }}</p>
                                    <div class="flex items-center justify-end space-x-1 mt-0.5">
                                        <span class="text-[10px] text-gray-500">{{ formatTime(msg.created_at) }}</span>
                                        <svg v-if="msg.status === 'sent'" class="h-3.5 w-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    </div>
                                </div>
                                <!-- Hora sola cuando no hay caption y la imagen falló -->
                                <div v-else-if="!msg.media_url || imgErrors[msg.id]" class="flex items-center justify-end gap-1 px-3 pb-1.5">
                                    <span class="text-[10px] text-gray-500">{{ formatTime(msg.created_at) }}</span>
                                    <svg v-if="msg.status === 'sent'" class="h-3.5 w-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </div>
                            </div>

                            <!-- ─── Video (enlace de descarga) ───────────────────────── -->
                            <div
                                v-else-if="msg.type === 'video'"
                                class="max-w-[75%] rounded-2xl shadow-sm overflow-hidden"
                                :class="msg.status === 'sent' ? 'bg-[#d9fdd3] rounded-tr-sm' : 'bg-white rounded-tl-sm'"
                            >
                                <p v-if="msg.status === 'sent' && msg.sender_name" class="px-3 pt-1.5 text-[11px] font-semibold text-emerald-600">
                                    {{ msg.sender_name }}
                                </p>
                                <a
                                    v-if="msg.media_url"
                                    :href="msg.media_url"
                                    :download="msg.media_filename || 'video'"
                                    class="flex items-center gap-2.5 px-3 py-2.5 hover:bg-black/5 transition min-w-[200px]"
                                >
                                    <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0"
                                         :class="msg.status === 'sent' ? 'bg-green-500' : 'bg-accent'">
                                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                                    </div>
                                    <div class="min-w-0 flex-1">
                                        <p class="text-sm font-medium text-gray-900 truncate">{{ msg.media_filename || 'Video' }}</p>
                                        <p class="text-[10px] text-gray-500">Toca para ver o descargar</p>
                                    </div>
                                    <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" /></svg>
                                </a>
                                <div v-else class="flex items-center gap-2.5 px-3 py-2.5 min-w-[200px]">
                                    <div class="w-10 h-10 rounded-full bg-gray-200 flex items-center justify-center shrink-0">
                                        <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                                    </div>
                                    <p class="text-sm text-gray-400 italic">Video no disponible</p>
                                </div>
                                <div v-if="msg.caption" class="px-3 pt-0.5 pb-1">
                                    <p class="text-sm text-gray-900 whitespace-pre-wrap break-words">{{ msg.caption }}</p>
                                </div>
                                <div class="flex items-center justify-end gap-1 px-3 pb-1.5">
                                    <span class="text-[10px] text-gray-500">{{ formatTime(msg.created_at) }}</span>
                                    <svg v-if="msg.status === 'sent'" class="h-3.5 w-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </div>
                            </div>

                            <!-- ─── Audio / Documento / Texto ──────────────────────── -->
                            <div
                                v-else
                                class="max-w-[75%] rounded-2xl shadow-sm overflow-hidden"
                                :class="msg.status === 'sent' ? 'bg-[#d9fdd3] rounded-tr-sm' : 'bg-white rounded-tl-sm'"
                            >
                                <!-- Nombre del asesor que envió (solo mensajes salientes) -->
                                <p v-if="msg.status === 'sent' && msg.sender_name" class="px-4 pt-1.5 text-[11px] font-semibold text-emerald-600">
                                    {{ msg.sender_name }}
                                </p>

                                <template v-if="msg.type === 'audio'">
                                    <!-- Hidden native audio element -->
                                    <audio
                                        v-if="msg.media_url"
                                        :id="'audio-' + msg.id"
                                        :src="msg.media_url"
                                        preload="metadata"
                                        class="hidden"
                                        @play="onAudioPlay(msg.id)"
                                        @pause="onAudioPause(msg.id)"
                                        @ended="onAudioEnded(msg.id)"
                                        @error="onAudioError(msg.id)"
                                        @timeupdate="onAudioTimeUpdate(msg.id)"
                                        @loadedmetadata="onAudioMetadata(msg.id)"
                                    >
                                        <source :src="msg.media_url" :type="normalizeAudioMime(msg.media_mime)" />
                                    </audio>
                                    <div class="flex items-center gap-2.5 px-3 py-2.5 min-w-[220px]">
                                        <!-- Play / Pause button -->
                                        <button
                                            v-if="msg.media_url && !audioState(msg.id).error"
                                            @click="toggleAudio(msg.id)"
                                            class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 transition"
                                            :class="msg.status === 'sent' ? 'bg-green-500 hover:bg-green-600' : 'bg-accent hover:bg-accent-hover'"
                                        >
                                            <svg v-if="!audioState(msg.id).playing" class="w-5 h-5 text-white ml-0.5" fill="currentColor" viewBox="0 0 24 24"><path d="M8 5v14l11-7z"/></svg>
                                            <svg v-else class="w-4 h-4 text-white" fill="currentColor" viewBox="0 0 24 24"><path d="M6 19h4V5H6v14zm8-14v14h4V5h-4z"/></svg>
                                        </button>
                                        <!-- Error: fallback download link -->
                                        <a v-else-if="msg.media_url" :href="msg.media_url" target="_blank" rel="noopener noreferrer"
                                           class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 bg-gray-300 hover:bg-gray-400 transition"
                                           title="Descargar audio">
                                            <svg class="w-5 h-5 text-gray-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                        </a>
                                        <!-- No media available -->
                                        <div v-else class="w-10 h-10 rounded-full flex items-center justify-center shrink-0 bg-gray-300">
                                            <svg class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 11a7 7 0 01-7 7m0 0a7 7 0 01-7-7m7 7v4m0 0H8m4 0h4m-4-8a3 3 0 01-3-3V5a3 3 0 116 0v6a3 3 0 01-3 3z"/></svg>
                                        </div>

                                        <!-- Progress + time -->
                                        <div class="flex-1 min-w-0">
                                            <!-- Barra clicable para adelantar/retroceder (seek) -->
                                            <div class="relative h-3 flex items-center cursor-pointer" @click="seekAudio(msg.id, $event)">
                                                <div class="relative w-full h-1.5 rounded-full overflow-hidden" :class="msg.status === 'sent' ? 'bg-green-200' : 'bg-gray-200'">
                                                    <div
                                                        class="absolute inset-y-0 left-0 rounded-full transition-all duration-100"
                                                        :class="msg.status === 'sent' ? 'bg-green-600' : 'bg-accent'"
                                                        :style="{ width: audioProgress(msg.id) + '%' }"
                                                    />
                                                </div>
                                            </div>
                                            <p class="text-[10px] mt-1" :class="msg.status === 'sent' ? 'text-green-700' : 'text-gray-500'">
                                                <span v-if="audioState(msg.id).error && msg.media_url">
                                                    <a :href="msg.media_url" target="_blank" class="underline hover:text-accent">Descargar audio</a>
                                                </span>
                                                <span v-else-if="audioState(msg.id).error">No disponible</span>
                                                <span v-else-if="audioState(msg.id).currentTime">{{ fmtAudioTime(audioState(msg.id).currentTime) }} / {{ fmtAudioTime(audioState(msg.id).duration) }}</span>
                                                <span v-else>{{ fmtAudioTime(audioState(msg.id).duration) || '...' }}</span>
                                            </p>
                                        </div>

                                        <!-- Velocidad de reproducción (x1 / x1.5 / x2) -->
                                        <button
                                            v-if="msg.media_url && !audioState(msg.id).error"
                                            type="button"
                                            @click="cyclePlaybackRate"
                                            class="shrink-0 text-[11px] font-semibold px-1.5 py-0.5 rounded-md tabular-nums transition"
                                            :class="msg.status === 'sent' ? 'text-green-700 hover:bg-green-200/70' : 'text-gray-500 hover:bg-gray-200'"
                                            title="Velocidad de reproducción"
                                        >{{ playbackRate }}×</button>

                                        <!-- Mic icon / Download icon when error -->
                                        <a v-if="audioState(msg.id).error && msg.media_url" :href="msg.media_url" target="_blank" rel="noopener noreferrer"
                                           class="shrink-0 p-1 rounded-full hover:bg-black/5 transition" title="Descargar">
                                            <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                                        </a>
                                        <svg v-else class="w-4 h-4 shrink-0" :class="msg.status === 'sent' ? 'text-green-600' : 'text-gray-400'" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
                                    </div>
                                </template>

                                <template v-else-if="msg.type === 'location' && googleMapsUrl(msg.body)">
                                    <a
                                        :href="googleMapsUrl(msg.body)"
                                        target="_blank"
                                        rel="noopener noreferrer"
                                        class="flex items-center gap-2.5 px-3 py-2.5 hover:bg-black/5 transition min-w-[220px]"
                                    >
                                        <div class="w-10 h-10 rounded-full flex items-center justify-center shrink-0"
                                             :class="msg.status === 'sent' ? 'bg-green-500' : 'bg-accent'">
                                            <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 10.5a3 3 0 11-6 0 3 3 0 016 0z"/><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 10.5c0 7.142-7.5 11.25-7.5 11.25S4.5 17.642 4.5 10.5a7.5 7.5 0 1115 0z"/></svg>
                                        </div>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-gray-900">Ubicación compartida</p>
                                            <p class="text-[10px] text-gray-500 truncate">{{ locationCoords(msg.body) }} · Abrir en Maps</p>
                                        </div>
                                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25"/></svg>
                                    </a>
                                </template>

                                <template v-else-if="msg.type === 'document'">
                                    <a
                                        v-if="msg.media_url"
                                        :href="msg.media_url"
                                        :download="msg.media_filename || 'documento'"
                                        class="flex items-center px-3 py-2.5 gap-2.5 hover:bg-black/5 transition"
                                    >
                                        <svg class="w-8 h-8 text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                        <div class="min-w-0 flex-1">
                                            <p class="text-sm font-medium text-gray-900 truncate">{{ msg.media_filename || 'Documento' }}</p>
                                            <p class="text-[10px] text-gray-500">Toca para descargar</p>
                                        </div>
                                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3" /></svg>
                                    </a>
                                    <div v-else class="flex items-center gap-2.5 px-3 py-2.5">
                                        <svg class="w-8 h-8 text-gray-300 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                                        <p class="text-sm text-gray-400 italic">Documento no disponible</p>
                                    </div>
                                    <p v-if="msg.caption" class="px-3 pb-1 text-sm text-gray-900 whitespace-pre-wrap break-words">{{ msg.caption }}</p>
                                </template>

                                <template v-else>
                                    <p class="text-gray-900 whitespace-pre-wrap break-words px-4 pt-2">{{ msg.body }}</p>
                                </template>

                                <div class="flex items-center justify-end space-x-1 px-3 pb-1.5">
                                    <span class="text-[10px] text-gray-500">{{ formatTime(msg.created_at) }}</span>
                                    <svg v-if="msg.status === 'sent'" class="h-3.5 w-3.5 text-blue-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Banner conversación cerrada (con botón Reabrir) -->
                    <div v-if="activeStatus === 'closed'" class="bg-gray-100 border-t border-gray-200 px-4 py-3 z-10 shrink-0 flex items-center justify-between gap-3">
                        <div class="flex items-center gap-2 text-sm text-gray-700 min-w-0">
                            <svg class="w-4 h-4 text-gray-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                            <span class="truncate">Esta conversación está cerrada. No puedes enviar mensajes hasta reabrirla.</span>
                        </div>
                        <button v-if="canWrite" @click="reopenConversation"
                                class="shrink-0 px-3 py-1.5 bg-accent text-white rounded-lg text-xs font-medium hover:bg-accent-hover transition">
                            Reabrir
                        </button>
                    </div>

                    <!-- Input Area (solo agent/admin pueden escribir) -->
                    <div v-else-if="canWrite" class="bg-[#f0f2f5] p-3 z-10 shrink-0">

                        <!-- Vista previa del archivo seleccionado -->
                        <div v-if="selectedFile" class="mb-2 bg-white rounded-xl border border-gray-200 shadow-sm p-3 flex items-center gap-3">
                            <!-- Preview imagen -->
                            <img v-if="filePreviewUrl" :src="filePreviewUrl" class="w-14 h-14 rounded-lg object-cover shrink-0" />
                            <!-- Ícono audio -->
                            <div v-else class="w-14 h-14 rounded-lg bg-accent/10 flex items-center justify-center shrink-0">
                                <svg class="w-7 h-7 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
                            </div>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-medium text-gray-900 truncate">{{ selectedFile.name }}</p>
                                <p class="text-xs text-gray-500">{{ isAudioFile(selectedFile) ? 'Audio' : 'Imagen' }} · {{ (selectedFile.size / 1024).toFixed(0) }} KB</p>
                                <!-- Caption solo para imágenes -->
                                <input v-if="!isAudioFile(selectedFile)" v-model="mediaForm.caption" type="text" placeholder="Añadir descripción (opcional)" class="mt-1 w-full text-xs border-0 border-b border-gray-200 bg-transparent focus:ring-0 focus:border-accent px-0 py-0.5" />
                                <p v-if="mediaForm.errors.file" class="mt-1 text-xs text-red-500">{{ mediaForm.errors.file }}</p>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button type="button" @click="clearSelectedFile" class="p-1.5 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                                </button>
                                <button type="button" @click="submitMedia" :disabled="mediaForm.processing" class="p-2 bg-accent text-white rounded-lg hover:bg-accent-hover disabled:opacity-50 transition">
                                    <svg v-if="!mediaForm.processing" class="w-4 h-4 rotate-90" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z"/></svg>
                                    <svg v-else class="animate-spin w-4 h-4" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                </button>
                            </div>
                        </div>

                        <!-- Error de grabación de micrófono -->
                        <p v-if="recordError" class="mb-2 text-xs text-red-500">{{ recordError }}</p>

                        <form @submit.prevent="submit" class="flex items-end space-x-2">
                            <!-- Hidden file input -->
                            <input ref="fileInputRef" type="file" class="hidden" @change="onFileSelected" />

                            <!-- Controles normales (ocultos mientras se graba) -->
                            <template v-if="!recording">
                                <!-- Botón Imagen (directo, sin dropdown) -->
                                <button type="button" @click="openFileInput('image/jpeg,image/jpg,image/png,image/webp')"
                                        class="p-2.5 text-gray-500 hover:text-blue-500 transition rounded-lg hover:bg-blue-50 group relative"
                                        title="Enviar imagen">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Zm10.5-11.25h.008v.008h-.008V8.25Zm.375 0a.375.375 0 1 1-.75 0 .375.375 0 0 1 .75 0Z"/></svg>
                                    <span class="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-0.5 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none">Imagen</span>
                                </button>

                                <!-- Botón Audio (subir archivo) -->
                                <button type="button" @click="openFileInput('audio/ogg,audio/mpeg,audio/mp4,audio/aac,audio/amr,audio/*')"
                                        class="p-2.5 text-gray-500 hover:text-green-500 transition rounded-lg hover:bg-green-50 group relative"
                                        title="Subir audio">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 7.5L12 3m0 0L7.5 7.5M12 3v13.5"/></svg>
                                    <span class="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-0.5 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none">Subir audio</span>
                                </button>

                                <!-- Botón Grabar audio -->
                                <button type="button" @click="startRecording"
                                        class="p-2.5 text-gray-500 hover:text-red-500 transition rounded-lg hover:bg-red-50 group relative"
                                        title="Grabar audio">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 18.75a6 6 0 006-6v-1.5m-6 7.5a6 6 0 01-6-6v-1.5m6 7.5v3.75m-3.75 0h7.5M12 15.75a3 3 0 01-3-3V4.5a3 3 0 116 0v8.25a3 3 0 01-3 3z"/></svg>
                                    <span class="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-0.5 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none">Grabar audio</span>
                                </button>

                                <!-- Quick replies button -->
                                <div class="relative">
                                    <button type="button" @click="showQuickReplies = !showQuickReplies" class="p-2.5 text-gray-500 hover:text-accent transition rounded-lg hover:bg-white group relative">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                                        <span class="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-0.5 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none">Respuestas rápidas</span>
                                    </button>
                                    <!-- Backdrop para cerrar al hacer click fuera -->
                                    <Teleport to="body">
                                        <div v-if="showQuickReplies && quickReplies.length" class="fixed inset-0 z-[19]" @click="showQuickReplies = false"></div>
                                    </Teleport>
                                    <!-- Quick replies dropdown -->
                                    <div v-if="showQuickReplies && quickReplies.length" class="absolute bottom-12 left-0 w-[calc(100vw-2rem)] sm:w-72 max-w-xs bg-white rounded-xl shadow-xl border border-gray-200 max-h-60 overflow-y-auto z-20 animate-scale-in">
                                        <div class="p-2">
                                            <button v-for="qr in quickReplies" :key="qr.id" type="button" @click="useQuickReply(qr)" class="w-full text-left p-3 rounded-lg hover:bg-gray-50 transition">
                                                <p class="text-sm font-medium text-gray-900">{{ qr.title }}</p>
                                                <p class="text-xs text-gray-500 truncate">{{ qr.body }}</p>
                                            </button>
                                        </div>
                                    </div>
                                </div>

                                <!-- Toggle: nota interna -->
                                <button type="button" @click="noteMode = !noteMode"
                                        class="p-2.5 rounded-lg transition group relative shrink-0"
                                        :class="noteMode ? 'text-amber-600 bg-amber-100' : 'text-gray-500 hover:text-amber-500 hover:bg-amber-50'"
                                        :title="noteMode ? 'Modo nota interna activo (clic para volver a chat)' : 'Escribir nota interna'">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                    <span class="absolute -top-8 left-1/2 -translate-x-1/2 bg-gray-800 text-white text-[10px] px-2 py-0.5 rounded-md whitespace-nowrap opacity-0 group-hover:opacity-100 transition pointer-events-none">Nota interna</span>
                                </button>

                                <div class="flex-1 rounded-xl flex items-center shadow-sm border focus-within:ring-2 transition"
                                     :class="noteMode ? 'bg-amber-50 border-amber-200 focus-within:ring-amber-300/40' : 'bg-white border-gray-100 focus-within:ring-accent/30'">
                                    <textarea v-model="form.message" rows="1"
                                              :placeholder="noteMode ? 'Nota interna (solo el equipo la verá)…' : 'Escribe un mensaje'"
                                              class="w-full border-none focus:ring-0 rounded-xl resize-none py-3 px-4 text-sm max-h-32 bg-transparent"
                                              @paste="onPaste"
                                              @keydown.enter.exact.prevent="submit"></textarea>
                                </div>
                                <button type="submit" :disabled="(noteMode ? noteSending : form.processing) || !form.message"
                                        class="p-3 text-white rounded-xl disabled:opacity-50 transition shadow-sm flex items-center justify-center h-11 w-11"
                                        :class="noteMode ? 'bg-amber-500 hover:bg-amber-600' : 'bg-accent hover:bg-accent-hover'"
                                        :title="noteMode ? 'Guardar nota interna' : 'Enviar mensaje'">
                                    <svg v-if="noteMode ? noteSending : form.processing" class="animate-spin w-5 h-5" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"></path></svg>
                                    <svg v-else-if="noteMode" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                                    <svg v-else class="w-5 h-5 rotate-90" fill="currentColor" viewBox="0 0 20 20"><path d="M10.894 2.553a1 1 0 00-1.788 0l-7 14a1 1 0 001.169 1.409l5-1.429A1 1 0 009 15.571V11a1 1 0 112 0v4.571a1 1 0 00.725.962l5 1.428a1 1 0 001.17-1.408l-7-14z" /></svg>
                                </button>
                            </template>

                            <!-- Barra de grabación de audio -->
                            <div v-else class="flex-1 flex items-center gap-3 bg-white rounded-xl shadow-sm border border-red-200 px-4 py-2.5">
                                <span class="relative flex h-3 w-3 shrink-0">
                                    <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-red-400 opacity-75"></span>
                                    <span class="relative inline-flex rounded-full h-3 w-3 bg-red-500"></span>
                                </span>
                                <span class="text-sm font-medium text-gray-700 tabular-nums">{{ fmtRecordTime(recordingTime) }}</span>
                                <span class="text-xs text-gray-400 hidden sm:inline">Grabando…</span>
                                <div class="flex-1"></div>
                                <button type="button" @click="cancelRecording" class="p-2 text-gray-400 hover:text-red-500 hover:bg-red-50 rounded-lg transition" title="Cancelar grabación">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"/></svg>
                                </button>
                                <button type="button" @click="stopRecording" class="p-2.5 bg-accent text-white rounded-lg hover:bg-accent-hover transition shadow-sm" title="Detener y revisar">
                                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M4.5 12.75l6 6 9-13.5"/></svg>
                                </button>
                            </div>
                        </form>
                    </div>

                    <!-- Solo lectura: los viewers no pueden enviar mensajes -->
                    <div v-else class="bg-[#f0f2f5] px-4 py-3.5 z-10 shrink-0 flex items-center justify-center gap-2 text-xs text-gray-500 border-t border-gray-200">
                        <svg class="w-4 h-4 text-gray-400 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z"/></svg>
                        <span>Tienes acceso de <strong class="font-semibold">solo lectura</strong>. No puedes enviar mensajes ni notas.</span>
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

            <!-- ─── Backdrop drawer (solo mobile/tablet) ─────────────────────── -->
            <div
                v-if="activeConversationId && showCustomerPanel"
                class="fixed inset-0 z-40 bg-black/50 lg:hidden"
                @click="showCustomerPanel = false"
            ></div>

            <!-- ─── Customer Sidebar (ISPWatch) ───────────────────────────────── -->
            <!-- En lg+: panel estático al lado del chat.
                 En < lg: drawer overlay desde la derecha. -->
            <aside
                v-if="activeConversationId && showCustomerPanel"
                class="fixed lg:relative inset-y-0 right-0 z-50 lg:z-auto w-[88vw] max-w-sm lg:w-80 xl:w-96 border-l border-gray-200 bg-white flex flex-col shrink-0 shadow-2xl lg:shadow-none animate-slide-in-right lg:animate-none"
            >
                <!-- Header -->
                <div class="p-4 border-b border-gray-100 shrink-0">
                    <div class="flex items-start gap-3">
                        <div class="w-12 h-12 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white font-bold shrink-0">
                            {{ (ispwatchCustomer?.name || activeName || '?')[0].toUpperCase() }}
                        </div>
                        <div class="flex-1 min-w-0">
                            <h3 class="text-sm font-semibold text-gray-900 truncate">
                                {{ ispwatchCustomer?.name || activeName }}
                            </h3>
                            <p class="text-xs text-gray-500 truncate">{{ formatPhone(activePhone) }}</p>
                            <div class="mt-2 inline-flex items-center gap-1.5 px-2 py-0.5 rounded-full text-[10px] font-medium"
                                 :class="ispwatchCustomer ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                                <span class="w-1.5 h-1.5 rounded-full" :class="ispwatchCustomer ? 'bg-emerald-500' : 'bg-amber-500'"></span>
                                {{ ispwatchCustomer ? 'Cliente ISPWatch' : 'No registrado en ISPWatch' }}
                            </div>
                        </div>
                        <button @click="showCustomerPanel = false" class="p-1 text-gray-400 hover:text-gray-600 hover:bg-gray-100 rounded-lg transition" title="Ocultar panel">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                        </button>
                    </div>
                </div>

                <!-- Body -->
                <div class="flex-1 overflow-y-auto p-4 space-y-4">
                    <!-- Caso: cliente encontrado en ispwatch -->
                    <template v-if="ispwatchCustomer">
                        <!-- Servicio -->
                        <section class="bg-gray-50 rounded-xl p-3">
                            <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Servicio</h4>
                            <div class="flex items-center justify-between mb-2">
                                <span class="text-xs text-gray-600">Estado</span>
                                <span class="px-2 py-0.5 rounded-full text-[10px] font-semibold capitalize" :class="serviceStatusClass(ispwatchCustomer.service_status)">
                                    {{ ispwatchCustomer.service_status || 'desconocido' }}
                                </span>
                            </div>
                            <div v-if="ispwatchCustomer.pppoe_username" class="flex items-center justify-between mb-1.5">
                                <span class="text-xs text-gray-600">PPPoE</span>
                                <span class="text-xs font-mono text-gray-900">{{ ispwatchCustomer.pppoe_username }}</span>
                            </div>
                            <div v-if="ispwatchCustomer.ip_user" class="flex items-center justify-between">
                                <span class="text-xs text-gray-600">IP</span>
                                <span class="text-xs font-mono text-gray-900">{{ ispwatchCustomer.ip_user }}</span>
                            </div>
                        </section>

                        <!-- Cuenta -->
                        <section class="bg-gray-50 rounded-xl p-3">
                            <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Cuenta</h4>
                            <div class="flex items-center justify-between">
                                <span class="text-xs text-gray-600">Saldo a favor</span>
                                <span class="text-sm font-semibold" :class="Number(ispwatchCustomer.credit_balance) > 0 ? 'text-emerald-600' : 'text-gray-900'">
                                    {{ formatMoney(ispwatchCustomer.credit_balance) }}
                                </span>
                            </div>
                        </section>

                        <!-- Facturas pendientes -->
                        <section>
                            <div class="flex items-center justify-between mb-2 px-1">
                                <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-wider">Facturas pendientes</h4>
                                <span v-if="ispwatchInvoices.length" class="text-[10px] font-semibold text-gray-400">{{ ispwatchInvoices.length }}</span>
                            </div>
                            <div v-if="ispwatchInvoices.length" class="space-y-2">
                                <div v-for="inv in ispwatchInvoices" :key="inv.id"
                                     class="bg-white border border-gray-100 rounded-xl p-3 shadow-sm">
                                    <div class="flex items-start justify-between mb-1">
                                        <div class="min-w-0">
                                            <p class="text-xs font-semibold text-gray-900">#{{ inv.number }}</p>
                                            <p class="text-[10px]" :class="isOverdue(inv.due_date) ? 'text-red-600 font-medium' : 'text-gray-500'">
                                                {{ isOverdue(inv.due_date) ? 'Vencida · ' : 'Vence ' }}{{ formatDueDate(inv.due_date) }}
                                            </p>
                                        </div>
                                        <span class="px-1.5 py-0.5 rounded text-[9px] uppercase font-semibold tracking-wide bg-gray-100 text-gray-600">
                                            {{ inv.status }}
                                        </span>
                                    </div>
                                    <div class="flex items-end justify-between pt-1.5 border-t border-gray-50">
                                        <span class="text-[10px] text-gray-400">Saldo</span>
                                        <span class="text-sm font-bold text-red-600">{{ formatMoney(inv.balance_due, inv.currency) }}</span>
                                    </div>
                                </div>
                            </div>
                            <div v-else class="bg-emerald-50 border border-emerald-100 rounded-xl p-3 flex items-center gap-2">
                                <svg class="w-4 h-4 text-emerald-500 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                                <p class="text-xs text-emerald-700">Sin facturas pendientes</p>
                            </div>
                        </section>

                        <!-- Contacto -->
                        <section class="bg-gray-50 rounded-xl p-3">
                            <h4 class="text-[10px] font-bold text-gray-500 uppercase tracking-wider mb-2">Contacto</h4>
                            <div v-if="ispwatchCustomer.cedula" class="flex items-center justify-between mb-1.5">
                                <span class="text-xs text-gray-600">Cédula</span>
                                <span class="text-xs text-gray-900">{{ ispwatchCustomer.cedula }}</span>
                            </div>
                            <div v-if="ispwatchCustomer.email" class="flex items-center justify-between mb-1.5">
                                <span class="text-xs text-gray-600">Email</span>
                                <span class="text-xs text-gray-900 truncate ml-2">{{ ispwatchCustomer.email }}</span>
                            </div>
                            <div v-if="ispwatchCustomer.address" class="flex items-start justify-between">
                                <span class="text-xs text-gray-600 shrink-0">Dirección</span>
                                <span class="text-xs text-gray-900 text-right ml-2">{{ ispwatchCustomer.address }}</span>
                            </div>
                        </section>
                    </template>

                    <!-- Caso: prospecto (no en ispwatch) -->
                    <template v-else>
                        <div class="bg-amber-50 border border-amber-100 rounded-xl p-4">
                            <div class="flex items-start gap-2.5">
                                <svg class="w-5 h-5 text-amber-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                                <div class="min-w-0">
                                    <p class="text-sm font-semibold text-amber-900">Prospecto</p>
                                    <p class="text-xs text-amber-700 mt-0.5">
                                        Este número no está registrado como cliente en ISPWatch. Mostramos el nombre que el contacto tiene configurado en WhatsApp.
                                    </p>
                                </div>
                            </div>
                        </div>
                        <div class="text-center py-6 text-gray-400">
                            <svg class="w-10 h-10 mx-auto mb-2 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z"/></svg>
                            <p class="text-xs">Sin servicio activo, facturas ni datos de cuenta.</p>
                        </div>
                    </template>
                </div>
            </aside>

        </div>

        <!-- ═══════════════ Modal: Cerrar conversación ═══════════════ -->
        <Teleport to="body">
            <div v-if="showCloseModal" class="fixed inset-0 z-[60] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/50" @click="showCloseModal = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg animate-scale-in">
                    <div class="p-6">
                        <div class="flex items-start gap-3 mb-4">
                            <div class="w-10 h-10 rounded-full bg-accent/10 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            </div>
                            <div class="min-w-0">
                                <h3 class="text-lg font-bold text-gray-900">Cerrar conversación</h3>
                                <p class="text-xs text-gray-500 truncate">{{ activeName }}</p>
                            </div>
                        </div>

                        <form @submit.prevent="submitClosing" class="space-y-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nota de cierre</label>
                                <textarea v-model="closingForm.note" rows="4" placeholder="¿Cómo se resolvió? ¿Qué quedó pendiente?"
                                          class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-none"
                                          :class="{ 'border-red-400': closingForm.errors.note }"></textarea>
                                <p v-if="closingForm.errors.note" class="text-red-500 text-xs mt-1">{{ closingForm.errors.note }}</p>
                            </div>

                            <div>
                                <p class="text-[10px] text-gray-500 uppercase tracking-wider font-semibold mb-1.5">Plantillas rápidas</p>
                                <div class="flex flex-wrap gap-1.5">
                                    <button v-for="(tpl, i) in closingTemplates" :key="i" type="button" @click="applyClosingTemplate(tpl)"
                                            class="px-2.5 py-1 bg-gray-100 hover:bg-gray-200 rounded-lg text-[11px] text-gray-700 transition">
                                        {{ tpl.slice(0, 40) }}{{ tpl.length > 40 ? '…' : '' }}
                                    </button>
                                </div>
                            </div>

                            <label class="flex items-center cursor-pointer pt-1">
                                <input v-model="closingForm.close_conversation" type="checkbox" class="rounded border-gray-300 text-accent focus:ring-accent/30">
                                <span class="ml-2 text-sm text-gray-700">Cerrar la conversación al guardar</span>
                            </label>
                            <p v-if="!closingForm.close_conversation" class="text-[11px] text-amber-600 -mt-2 ml-6">Sin esto, queda solo como nota interna y la conversación sigue abierta.</p>

                            <div class="flex justify-end gap-3 pt-3 border-t border-gray-50">
                                <button type="button" @click="showCloseModal = false" class="px-4 py-2.5 text-sm text-gray-600 hover:bg-gray-100 rounded-xl transition">Cancelar</button>
                                <button type="submit" :disabled="closingForm.processing || !closingForm.note" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                    {{ closingForm.processing ? 'Guardando…' : 'Guardar' }}
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </Teleport>

        <!-- Delete Confirmation Modal -->
        <Teleport to="body">
            <div v-if="deleteConfirm.show" class="fixed inset-0 z-[70] flex items-center justify-center p-4">
                <div class="fixed inset-0 bg-black/40" @click="deleteConfirm.show = false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-sm">
                    <div class="p-6">
                        <div class="flex items-center gap-3 mb-3">
                            <div class="w-10 h-10 rounded-full bg-red-100 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-red-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16" />
                                </svg>
                            </div>
                            <h3 class="text-base font-semibold text-gray-900">Eliminar conversación</h3>
                        </div>
                        <p class="text-sm text-gray-600 mb-5">
                            ¿Estás seguro de que deseas eliminar la conversación con <strong>{{ deleteConfirm.name }}</strong>? Se eliminarán todos los mensajes y no se puede deshacer.
                        </p>
                        <div class="flex justify-end gap-2">
                            <button @click="deleteConfirm.show = false" class="px-4 py-2 text-sm text-gray-700 hover:bg-gray-100 rounded-lg transition">
                                Cancelar
                            </button>
                            <button @click="executeDelete" class="px-4 py-2 text-sm text-white bg-red-500 hover:bg-red-600 rounded-lg transition">
                                Eliminar
                            </button>
                        </div>
                    </div>
                </div>
            </div>
        </Teleport>

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
