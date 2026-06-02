<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { useForm, Head, Link } from '@inertiajs/vue3';
import { ref, computed } from 'vue';
import axios from 'axios';

const props = defineProps({
    tenant: Object,
    workspace: Object,
    ispwatchStatus: { type: Object, default: null },
    // Plantillas aprobadas: [{ id, name, language, event_key }]
    approvedTemplates: { type: Array, default: () => [] },
    // Catálogo de eventos: [{ key, label, description, trigger, variables }]
    notificationEvents: { type: Array, default: () => [] },
    // Asignación actual: { event_key: { template_id, enabled } }
    notificationRoutes: { type: Object, default: () => ({}) },
});

// ── Forms (uno por sección) ──────────────────────────────────────────────────
const profileForm = useForm({
    name: props.tenant.name ?? '',
    slug: props.tenant.slug ?? '',
    is_active: props.tenant.is_active,
});

const whatsappForm = useForm({
    wa_phone_number_id: props.tenant.wa_phone_number_id ?? '',
    wa_business_account_id: props.tenant.wa_business_account_id ?? '',
    wa_verify_token: props.tenant.wa_verify_token ?? '',
    wa_access_token: '',
    wa_app_secret: '',
});

// ── Avisos automáticos (evento → plantilla) ──────────────────────────────────
const routesForm = useForm({
    routes: props.notificationEvents.map((e) => {
        const r = props.notificationRoutes[e.key] || {};
        return {
            event_key: e.key,
            template_id: r.template_id ?? '',
            enabled: r.enabled ?? false,
        };
    }),
});

// Plantillas elegibles para un evento: las tageadas con ese propósito o las
// "generales" (sin propósito).
function templatesForEvent(eventKey) {
    return props.approvedTemplates.filter((t) => t.event_key === eventKey || !t.event_key);
}

function saveNotifications() {
    routesForm
        .transform((data) => ({
            routes: data.routes.map((r) => {
                const templateId = r.template_id === '' || r.template_id == null ? null : Number(r.template_id);
                return {
                    event_key: r.event_key,
                    template_id: templateId,
                    enabled: !!r.enabled && templateId != null,
                };
            }),
        }))
        .put(route('settings.notifications.update'), { preserveScroll: true });
}

// La vinculación ISPWatch NO es editable por el cliente: la hace el admin del
// SaaS con `php artisan tenant:link`. Aquí solo mostramos estado en vivo.

// ── Token replace toggle ─────────────────────────────────────────────────────
const editingAccessToken = ref(!props.tenant.wa_access_token_set);
const editingAppSecret = ref(!props.tenant.wa_app_secret_set);

function cancelAccessTokenEdit() {
    whatsappForm.wa_access_token = '';
    editingAccessToken.value = false;
}
function cancelAppSecretEdit() {
    whatsappForm.wa_app_secret = '';
    editingAppSecret.value = false;
}

// ── Submits ──────────────────────────────────────────────────────────────────
function saveProfile() {
    profileForm.put(route('settings.profile.update'), { preserveScroll: true });
}
function saveWhatsapp() {
    whatsappForm.put(route('settings.whatsapp.update'), {
        preserveScroll: true,
        onSuccess: () => {
            whatsappForm.wa_access_token = '';
            whatsappForm.wa_app_secret = '';
            if (props.tenant.wa_access_token_set) editingAccessToken.value = false;
            if (props.tenant.wa_app_secret_set) editingAppSecret.value = false;
        },
    });
}
// ── Test WhatsApp connection ─────────────────────────────────────────────────
const testing = ref(false);
const testResult = ref(null);

async function testWhatsApp() {
    testing.value = true;
    testResult.value = null;
    try {
        const { data } = await axios.post(route('settings.whatsapp.test'));
        testResult.value = data;
    } catch (e) {
        testResult.value = { ok: false, message: 'Error de red al contactar Meta.' };
    } finally {
        testing.value = false;
    }
}

// ── ISPWatch live status refresh ─────────────────────────────────────────────
const refreshing = ref(false);
const liveStatus = ref(props.ispwatchStatus);

async function refreshIspwatchStatus() {
    refreshing.value = true;
    try {
        const { data } = await axios.get(route('settings.ispwatch.status'));
        liveStatus.value = data;
    } finally {
        refreshing.value = false;
    }
}

