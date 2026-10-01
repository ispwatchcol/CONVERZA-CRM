<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps({
    flows: { type: Array, default: () => [] },
    templates: { type: Array, default: () => [] },
    legacyBot: { type: Object, default: () => ({ enabled: false }) },
});

const activeCount = computed(() => props.flows.filter((f) => f.is_active).length);

// ── Crear ────────────────────────────────────────────────────────────────────
const showCreate = ref(false);
const form = useForm({ name: '', template: 'support' });

function openCreate(template = 'support') {
    form.reset();
    form.clearErrors();
    form.template = template;
    form.name = template === 'legacy' ? 'Bot clásico (editable)' : '';
    showCreate.value = true;
}

function create() {
    form.post(route('flows.store'));
}

// ── Acciones ─────────────────────────────────────────────────────────────────
function toggle(flow) {
    if (flow.is_active && !confirm(`Al apagar «${flow.name}», las conversaciones que esté atendiendo pasan a tu equipo. ¿Apagar?`)) return;
    router.patch(route('flows.toggle', flow.id), { active: !flow.is_active }, { preserveScroll: true });
}

function duplicate(flow) {
    router.post(route('flows.duplicate', flow.id));
}

function destroy(flow) {
    if (!confirm(`¿Eliminar «${flow.name}» con todas sus versiones y su historial? No se puede deshacer.`)) return;
    router.delete(route('flows.destroy', flow.id), { preserveScroll: true });
}

function pct(part, total) {
    return total ? `${Math.round((part / total) * 100)}%` : '—';
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}
</script>

