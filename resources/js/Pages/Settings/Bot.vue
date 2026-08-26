<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps({
    botSettings: { type: Object, default: () => ({}) },
    // 'off' | 'active' | 'out_of_schedule' — calculado en el backend con la hora real.
    botState:    { type: String, default: 'off' },
    timezones:   { type: Array,  default: () => [] },
    recentLogs:  { type: Array,  default: () => [] },
});

const isAdmin = computed(() => usePage().props.auth?.user?.role === 'admin');

const form = useForm({
    bot_enabled:    props.botSettings.bot_enabled    ?? false,

    schedule_enabled:  props.botSettings.schedule_enabled  ?? false,
    schedule_mode:     props.botSettings.schedule_mode     ?? 'inside',
    schedule_timezone: props.botSettings.schedule_timezone ?? 'America/Bogota',
    schedule_days:     [...(props.botSettings.schedule_days ?? [1, 2, 3, 4, 5])],
    schedule_start:    props.botSettings.schedule_start    ?? '08:00',
    schedule_end:      props.botSettings.schedule_end      ?? '18:00',

    step_greeting_enabled:            props.botSettings.step_greeting_enabled            ?? true,
    step_branches_enabled:            props.botSettings.step_branches_enabled            ?? true,
    step_qualify_subscribers_enabled: props.botSettings.step_qualify_subscribers_enabled ?? true,
    step_qualify_name_enabled:        props.botSettings.step_qualify_name_enabled        ?? true,
    step_fallback_enabled:            props.botSettings.step_fallback_enabled            ?? true,

    msg_greeting:        props.botSettings.msg_greeting        ?? '',
    msg_info:            props.botSettings.msg_info            ?? '',
    msg_socio:           props.botSettings.msg_socio           ?? '',
    msg_demo:            props.botSettings.msg_demo            ?? '',
    msg_price:           props.botSettings.msg_price           ?? '',
    msg_ask_subscribers: props.botSettings.msg_ask_subscribers ?? '',
    msg_ask_name:        props.botSettings.msg_ask_name        ?? '',
    msg_handoff:         props.botSettings.msg_handoff         ?? '',
    msg_fallback_1:      props.botSettings.msg_fallback_1      ?? '',
    msg_fallback_2:      props.botSettings.msg_fallback_2      ?? '',
});

function save() {
    form.put(route('settings.bot.update'), { preserveScroll: true });
}

// ── Horario ──────────────────────────────────────────────────────────────────
// Días ISO-8601: 1 = lunes … 7 = domingo, igual que Carbon::dayOfWeekIso.
const weekDays = [
    { value: 1, label: 'Lun' },
    { value: 2, label: 'Mar' },
    { value: 3, label: 'Mié' },
    { value: 4, label: 'Jue' },
    { value: 5, label: 'Vie' },
    { value: 6, label: 'Sáb' },
    { value: 7, label: 'Dom' },
];

function toggleDay(day) {
    if (!isAdmin.value) return;
    const i = form.schedule_days.indexOf(day);
    if (i === -1) form.schedule_days.push(day);
    else form.schedule_days.splice(i, 1);
}

// Una franja cuyo fin es menor o igual que el inicio cruza medianoche
// (18:00 → 08:00). Lo avisamos porque no es obvio al escribirlo.
const crossesMidnight = computed(() =>
    form.schedule_enabled && form.schedule_end <= form.schedule_start
);

// El estado que muestra la píldora del encabezado: el backend ya calculó si
// estamos dentro de la franja, así que solo reaccionamos a cambios sin guardar.
const headerState = computed(() => {
    if (!form.bot_enabled) return 'off';
    if (props.botSettings.bot_enabled && props.botState === 'out_of_schedule') return 'out_of_schedule';
    return 'active';
});

const headerLabel = computed(() => ({
    off:             'Bot inactivo',
    active:          'Bot activo',
    out_of_schedule: 'Activo — fuera de horario',
}[headerState.value]));