// ── Helpers ──────────────────────────────────────────────────────────────────
const copiedKey = ref(null);
function copy(text, key) {
    if (!navigator.clipboard) return;
    navigator.clipboard.writeText(text).then(() => {
        copiedKey.value = key;
        setTimeout(() => { if (copiedKey.value === key) copiedKey.value = null; }, 1500);
    });
}

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', year: 'numeric' }).format(new Date(iso));
}
function formatTime(iso) {
    if (!iso) return '';
    return new Intl.DateTimeFormat('es-CO', { hour: '2-digit', minute: '2-digit', second: '2-digit' }).format(new Date(iso));
}

const ispwatchBadge = computed(() => {
    if (!props.tenant.ispwatch_linked) return { label: 'No vinculado', cls: 'bg-gray-100 text-gray-600' };
    if (!liveStatus.value) return { label: 'Verificando…', cls: 'bg-gray-100 text-gray-600' };
    if (liveStatus.value.connected) return { label: 'Conectado', cls: 'bg-emerald-100 text-emerald-700' };
    return { label: 'No encontrado', cls: 'bg-red-100 text-red-700' };
});

const waBadge = computed(() => {
    if (props.tenant.wa_status === 'connected') return { label: 'Conectado', cls: 'bg-emerald-100 text-emerald-700', dot: 'bg-emerald-500' };
    if (props.tenant.wa_status === 'error') return { label: 'Error', cls: 'bg-red-100 text-red-700', dot: 'bg-red-500' };
    if (props.tenant.has_whatsapp) return { label: 'Configurado', cls: 'bg-blue-100 text-blue-700', dot: 'bg-blue-500' };
    return { label: 'Sin configurar', cls: 'bg-gray-100 text-gray-600', dot: 'bg-gray-400' };
});
</script>