<template>
    <Head title="Flujos del bot" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-6xl mx-auto">
            <!-- Encabezado -->
            <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-3 mb-6">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Flujos del bot</h1>
                    <p class="text-sm text-gray-500 mt-1">Arma cómo atiende tu bot de WhatsApp: menús, preguntas, datos del cliente y traspaso a tu equipo.</p>
                </div>
                <button type="button" class="inline-flex items-center px-4 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition shadow-sm shrink-0" @click="openCreate()">
                    <svg class="w-4 h-4 mr-2" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4v16m8-8H4"/></svg>
                    Nuevo flujo
                </button>
            </div>

            <!-- Convivencia con el bot clásico -->
            <div v-if="legacyBot.enabled && activeCount > 0" class="mb-6 flex items-start gap-2.5 px-4 py-3 bg-amber-50 border border-amber-200 rounded-xl text-sm text-amber-900">
                <span class="shrink-0">⏸</span>
                <span>Tu bot clásico (Configuración → Bot) está <strong>en pausa</strong> mientras haya un flujo encendido. Nunca contestan los dos.</span>
            </div>
            <div v-else-if="legacyBot.enabled" class="mb-6 flex flex-col sm:flex-row sm:items-center gap-3 px-4 py-3 bg-blue-50 border border-blue-200 rounded-xl text-sm text-blue-900">
                <span class="flex-1">Hoy te atiende el bot clásico. Puedes convertirlo en un flujo editable con tus mismos textos y, cuando lo enciendas, el clásico queda en pausa.</span>
                <button type="button" class="shrink-0 text-sm font-semibold text-blue-700 hover:underline" @click="openCreate('legacy')">Convertir mi bot →</button>
            </div>

            <!-- Vacío -->
            <div v-if="!flows.length" class="bg-white rounded-2xl p-8 shadow-sm border border-gray-100 text-center">
                <div class="w-12 h-12 rounded-2xl bg-accent/10 flex items-center justify-center mx-auto mb-4">
                    <svg class="w-6 h-6 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.8"><path stroke-linecap="round" stroke-linejoin="round" d="M7.5 3.75H6A2.25 2.25 0 003.75 6v1.5M16.5 3.75H18A2.25 2.25 0 0120.25 6v1.5m0 9V18A2.25 2.25 0 0118 20.25h-1.5m-9 0H6A2.25 2.25 0 013.75 18v-1.5M12 8.25v7.5m-3.75-3.75h7.5"/></svg>
                </div>
                <h2 class="text-lg font-semibold text-gray-900">Todavía no tienes flujos</h2>
                <p class="text-sm text-gray-500 mt-1 max-w-lg mx-auto">
                    Un flujo es la conversación que tiene tu bot con cada cliente: lo armas con bloques, lo pruebas en el
                    simulador sin enviar nada y lo publicas cuando esté listo.
                </p>
                <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mt-6 text-left">
                    <button v-for="t in templates" :key="t.key" type="button" class="rounded-xl border border-gray-200 p-4 hover:border-accent hover:shadow-sm transition" @click="openCreate(t.key)">
                        <p class="text-sm font-semibold text-gray-900">{{ t.name }}</p>
                        <p class="text-xs text-gray-500 mt-1">{{ t.description }}</p>
                    </button>
                </div>
            </div>

            <!-- Lista -->
            <div v-else class="space-y-3">
                <div v-for="flow in flows" :key="flow.id" class="bg-white rounded-2xl p-5 shadow-sm border border-gray-100">
                    <div class="flex flex-col lg:flex-row lg:items-start gap-4">
                        <div class="flex-1 min-w-0">
                            <div class="flex items-center gap-2 flex-wrap">
                                <Link :href="route('flows.edit', flow.id)" class="text-base font-semibold text-gray-900 hover:text-accent truncate">{{ flow.name }}</Link>
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold"
                                      :class="flow.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'">
                                    {{ flow.is_active ? '● Atendiendo' : 'Apagado' }}
                                </span>
                                <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600">
                                    {{ flow.version ? `v${flow.version}` : 'Sin publicar' }}
                                </span>
                                <span v-if="flow.version && flow.has_unpublished_changes" class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-sky-100 text-sky-700">Borrador sin publicar</span>
                            </div>
                            <p v-if="flow.description" class="text-sm text-gray-600 mt-1">{{ flow.description }}</p>
                            <p class="text-xs text-gray-500 mt-1">
                                <span class="font-medium text-gray-600">Arranca:</span> {{ flow.trigger }}
                                <span v-if="flow.published_at"> · publicado {{ formatDate(flow.published_at) }}</span>
                            </p>
                        </div>

                        <!-- Métricas de 7 días -->
                        <div class="grid grid-cols-4 gap-2 text-center shrink-0 lg:w-[420px]">
                            <div class="rounded-xl bg-gray-50 px-2 py-2">
                                <p class="text-lg font-bold text-gray-900">{{ flow.stats.runs }}</p>
                                <p class="text-[10px] text-gray-500 leading-tight">conversaciones<br>(7 días)</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-2 py-2" title="Terminaron en un bloque Fin, sin asesor">
                                <p class="text-lg font-bold text-emerald-700">{{ pct(flow.stats.completed, flow.stats.runs) }}</p>
                                <p class="text-[10px] text-gray-500 leading-tight">resueltas<br>por el bot</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-2 py-2" title="Pasaron al equipo (por un bloque Traspaso o porque el flujo no pudo seguir)">
                                <p class="text-lg font-bold text-orange-600">{{ pct(flow.stats.handed_off + flow.stats.failed, flow.stats.runs) }}</p>
                                <p class="text-[10px] text-gray-500 leading-tight">pasaron<br>al equipo</p>
                            </div>
                            <div class="rounded-xl bg-gray-50 px-2 py-2" title="Veces que un menú no entendió la respuesta del cliente">
                                <p class="text-lg font-bold" :class="flow.stats.no_match ? 'text-red-600' : 'text-gray-900'">{{ flow.stats.no_match }}</p>
                                <p class="text-[10px] text-gray-500 leading-tight">«no<br>entendí»</p>
                            </div>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 mt-4 pt-4 border-t border-gray-100">
                        <Link :href="route('flows.edit', flow.id)" class="px-3 py-1.5 rounded-lg bg-accent/10 text-accent text-xs font-semibold hover:bg-accent/20">Editar</Link>
                        <Link :href="route('flows.runs', flow.id)" class="px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-100">
                            Actividad<span v-if="flow.stats.live"> · {{ flow.stats.live }} en curso</span>
                        </Link>
                        <button type="button" class="px-3 py-1.5 rounded-lg text-xs font-medium text-gray-600 hover:bg-gray-100" @click="duplicate(flow)">Duplicar</button>
                        <button type="button" class="px-3 py-1.5 rounded-lg text-xs font-medium text-red-600 hover:bg-red-50 disabled:opacity-40 disabled:hover:bg-transparent"
                                :disabled="flow.is_active" :title="flow.is_active ? 'Apágalo antes de borrarlo' : ''" @click="destroy(flow)">Eliminar</button>

                        <label class="ml-auto flex items-center gap-2 text-xs text-gray-600">
                            <span>{{ flow.is_active ? 'Encendido' : (flow.version ? 'Apagado' : 'Publícalo para encenderlo') }}</span>
                            <button type="button" class="relative w-11 h-6 rounded-full transition-colors disabled:opacity-40"
                                    :class="flow.is_active ? 'bg-emerald-500' : 'bg-gray-300'"
                                    :disabled="!flow.version" @click="toggle(flow)">
                                <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform" :class="flow.is_active ? 'translate-x-5' : ''"></span>
                            </button>
                        </label>
                    </div>
                </div>

                <p class="text-xs text-gray-500 px-1">
                    Si varios flujos podrían arrancar con el mismo mensaje, ganan primero los de palabra clave y luego el más antiguo de la lista.
                </p>
            </div>
        </div>

        <!-- ═══ Nuevo flujo ═══ -->
        <div v-if="showCreate" class="fixed inset-0 z-[95] bg-black/40 flex items-center justify-center p-4" @click.self="showCreate = false">
            <form class="bg-white rounded-2xl shadow-xl w-full max-w-lg p-6 space-y-4" @submit.prevent="create">
                <h3 class="text-lg font-semibold text-gray-900">Nuevo flujo</h3>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Nombre</label>
                    <input v-model="form.name" type="text" maxlength="120" required placeholder="Ej. Atención a clientes"
                           class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm" />
                    <p v-if="form.errors.name" class="text-xs text-red-500 mt-1">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1">Empezar desde</label>
                    <div class="space-y-2">
                        <button v-for="t in templates" :key="t.key" type="button"
                                class="w-full text-left rounded-xl border p-3 transition"
                                :class="form.template === t.key ? 'border-accent bg-accent/5' : 'border-gray-200 hover:border-gray-300'"
                                @click="form.template = t.key">
                            <p class="text-sm font-semibold text-gray-900">{{ t.name }}</p>
                            <p class="text-xs text-gray-500 mt-0.5">{{ t.description }}</p>
                        </button>
                    </div>
                </div>
                <p class="text-xs text-gray-500">Se crea como borrador apagado: nada cambia para tus clientes hasta que lo publiques y lo enciendas.</p>
                <div class="flex justify-end gap-2">
                    <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="showCreate = false">Cancelar</button>
                    <button type="submit" class="px-4 py-2 text-sm font-semibold rounded-xl bg-accent hover:bg-accent-hover text-white disabled:opacity-60" :disabled="form.processing">
                        {{ form.processing ? 'Creando…' : 'Crear y abrir el editor' }}
                    </button>
                </div>
            </form>
        </div>
    </AppLayout>
</template>
