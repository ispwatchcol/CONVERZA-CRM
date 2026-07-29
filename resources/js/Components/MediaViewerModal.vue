<script setup>
import { ref, computed, watch, nextTick, onMounted, onUnmounted } from 'vue';

const props = defineProps({
    open:     { type: Boolean, default: false },
    type:     { type: String, default: null }, // 'image' | 'video' | 'document'
    url:      { type: String, default: null },
    // URL que fuerza la descarga con el nombre original. Si no llega, se cae a `url`.
    downloadUrl: { type: String, default: null },
    filename: { type: String, default: null },
    mime:     { type: String, default: null },
    caption:  { type: String, default: null },
    items:    { type: Array, default: () => [] }, // galería (solo imágenes)
    index:    { type: Number, default: 0 },
});

const emit = defineEmits(['close', 'navigate']);

const closeBtn = ref(null);
const mediaError = ref(false);

const dlHref = computed(() => props.downloadUrl || props.url);

const hasGallery = computed(() => props.type === 'image' && props.items.length > 1);
const canPrev = computed(() => hasGallery.value && props.index > 0);
const canNext = computed(() => hasGallery.value && props.index < props.items.length - 1);

const isPdf = computed(() => {
    if (props.mime === 'application/pdf') return true;
    return !!props.filename && props.filename.toLowerCase().endsWith('.pdf');
});

const mimeLabel = computed(() => {
    if (!props.mime) return null;
    const known = {
        'application/pdf': 'PDF',
        'application/msword': 'Word (.doc)',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document': 'Word (.docx)',
        'application/vnd.ms-excel': 'Excel (.xls)',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet': 'Excel (.xlsx)',
        'application/vnd.ms-powerpoint': 'PowerPoint (.ppt)',
        'application/vnd.openxmlformats-officedocument.presentationml.presentation': 'PowerPoint (.pptx)',
        'text/plain': 'Texto (.txt)',
    };
    return known[props.mime] || props.mime;
});

watch(() => props.open, (isOpen) => {
    mediaError.value = false;
    if (isOpen) {
        document.body.style.overflow = 'hidden';
        nextTick(() => closeBtn.value?.focus());
    } else {
        document.body.style.overflow = '';
    }
});

// Reinicia el estado de error al navegar entre imágenes de la galería.
watch(() => props.url, () => { mediaError.value = false; });

function close() {
    emit('close');
}

function handleKeydown(e) {
    if (!props.open) return;
    if (e.key === 'Escape') {
        close();
    } else if (e.key === 'ArrowLeft' && canPrev.value) {
        emit('navigate', -1);
    } else if (e.key === 'ArrowRight' && canNext.value) {
        emit('navigate', 1);
    }
}

onMounted(() => {
    document.addEventListener('keydown', handleKeydown);
});
onUnmounted(() => {
    document.removeEventListener('keydown', handleKeydown);
    document.body.style.overflow = '';
});
</script>