<template>
    <Head title="Configuración" />
    <AppLayout>
        <div class="p-4 md:p-6 lg:p-8 animate-fade-in max-w-4xl mx-auto">
            <!-- Header -->
            <div class="mb-6 flex items-start justify-between">
                <div>
                    <h1 class="text-2xl font-bold text-gray-900">Configuración</h1>
                    <p class="text-sm text-gray-500 mt-1">Ajustes de tu workspace · <span class="font-medium text-gray-700">{{ tenant.name }}</span></p>
                </div>
                <span class="px-3 py-1 rounded-full text-xs font-medium" :class="tenant.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-amber-100 text-amber-700'">
                    {{ tenant.is_active ? 'Tenant activo' : 'Tenant inactivo' }}
                </span>
            </div>

            <div class="space-y-6">
                <!-- ═══════════════ PERFIL ═══════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="w-9 h-9 rounded-xl bg-accent/10 flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-accent" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7.5L7.5 3h9L21 7.5v9L16.5 21h-9L3 16.5v-9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M9 12h6"/></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Perfil del tenant</h3>
                                <p class="text-xs text-gray-500">Identidad de tu workspace dentro de Converza</p>
                            </div>
                        </div>
                    </div>

                    <form @submit.prevent="saveProfile" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Nombre comercial</label>
                                <input v-model="profileForm.name" type="text" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': profileForm.errors.name }">
                                <p v-if="profileForm.errors.name" class="text-red-500 text-xs mt-1">{{ profileForm.errors.name }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Slug</label>
                                <input v-model="profileForm.slug" type="text" placeholder="mi-empresa" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent" :class="{ 'border-red-400': profileForm.errors.slug }">
                                <p v-if="profileForm.errors.slug" class="text-red-500 text-xs mt-1">{{ profileForm.errors.slug }}</p>
                                <p v-else class="text-xs text-gray-400 mt-1">Solo minúsculas, números y guiones.</p>
                            </div>
                        </div>
                        <div>
                            <label class="inline-flex items-center cursor-pointer">
                                <input v-model="profileForm.is_active" type="checkbox" class="rounded border-gray-300 text-accent focus:ring-accent/30">
                                <span class="ml-2 text-sm text-gray-700">Tenant activo</span>
                            </label>
                            <p class="text-xs text-gray-400 mt-1">Si lo desactivas, los usuarios de este tenant no podrán iniciar sesión.</p>
                        </div>
                        <div class="flex justify-end pt-2 border-t border-gray-50">
                            <button type="submit" :disabled="profileForm.processing" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                {{ profileForm.processing ? 'Guardando…' : 'Guardar perfil' }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- ═══════════════ ISPWATCH (read-only) ═══════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="w-9 h-9 rounded-xl bg-blue-50 flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-blue-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M20.25 6.375c0 2.278-3.694 4.125-8.25 4.125S3.75 8.653 3.75 6.375m16.5 0c0-2.278-3.694-4.125-8.25-4.125S3.75 4.097 3.75 6.375m16.5 0v11.25c0 2.278-3.694 4.125-8.25 4.125S3.75 19.903 3.75 17.625V6.375m16.5 0v3.75m-16.5-3.75v3.75m16.5 0v3.75C20.25 16.153 16.556 18 12 18s-8.25-1.847-8.25-4.125v-3.75m16.5 0c0 2.278-3.694 4.125-8.25 4.125s-8.25-1.847-8.25-4.125"/></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Integración ISPWatch</h3>
                                <p class="text-xs text-gray-500">Tu cuenta está conectada a un cliente de ISPWatch. Esta vinculación la gestiona el administrador de Converza.</p>
                            </div>
                        </div>
                        <span class="px-2.5 py-0.5 rounded-full text-[10px] font-medium" :class="ispwatchBadge.cls">
                            {{ ispwatchBadge.label }}
                        </span>
                    </div>

                    <!-- Live status -->
                    <div v-if="liveStatus && liveStatus.connected" class="rounded-xl p-4 bg-emerald-50 border border-emerald-100">
                        <div class="flex items-start gap-3">
                            <svg class="w-6 h-6 text-emerald-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-emerald-900">Conectado a "{{ liveStatus.name }}"</p>
                                <p class="text-xs text-emerald-700 mt-0.5">
                                    {{ liveStatus.customers_count }} clientes · {{ liveStatus.invoices_count }} facturas accesibles desde el chat
                                </p>
                                <p class="text-[10px] text-emerald-600/70 mt-1">Verificado: {{ formatTime(liveStatus.checked_at) }}</p>
                            </div>
                            <button @click="refreshIspwatchStatus" :disabled="refreshing" class="px-3 py-1.5 border border-emerald-200 bg-white rounded-lg text-xs text-emerald-700 hover:bg-emerald-50 transition disabled:opacity-50">
                                {{ refreshing ? 'Verificando…' : 'Re-verificar' }}
                            </button>
                        </div>
                    </div>

                    <div v-else-if="liveStatus && !liveStatus.connected" class="rounded-xl p-4 bg-red-50 border border-red-100">
                        <div class="flex items-start gap-3">
                            <svg class="w-6 h-6 text-red-500 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z"/></svg>
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-red-900">Vinculación rota</p>
                                <p class="text-xs text-red-700 mt-0.5">{{ liveStatus.message }} Contacta al administrador para reconfigurar.</p>
                            </div>
                        </div>
                    </div>

                    <div v-else class="rounded-xl p-4 bg-gray-50 border border-gray-100">
                        <p class="text-sm text-gray-700">No hay vinculación con ISPWatch para este workspace.</p>
                        <p class="text-xs text-gray-500 mt-1">Las fichas de cliente en el chat aparecerán vacías hasta que un administrador conecte tu cuenta. Mientras tanto, los contactos se muestran como prospectos.</p>
                    </div>

                </section>

                <!-- ═══════════════ WHATSAPP ═══════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="w-9 h-9 rounded-xl bg-green-50 flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-green-500" viewBox="0 0 24 24" fill="currentColor"><path d="M17.472 14.382c-.297-.149-1.758-.867-2.03-.967-.273-.099-.471-.148-.67.15-.197.297-.767.966-.94 1.164-.173.199-.347.223-.644.075-.297-.15-1.255-.463-2.39-1.475-.883-.788-1.48-1.761-1.653-2.059-.173-.297-.018-.458.13-.606.134-.133.298-.347.446-.52.149-.174.198-.298.298-.497.099-.198.05-.371-.025-.52-.075-.149-.669-1.612-.916-2.207-.242-.579-.487-.5-.669-.51-.173-.008-.371-.01-.57-.01-.198 0-.52.074-.792.372-.272.297-1.04 1.016-1.04 2.479 0 1.462 1.065 2.875 1.213 3.074.149.198 2.096 3.2 5.077 4.487.709.306 1.262.489 1.694.625.712.227 1.36.195 1.871.118.571-.085 1.758-.719 2.006-1.413.248-.694.248-1.289.173-1.413-.074-.124-.272-.198-.57-.347"/></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">WhatsApp Cloud API (Meta)</h3>
                                <p class="text-xs text-gray-500">Credenciales del número Meta de este tenant</p>
                            </div>
                        </div>
                        <span class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full text-[10px] font-medium" :class="waBadge.cls">
                            <span class="w-1.5 h-1.5 rounded-full" :class="waBadge.dot"></span>
                            {{ waBadge.label }}
                        </span>
                    </div>

                    <form @submit.prevent="saveWhatsapp" class="space-y-4">
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Phone Number ID</label>
                                <input v-model="whatsappForm.wa_phone_number_id" type="text" placeholder="998494756683000" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <p v-if="whatsappForm.errors.wa_phone_number_id" class="text-red-500 text-xs mt-1">{{ whatsappForm.errors.wa_phone_number_id }}</p>
                            </div>
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-1">Business Account ID</label>
                                <input v-model="whatsappForm.wa_business_account_id" type="text" placeholder="1234567890" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent">
                            </div>
                        </div>

                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Verify Token (webhook)</label>
                            <input v-model="whatsappForm.wa_verify_token" type="text" placeholder="mi-verify-token-secreto" class="w-full px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent">
                            <p class="text-xs text-gray-400 mt-1">Lo que pongas aquí debe coincidir EXACTO con lo que configures en Meta Business al registrar el webhook.</p>
                        </div>

                        <!-- Access Token -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">Access Token</label>
                            <div v-if="!editingAccessToken && tenant.wa_access_token_set" class="flex gap-2">
                                <input :value="tenant.wa_access_token_broken ? '⚠ valor ilegible — reemplazar' : '••••••••••••••••'" disabled type="text" class="flex-1 px-4 py-2.5 border rounded-xl text-sm" :class="tenant.wa_access_token_broken ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-gray-200 bg-gray-50 text-gray-500'">
                                <button type="button" @click="editingAccessToken = true" class="px-4 py-2.5 border rounded-xl text-sm transition" :class="tenant.wa_access_token_broken ? 'border-amber-300 bg-amber-100 text-amber-800 hover:bg-amber-200 font-medium' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">Reemplazar</button>
                            </div>
                            <div v-else class="flex gap-2">
                                <input v-model="whatsappForm.wa_access_token" type="password" :placeholder="tenant.wa_access_token_set ? 'Nuevo token (deja vacío para no cambiar)' : 'EAAxxxxxxxxxxxx'" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <button v-if="tenant.wa_access_token_set" type="button" @click="cancelAccessTokenEdit" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-700 hover:bg-gray-50 transition">Cancelar</button>
                            </div>
                            <p v-if="tenant.wa_access_token_broken" class="text-xs text-amber-700 mt-1">
                                El valor guardado no se puede descifrar con el APP_KEY actual (probablemente se guardó en texto plano o con otra clave). Reemplázalo aquí y se cifrará correctamente.
                            </p>
                        </div>

                        <!-- App Secret -->
                        <div>
                            <label class="block text-sm font-medium text-gray-700 mb-1">App Secret <span class="text-gray-400 font-normal">(opcional, para firmar webhooks)</span></label>
                            <div v-if="!editingAppSecret && tenant.wa_app_secret_set" class="flex gap-2">
                                <input :value="tenant.wa_app_secret_broken ? '⚠ valor ilegible — reemplazar' : '••••••••••••••••'" disabled type="text" class="flex-1 px-4 py-2.5 border rounded-xl text-sm" :class="tenant.wa_app_secret_broken ? 'border-amber-300 bg-amber-50 text-amber-800' : 'border-gray-200 bg-gray-50 text-gray-500'">
                                <button type="button" @click="editingAppSecret = true" class="px-4 py-2.5 border rounded-xl text-sm transition" :class="tenant.wa_app_secret_broken ? 'border-amber-300 bg-amber-100 text-amber-800 hover:bg-amber-200 font-medium' : 'border-gray-200 text-gray-700 hover:bg-gray-50'">Reemplazar</button>
                            </div>
                            <div v-else class="flex gap-2">
                                <input v-model="whatsappForm.wa_app_secret" type="password" :placeholder="tenant.wa_app_secret_set ? 'Nuevo app secret (deja vacío para no cambiar)' : 'app secret'" class="flex-1 px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-mono focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                <button v-if="tenant.wa_app_secret_set" type="button" @click="cancelAppSecretEdit" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm text-gray-700 hover:bg-gray-50 transition">Cancelar</button>
                            </div>
                            <p v-if="tenant.wa_app_secret_broken" class="text-xs text-amber-700 mt-1">
                                El valor guardado no se puede descifrar. Reemplázalo aquí.
                            </p>
                        </div>

                        <!-- Webhook URL (read-only, copy) -->
                        <div class="rounded-xl bg-gray-50 border border-gray-100 p-3">
                            <p class="text-xs font-semibold text-gray-600 uppercase tracking-wider mb-1">Webhook URL para Meta</p>
                            <p class="text-xs text-gray-500 mb-2">Pega esta URL en <span class="font-mono">Meta Business → WhatsApp → Configuración → Webhooks → Callback URL</span>. Usa el Verify Token de arriba.</p>
                            <div class="flex gap-2">
                                <input :value="workspace.webhook_url" disabled type="text" class="flex-1 px-3 py-2 bg-white border border-gray-200 rounded-lg text-xs font-mono text-gray-700">
                                <button type="button" @click="copy(workspace.webhook_url, 'webhook')" class="px-3 py-2 border border-gray-200 rounded-lg text-xs text-gray-700 hover:bg-white transition">
                                    {{ copiedKey === 'webhook' ? '✓ Copiado' : 'Copiar' }}
                                </button>
                            </div>
                        </div>

                        <!-- Test result -->
                        <div v-if="testResult" class="rounded-xl p-3 text-sm" :class="testResult.ok ? 'bg-emerald-50 border border-emerald-100 text-emerald-900' : 'bg-red-50 border border-red-100 text-red-900'">
                            <template v-if="testResult.ok">
                                <p class="font-semibold">✓ Credenciales válidas</p>
                                <p class="text-xs mt-1">Número {{ testResult.phone }} · Nombre verificado: <span class="font-medium">{{ testResult.verified_name || '—' }}</span><span v-if="testResult.quality_rating"> · Calidad: {{ testResult.quality_rating }}</span></p>
                            </template>
                            <template v-else>
                                <p class="font-semibold">✗ No se pudo verificar</p>
                                <p class="text-xs mt-1">{{ testResult.message }}<span v-if="testResult.code"> (HTTP {{ testResult.code }})</span></p>
                            </template>
                        </div>

                        <div class="flex justify-between items-center pt-2 border-t border-gray-50">
                            <button type="button" @click="testWhatsApp" :disabled="testing || !tenant.wa_phone_number_id || !tenant.wa_access_token_set" class="px-4 py-2.5 border border-gray-200 rounded-xl text-sm font-medium text-gray-700 hover:bg-gray-50 transition disabled:opacity-50 disabled:cursor-not-allowed" :title="!tenant.wa_phone_number_id || !tenant.wa_access_token_set ? 'Guarda Phone Number ID y Access Token primero' : ''">
                                {{ testing ? 'Probando…' : 'Probar conexión a Meta' }}
                            </button>
                            <button type="submit" :disabled="whatsappForm.processing" class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                {{ whatsappForm.processing ? 'Guardando…' : 'Guardar credenciales' }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- ═══════════════ AVISOS AUTOMÁTICOS ═══════════════ -->
                <section class="bg-white rounded-2xl p-6 shadow-sm border border-gray-100">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="w-9 h-9 rounded-xl bg-amber-50 flex items-center justify-center mr-3">
                                <svg class="w-5 h-5 text-amber-500" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0"/></svg>
                            </div>
                            <div>
                                <h3 class="text-base font-semibold text-gray-900">Avisos automáticos</h3>
                                <p class="text-xs text-gray-500">Por cada evento, elige qué plantilla enviar y actívalo. Las fechas las define el router en ISPWatch.</p>
                            </div>
                        </div>
                    </div>

                    <div v-if="!approvedTemplates.length" class="rounded-xl bg-amber-50 border border-amber-100 p-4 text-sm text-amber-800">
                        No tienes plantillas aprobadas y activas todavía. Créalas y sincronízalas en <Link :href="route('templates.index')" class="font-medium underline">Plantillas</Link> para poder asignarlas a un aviso.
                    </div>

                    <form v-else @submit.prevent="saveNotifications" class="space-y-3">
                        <div v-for="(row, i) in routesForm.routes" :key="row.event_key"
                             class="rounded-xl border border-gray-100 p-4 flex flex-col md:flex-row md:items-center gap-3">
                            <div class="flex-1 min-w-0">
                                <p class="text-sm font-semibold text-gray-900">{{ notificationEvents[i]?.label }}</p>
                                <p class="text-xs text-gray-500 mt-0.5">{{ notificationEvents[i]?.description }}</p>
                            </div>
                            <div class="md:w-64">
                                <select v-model="row.template_id"
                                        class="w-full px-3 py-2.5 border border-gray-200 rounded-xl text-sm bg-white focus:ring-2 focus:ring-accent/30 focus:border-accent">
                                    <option value="">— Sin plantilla —</option>
                                    <option v-for="t in templatesForEvent(row.event_key)" :key="t.id" :value="t.id">{{ t.name }}</option>
                                </select>
                                <p v-if="!templatesForEvent(row.event_key).length" class="text-[10px] text-amber-700 mt-1">
                                    Ninguna plantilla con este propósito. Tagea una en Plantillas.
                                </p>
                            </div>
                            <label class="inline-flex items-center gap-2 cursor-pointer shrink-0"
                                   :class="{ 'opacity-40 cursor-not-allowed': !row.template_id }">
                                <input type="checkbox" v-model="row.enabled" :disabled="!row.template_id"
                                       class="rounded border-gray-300 text-accent focus:ring-accent/30">
                                <span class="text-sm text-gray-700">Activo</span>
                            </label>
                        </div>

                        <div class="flex justify-end pt-2 border-t border-gray-50">
                            <button type="submit" :disabled="routesForm.processing"
                                    class="px-5 py-2.5 bg-accent text-white rounded-xl text-sm font-medium hover:bg-accent-hover transition disabled:opacity-50">
                                {{ routesForm.processing ? 'Guardando…' : 'Guardar avisos' }}
                            </button>
                        </div>
                    </form>
                </section>

                <!-- ═══════════════ WORKSPACE INFO ═══════════════ -->
                <section class="bg-gradient-to-br from-sidebar to-sidebar-hover rounded-2xl p-6 text-white">
                    <div class="flex items-center justify-between mb-4">
                        <div class="flex items-center">
                            <div class="w-9 h-9 rounded-xl bg-white/10 flex items-center justify-center mr-3">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.25 11.25l.041-.02a.75.75 0 011.063.852l-.708 2.836a.75.75 0 001.063.853l.041-.021M21 12a9 9 0 11-18 0 9 9 0 0118 0zm-9-3.75h.008v.008H12V8.25z"/></svg>
                            </div>
                            <h3 class="text-base font-semibold">Información del workspace</h3>
                        </div>
                        <span v-if="!workspace.is_production" class="inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded-full bg-amber-500/20 text-amber-200 text-[10px] font-medium">
                            <span class="w-1.5 h-1.5 rounded-full bg-amber-400"></span>
                            Modo desarrollo
                        </span>
                    </div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between items-center">
                            <dt class="text-white/60">Aplicación</dt>
                            <dd class="font-medium">{{ workspace.app_name }}</dd>
                        </div>
                        <div class="flex justify-between items-center">
                            <dt class="text-white/60">URL pública</dt>
                            <dd class="font-mono text-xs">{{ workspace.public_url }}</dd>
                        </div>
                        <div class="flex justify-between items-center">
                            <dt class="text-white/60">Creado</dt>
                            <dd>{{ formatDate(tenant.created_at) }}</dd>
                        </div>
                    </dl>
                    <p class="text-xs text-white/50 mt-4 pt-4 border-t border-white/10">
                        Esta URL es la misma para todos los clientes de Converza. Lo que diferencia tu cuenta es el tenant y sus credenciales de WhatsApp.
                        <template v-if="!workspace.is_production">
                            <br><span class="text-amber-200/80">Aclaración:</span> estás en modo desarrollo. La URL pública de arriba es la real (la que ven los clientes); tu instancia local corre aparte en localhost.
                        </template>
                    </p>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
