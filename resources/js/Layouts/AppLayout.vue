<script setup>
import { Link, usePage } from '@inertiajs/vue3';
import { ref, computed, watch, onMounted, onUnmounted } from 'vue';

const page = usePage();
const sidebarOpen = ref(typeof window !== 'undefined' ? window.innerWidth >= 768 : true);
const flashMessage = ref(null);
const flashType = ref('success');

// ─── Theme ────────────────────────────────────────────────────────────────────
// Modes: 'light' | 'dark' | 'system'
const theme = ref(localStorage.getItem('converza-theme') || 'system');

function applyTheme(mode) {
    const html = document.documentElement;
    if (mode === 'dark' || (mode === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        html.classList.add('dark');
    } else {
        html.classList.remove('dark');
    }
}

function setTheme(mode) {
    theme.value = mode;
    localStorage.setItem('converza-theme', mode);
    applyTheme(mode);
    showThemeMenu.value = false;
}

onMounted(() => {
    applyTheme(theme.value);
    // Listen for system preference changes
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', () => {
        if (theme.value === 'system') applyTheme('system');
    });
});

const showThemeMenu = ref(false);

const themeIcon = computed(() => {
    if (theme.value === 'dark') return 'moon';
    if (theme.value === 'light') return 'sun';
    return 'system';
});

// ─── Support WhatsApp ─────────────────────────────────────────────────────────
const SUPPORT_WHATSAPP = 'https://wa.me/573001234567'; // ← replace number here

// ─── Navigation ───────────────────────────────────────────────────────────────
const currentRoute = computed(() => {
    try { return route().current(); } catch { return ''; }
});

const navItems = [
    { name: 'Dashboard', route: 'dashboard', icon: 'dashboard' },
    { name: 'Contactos', route: 'contacts.index', icon: 'contacts' },
    { name: 'Etiquetas', route: 'labels.index', icon: 'labels' },
    { name: 'Chat', route: 'chat.index', icon: 'chat' },
    { name: 'Plantillas', route: 'templates.index', icon: 'templates' },
    { name: 'Staff', route: 'staff.index', icon: 'staff' },
    { name: 'Equipos', route: 'teams.index', icon: 'teams' },
    { name: 'Respuestas Rápidas', route: 'quick-replies.index', icon: 'quickreplies' },
    { name: 'Notas de Cierre', route: 'closing-notes.index', icon: 'closingnotes' },
    { name: 'Métricas', route: 'metrics.index', icon: 'metrics' },
    { name: 'Configuración', route: 'settings.index', icon: 'settings' },
];

function isActive(routeName) {
    return currentRoute.value?.startsWith(routeName.split('.')[0]);
}

// ─── Flash ────────────────────────────────────────────────────────────────────
watch(() => page.props.flash, (flash) => {
    if (flash?.success) {
        flashMessage.value = flash.success; flashType.value = 'success';
        setTimeout(() => { flashMessage.value = null; }, 4000);
    }
    if (flash?.error) {
        flashMessage.value = flash.error; flashType.value = 'error';
        setTimeout(() => { flashMessage.value = null; }, 4000);
    }
}, { deep: true, immediate: true });

watch(currentRoute, () => { 
    if (typeof window !== 'undefined' && window.innerWidth < 768) {
        sidebarOpen.value = false; 
    }
});

function handleEsc(e) {
    if (e.key === 'Escape') { 
        if (typeof window !== 'undefined' && window.innerWidth < 768) {
            sidebarOpen.value = false; 
        }
        showThemeMenu.value = false; 
    }
}
onMounted(() => document.addEventListener('keydown', handleEsc));
onUnmounted(() => document.removeEventListener('keydown', handleEsc));
</script>