<template>
    <Teleport to="body">
        <div v-if="open" class="fixed inset-0 z-[100] flex flex-col" role="dialog" aria-modal="true">
            <div class="fixed inset-0 bg-black/80" @click="close"></div>

            <!-- Header -->
            <div class="relative z-10 flex items-center justify-between gap-3 px-4 py-3 text-white shrink-0">
                <p class="text-sm font-medium truncate min-w-0">{{ filename || (type === 'image' ? 'Imagen' : type === 'video' ? 'Video' : 'Documento') }}</p>
                <div class="flex items-center gap-1 shrink-0">
                    <a
                        v-if="url"
                        :href="dlHref"
                        :download="filename || true"
                        class="p-2 rounded-full hover:bg-white/15 transition"
                        title="Descargar"
                        @click.stop
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                    </a>
                    <button
                        ref="closeBtn"
                        type="button"
                        class="p-2 rounded-full hover:bg-white/15 transition"
                        title="Cerrar"
                        @click="close"
                    >
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12"/></svg>
                    </button>
                </div>
            </div>

            <!-- Body -->
            <div class="relative z-10 flex-1 min-h-0 flex items-center justify-center px-4 pb-4">
                <!-- Flecha anterior -->
                <button
                    v-if="canPrev"
                    type="button"
                    class="absolute left-2 sm:left-4 top-1/2 -translate-y-1/2 p-2 rounded-full bg-black/40 hover:bg-black/60 text-white transition"
                    title="Anterior"
                    @click.stop="emit('navigate', -1)"
                >
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 19.5L8.25 12l7.5-7.5"/></svg>
                </button>

                <!-- Sin archivo disponible -->
                <div v-if="!url" class="flex flex-col items-center gap-2 text-white/80 animate-scale-in">
                    <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z"/></svg>
                    <p class="text-sm">Archivo no disponible</p>
                </div>

                <!-- Imagen -->
                <div v-else-if="type === 'image'" class="w-full h-full flex flex-col items-center justify-center gap-3 animate-scale-in">
                    <img
                        v-if="!mediaError"
                        :src="url"
                        :alt="filename || 'imagen'"
                        class="max-w-full max-h-[80vh] object-contain rounded-lg shadow-2xl"
                        @error="mediaError = true"
                        @click.stop
                    />
                    <div v-else class="flex flex-col items-center gap-2 text-white/80">
                        <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="m2.25 15.75 5.159-5.159a2.25 2.25 0 0 1 3.182 0l5.159 5.159m-1.5-1.5 1.409-1.409a2.25 2.25 0 0 1 3.182 0l2.909 2.909m-18 3.75h16.5a1.5 1.5 0 0 0 1.5-1.5V6a1.5 1.5 0 0 0-1.5-1.5H3.75A1.5 1.5 0 0 0 2.25 6v12a1.5 1.5 0 0 0 1.5 1.5Z" /></svg>
                        <p class="text-sm">No se pudo cargar la imagen</p>
                        <a :href="url" target="_blank" rel="noopener noreferrer" class="text-sm underline hover:text-white">Abrir en pestaña nueva</a>
                    </div>
                    <p v-if="caption" class="max-w-2xl text-center text-sm text-white/90 px-2" @click.stop>{{ caption }}</p>
                </div>

                <!-- Video -->
                <div v-else-if="type === 'video'" class="w-full h-full flex flex-col items-center justify-center gap-3 animate-scale-in">
                    <video
                        v-if="!mediaError"
                        :src="url"
                        controls
                        autoplay
                        preload="metadata"
                        class="max-w-full max-h-[80vh] rounded-lg shadow-2xl bg-black"
                        @error="mediaError = true"
                        @click.stop
                    ></video>
                    <div v-else class="flex flex-col items-center gap-2 text-white/80">
                        <svg class="w-12 h-12" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 10.5l4.72-4.72a.75.75 0 011.28.53v11.38a.75.75 0 01-1.28.53l-4.72-4.72M4.5 18.75h9a2.25 2.25 0 002.25-2.25v-9a2.25 2.25 0 00-2.25-2.25h-9A2.25 2.25 0 002.25 7.5v9a2.25 2.25 0 002.25 2.25z" /></svg>
                        <p class="text-sm">No se pudo reproducir el video</p>
                        <a :href="url" target="_blank" rel="noopener noreferrer" class="text-sm underline hover:text-white">Abrir / descargar</a>
                    </div>
                    <p v-if="caption" class="max-w-2xl text-center text-sm text-white/90 px-2" @click.stop>{{ caption }}</p>
                </div>

                <!-- Documento -->
                <div v-else-if="type === 'document'" class="w-full h-full flex items-center justify-center animate-scale-in" @click.stop>
                    <div v-if="isPdf && !mediaError" class="w-full h-full max-w-4xl bg-white rounded-lg shadow-2xl overflow-hidden">
                        <iframe :src="url" class="w-full h-full border-0" title="Previsualización de documento"></iframe>
                    </div>
                    <div v-else class="flex flex-col items-center gap-3 bg-white rounded-2xl shadow-2xl px-8 py-10 max-w-sm text-center">
                        <div class="w-14 h-14 rounded-xl bg-gray-100 flex items-center justify-center">
                            <svg class="w-8 h-8 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m2.25 0H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                        </div>
                        <p class="text-sm font-medium text-gray-900 break-words">{{ filename || 'Documento' }}</p>
                        <p v-if="mimeLabel" class="text-xs text-gray-500">{{ mimeLabel }}</p>
                        <p class="text-xs text-gray-400">La previsualización no está disponible para este tipo de archivo.</p>
                        <a :href="dlHref" :download="filename || true" target="_blank" rel="noopener noreferrer" class="mt-1 inline-flex items-center gap-1.5 px-4 py-2 rounded-lg bg-accent text-white text-sm font-medium hover:bg-accent-hover transition">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 16.5v2.25A2.25 2.25 0 005.25 21h13.5A2.25 2.25 0 0021 18.75V16.5M16.5 12L12 16.5m0 0L7.5 12m4.5 4.5V3"/></svg>
                            Abrir / descargar
                        </a>
                    </div>
                </div>

                <!-- Flecha siguiente -->
                <button
                    v-if="canNext"
                    type="button"
                    class="absolute right-2 sm:right-4 top-1/2 -translate-y-1/2 p-2 rounded-full bg-black/40 hover:bg-black/60 text-white transition"
                    title="Siguiente"
                    @click.stop="emit('navigate', 1)"
                >
                    <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5"/></svg>
                </button>
            </div>
        </div>
    </Teleport>
</template>
