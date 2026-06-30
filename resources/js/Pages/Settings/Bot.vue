<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    botSettings: { type: Object, default: () => ({}) },
    recentLogs:  { type: Array,  default: () => [] },
});

const isAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');

const form = useForm({
    bot_enabled:    props.botSettings.bot_enabled    ?? false,
    msg_greeting:   props.botSettings.msg_greeting   ?? '',
    msg_info:       props.botSettings.msg_info       ?? '',
    msg_socio:      props.botSettings.msg_socio      ?? '',
    msg_demo:       props.botSettings.msg_demo       ?? '',
    msg_price:      props.botSettings.msg_price      ?? '',
    msg_handoff:    props.botSettings.msg_handoff    ?? '',
    msg_fallback_1: props.botSettings.msg_fallback_1 ?? '',
    msg_fallback_2: props.botSettings.msg_fallback_2 ?? '',
});

function save() {
    form.put(route('settings.bot.update'), { preserveScroll: true });
}

const intentLabel = {
    demo:             'Demo',
    info:             'Info ISPWatch',
    socio:            'Socio Fundador',
    price:            'Precio',
    agent:            'Asesor',
    greeting:         'Saludo',
    qualifying_name:  'Pregunta nombre',
    fallback_1:       'Fallback 1',
    fallback_2:       'Fallback 2',
    handoff:          'Handoff',
    unknown:          'Desconocido',
};

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', {
        day: '2-digit', month: 'short', year: 'numeric',
        hour: '2-digit', minute: '2-digit',
    }).format(new Date(iso));
}

function contextSummary(ctx) {
    if (!ctx) return null;
    const parts = [];
    if (ctx.intent)       parts.push(`Rama: ${intentLabel[ctx.intent] ?? ctx.intent}`);
    if (ctx.suscriptores) parts.push(`Suscriptores: ${ctx.suscriptores}`);
    if (ctx.nombre)       parts.push(`Nombre: ${ctx.nombre}`);
    if (ctx.ciudad)       parts.push(`Ciudad: ${ctx.ciudad}`);
    return parts.join(' · ') || null;
}
</script>

