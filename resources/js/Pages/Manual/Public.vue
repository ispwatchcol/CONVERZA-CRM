<script setup>
/**
 * Manual PÚBLICO (/ayuda): accesible con el link, sin iniciar sesión.
 *
 * No usa AppLayout porque ese layout depende de props de sesión (usuario,
 * tenant, estado de WhatsApp) que un invitado no tiene. El contenido es el
 * mismo componente que consume /manual, así que ambos se actualizan juntos.
 *
 * Se renderiza siempre en claro: es una página de cara al público y no hay
 * sesión donde guardar la preferencia de tema.
 */
import ManualContent from '@/Components/ManualContent.vue';
import Logo from '@/Components/Logo.vue';
import { Head } from '@inertiajs/vue3';

const year = new Date().getFullYear();
</script>

<template>
    <Head>
        <title>Manual de usuario — Converza CRM</title>
        <meta
            name="description"
            content="Guía completa de Converza CRM: chat de WhatsApp multi-agente, campañas masivas, avisos automáticos de facturación y métricas para ISPs."
        />
        <meta name="robots" content="index, follow" />
    </Head>

    <div class="min-h-screen bg-gray-50">
        <!-- Barra superior: identidad + puerta de entrada a la app -->
        <header class="bg-sidebar">
            <div class="max-w-7xl mx-auto px-4 md:px-6 h-16 flex items-center justify-between gap-4">
                <div class="flex items-center gap-3 min-w-0">
                    <Logo size="sm" />
                    <div class="truncate">
                        <p class="text-white font-bold text-base tracking-tight leading-tight">CONVERZA</p>
                        <p class="text-text-muted text-[10px] uppercase tracking-widest">CRM &amp; Chatbot</p>
                    </div>
                </div>

                <a
                    href="/login"
                    class="shrink-0 inline-flex items-center gap-2 px-4 py-2 text-sm font-semibold rounded-lg bg-accent text-white hover:bg-accent-hover transition"
                >
                    Iniciar sesión
                </a>
            </div>
        </header>

        <ManualContent public-mode />

        <footer class="border-t border-gray-200 bg-white">
            <div class="max-w-7xl mx-auto px-4 md:px-6 py-6 flex flex-col sm:flex-row items-center justify-between gap-3">
                <p class="text-xs text-gray-500">
                    Converza CRM &middot; {{ year }}
                </p>
                <p class="text-xs text-gray-400">
                    ¿Necesitas ayuda? Escríbenos por WhatsApp desde tu workspace.
                </p>
            </div>
        </footer>
    </div>
</template>