<template>
    <div class="h-screen flex overflow-hidden bg-gray-100">

        <!-- Mobile overlay -->
        <div v-if="sidebarOpen" class="fixed inset-0 z-40 bg-black/50 md:hidden" @click="sidebarOpen = false"></div>

        <!-- ── Sidebar ──────────────────────────────────────────────────────── -->
        <aside
            class="fixed inset-y-0 left-0 z-50 bg-sidebar flex flex-col transform transition-all duration-300 ease-in-out md:relative"
            :class="[
                sidebarOpen ? 'w-64 translate-x-0' : 'w-20 -translate-x-full md:translate-x-0'
            ]"
        >
            <!-- Logo -->
            <div class="flex items-center h-16 shrink-0 border-b border-white/10"
                 :class="sidebarOpen ? 'px-5 justify-between' : 'justify-center'">
                 
                <!-- Logo & Title -->
                <div v-show="sidebarOpen" class="flex items-center space-x-3 overflow-hidden">
                    <div class="w-9 h-9 rounded-xl bg-accent flex items-center justify-center shrink-0">
                        <svg class="w-5 h-5 text-white" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
                        </svg>
                    </div>
                    <div class="truncate">
                        <h1 class="text-white font-bold text-lg tracking-tight">CONVERZA</h1>
                        <p class="text-text-muted text-[10px] uppercase tracking-widest">CRM & Chatbot</p>
                    </div>
                </div>

                <!-- Hamburger inside sidebar -->
                <button @click="sidebarOpen = !sidebarOpen" class="p-2 rounded-lg text-gray-400 hover:text-white hover:bg-white/10 transition hidden md:block shrink-0" title="Alternar menú">
                   <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                </button>
            </div>

            <!-- Nav -->
            <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-0.5"
                 :class="!sidebarOpen && 'flex flex-col items-center px-0 space-y-2'">
                <Link
                    v-for="item in navItems"
                    :key="item.route"
                    :href="route(item.route)"
                    class="group flex items-center rounded-lg font-medium transition-all duration-200"
                    :class="[
                        sidebarOpen ? 'px-3 py-2.5 text-sm w-full' : 'p-3 justify-center w-12 h-12',
                        isActive(item.route)
                            ? (sidebarOpen ? 'bg-sidebar-active text-white border-l-[3px] border-accent' : 'bg-sidebar-active text-white')
                            : 'text-text-muted hover:bg-sidebar-hover hover:text-white'
                    ]"
                    :title="!sidebarOpen ? item.name : ''"
                >
                    <span class="shrink-0 flex items-center justify-center transition-transform group-hover:scale-110" :class="sidebarOpen ? 'w-5 h-5 mr-3' : 'w-6 h-6'">
                        <svg v-if="item.icon === 'dashboard'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" /></svg>
                        <svg v-else-if="item.icon === 'contacts'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19.128a9.38 9.38 0 002.625.372 9.337 9.337 0 004.121-.952 4.125 4.125 0 00-7.533-2.493M15 19.128v-.003c0-1.113-.285-2.16-.786-3.07M15 19.128v.106A12.318 12.318 0 018.624 21c-2.331 0-4.512-.645-6.374-1.766l-.001-.109a6.375 6.375 0 0111.964-3.07M12 6.375a3.375 3.375 0 11-6.75 0 3.375 3.375 0 016.75 0zm8.25 2.25a2.625 2.625 0 11-5.25 0 2.625 2.625 0 015.25 0z" /></svg>
                        <svg v-else-if="item.icon === 'labels'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.568 3H5.25A2.25 2.25 0 003 5.25v4.318c0 .597.237 1.17.659 1.591l9.581 9.581c.699.699 1.78.872 2.607.33a18.095 18.095 0 005.223-5.223c.542-.827.369-1.908-.33-2.607L11.16 3.66A2.25 2.25 0 009.568 3z" /><path stroke-linecap="round" stroke-linejoin="round" d="M6 6h.008v.008H6V6z" /></svg>
                        <svg v-else-if="item.icon === 'chat'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 8.511c.884.284 1.5 1.128 1.5 2.097v4.286c0 1.136-.847 2.1-1.98 2.193-.34.027-.68.052-1.02.072v3.091l-3-3c-1.354 0-2.694-.055-4.02-.163a2.115 2.115 0 01-.825-.242m9.345-8.334a2.126 2.126 0 00-.476-.095 48.64 48.64 0 00-8.048 0c-1.131.094-1.976 1.057-1.976 2.192v4.286c0 .837.46 1.58 1.155 1.951m9.345-8.334V6.637c0-1.621-1.152-3.026-2.76-3.235A48.455 48.455 0 0011.25 3c-2.115 0-4.198.137-6.24.402-1.608.209-2.76 1.614-2.76 3.235v6.226c0 1.621 1.152 3.026 2.76 3.235.577.075 1.157.14 1.74.194V21l4.155-4.155" /></svg>
                        <svg v-else-if="item.icon === 'templates'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M19.5 14.25v-2.625a3.375 3.375 0 00-3.375-3.375h-1.5A1.125 1.125 0 0113.5 7.125v-1.5a3.375 3.375 0 00-3.375-3.375H8.25m0 12.75h7.5m-7.5 3H12M10.5 2.25H5.625c-.621 0-1.125.504-1.125 1.125v17.25c0 .621.504 1.125 1.125 1.125h12.75c.621 0 1.125-.504 1.125-1.125V11.25a9 9 0 00-9-9z" /></svg>
                        <svg v-else-if="item.icon === 'staff'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                        <svg v-else-if="item.icon === 'teams'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M18 18.72a9.094 9.094 0 003.741-.479 3 3 0 00-4.682-2.72m.94 3.198l.001.031c0 .225-.012.447-.037.666A11.944 11.944 0 0112 21c-2.17 0-4.207-.576-5.963-1.584A6.062 6.062 0 016 18.719m12 0a5.971 5.971 0 00-.941-3.197m0 0A5.995 5.995 0 0012 12.75a5.995 5.995 0 00-5.058 2.772m0 0a3 3 0 00-4.681 2.72 8.986 8.986 0 003.74.477m.94-3.197a5.971 5.971 0 00-.94 3.197M15 6.75a3 3 0 11-6 0 3 3 0 016 0zm6 3a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0zm-13.5 0a2.25 2.25 0 11-4.5 0 2.25 2.25 0 014.5 0z" /></svg>
                        <svg v-else-if="item.icon === 'quickreplies'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" /></svg>
                        <svg v-else-if="item.icon === 'closingnotes'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                        <svg v-else-if="item.icon === 'metrics'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 013 19.875v-6.75zM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V8.625zM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 01-1.125-1.125V4.125z" /></svg>
                        <svg v-else-if="item.icon === 'settings'" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M9.594 3.94c.09-.542.56-.94 1.11-.94h2.593c.55 0 1.02.398 1.11.94l.213 1.281c.063.374.313.686.645.87.074.04.147.083.22.127.324.196.72.257 1.075.124l1.217-.456a1.125 1.125 0 011.37.49l1.296 2.247a1.125 1.125 0 01-.26 1.431l-1.003.827c-.293.24-.438.613-.431.992a6.759 6.759 0 010 .255c-.007.378.138.75.43.99l1.005.828c.424.35.534.954.26 1.43l-1.298 2.247a1.125 1.125 0 01-1.369.491l-1.217-.456c-.355-.133-.75-.072-1.076.124a6.57 6.57 0 01-.22.128c-.331.183-.581.495-.644.869l-.213 1.28c-.09.543-.56.941-1.11.941h-2.594c-.55 0-1.02-.398-1.11-.94l-.213-1.281c-.062-.374-.312-.686-.644-.87a6.52 6.52 0 01-.22-.127c-.325-.196-.72-.257-1.076-.124l-1.217.456a1.125 1.125 0 01-1.369-.49l-1.297-2.247a1.125 1.125 0 01.26-1.431l1.004-.827c.292-.24.437-.613.43-.992a6.932 6.932 0 010-.255c.007-.378-.138-.75-.43-.99l-1.004-.828a1.125 1.125 0 01-.26-1.43l1.297-2.247a1.125 1.125 0 011.37-.491l1.216.456c.356.133.751.072 1.076-.124.072-.044.146-.087.22-.128.332-.183.582-.495.644-.869l.214-1.281z" /><path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                    </span>
                    <span v-show="sidebarOpen" class="truncate">{{ item.name }}</span>
                </Link>
            </nav>

            <!-- WhatsApp API badge -->
            <div class="mb-3 transition-all duration-300" :class="sidebarOpen ? 'p-3 mx-3 rounded-xl bg-sidebar-hover border border-white/5' : 'flex justify-center'">
                <div class="flex items-center" :class="sidebarOpen ? 'space-x-3' : 'flex-col'">
                    <div class="w-8 h-8 rounded-full bg-accent/20 flex items-center justify-center shrink-0" :title="!sidebarOpen ? 'WhatsApp API: Conectado' : ''">
                        <svg class="w-4 h-4 text-accent" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/></svg>
                    </div>
                    <div v-show="sidebarOpen" class="truncate">
                        <p class="text-xs text-white font-medium">WhatsApp API</p>
                        <p class="text-[10px] text-accent">Conectado</p>
                    </div>
                </div>
            </div>
        </aside>

        <!-- ── Main content ──────────────────────────────────────────────────── -->
        <div class="flex-1 flex flex-col overflow-hidden">

            <!-- Top bar -->
            <header class="bg-white border-b border-gray-200 h-16 flex items-center justify-between px-4 md:px-6 shrink-0 z-10">
                <div class="flex items-center space-x-3">
                    <!-- Mobile menu -->
                    <button @click="sidebarOpen = !sidebarOpen" class="md:hidden p-2 rounded-lg text-gray-500 hover:bg-gray-100 transition">
                        <svg class="w-6 h-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5m-16.5 5.25h16.5" /></svg>
                    </button>
                    <h2 class="text-base font-semibold text-gray-800">
                        {{ navItems.find(i => isActive(i.route))?.name || 'Dashboard' }}
                    </h2>
                </div>

                <div class="flex items-center space-x-2">

                    <!-- ── Theme switcher ──────────────────────────────────── -->
                    <div class="relative">
                        <button
                            @click="showThemeMenu = !showThemeMenu"
                            class="p-2 rounded-lg text-gray-500 hover:bg-gray-100 transition"
                            title="Cambiar tema"
                        >
                            <!-- Sun (light) -->
                            <svg v-if="themeIcon === 'sun'" class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" />
                            </svg>
                            <!-- Moon (dark) -->
                            <svg v-else-if="themeIcon === 'moon'" class="w-5 h-5 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" />
                            </svg>
                            <!-- System -->
                            <svg v-else class="w-5 h-5 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" />
                            </svg>
                        </button>

                        <!-- Dropdown -->
                        <Transition enter-active-class="transition ease-out duration-150" enter-from-class="opacity-0 scale-95" enter-to-class="opacity-100 scale-100" leave-active-class="transition ease-in duration-100" leave-from-class="opacity-100 scale-100" leave-to-class="opacity-0 scale-95">
                            <div v-if="showThemeMenu" class="absolute right-0 top-11 w-40 bg-white rounded-xl shadow-xl border border-gray-200 py-1.5 z-50 animate-scale-in">
                                <button @click="setTheme('light')" class="flex items-center w-full px-4 py-2 text-sm hover:bg-gray-50 transition" :class="theme === 'light' ? 'text-accent font-medium' : 'text-gray-700'">
                                    <svg class="w-4 h-4 mr-3 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v2.25m6.364.386l-1.591 1.591M21 12h-2.25m-.386 6.364l-1.591-1.591M12 18.75V21m-4.773-4.227l-1.591 1.591M5.25 12H3m4.227-4.773L5.636 5.636M15.75 12a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0z" /></svg>
                                    Claro
                                    <svg v-if="theme === 'light'" class="w-4 h-4 ml-auto text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </button>
                                <button @click="setTheme('dark')" class="flex items-center w-full px-4 py-2 text-sm hover:bg-gray-50 transition" :class="theme === 'dark' ? 'text-accent font-medium' : 'text-gray-700'">
                                    <svg class="w-4 h-4 mr-3 text-indigo-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M21.752 15.002A9.718 9.718 0 0118 15.75c-5.385 0-9.75-4.365-9.75-9.75 0-1.33.266-2.597.748-3.752A9.753 9.753 0 003 11.25C3 16.635 7.365 21 12.75 21a9.753 9.753 0 009.002-5.998z" /></svg>
                                    Oscuro
                                    <svg v-if="theme === 'dark'" class="w-4 h-4 ml-auto text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </button>
                                <button @click="setTheme('system')" class="flex items-center w-full px-4 py-2 text-sm hover:bg-gray-50 transition" :class="theme === 'system' ? 'text-accent font-medium' : 'text-gray-700'">
                                    <svg class="w-4 h-4 mr-3 text-gray-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 17.25v1.007a3 3 0 01-.879 2.122L7.5 21h9l-.621-.621A3 3 0 0115 18.257V17.25m6-12V15a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 15V5.25m18 0A2.25 2.25 0 0018.75 3H5.25A2.25 2.25 0 003 5.25m18 0V12a2.25 2.25 0 01-2.25 2.25H5.25A2.25 2.25 0 013 12V5.25" /></svg>
                                    Sistema
                                    <svg v-if="theme === 'system'" class="w-4 h-4 ml-auto text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                </button>
                            </div>
                        </Transition>
                    </div>


                    <!-- User avatar -->
                    <div class="w-9 h-9 rounded-full bg-gradient-to-br from-accent to-accent-light flex items-center justify-center text-white text-sm font-bold">
                        U
                    </div>
                </div>
            </header>

            <!-- Page content -->
            <main class="flex-1 overflow-y-auto bg-gray-50">
                <slot />
            </main>
        </div>

        <!-- ── Floating WhatsApp Support Button ─────────────────────────────── -->
        <a
            :href="SUPPORT_WHATSAPP"
            target="_blank"
            rel="noopener noreferrer"
            title="Soporte por WhatsApp"
            class="fixed bottom-6 right-6 z-[90] w-14 h-14 bg-[#25D366] hover:bg-[#1ebe5d] rounded-full flex items-center justify-center shadow-2xl hover:shadow-[0_0_0_6px_rgba(37,211,102,0.25)] transition-all duration-300 group"
        >
            <svg class="w-7 h-7 text-white group-hover:scale-110 transition-transform duration-200" viewBox="0 0 24 24" fill="currentColor">
                <path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347m-5.421 7.403h-.004a9.87 9.87 0 01-5.031-1.378l-.361-.214-3.741.982.998-3.648-.235-.374a9.86 9.86 0 01-1.51-5.26c.001-5.45 4.436-9.884 9.888-9.884 2.64 0 5.122 1.03 6.988 2.898a9.825 9.825 0 012.893 6.994c-.003 5.45-4.437 9.884-9.885 9.884m8.413-18.297A11.815 11.815 0 0012.05 0C5.495 0 .16 5.335.157 11.892c0 2.096.547 4.142 1.588 5.945L.057 24l6.305-1.654a11.882 11.882 0 005.683 1.448h.005c6.554 0 11.89-5.335 11.893-11.893a11.821 11.821 0 00-3.48-8.413z"/>
            </svg>
        </a>

        <!-- ── Toast ─────────────────────────────────────────────────────────── -->
        <Transition enter-active-class="transition ease-out duration-300" enter-from-class="translate-y-4 opacity-0" enter-to-class="translate-y-0 opacity-100" leave-active-class="transition ease-in duration-200" leave-from-class="translate-y-0 opacity-100" leave-to-class="translate-y-4 opacity-0">
            <div v-if="flashMessage" class="fixed bottom-6 right-6 z-[100] max-w-sm px-4 py-3 rounded-xl shadow-2xl text-white text-sm font-medium flex items-center space-x-2" :class="flashType === 'success' ? 'bg-accent' : 'bg-red-500'">
                <svg v-if="flashType === 'success'" class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                <svg v-else class="w-5 h-5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" /></svg>
                <span>{{ flashMessage }}</span>
            </div>
        </Transition>
    </div>
</template>