const headerClass = computed(() => ({
    off:             'bg-gray-100 text-gray-500',
    active:          'bg-emerald-100 text-emerald-700',
    out_of_schedule: 'bg-amber-100 text-amber-700',
}[headerState.value]));

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
    cut_off:          'Corte (apagado/horario)',
    qualifying_subscribers: 'Pregunta suscriptores',
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
                <span class="shrink-0 px-3 py-1 rounded-full text-xs font-semibold" :class="headerClass">
                    {{ headerLabel }}
                </span>
            </div>

            <form @submit.prevent="save" class="space-y-6">

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

                    <p class="mt-4 text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2">
                        Si apagas el bot mientras conduce una conversación, esa conversación
                        recibe el <strong>mensaje de handoff</strong> y queda libre para un asesor.
                        Nadie se queda esperando una respuesta que ya no va a llegar.
                    </p>
                </section>

                <!-- ═══════════════ HORARIO ═══════════════════════════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-start justify-between gap-4 mb-4">
                        <div class="flex items-center gap-3">
                            <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center shrink-0">
                                <svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z"/>
                                </svg>
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Horario de atención</h3>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Limita las horas en que el bot responde. Fuera de la franja no envía nada.
                                </p>
                            </div>
                        </div>

                        <button
                            type="button"
                            :disabled="!isAdmin"
                            @click="isAdmin && (form.schedule_enabled = !form.schedule_enabled)"
                            class="relative shrink-0 w-12 h-6 rounded-full transition-colors duration-200 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-accent disabled:opacity-40 disabled:cursor-not-allowed"
                            :class="form.schedule_enabled ? 'bg-amber-500' : 'bg-gray-200'"
                            :aria-pressed="form.schedule_enabled"
                        >
                            <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform duration-200"
                                  :class="form.schedule_enabled ? 'translate-x-6' : 'translate-x-0'" />
                        </button>
                    </div>

                    <div class="space-y-4 transition-opacity" :class="{ 'opacity-50 pointer-events-none': !form.schedule_enabled }">
                        <!-- Modo -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">¿Cuándo debe responder?</label>
                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-2">
                                <button type="button" :disabled="!isAdmin" @click="form.schedule_mode = 'inside'"
                                        class="text-left rounded-xl border p-3 transition disabled:cursor-not-allowed"
                                        :class="form.schedule_mode === 'inside' ? 'border-amber-300 bg-amber-50' : 'border-gray-200 hover:border-gray-300'">
                                    <p class="text-sm font-semibold text-gray-900">Dentro del horario</p>
                                    <p class="text-xs text-gray-500 mt-0.5">El bot atiende en la franja; fuera de ella el chat queda para el equipo.</p>
                                </button>
                                <button type="button" :disabled="!isAdmin" @click="form.schedule_mode = 'outside'"
                                        class="text-left rounded-xl border p-3 transition disabled:cursor-not-allowed"
                                        :class="form.schedule_mode === 'outside' ? 'border-amber-300 bg-amber-50' : 'border-gray-200 hover:border-gray-300'">
                                    <p class="text-sm font-semibold text-gray-900">Solo fuera del horario</p>
                                    <p class="text-xs text-gray-500 mt-0.5">El equipo atiende en la franja; el bot cubre noches y fines de semana.</p>
                                </button>
                            </div>
                        </div>

                        <!-- Días -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-2">Días de la franja</label>
                            <div class="flex flex-wrap gap-2">
                                <button v-for="d in weekDays" :key="d.value" type="button"
                                        :disabled="!isAdmin" @click="toggleDay(d.value)"
                                        class="px-3 py-1.5 rounded-lg text-xs font-semibold border transition disabled:cursor-not-allowed"
                                        :class="form.schedule_days.includes(d.value)
                                            ? 'bg-amber-500 border-amber-500 text-white'
                                            : 'bg-white border-gray-200 text-gray-500 hover:border-gray-300'">
                                    {{ d.label }}
                                </button>
                            </div>
                            <p v-if="form.errors.schedule_days" class="text-red-500 text-xs mt-1">{{ form.errors.schedule_days }}</p>
                        </div>

                        <!-- Franja + zona -->
                        <div class="grid grid-cols-1 sm:grid-cols-3 gap-3">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Desde</label>
                                <input type="time" v-model="form.schedule_start" :disabled="!isAdmin"
                                       class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent disabled:bg-gray-50"
                                       :class="{ 'border-red-400': form.errors.schedule_start }" />
                                <p v-if="form.errors.schedule_start" class="text-red-500 text-xs mt-1">{{ form.errors.schedule_start }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Hasta</label>
                                <input type="time" v-model="form.schedule_end" :disabled="!isAdmin"
                                       class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent disabled:bg-gray-50"
                                       :class="{ 'border-red-400': form.errors.schedule_end }" />
                                <p v-if="form.errors.schedule_end" class="text-red-500 text-xs mt-1">{{ form.errors.schedule_end }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Zona horaria</label>
                                <select v-model="form.schedule_timezone" :disabled="!isAdmin"
                                        class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent disabled:bg-gray-50">
                                    <option v-for="tz in timezones" :key="tz" :value="tz">{{ tz }}</option>
                                </select>
                                <p v-if="form.errors.schedule_timezone" class="text-red-500 text-xs mt-1">{{ form.errors.schedule_timezone }}</p>
                            </div>
                        </div>

                        <p v-if="crossesMidnight" class="text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
                            La franja cruza medianoche ({{ form.schedule_start }} → {{ form.schedule_end }}).
                            Se cuenta como el día en que <strong>empieza</strong>: si marcas el lunes, la franja
                            va del lunes {{ form.schedule_start }} al martes {{ form.schedule_end }}.
                        </p>

                        <p class="text-xs text-gray-500">
                            El servidor corre en UTC; la franja se evalúa en la zona horaria que elijas aquí.
                        </p>
                    </div>
                </section>

                <!-- ═══════════════ PASOS DEL FLUJO ═══════════════════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center gap-3 mb-2">
                        <div class="w-9 h-9 rounded-xl bg-accent/10 flex items-center justify-center shrink-0">
                            <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M4 6h16M4 12h16M4 18h7"/>
                            </svg>
                        </div>
                        <div>
                            <h3 class="text-base font-semibold text-gray-900">Pasos del flujo</h3>
                            <p class="text-xs text-gray-500">Apaga los pasos que no necesites. El bot los salta y sigue con el siguiente.</p>
                        </div>
                    </div>

                    <p class="text-xs text-gray-500 bg-gray-50 rounded-lg px-3 py-2 mb-4">
                        Flujo completo: <strong>saludo</strong> → el cliente elige una opción →
                        <strong>respuesta comercial</strong> → <strong>nº de suscriptores</strong> →
                        <strong>nombre</strong> → <strong>handoff a un asesor</strong>.
                    </p>

                    <div class="space-y-2">
                        <!-- Saludo -->
                        <div class="rounded-xl border border-gray-100 p-4 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">1 · Saludo con menú</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Si lo apagas: el bot <strong>no muestra el menú</strong>. Cada conversación nueva
                                    recibe el mensaje de handoff y pasa directo a un asesor.
                                </p>
                            </div>
                            <button type="button" :disabled="!isAdmin"
                                    @click="isAdmin && (form.step_greeting_enabled = !form.step_greeting_enabled)"
                                    class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="form.step_greeting_enabled ? 'bg-accent' : 'bg-gray-300'"
                                    :aria-pressed="form.step_greeting_enabled">
                                <span class="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all"
                                      :class="form.step_greeting_enabled ? 'left-5' : 'left-0.5'"></span>
                            </button>
                        </div>

                        <!-- Ramas -->
                        <div class="rounded-xl border border-gray-100 p-4 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">2 · Respuestas comerciales (opciones 1–4)</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Si lo apagas: el bot <strong>enruta sin vender</strong>. Detecta la opción elegida,
                                    la deja como contexto para el asesor y pasa directo a calificar
                                    (o al handoff, si también apagaste los pasos 3 y 4).
                                </p>
                            </div>
                            <button type="button" :disabled="!isAdmin"
                                    @click="isAdmin && (form.step_branches_enabled = !form.step_branches_enabled)"
                                    class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="form.step_branches_enabled ? 'bg-accent' : 'bg-gray-300'"
                                    :aria-pressed="form.step_branches_enabled">
                                <span class="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all"
                                      :class="form.step_branches_enabled ? 'left-5' : 'left-0.5'"></span>
                            </button>
                        </div>

                        <!-- Suscriptores -->
                        <div class="rounded-xl border border-gray-100 p-4 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">3 · Preguntar nº de suscriptores</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Si lo apagas: el bot <strong>no califica por tamaño</strong> y pasa directo a
                                    preguntar el nombre (o al handoff si ese paso también está apagado).
                                </p>
                            </div>
                            <button type="button" :disabled="!isAdmin"
                                    @click="isAdmin && (form.step_qualify_subscribers_enabled = !form.step_qualify_subscribers_enabled)"
                                    class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="form.step_qualify_subscribers_enabled ? 'bg-accent' : 'bg-gray-300'"
                                    :aria-pressed="form.step_qualify_subscribers_enabled">
                                <span class="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all"
                                      :class="form.step_qualify_subscribers_enabled ? 'left-5' : 'left-0.5'"></span>
                            </button>
                        </div>

                        <p v-if="!form.step_qualify_subscribers_enabled && form.step_branches_enabled"
                           class="text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
                            Las respuestas comerciales de abajo <strong>terminan preguntando por el nº de
                            suscriptores</strong>. Con este paso apagado, quita esa pregunta de sus textos o el
                            cliente responderá algo que el bot ya no espera.
                        </p>

                        <!-- Nombre -->
                        <div class="rounded-xl border border-gray-100 p-4 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">4 · Preguntar el nombre</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Si lo apagas: el bot <strong>no pide el nombre</strong> ni lo guarda en la ficha
                                    del contacto; va directo al handoff.
                                </p>
                            </div>
                            <button type="button" :disabled="!isAdmin"
                                    @click="isAdmin && (form.step_qualify_name_enabled = !form.step_qualify_name_enabled)"
                                    class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="form.step_qualify_name_enabled ? 'bg-accent' : 'bg-gray-300'"
                                    :aria-pressed="form.step_qualify_name_enabled">
                                <span class="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all"
                                      :class="form.step_qualify_name_enabled ? 'left-5' : 'left-0.5'"></span>
                            </button>
                        </div>

                        <!-- Fallback -->
                        <div class="rounded-xl border border-gray-100 p-4 flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900">5 · Reintentar cuando no entiende</p>
                                <p class="text-xs text-gray-500 mt-0.5">
                                    Si lo apagas: <strong>sin segunda oportunidad</strong>. Al primer mensaje que no
                                    reconozca envía el Fallback 2 y escala a un asesor.
                                </p>
                            </div>
                            <button type="button" :disabled="!isAdmin"
                                    @click="isAdmin && (form.step_fallback_enabled = !form.step_fallback_enabled)"
                                    class="relative inline-flex h-6 w-11 shrink-0 rounded-full transition-colors disabled:opacity-40 disabled:cursor-not-allowed"
                                    :class="form.step_fallback_enabled ? 'bg-accent' : 'bg-gray-300'"
                                    :aria-pressed="form.step_fallback_enabled">
                                <span class="absolute top-0.5 h-5 w-5 bg-white rounded-full shadow transition-all"
                                      :class="form.step_fallback_enabled ? 'left-5' : 'left-0.5'"></span>
                            </button>
                        </div>
                    </div>
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

                    <div class="space-y-5">

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

                        <!-- Pregunta de suscriptores (solo si las ramas están apagadas) -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Pregunta — nº de suscriptores
                                <span class="text-xs font-normal text-gray-400 ml-1">— solo se envía si las respuestas comerciales están apagadas</span>
                            </label>
                            <textarea
                                v-model="form.msg_ask_subscribers"
                                rows="2"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_ask_subscribers }"
                            />
                            <p class="text-xs text-gray-400 mt-1">
                                Con las respuestas comerciales activas la pregunta ya viene dentro de sus textos, así que este no se usa.
                            </p>
                            <p v-if="form.errors.msg_ask_subscribers" class="text-red-500 text-xs mt-1">{{ form.errors.msg_ask_subscribers }}</p>
                        </div>

                        <!-- Pregunta del nombre -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">
                                Pregunta — nombre del contacto
                                <span class="text-xs font-normal text-gray-400 ml-1">— la respuesta se guarda en la ficha del contacto</span>
                            </label>
                            <textarea
                                v-model="form.msg_ask_name"
                                rows="2"
                                :disabled="!isAdmin"
                                class="w-full px-4 py-3 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y disabled:bg-gray-50 disabled:text-gray-400"
                                :class="{ 'border-red-400': form.errors.msg_ask_name }"
                            />
                            <p v-if="form.errors.msg_ask_name" class="text-red-500 text-xs mt-1">{{ form.errors.msg_ask_name }}</p>
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

                    </div>
                </section>

                <!-- ═══════════════ GUARDAR ═══════════════════════════════════ -->
                <!-- Sticky: activación, horario, pasos y mensajes son un solo
                     formulario, y el admin no debería tener que bajar al final
                     para guardar un cambio hecho arriba. -->
                <div v-if="isAdmin" class="sticky bottom-4 z-10 flex items-center justify-between gap-3 bg-white/95 backdrop-blur rounded-2xl border border-gray-100 shadow-sm px-4 py-3">
                    <p v-if="$page.props.flash?.success" class="text-emerald-600 text-sm font-medium">
                        {{ $page.props.flash.success }}
                    </p>
                    <p v-else class="text-xs text-gray-500">
                        Los cambios de activación, horario, pasos y mensajes se guardan juntos.
                    </p>

                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="shrink-0 px-5 py-2.5 bg-accent hover:bg-accent-hover text-white text-sm font-semibold rounded-xl shadow-sm transition disabled:opacity-60 disabled:cursor-not-allowed"
                    >
                        {{ form.processing ? 'Guardando…' : 'Guardar cambios' }}
                    </button>
                </div>

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

            </form>
        </div>
    </AppLayout>
</template>