<template>
    <Head title="Bot de WhatsApp" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-4xl mx-auto">

            <!-- ── Header ──────────────────────────────────────────────────── -->
            <div class="mb-6 flex items-start justify-between gap-4">
                <div>
                    <div class="flex items-center gap-2 mb-1">
                        <Link :href="route('settings.index')"
                              class="text-sm text-gray-400 hover:text-accent transition">
                            Configuración
                        </Link>
                        <svg class="w-3 h-3 text-gray-300" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                        </svg>
                        <span class="text-sm text-gray-700 font-medium">Bot de WhatsApp</span>
                    </div>
                    <h1 class="text-2xl font-bold text-gray-900">Bot de WhatsApp</h1>
                    <p class="text-sm text-gray-500 mt-1">Respuestas automáticas para leads de pauta publicitaria</p>
                </div>

                <!-- Indicador de estado -->
                <span class="shrink-0 px-3 py-1 rounded-full text-xs font-semibold"
                      :class="form.bot_enabled
                          ? 'bg-emerald-100 text-emerald-700'
                          : 'bg-gray-100 text-gray-500'">
                    {{ form.bot_enabled ? 'Bot activo' : 'Bot inactivo' }}
                </span>
            </div>

            <div class="space-y-6">

                <!-- ═══════════════ ACTIVACIÓN ═══════════════════════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-start justify-between gap-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-accent/10 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Activar bot</h3>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Cuando está activo, el bot responde automáticamente a conversaciones nuevas
                                    mientras no haya un agente asignado.
                                </p>
                            </div>
                        </div>

                        <!-- Toggle -->
                        <button
                            type="button"
                            :disabled="!isAdmin"
                            @click="isAdmin && (form.bot_enabled = !form.bot_enabled)"
                            class="relative shrink-0 w-12 h-6 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="form.bot_enabled ? 'bg-accent' : 'bg-gray-200'"
                        >
                            <span
                                class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"
                                :class="form.bot_enabled ? 'translate-x-6' : 'translate-x-0'"
                            />
                        </button>
                    </div>

                    <p v-if="!isAdmin" class="mt-4 text-xs text-amber-600 bg-amber-50 rounded-lg px-3 py-2">
                        Solo los administradores pueden activar o desactivar el bot.
                    </p>
                </section>

                <!-- ═══════════════ MENSAJES ══════════════════════════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center gap-3 mb-6">
                        <div class="w-9 h-9 rounded-xl bg-accent/10 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Mensajes del bot</h3>
                            <p class="text-xs text-gray-500">Texto exacto que se envía en cada situación</p>
                        </div>
                    </div>

                    <form @submit.prevent="save" class="space-y-5">

                        <!-- Saludo inicial -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Saludo inicial
                                <span class="text-xs font-normal text-gray-400 ml-1">— se envía ante el primer mensaje de una conversación nueva</span>
                            </label>
                            <textarea
                                v-model="form.msg_greeting"
                                rows="8"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_greeting }"
                            />
                            <p v-if="form.errors.msg_greeting" class="text-red-500 text-xs mt-1">{{ form.errors.msg_greeting }}</p>
                        </div>

                        <hr class="border-gray-100">

                        <!-- Rama Info ISPWatch -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Respuesta — Rama "Conocer ISPWatch" <span class="text-xs font-normal text-gray-400">(opción 1)</span>
                            </label>
                            <textarea
                                v-model="form.msg_info"
                                rows="8"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_info }"
                            />
                            <p v-if="form.errors.msg_info" class="text-red-500 text-xs mt-1">{{ form.errors.msg_info }}</p>
                        </div>

                        <!-- Rama Socio Fundador -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Respuesta — Rama "Socio Fundador" <span class="text-xs font-normal text-gray-400">(opción 2)</span>
                            </label>
                            <textarea
                                v-model="form.msg_socio"
                                rows="10"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_socio }"
                            />
                            <p v-if="form.errors.msg_socio" class="text-red-500 text-xs mt-1">{{ form.errors.msg_socio }}</p>
                        </div>

                        <!-- Rama Demo -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Respuesta — Rama "Demo" <span class="text-xs font-normal text-gray-400">(opción 3)</span>
                            </label>
                            <textarea
                                v-model="form.msg_demo"
                                rows="6"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_demo }"
                            />
                            <p v-if="form.errors.msg_demo" class="text-red-500 text-xs mt-1">{{ form.errors.msg_demo }}</p>
                        </div>

                        <!-- Rama Precio -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Respuesta — Rama "Precios" <span class="text-xs font-normal text-gray-400">(opción 4)</span>
                            </label>
                            <textarea
                                v-model="form.msg_price"
                                rows="4"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_price }"
                            />
                            <p v-if="form.errors.msg_price" class="text-red-500 text-xs mt-1">{{ form.errors.msg_price }}</p>
                        </div>

                        <hr class="border-gray-100">

                        <!-- Handoff -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Mensaje de handoff
                                <span class="text-xs font-normal text-gray-400 ml-1">— se envía cuando el bot cede el control al asesor</span>
                            </label>
                            <textarea
                                v-model="form.msg_handoff"
                                rows="3"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_handoff }"
                            />
                            <p v-if="form.errors.msg_handoff" class="text-red-500 text-xs mt-1">{{ form.errors.msg_handoff }}</p>
                        </div>

                        <!-- Fallback 1 -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fallback 1
                                <span class="text-xs font-normal text-gray-400 ml-1">— intención no reconocida (primer intento)</span>
                            </label>
                            <textarea
                                v-model="form.msg_fallback_1"
                                rows="6"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_fallback_1 }"
                            />
                            <p v-if="form.errors.msg_fallback_1" class="text-red-500 text-xs mt-1">{{ form.errors.msg_fallback_1 }}</p>
                        </div>

                        <!-- Fallback 2 / escalación automática -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Fallback 2
                                <span class="text-xs font-normal text-gray-400 ml-1">— segundo intento fallido → escala a asesor</span>
                            </label>
                            <textarea
                                v-model="form.msg_fallback_2"
                                rows="2"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_fallback_2 }"
                            />
                            <p v-if="form.errors.msg_fallback_2" class="text-red-500 text-xs mt-1">{{ form.errors.msg_fallback_2 }}</p>
                        </div>

                        <!-- Botón guardar -->
                        <div v-if="isAdmin" class="flex items-center justify-between pt-2">
                            <p v-if="$page.props.flash?.success" class="text-emerald-600 text-sm font-medium">
                                {{ $page.props.flash.success }}
                            </p>
                            <div v-else></div>

                            <button
                                type="submit"
                                :disabled="form.processing"
                                class="px-5 py-2.5 bg-accent hover:bg-accent-hover text-white text-sm font-semibold rounded-xl shadow-sm transition disabled:opacity-60 disabled:cursor-not-allowed"
                            >
                                {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- ═══════════════ ACTIVIDAD RECIENTE ═══════════════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center gap-3 mb-4">
                        <div class="w-9 h-9 rounded-xl bg-accent/10 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Actividad reciente</h3>
                            <p class="text-xs text-gray-500">Últimas 50 interacciones del bot</p>
                        </div>
                    </div>

                    <div v-if="recentLogs.length === 0"
                         class="text-center py-10 text-gray-400 text-sm">
                        Sin actividad aún. El bot registrará aquí cada interacción.
                    </div>

                    <div v-else class="overflow-x-auto -mx-2">
                        <table class="w-full text-sm">
                            <thead>
                                <tr class="text-left text-xs font-medium text-gray-500 border-b border-gray-100">
                                    <th class="pb-2 px-2">Fecha</th>
                                    <th class="pb-2 px-2">Conv.</th>
                                    <th class="pb-2 px-2">Intención</th>
                                    <th class="pb-2 px-2">Contexto</th>
                                    <th class="pb-2 px-2 text-center">Escaló</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-50">
                                <tr v-for="log in recentLogs" :key="log.id"
                                    class="hover:bg-gray-50 transition">
                                    <td class="py-2.5 px-2 text-xs text-gray-500 whitespace-nowrap">
                                        {{ formatDate(log.created_at) }}
                                    </td>
                                    <td class="py-2.5 px-2">
                                        <Link :href="route('chat.index', { conversation: log.conversation_id })"
                                              class="text-accent hover:underline text-xs font-mono">
                                            #{{ log.conversation_id }}
                                        </Link>
                                    </td>
                                    <td class="py-2.5 px-2">
                                        <span class="px-2 py-0.5 rounded-full text-xs font-medium bg-gray-100 text-gray-600">
                                            {{ intentLabel[log.intent_detected] ?? log.intent_detected ?? '—' }}
                                        </span>
                                    </td>
                                    <td class="py-2.5 px-2 text-xs text-gray-500 max-w-xs truncate">
                                        {{ contextSummary(log.context_data) ?? '—' }}
                                    </td>
                                    <td class="py-2.5 px-2 text-center">
                                        <span v-if="log.escalated"
                                              class="inline-block w-2 h-2 rounded-full bg-amber-400"
                                              title="Escalado a asesor" />
                                        <span v-else class="text-gray-300 text-xs">—</span>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </section>

            </div>
        </div>
    </AppLayout>
</template>
