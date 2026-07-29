<script setup>
import AppLayout from '@/Layouts/AppLayout.vue';
import { Link, router, useForm } from '@inertiajs/vue3';
import { ref, computed } from 'vue';

const props = defineProps({
    account:        Object,
    converza_live:  Object,
    ispwatch_live:  Object,
    internal_users: Array,
    invoices:       Array,
    payments:       { type: Array, default: () => [] },
    balance:        { type: Object, default: () => ({}) },
    tickets:        Array,
    notes:          Array,
    tenants:        { type: Array, default: () => [] },
    plan_catalog:   { type: Object, default: () => ({ base_currency: 'USD', ispwatch: [], converza: [], combo: [] }) },
    plan_usage:     { type: Object, default: () => ({ clients: null, agents: null }) },
});

const baseCurrency = props.plan_catalog.base_currency ?? 'USD';

// ── Tabs ───────────────────────────────────────────────────────────────────
const activeTab = ref('summary');
const tabs = [
    { id: 'summary',  label: 'Resumen' },
    { id: 'products', label: 'Productos' },
    { id: 'billing',  label: 'Cobros' },
    { id: 'support',  label: 'Soporte' },
    { id: 'whatsapp', label: 'WhatsApp', soon: true },
];

// ── UI helpers ─────────────────────────────────────────────────────────────
const statusLabel  = { prospect:'Prospecto', active:'Activo', past_due:'En mora', suspended:'Suspendido', churned:'Cancelado' };
const statusColor  = { prospect:'bg-gray-100 text-gray-700', active:'bg-green-100 text-green-700', past_due:'bg-red-100 text-red-700', suspended:'bg-yellow-100 text-yellow-700', churned:'bg-gray-200 text-gray-500' };
const healthLabel  = { good:'Buena', at_risk:'En riesgo', critical:'Crítica' };
const healthColor  = { good:'bg-green-100 text-green-700', at_risk:'bg-yellow-100 text-yellow-700', critical:'bg-red-100 text-red-700' };
const productLabel = { ispwatch:'ispwatch', converza:'Converza' };
const cycleLabel   = { monthly:'Mensual', quarterly:'Trimestral', yearly:'Anual' };
const invStatusLabel = { draft:'Borrador', sent:'Enviada', paid:'Pagada', partial:'Pago parcial', overdue:'Vencida', void:'Anulada' };
const invStatusColor = { draft:'bg-gray-100 text-gray-600', sent:'bg-blue-100 text-blue-700', paid:'bg-green-100 text-green-700', partial:'bg-yellow-100 text-yellow-700', overdue:'bg-red-100 text-red-700', void:'bg-gray-200 text-gray-400' };
const ticketStatusLabel = { open:'Abierto', pending:'Pendiente', resolved:'Resuelto', closed:'Cerrado' };
const ticketStatusColor = { open:'bg-blue-100 text-blue-700', pending:'bg-yellow-100 text-yellow-700', resolved:'bg-green-100 text-green-700', closed:'bg-gray-100 text-gray-500' };
const priorityLabel = { low:'Baja', normal:'Normal', high:'Alta', urgent:'Urgente' };
const priorityColor = { low:'text-gray-500', normal:'text-blue-600', high:'text-orange-500', urgent:'text-red-600 font-semibold' };

function formatAmount(amount, currency) {
    return new Intl.NumberFormat('es-CO', { style: 'currency', currency, minimumFractionDigits: 0 }).format(Number(amount));
}
function fmtDate(d) { return d ? new Date(d).toLocaleDateString('es-CO') : '—'; }

// ── Billing summary ────────────────────────────────────────────────────────
const billingStats = computed(() => {
    const active = props.invoices.filter(i => i.status !== 'void');
    const pending = active.filter(i => !['paid'].includes(i.status));
    const totalByCurrency = {};
    const pendingByCurrency = {};
    for (const inv of active) {
        totalByCurrency[inv.currency] = (totalByCurrency[inv.currency] ?? 0) + Number(inv.total);
    }
    for (const inv of pending) {
        pendingByCurrency[inv.currency] = (pendingByCurrency[inv.currency] ?? 0) + Number(inv.balance_due);
    }
    return { totalByCurrency, pendingByCurrency, overdueCount: active.filter(i => i.status === 'overdue').length };
});

// Saldo de la cuenta: positivo = saldo a favor (pagó de más), negativo = debe.
// Lo calcula el backend (Account::balanceByCurrency) para que no se desincronice.
const creditByCurrency = computed(() =>
    Object.fromEntries(Object.entries(props.balance).filter(([, v]) => Number(v) > 0))
);
const debtByCurrency = computed(() =>
    Object.fromEntries(Object.entries(props.balance).filter(([, v]) => Number(v) < 0).map(([c, v]) => [c, Math.abs(Number(v))]))
);
const hasCredit = computed(() => Object.keys(creditByCurrency.value).length > 0);
function creditIn(currency) { return Number(props.balance?.[currency] ?? 0) > 0 ? Number(props.balance[currency]) : 0; }

const methodLabel = { transfer:'Transferencia', cash:'Efectivo', card:'Tarjeta', other:'Otro' };

// ── Edit account ───────────────────────────────────────────────────────────
const showEdit = ref(false);
const editForm = useForm({
    name: props.account.name, legal_name: props.account.legal_name ?? '',
    ispwatch_tenant_id: props.account.ispwatch_tenant_id ?? '', tenant_id: props.account.tenant_id ?? '',
    status: props.account.status, health: props.account.health ?? '',
    owner_user_id: props.account.owner?.id ?? '', contact_name: props.account.contact_name ?? '',
    contact_email: props.account.contact_email ?? '', contact_phone: props.account.contact_phone ?? '',
    country: props.account.country ?? 'CO', onboarding_at: props.account.onboarding_at ?? '',
    renewal_at: props.account.renewal_at ?? '', billing_day: props.account.billing_day ?? '',
    notes: props.account.notes ?? '',
});
function submitEdit() { editForm.put(route('brain.accounts.update', props.account.id), { onSuccess: () => { showEdit.value = false; } }); }

// ── Corte / reactivación manual del servicio ───────────────────────────────
// Nada se corta solo: el founder decide. Reusa editForm (ya tiene todos los
// campos requeridos) y solo cambia el estado de la cuenta.
function setAccountStatus(newStatus, confirmMsg) {
    if (confirmMsg && !confirm(confirmMsg)) return;
    editForm.status = newStatus;
    editForm.put(route('brain.accounts.update', props.account.id), { preserveScroll: true });
}
function cutService()      { setAccountStatus('suspended', `¿Suspender el servicio de "${props.account.name}"? Podrás reactivarlo cuando pague.`); }
function reactivateService() { setAccountStatus('active'); }

// Semáforo de vencimiento de un producto según su fecha "vence el" (renews_at).
function expiryInfo(dateStr) {
    if (!dateStr) return null;
    const today = new Date(); today.setHours(0, 0, 0, 0);
    const due = new Date(dateStr + 'T00:00:00');
    const days = Math.round((due - today) / 86400000);
    if (days < 0)  return { tone: 'text-red-600 bg-red-50 border-red-200',       label: `Vencido hace ${-days} d` };
    if (days <= 7) return { tone: 'text-amber-600 bg-amber-50 border-amber-200', label: `Vence en ${days} d` };
    return null;
}

// ── Uso vs tope del plan ───────────────────────────────────────────────────
// Semáforo informativo: el tope de clientes NO se puede bloquear desde acá (la BD de
// ispwatch se lee read-only), sirve para ver a tiempo cuándo toca ofrecer el upgrade.
const usageStyle = {
    ok:        { bar:'bg-green-500',  text:'text-green-700',  chip:'bg-green-50 text-green-700 border-green-200',    label:'En rango' },
    warn:      { bar:'bg-yellow-500', text:'text-yellow-700', chip:'bg-yellow-50 text-yellow-700 border-yellow-200', label:'Cerca del tope' },
    high:      { bar:'bg-orange-500', text:'text-orange-700', chip:'bg-orange-50 text-orange-700 border-orange-200', label:'Al límite' },
    over:      { bar:'bg-red-500',    text:'text-red-700',    chip:'bg-red-50 text-red-700 border-red-200',          label:'Excedido' },
    unlimited: { bar:'bg-gray-300',   text:'text-gray-500',   chip:'bg-gray-50 text-gray-500 border-gray-200',       label:'Ilimitado' },
};
const usageRows = computed(() => [
    { key:'clients', title:'Clientes en ispwatch', unit:'clientes', u:props.plan_usage?.clients },
    { key:'agents',  title:'Agentes de Converza',  unit:'agentes',  u:props.plan_usage?.agents  },
].filter(r => r.u));
const hasPlanUsage = computed(() => usageRows.value.length > 0);
// Ancho de la barra: se topa en 100% para que un excedido no se salga de la caja.
function usageWidth(u) { return u.state === 'unlimited' ? 100 : Math.min(100, u.pct ?? 0); }
function usageLeft(u) { return u.limit === null ? null : u.limit - u.used; }

// ── Products ───────────────────────────────────────────────────────────────
const showProductModal = ref(false);
const editingProduct   = ref(null);
const productForm = useForm({ product:'', plan_key:'', plan:'', status:'active', billing_cycle:'monthly', amount:'', currency:'COP', started_at:'', renews_at:'', cancelled_at:'' });
function openAddProduct() { editingProduct.value = null; productForm.reset(); productForm.currency='COP'; productForm.status='active'; productForm.billing_cycle='monthly'; showProductModal.value=true; }
function openEditProduct(p) { editingProduct.value=p; Object.assign(productForm, { product:p.product, plan_key:p.plan_key??'', plan:p.plan??'', status:p.status, billing_cycle:p.billing_cycle, amount:p.amount, currency:p.currency, started_at:p.started_at??'', renews_at:p.renews_at??'', cancelled_at:p.cancelled_at??'' }); showProductModal.value=true; }

// Planes del catálogo aplicables a este producto: los de su familia + combos.
const productPlanOptions = computed(() => {
    const fam = productForm.product ? (props.plan_catalog[productForm.product] ?? []) : [];
    return [...fam, ...(props.plan_catalog.combo ?? [])];
});
function findCatalogPlan(key) {
    for (const fam of ['ispwatch', 'converza', 'combo']) {
        const hit = (props.plan_catalog[fam] ?? []).find(p => p.key === key);
        if (hit) return hit;
    }
    return null;
}
// Al elegir un plan del catálogo: fija etiqueta y pre-rellena precio base + moneda.
function applyCatalogPlan() {
    const p = findCatalogPlan(productForm.plan_key);
    if (!p) return;
    productForm.plan = p.name;
    productForm.currency = baseCurrency;
    if (p.price != null) productForm.amount = String(p.price);
}
function submitProduct() {
    const opts = { onSuccess: () => { showProductModal.value=false; } };
    editingProduct.value
        ? productForm.put(route('brain.accounts.products.update', [props.account.id, editingProduct.value.id]), opts)
        : productForm.post(route('brain.accounts.products.store', props.account.id), opts);
}
function deleteProduct(p) { if (!confirm(`¿Eliminar ${productLabel[p.product]}?`)) return; router.delete(route('brain.accounts.products.destroy', [props.account.id, p.id])); }

// ── Invoices ───────────────────────────────────────────────────────────────
const showInvoiceModal = ref(false);
const editingInvoice   = ref(null);
const invoiceForm = useForm({ account_product_id:'', number:'', concept:'', amount:'', tax:'0', currency:'COP', issued_at:'', due_at:'', period_start:'', period_end:'', apply_credit:true });

// Mismo día del mes N meses después, recortado si el mes destino no lo tiene
// (31 de enero + 1 mes = 28/29 de febrero, no el 2 o 3 de marzo).
function shiftMonth(iso, months) {
    const d = new Date(iso + 'T00:00:00');
    const lastDayOfTarget = new Date(d.getFullYear(), d.getMonth() + months + 1, 0).getDate();
    return new Date(d.getFullYear(), d.getMonth() + months, Math.min(d.getDate(), lastDayOfTarget));
}
function toIso(d) { return `${d.getFullYear()}-${String(d.getMonth()+1).padStart(2,'0')}-${String(d.getDate()).padStart(2,'0')}`; }

function openAddInvoice() {
    editingInvoice.value = null;
    invoiceForm.reset();
    invoiceForm.tax = '0';
    invoiceForm.apply_credit = true;

    // Arranca del producto activo: su moneda y su valor son lo que se cobra.
    const products = props.account.products ?? [];
    const main = products.find(p => p.status === 'active' && Number(p.amount) > 0) ?? products[0];
    invoiceForm.currency = main?.currency ?? 'COP';
    if (main) { invoiceForm.account_product_id = main.id; invoiceForm.amount = main.amount; }

    // Si la cuenta tiene día de facturación propio, las fechas salen de ahí.
    const start = props.account.next_billing_at ?? new Date().toISOString().split('T')[0];
    invoiceForm.issued_at    = start;
    invoiceForm.due_at       = start;
    invoiceForm.period_start = start;
    const end = shiftMonth(start, 1); end.setDate(end.getDate() - 1);
    invoiceForm.period_end = toIso(end);

    showInvoiceModal.value = true;
}
function openEditInvoice(inv) { editingInvoice.value=inv; Object.assign(invoiceForm, { account_product_id:inv.account_product_id??'', number:inv.number, concept:inv.concept, amount:inv.amount, tax:inv.tax, currency:inv.currency, issued_at:inv.issued_at??'', due_at:inv.due_at??'', period_start:inv.period_start??'', period_end:inv.period_end??'' }); showInvoiceModal.value=true; }
function submitInvoice() {
    const opts = { onSuccess: () => { showInvoiceModal.value=false; } };
    editingInvoice.value
        ? invoiceForm.put(route('brain.accounts.invoices.update', [props.account.id, editingInvoice.value.id]), opts)
        : invoiceForm.post(route('brain.accounts.invoices.store', props.account.id), opts);
}
function voidInvoice(inv) { if (!confirm('¿Anular esta factura?')) return; router.patch(route('brain.accounts.invoices.void', [props.account.id, inv.id])); }

// ── Payments ───────────────────────────────────────────────────────────────
const showPaymentModal = ref(false);
const payingInvoice    = ref(null);
const paymentForm = useForm({ account_invoice_id:'', amount:'', currency:'COP', method:'transfer', reference:'', paid_at:'' });
function openPayment(inv) { payingInvoice.value=inv; paymentForm.reset(); paymentForm.currency=inv?.currency??'COP'; paymentForm.method='transfer'; paymentForm.paid_at=new Date().toISOString().split('T')[0]; if(inv) { paymentForm.account_invoice_id=inv.id; paymentForm.amount=inv.balance_due; } showPaymentModal.value=true; }
function submitPayment() { paymentForm.post(route('brain.accounts.payments.store', props.account.id), { onSuccess: () => { showPaymentModal.value=false; } }); }
function deletePayment(p) { if (!confirm('¿Eliminar este pago?')) return; router.delete(route('brain.accounts.payments.destroy', [props.account.id, p.id])); }

// ── Tickets ────────────────────────────────────────────────────────────────
const showTicketModal  = ref(false);
const editingTicket    = ref(null);
const openTicket       = ref(null);
const ticketForm = useForm({ subject:'', priority:'normal', category:'', product:'', source:'manual', assigned_to:'', body:'' });
const ticketUpdateForm = useForm({ subject:'', status:'', priority:'', category:'', product:'', assigned_to:'' });
const activeTicketForm = computed(() => editingTicket.value ? ticketUpdateForm : ticketForm);
const eventForm = useForm({ type:'message', body:'' });

function openAddTicket() { editingTicket.value=null; ticketForm.reset(); ticketForm.priority='normal'; ticketForm.source='manual'; showTicketModal.value=true; }
function openEditTicket(t) { editingTicket.value=t; Object.assign(ticketUpdateForm, { subject:t.subject, status:t.status, priority:t.priority, category:t.category??'', product:t.product??'', assigned_to:t.assigned_to?.id??'' }); showTicketModal.value=true; }
function submitTicket() {
    const opts = { onSuccess: () => { showTicketModal.value=false; } };
    editingTicket.value
        ? ticketUpdateForm.put(route('brain.accounts.tickets.update', [props.account.id, editingTicket.value.id]), opts)
        : ticketForm.post(route('brain.accounts.tickets.store', props.account.id), opts);
}
function deleteTicket(t) { if (!confirm('¿Eliminar ticket?')) return; router.delete(route('brain.accounts.tickets.destroy', [props.account.id, t.id])); }
function submitEvent(ticket) { eventForm.post(route('brain.accounts.tickets.events.store', [props.account.id, ticket.id]), { onSuccess: () => { eventForm.reset(); eventForm.type='message'; } }); }

// ── Notes ──────────────────────────────────────────────────────────────────
const noteBody    = ref('');
const editNoteId  = ref(null);
const editNoteBody = ref('');
const noteForm = useForm({ body:'', pinned:false });
function submitNote() { noteForm.body=noteBody.value; noteForm.post(route('brain.accounts.notes.store', props.account.id), { onSuccess: () => { noteBody.value=''; } }); }
function startEditNote(n) { editNoteId.value=n.id; editNoteBody.value=n.body; }
function saveNote(n) { router.put(route('brain.accounts.notes.update', [props.account.id, n.id]), { body:editNoteBody.value, pinned:n.pinned }, { onSuccess: () => { editNoteId.value=null; } }); }
function deleteNote(n) { if (!confirm('¿Eliminar nota?')) return; router.delete(route('brain.accounts.notes.destroy', [props.account.id, n.id])); }
function togglePin(n) { router.patch(route('brain.accounts.notes.pin', [props.account.id, n.id])); }

// ── Delete account ─────────────────────────────────────────────────────────
function deleteAccount() { if (!confirm(`¿Eliminar "${props.account.name}"?`)) return; router.delete(route('brain.accounts.destroy', props.account.id)); }
</script>

<template>
    <AppLayout>
        <div class="p-6 max-w-6xl mx-auto space-y-6">

            <!-- Breadcrumb -->
            <div class="flex items-center gap-2 text-sm text-gray-500">
                <Link :href="route('brain.accounts.index')" class="hover:text-amber-700 transition">Cuentas</Link>
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8.25 4.5l7.5 7.5-7.5 7.5" /></svg>
                <span class="text-gray-900 font-medium">{{ account.name }}</span>
            </div>

            <!-- Cabecera -->
            <div class="bg-white rounded-2xl border border-gray-200 p-6">
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="flex-1 min-w-0">
                        <div class="flex items-center gap-3 flex-wrap">
                            <h1 class="text-2xl font-bold text-gray-900">{{ account.name }}</h1>
                            <span class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="statusColor[account.status]">{{ statusLabel[account.status] }}</span>
                            <span v-if="account.health" class="inline-flex items-center px-2.5 py-0.5 rounded-full text-xs font-medium" :class="healthColor[account.health]">{{ healthLabel[account.health] }}</span>
                        </div>
                        <p v-if="account.legal_name" class="text-sm text-gray-500 mt-1">{{ account.legal_name }}</p>
                        <div class="flex flex-wrap items-center gap-4 mt-3 text-sm text-gray-600">
                            <span v-if="account.contact_name" class="flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 6a3.75 3.75 0 11-7.5 0 3.75 3.75 0 017.5 0zM4.501 20.118a7.5 7.5 0 0114.998 0A17.933 17.933 0 0112 21.75c-2.676 0-5.216-.584-7.499-1.632z" /></svg>
                                {{ account.contact_name }}
                            </span>
                            <a v-if="account.contact_email" :href="`mailto:${account.contact_email}`" class="flex items-center gap-1.5 hover:text-amber-700">
                                <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" /></svg>
                                {{ account.contact_email }}
                            </a>
                            <span v-if="account.contact_phone" class="flex items-center gap-1.5">
                                <svg class="w-4 h-4 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M2.25 6.75c0 8.284 6.716 15 15 15h2.25a2.25 2.25 0 002.25-2.25v-1.372c0-.516-.351-.966-.852-1.091l-4.423-1.106c-.44-.11-.902.055-1.173.417l-.97 1.293c-.282.376-.769.542-1.21.38a12.035 12.035 0 01-7.143-7.143c-.162-.441.004-.928.38-1.21l1.293-.97c.363-.271.527-.734.417-1.173L6.963 3.102a1.125 1.125 0 00-1.091-.852H4.5A2.25 2.25 0 002.25 4.5v2.25z" /></svg>
                                {{ account.contact_phone }}
                            </span>
                            <span v-if="account.renewal_at" class="flex items-center gap-1.5 text-amber-600">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6v6h4.5m4.5 0a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                                Renueva {{ account.renewal_at }}
                            </span>
                            <span v-if="account.owner" class="flex items-center gap-1.5 text-amber-700">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M17.982 18.725A7.488 7.488 0 0012 15.75a7.488 7.488 0 00-5.982 2.975m11.963 0a9 9 0 10-11.963 0m11.963 0A8.966 8.966 0 0112 21a8.966 8.966 0 01-5.982-2.275M15 9.75a3 3 0 11-6 0 3 3 0 016 0z" /></svg>
                                {{ account.owner.name }}
                            </span>
                        </div>
                        <p v-if="account.notes" class="mt-3 text-sm text-gray-600 bg-gray-50 rounded-lg px-4 py-2.5 border border-gray-100">{{ account.notes }}</p>
                    </div>
                    <div class="flex items-center gap-2 shrink-0">
                        <button v-if="['suspended','past_due'].includes(account.status)" @click="reactivateService" :disabled="editForm.processing"
                            class="px-3 py-2 text-sm text-green-700 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition flex items-center gap-1.5 disabled:opacity-60">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5.25 5.653c0-.856.917-1.398 1.667-.986l11.54 6.348a1.125 1.125 0 010 1.971l-11.54 6.347a1.125 1.125 0 01-1.667-.985V5.653z" /></svg>
                            Reactivar
                        </button>
                        <button v-else-if="account.status === 'active'" @click="cutService" :disabled="editForm.processing"
                            class="px-3 py-2 text-sm text-orange-700 bg-orange-50 border border-orange-200 rounded-lg hover:bg-orange-100 transition flex items-center gap-1.5 disabled:opacity-60">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M15.75 5.25a3 3 0 013 3m3 0a6 6 0 01-7.029 5.912c-.563-.097-1.159.026-1.563.43L10.5 17.25H8.25v2.25H6v2.25H2.25v-2.818c0-.597.237-1.17.659-1.591l6.499-6.499c.404-.404.527-1 .43-1.563A6 6 0 1121.75 8.25z" /></svg>
                            Cortar servicio
                        </button>
                        <button @click="showEdit = true" class="px-3 py-2 text-sm text-gray-700 bg-white border border-gray-300 rounded-lg hover:bg-gray-50 transition flex items-center gap-1.5">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg>
                            Editar
                        </button>
                        <button @click="deleteAccount" class="px-3 py-2 text-sm text-red-600 bg-white border border-red-200 rounded-lg hover:bg-red-50 transition">Eliminar</button>
                    </div>
                </div>
            </div>

            <!-- Tabs -->
            <div class="border-b border-gray-200">
                <nav class="flex gap-1 -mb-px">
                    <button v-for="tab in tabs" :key="tab.id" @click="!tab.soon && (activeTab = tab.id)"
                        class="px-4 py-2.5 text-sm font-medium border-b-2 transition whitespace-nowrap"
                        :class="[tab.soon ? 'text-gray-300 cursor-not-allowed border-transparent' : activeTab === tab.id ? 'text-amber-700 border-amber-500' : 'text-gray-600 border-transparent hover:text-gray-900 hover:border-gray-300']">
                        {{ tab.label }}
                        <span v-if="tab.soon" class="ml-1 text-[10px] bg-gray-100 text-gray-400 px-1 rounded">pronto</span>
                        <span v-else-if="tab.id === 'billing' && billingStats.overdueCount > 0" class="ml-1.5 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold bg-red-500 text-white rounded-full">{{ billingStats.overdueCount }}</span>
                        <span v-else-if="tab.id === 'support' && tickets.filter(t=>t.status==='open').length > 0" class="ml-1.5 inline-flex items-center justify-center w-4 h-4 text-[10px] font-bold bg-blue-500 text-white rounded-full">{{ tickets.filter(t=>t.status==='open').length }}</span>
                    </button>
                </nav>
            </div>

            <!-- ── Tab: Resumen ────────────────────────────────────────────── -->
            <div v-show="activeTab === 'summary'" class="grid grid-cols-1 lg:grid-cols-2 gap-6">
                <!-- Uso vs tope del plan -->
                <div v-if="hasPlanUsage" class="lg:col-span-2 bg-white rounded-xl border border-gray-200 p-5 space-y-4">
                    <div class="flex items-center gap-2">
                        <h3 class="text-sm font-semibold text-gray-900">Uso del plan</h3>
                        <span class="text-xs text-gray-400">— informativo, no bloquea altas en ispwatch</span>
                    </div>
                    <div class="grid grid-cols-1 sm:grid-cols-2 gap-5">
                        <div v-for="row in usageRows" :key="row.key" class="space-y-2">
                            <div class="flex items-baseline justify-between gap-2">
                                <span class="text-xs font-medium text-gray-600">{{ row.title }}</span>
                                <span class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full border" :class="usageStyle[row.u.state].chip">
                                    {{ usageStyle[row.u.state].label }}
                                </span>
                            </div>
                            <div class="flex items-baseline gap-1.5">
                                <span class="text-2xl font-bold text-gray-900">{{ row.u.used }}</span>
                                <span class="text-sm text-gray-400">/ {{ row.u.limit ?? '∞' }}</span>
                                <span v-if="row.u.pct !== null" class="ml-auto text-xs font-semibold" :class="usageStyle[row.u.state].text">{{ row.u.pct }}%</span>
                            </div>
                            <div class="h-2 w-full bg-gray-100 rounded-full overflow-hidden">
                                <div class="h-full rounded-full transition-all" :class="usageStyle[row.u.state].bar" :style="{ width: usageWidth(row.u) + '%' }"></div>
                            </div>
                            <p class="text-[11px] text-gray-500">
                                {{ row.u.plan_name }}<template v-if="row.u.limit !== null"> ·
                                    <span v-if="usageLeft(row.u) > 0">quedan <strong>{{ usageLeft(row.u) }}</strong> {{ row.unit }}</span>
                                    <span v-else class="text-red-600 font-semibold">excedido en {{ -usageLeft(row.u) }} {{ row.unit }} — toca upgrade</span>
                                </template>
                            </p>
                        </div>
                    </div>
                </div>

                <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-900">Datos de la cuenta</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">País</dt><dd class="font-medium">{{ account.country ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Inicio</dt><dd class="font-medium">{{ account.onboarding_at ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Renovación</dt><dd class="font-medium">{{ account.renewal_at ?? '—' }}</dd></div>
                        <div class="flex justify-between">
                            <dt class="text-gray-500">Día de facturación</dt>
                            <dd v-if="account.billing_day" class="font-medium text-right">
                                <span class="inline-flex items-center gap-1 text-amber-700 bg-amber-50 border border-amber-200 px-1.5 py-0.5 rounded text-xs">día {{ account.billing_day }}</span>
                                <span class="block text-[11px] text-gray-400 mt-0.5">próxima: {{ fmtDate(account.next_billing_at) }}</span>
                            </dd>
                            <dd v-else class="font-medium text-gray-400">Sin excepción</dd>
                        </div>
                        <div class="flex justify-between"><dt class="text-gray-500">ispwatch ID</dt><dd class="font-mono text-xs font-medium">{{ account.ispwatch_tenant_id ?? '—' }}</dd></div>
                        <div class="flex justify-between items-center">
                            <dt class="text-gray-500">Tenant Converza</dt>
                            <dd v-if="account.tenant" class="text-xs font-medium">
                                <Link :href="route('admin.tenants.index')" class="text-amber-700 hover:underline">{{ account.tenant.name }}</Link>
                                <span class="text-gray-400 font-mono ml-1">/{{ account.tenant.slug }}</span>
                            </dd>
                            <dd v-else class="font-mono text-xs font-medium text-gray-400">Sin vincular</dd>
                        </div>
                    </dl>
                </div>
                <div v-if="converza_live" class="bg-white rounded-xl border border-amber-200 p-5 space-y-4">
                    <div class="flex items-center gap-2">
                        <span class="w-2 h-2 rounded-full" :class="converza_live.wa_status === 'connected' ? 'bg-green-400' : 'bg-red-400'"></span>
                        <h3 class="text-sm font-semibold text-gray-900">Converza — en vivo</h3>
                        <span class="ml-auto text-xs text-amber-700 font-medium bg-amber-50 px-2 py-0.5 rounded-full">{{ converza_live.wa_status }}</span>
                    </div>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Chats abiertos</dt><dd class="font-semibold text-amber-700">{{ converza_live.conversations_open }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Contactos</dt><dd class="font-medium">{{ converza_live.contacts_total }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Agentes</dt><dd class="font-medium">{{ converza_live.staff_count }}</dd></div>
                    </dl>
                </div>
                <div v-else class="bg-gray-50 rounded-xl border border-dashed border-gray-200 p-5 flex items-center justify-center text-sm text-gray-400">
                    {{ account.tenant_id ? 'Converza: sin datos' : 'Sin workspace de Converza vinculado' }}
                </div>
                <div v-if="ispwatch_live" class="bg-white rounded-xl border border-blue-200 p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-900">ispwatch — en vivo</h3>
                    <dl class="space-y-2 text-sm">
                        <div class="flex justify-between"><dt class="text-gray-500">Clientes activos</dt><dd class="font-semibold text-blue-700">{{ ispwatch_live.active_customers ?? ispwatch_live.customers_count ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Suspendidos</dt><dd class="font-medium text-yellow-600">{{ ispwatch_live.suspended_customers ?? '—' }}</dd></div>
                        <div class="flex justify-between"><dt class="text-gray-500">Facturas vencidas</dt><dd class="font-medium" :class="(ispwatch_live.overdue_invoices ?? 0) > 0 ? 'text-red-600' : 'text-gray-700'">{{ ispwatch_live.overdue_invoices ?? '—' }}</dd></div>
                    </dl>
                </div>
                <div v-else class="bg-gray-50 rounded-xl border border-dashed border-gray-200 p-5 flex items-center justify-center text-sm text-gray-400">
                    {{ account.ispwatch_tenant_id ? 'ispwatch: sin datos' : 'Sin tenant de ispwatch vinculado' }}
                </div>
            </div>

            <!-- ── Tab: Productos ──────────────────────────────────────────── -->
            <div v-show="activeTab === 'products'" class="space-y-4">
                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">Productos contratados</h3>
                    <button @click="openAddProduct" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                        Agregar
                    </button>
                </div>
                <div v-if="account.products.length === 0" class="py-12 text-center text-gray-400 text-sm bg-white rounded-xl border border-dashed border-gray-200">
                    Sin productos. <button @click="openAddProduct" class="text-amber-600 hover:underline">Agregar el primero</button>
                </div>
                <div v-for="p in account.products" :key="p.id" class="bg-white rounded-xl border border-gray-200 p-5 flex items-start justify-between gap-4">
                    <div class="flex-1 space-y-1">
                        <div class="flex items-center gap-2 flex-wrap">
                            <span class="font-semibold text-gray-900">{{ productLabel[p.product] }}</span>
                            <span v-if="p.plan" class="text-xs text-gray-500 bg-gray-100 px-2 py-0.5 rounded-full">{{ p.plan }}</span>
                            <span v-if="p.plan_key && p.plan_key.startsWith('combo_')" class="text-[10px] font-semibold text-amber-700 bg-amber-100 px-1.5 py-0.5 rounded">COMBO</span>
                            <span class="text-xs px-2 py-0.5 rounded-full font-medium" :class="p.status==='active'?'bg-green-100 text-green-700':p.status==='paused'?'bg-yellow-100 text-yellow-700':'bg-gray-100 text-gray-500'">
                                {{ p.status==='active'?'Activo':p.status==='paused'?'Pausado':'Cancelado' }}
                            </span>
                            <span v-if="expiryInfo(p.renews_at)" class="text-[11px] px-2 py-0.5 rounded-full border font-medium" :class="expiryInfo(p.renews_at).tone">{{ expiryInfo(p.renews_at).label }}</span>
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm">
                            <span class="text-xl font-bold text-gray-900">{{ formatAmount(p.amount, p.currency) }}<span class="text-xs text-gray-400 ml-1 font-normal">/ {{ cycleLabel[p.billing_cycle].toLowerCase() }}</span></span>
                            <span v-if="p.renews_at" class="text-xs text-amber-600 self-end">Vence {{ p.renews_at }}</span>
                        </div>
                    </div>
                    <div class="flex items-center gap-1 shrink-0">
                        <button @click="openEditProduct(p)" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                        <button @click="deleteProduct(p)" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                    </div>
                </div>
            </div>

            <!-- ── Tab: Cobros ─────────────────────────────────────────────── -->
            <div v-show="activeTab === 'billing'" class="space-y-6">

                <!-- Resumen de cobros -->
                <div class="grid grid-cols-2 sm:grid-cols-4 gap-4">
                    <div class="bg-white rounded-xl border border-gray-200 p-4 text-center">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Facturas</p>
                        <p class="text-2xl font-bold text-gray-900 mt-1">{{ invoices.filter(i=>i.status!=='void').length }}</p>
                    </div>
                    <div v-for="(total, cur) in billingStats.totalByCurrency" :key="cur" class="bg-white rounded-xl border border-gray-200 p-4 text-center">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Facturado {{ cur }}</p>
                        <p class="text-lg font-bold text-gray-900 mt-1">{{ formatAmount(total, cur) }}</p>
                    </div>
                    <div v-for="(pend, cur) in billingStats.pendingByCurrency" :key="'p'+cur" class="bg-white rounded-xl border border-red-100 p-4 text-center" :class="Number(pend)>0?'border-red-200':''">
                        <p class="text-xs text-gray-500 uppercase tracking-wider">Por cobrar {{ cur }}</p>
                        <p class="text-lg font-bold mt-1" :class="Number(pend)>0?'text-red-600':'text-gray-900'">{{ formatAmount(pend, cur) }}</p>
                    </div>
                    <div v-for="(cred, cur) in creditByCurrency" :key="'c'+cur" class="bg-emerald-50 rounded-xl border border-emerald-200 p-4 text-center">
                        <p class="text-xs text-emerald-700 uppercase tracking-wider">Saldo a favor {{ cur }}</p>
                        <p class="text-lg font-bold text-emerald-700 mt-1">{{ formatAmount(cred, cur) }}</p>
                    </div>
                </div>

                <!-- Saldo a favor: se descuenta solo en la próxima factura -->
                <div v-if="hasCredit" class="bg-emerald-50 border border-emerald-200 rounded-xl px-5 py-3 flex items-start gap-3">
                    <svg class="w-5 h-5 text-emerald-600 shrink-0 mt-0.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <p class="text-sm text-emerald-900">
                        Este cliente tiene
                        <strong v-for="(cred, cur) in creditByCurrency" :key="'t'+cur">{{ formatAmount(cred, cur) }}</strong>
                        de saldo a favor. Se le va a descontar automáticamente en la próxima factura que le crees.
                    </p>
                </div>
                <div v-for="(deb, cur) in debtByCurrency" :key="'d'+cur" class="bg-red-50 border border-red-200 rounded-xl px-5 py-3 text-sm text-red-900">
                    Saldo en contra: debe <strong>{{ formatAmount(deb, cur) }}</strong> (facturado menos recaudado).
                </div>

                <div class="flex items-center justify-between">
                    <h3 class="text-sm font-semibold text-gray-900">Facturas</h3>
                    <div class="flex gap-2">
                        <button @click="openPayment(null)" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded-lg hover:bg-green-100 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Registrar pago
                        </button>
                        <button @click="openAddInvoice" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nueva factura
                        </button>
                    </div>
                </div>

                <div v-if="invoices.length === 0" class="py-10 text-center text-gray-400 text-sm bg-white rounded-xl border border-dashed border-gray-200">
                    Sin facturas. <button @click="openAddInvoice" class="text-amber-600 hover:underline">Crear la primera</button>
                </div>

                <div v-for="inv in invoices" :key="inv.id" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                    <div class="flex items-center justify-between px-5 py-3.5 gap-4">
                        <div class="flex items-center gap-3 flex-1 min-w-0">
                            <span class="text-xs font-mono text-gray-500">#{{ inv.number }}</span>
                            <span class="font-medium text-gray-900 truncate">{{ inv.concept }}</span>
                            <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium shrink-0" :class="invStatusColor[inv.status]">{{ invStatusLabel[inv.status] }}</span>
                        </div>
                        <div class="flex items-center gap-4 shrink-0">
                            <div class="text-right">
                                <p class="font-bold text-gray-900">{{ formatAmount(inv.total, inv.currency) }}</p>
                                <p v-if="Number(inv.balance_due) > 0" class="text-xs text-red-500">Pendiente: {{ formatAmount(inv.balance_due, inv.currency) }}</p>
                                <p class="text-xs text-gray-400">Vence {{ inv.due_at ?? '—' }}</p>
                            </div>
                            <div class="flex items-center gap-1">
                                <button v-if="!['paid','void'].includes(inv.status)" @click="openPayment(inv)" class="px-2 py-1 text-xs font-medium text-green-700 bg-green-50 border border-green-200 rounded hover:bg-green-100 transition">Pagar</button>
                                <button @click="openEditInvoice(inv)" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                                <button v-if="inv.status !== 'void'" @click="voidInvoice(inv)" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition" title="Anular"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
                            </div>
                        </div>
                    </div>
                    <!-- Pagos de esta factura -->
                    <div v-if="inv.payments.length > 0" class="border-t border-gray-100 bg-gray-50 px-5 py-2 flex flex-wrap gap-x-6 gap-y-1">
                        <div v-for="p in inv.payments" :key="p.id" class="flex items-center gap-3 text-xs text-gray-600">
                            <span class="text-green-600 font-medium">{{ formatAmount(p.amount, p.currency) }}</span>
                            <span class="text-gray-400">{{ p.paid_at }}</span>
                            <span class="capitalize text-gray-400">{{ p.method }}</span>
                            <span v-if="p.reference" class="text-gray-400">· {{ p.reference }}</span>
                            <button @click="deletePayment(p)" class="text-red-400 hover:text-red-600 transition">×</button>
                        </div>
                    </div>
                </div>

                <!-- Recaudos: TODOS los pagos, incluidos los que no van contra factura -->
                <div class="pt-2">
                    <div class="flex items-center justify-between mb-3">
                        <h3 class="text-sm font-semibold text-gray-900">Recaudos ({{ payments.length }})</h3>
                        <button @click="openPayment(null)" class="text-xs text-green-700 hover:underline">Registrar pago</button>
                    </div>
                    <div v-if="payments.length === 0" class="py-8 text-center text-gray-400 text-sm bg-white rounded-xl border border-dashed border-gray-200">
                        Sin recaudos registrados.
                    </div>
                    <div v-else class="bg-white rounded-xl border border-gray-200 overflow-hidden divide-y divide-gray-100">
                        <div v-for="p in payments" :key="p.id" class="flex items-center justify-between gap-4 px-5 py-3">
                            <div class="flex items-center gap-3 min-w-0">
                                <span class="font-bold" :class="p.is_credit_application ? 'text-emerald-600' : 'text-green-600'">{{ formatAmount(p.amount, p.currency) }}</span>
                                <span class="text-xs text-gray-500">{{ fmtDate(p.paid_at) }}</span>
                                <span v-if="p.is_credit_application" class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-emerald-100 text-emerald-700 shrink-0">SALDO A FAVOR APLICADO</span>
                                <span v-else class="text-xs text-gray-400">{{ methodLabel[p.method] ?? p.method }}</span>
                                <span v-if="p.reference && !p.is_credit_application" class="text-xs text-gray-400 truncate">· {{ p.reference }}</span>
                            </div>
                            <div class="flex items-center gap-3 shrink-0">
                                <span v-if="p.invoice_number" class="text-xs font-mono text-gray-500">#{{ p.invoice_number }}</span>
                                <span v-else class="text-[10px] font-semibold px-1.5 py-0.5 rounded-full bg-amber-100 text-amber-700">SIN FACTURA</span>
                                <button @click="deletePayment(p)" class="p-1 rounded hover:bg-red-50 text-gray-300 hover:text-red-500 transition" title="Eliminar recaudo">
                                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                </button>
                            </div>
                        </div>
                    </div>
                    <p v-if="payments.some(p => !p.invoice_number)" class="text-xs text-gray-500 mt-2">
                        Los recaudos <strong>sin factura</strong> quedan como saldo a favor de la cuenta y se descuentan solos en la próxima factura.
                    </p>
                </div>
            </div>

            <!-- ── Tab: Soporte ────────────────────────────────────────────── -->
            <div v-show="activeTab === 'support'" class="space-y-6">

                <!-- Notas internas -->
                <div class="bg-white rounded-xl border border-gray-200 p-5 space-y-4">
                    <h3 class="text-sm font-semibold text-gray-900">Notas internas</h3>

                    <!-- Nueva nota -->
                    <div class="flex gap-3">
                        <textarea v-model="noteBody" rows="2" placeholder="Añadir nota interna..."
                            class="flex-1 px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"
                            @keydown.ctrl.enter="submitNote"></textarea>
                        <button @click="submitNote" :disabled="!noteBody.trim()" class="self-end px-4 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-40 transition">Guardar</button>
                    </div>

                    <div v-if="notes.length === 0" class="text-sm text-gray-400 py-2">Sin notas aún.</div>
                    <div v-for="n in notes" :key="n.id" class="flex gap-3 group">
                        <div class="flex-1 rounded-lg px-4 py-3 text-sm" :class="n.pinned ? 'bg-amber-50 border border-amber-200' : 'bg-gray-50 border border-gray-100'">
                            <div v-if="editNoteId === n.id">
                                <textarea v-model="editNoteBody" rows="2" class="w-full px-2 py-1.5 text-sm border border-gray-300 rounded focus:outline-none focus:ring-1 focus:ring-amber-400 resize-none mb-2"></textarea>
                                <div class="flex gap-2">
                                    <button @click="saveNote(n)" class="px-3 py-1 text-xs font-medium bg-amber-600 text-white rounded hover:bg-amber-700 transition">Guardar</button>
                                    <button @click="editNoteId=null" class="px-3 py-1 text-xs text-gray-500 hover:text-gray-700 transition">Cancelar</button>
                                </div>
                            </div>
                            <p v-else class="whitespace-pre-wrap text-gray-800">{{ n.body }}</p>
                            <p class="text-[11px] text-gray-400 mt-1.5">{{ n.author?.name }} · {{ fmtDate(n.created_at) }}</p>
                        </div>
                        <div class="flex flex-col gap-1 opacity-0 group-hover:opacity-100 transition shrink-0">
                            <button @click="togglePin(n)" :title="n.pinned ? 'Desfijar' : 'Fijar'" class="p-1 text-gray-400 hover:text-amber-600 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 5a2 2 0 012-2h10a2 2 0 012 2v16l-7-3.5L5 21V5z" /></svg></button>
                            <button @click="startEditNote(n)" class="p-1 text-gray-400 hover:text-gray-700 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                            <button @click="deleteNote(n)" class="p-1 text-gray-400 hover:text-red-500 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                        </div>
                    </div>
                </div>

                <!-- Tickets -->
                <div class="space-y-4">
                    <div class="flex items-center justify-between">
                        <h3 class="text-sm font-semibold text-gray-900">Tickets de soporte</h3>
                        <button @click="openAddTicket" class="inline-flex items-center gap-1.5 px-3 py-1.5 text-xs font-medium text-amber-700 bg-amber-50 border border-amber-200 rounded-lg hover:bg-amber-100 transition">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4.5v15m7.5-7.5h-15" /></svg>
                            Nuevo ticket
                        </button>
                    </div>
                    <div v-if="tickets.length === 0" class="py-10 text-center text-gray-400 text-sm bg-white rounded-xl border border-dashed border-gray-200">
                        Sin tickets. <button @click="openAddTicket" class="text-amber-600 hover:underline">Crear el primero</button>
                    </div>

                    <div v-for="t in tickets" :key="t.id" class="bg-white rounded-xl border border-gray-200 overflow-hidden">
                        <div class="flex items-start justify-between px-5 py-4 gap-4">
                            <div class="flex-1 min-w-0 space-y-1.5">
                                <div class="flex items-center gap-2 flex-wrap">
                                    <span class="font-semibold text-gray-900">{{ t.subject }}</span>
                                    <span class="inline-flex items-center px-2 py-0.5 rounded-full text-[11px] font-medium" :class="ticketStatusColor[t.status]">{{ ticketStatusLabel[t.status] }}</span>
                                    <span class="text-xs font-medium" :class="priorityColor[t.priority]">{{ priorityLabel[t.priority] }}</span>
                                    <span v-if="t.product" class="text-xs bg-gray-100 text-gray-600 px-1.5 py-0.5 rounded">{{ productLabel[t.product] }}</span>
                                </div>
                                <div class="flex flex-wrap gap-3 text-xs text-gray-500">
                                    <span v-if="t.assigned_to">Asignado: <strong>{{ t.assigned_to.name }}</strong></span>
                                    <span>Abierto {{ fmtDate(t.created_at) }}</span>
                                    <span v-if="t.resolved_at">Resuelto {{ fmtDate(t.resolved_at) }}</span>
                                    <span>{{ t.events.length }} mensaje{{ t.events.length !== 1 ? 's' : '' }}</span>
                                </div>
                            </div>
                            <div class="flex items-center gap-1 shrink-0">
                                <button @click="openTicket = openTicket === t.id ? null : t.id" class="px-2 py-1 text-xs text-gray-600 bg-gray-100 rounded hover:bg-gray-200 transition">
                                    {{ openTicket === t.id ? 'Cerrar' : 'Ver' }}
                                </button>
                                <button @click="openEditTicket(t)" class="p-1.5 rounded-lg hover:bg-gray-100 text-gray-400 hover:text-gray-700 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M16.862 4.487l1.687-1.688a1.875 1.875 0 112.652 2.652L10.582 16.07a4.5 4.5 0 01-1.897 1.13L6 18l.8-2.685a4.5 4.5 0 011.13-1.897l8.932-8.931z" /></svg></button>
                                <button @click="deleteTicket(t)" class="p-1.5 rounded-lg hover:bg-red-50 text-gray-400 hover:text-red-500 transition"><svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5"><path stroke-linecap="round" stroke-linejoin="round" d="M14.74 9l-.346 9m-4.788 0L9.26 9m9.968-3.21c.342.052.682.107 1.022.166m-1.022-.165L18.16 19.673a2.25 2.25 0 01-2.244 2.077H8.084a2.25 2.25 0 01-2.244-2.077L4.772 5.79m14.456 0a48.108 48.108 0 00-3.478-.397m-12 .562c.34-.059.68-.114 1.022-.165m0 0a48.11 48.11 0 013.478-.397m7.5 0v-.916c0-1.18-.91-2.164-2.09-2.201a51.964 51.964 0 00-3.32 0c-1.18.037-2.09 1.022-2.09 2.201v.916m7.5 0a48.667 48.667 0 00-7.5 0" /></svg></button>
                            </div>
                        </div>

                        <!-- Timeline del ticket -->
                        <div v-if="openTicket === t.id" class="border-t border-gray-100 px-5 py-4 space-y-3 bg-gray-50">
                            <div v-for="e in t.events" :key="e.id" class="flex gap-3">
                                <div class="w-7 h-7 rounded-full bg-gray-200 flex items-center justify-center text-xs font-bold text-gray-600 shrink-0 mt-0.5">
                                    {{ (e.author?.name ?? 'S')[0].toUpperCase() }}
                                </div>
                                <div class="flex-1 text-sm">
                                    <div v-if="e.type === 'status_change'" class="text-gray-500 text-xs py-1">
                                        <strong>{{ e.author?.name }}</strong> cambió estado: <span class="line-through">{{ ticketStatusLabel[e.meta?.from] }}</span> → <strong>{{ ticketStatusLabel[e.meta?.to] }}</strong>
                                        <span class="ml-2 text-gray-400">{{ fmtDate(e.created_at) }}</span>
                                    </div>
                                    <div v-else-if="e.type === 'assignment'" class="text-gray-500 text-xs py-1">
                                        <strong>{{ e.author?.name }}</strong> asignó el ticket.
                                        <span class="ml-2 text-gray-400">{{ fmtDate(e.created_at) }}</span>
                                    </div>
                                    <div v-else class="bg-white rounded-lg border border-gray-200 px-4 py-2.5">
                                        <div class="flex items-center justify-between mb-1">
                                            <span class="text-xs font-semibold text-gray-700">{{ e.author?.name ?? 'Sistema' }}</span>
                                            <span class="text-[11px] text-gray-400">{{ fmtDate(e.created_at) }}</span>
                                        </div>
                                        <p class="text-gray-800 whitespace-pre-wrap">{{ e.body }}</p>
                                        <span v-if="e.type === 'note'" class="mt-1 inline-block text-[10px] bg-yellow-100 text-yellow-700 px-1.5 rounded">nota interna</span>
                                    </div>
                                </div>
                            </div>

                            <!-- Añadir mensaje -->
                            <div v-if="!['resolved','closed'].includes(t.status)" class="flex gap-2 mt-3">
                                <select v-model="eventForm.type" class="px-2 py-1.5 text-xs border border-gray-300 rounded-lg focus:outline-none focus:ring-1 focus:ring-amber-400">
                                    <option value="message">Mensaje</option>
                                    <option value="note">Nota interna</option>
                                </select>
                                <textarea v-model="eventForm.body" rows="1" placeholder="Escribe un mensaje..." class="flex-1 px-3 py-1.5 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"></textarea>
                                <button @click="submitEvent(t)" :disabled="!eventForm.body.trim()" class="self-end px-3 py-1.5 text-xs font-medium bg-amber-600 text-white rounded-lg hover:bg-amber-700 disabled:opacity-40 transition">Enviar</button>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

        </div>

        <!-- ── MODALES ──────────────────────────────────────────────────────── -->

        <!-- Editar cuenta -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showEdit" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showEdit=false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-2xl max-h-[80vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between rounded-t-2xl">
                        <h2 class="text-lg font-bold text-gray-900">Editar cuenta</h2>
                        <button @click="showEdit=false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button>
                    </div>
                    <form @submit.prevent="submitEdit" class="p-6 space-y-4">
                        <div class="grid sm:grid-cols-2 gap-4">
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Nombre *</label><input v-model="editForm.name" required class="w-full px-3 py-2 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" :class="editForm.errors.name?'border-red-400':'border-gray-300'" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Razón social</label><input v-model="editForm.legal_name" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">ispwatch Tenant ID</label><input v-model="editForm.ispwatch_tenant_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Workspace de Converza</label><select v-model="editForm.tenant_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="">Sin vincular</option><option v-for="t in tenants" :key="t.id" :value="t.id" :disabled="t.taken_by && t.id !== account.tenant_id">{{ t.name }}{{ !t.is_active ? ' (inactivo)' : '' }}{{ t.taken_by && t.id !== account.tenant_id ? ` — ya en ${t.taken_by}` : '' }}</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Estado *</label><select v-model="editForm.status" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="prospect">Prospecto</option><option value="active">Activo</option><option value="past_due">En mora</option><option value="suspended">Suspendido</option><option value="churned">Cancelado</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Salud</label><select v-model="editForm.health" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="">Sin definir</option><option value="good">Buena</option><option value="at_risk">En riesgo</option><option value="critical">Crítica</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Nombre contacto</label><input v-model="editForm.contact_name" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Email</label><input v-model="editForm.contact_email" type="email" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Teléfono</label><input v-model="editForm.contact_phone" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Inicio</label><input v-model="editForm.onboarding_at" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Renovación</label><input v-model="editForm.renewal_at" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div class="col-span-2">
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Día de facturación <span class="font-normal text-gray-400">— excepción de este cliente</span></label>
                                <input v-model="editForm.billing_day" type="number" min="1" max="31" placeholder="Sin excepción (fechas a mano)" class="w-full px-3 py-2 text-sm border rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" :class="editForm.errors.billing_day?'border-red-400':'border-gray-300'" />
                                <p v-if="editForm.errors.billing_day" class="text-xs text-red-500 mt-1">{{ editForm.errors.billing_day }}</p>
                                <p v-else class="text-xs text-gray-400 mt-1">Día del mes en que se le cobra a este cliente (ej. 9 = los 9 de cada mes). Si el mes no tiene ese día, se usa el último. Vacío = sin excepción.</p>
                            </div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Manager</label><select v-model="editForm.owner_user_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="">Sin asignar</option><option v-for="u in internal_users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                        </div>
                        <div><label class="block text-xs font-semibold text-gray-700 mb-1">Notas</label><textarea v-model="editForm.notes" rows="3" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"></textarea></div>
                        <div class="flex justify-end gap-3 pt-2">
                            <button type="button" @click="showEdit=false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button>
                            <button type="submit" :disabled="editForm.processing" class="px-6 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-60 transition">{{ editForm.processing ? 'Guardando...' : 'Guardar' }}</button>
                        </div>
                    </form>
                </div>
            </div>
        </Transition>

        <!-- Producto -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showProductModal" class="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showProductModal=false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg">
                    <div class="border-b border-gray-100 px-6 py-4 flex items-center justify-between"><h2 class="text-lg font-bold text-gray-900">{{ editingProduct ? 'Editar producto' : 'Agregar producto' }}</h2><button @click="showProductModal=false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button></div>
                    <form @submit.prevent="submitProduct" class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Producto *</label><select v-model="productForm.product" :disabled="!!editingProduct" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 disabled:bg-gray-50"><option value="">Seleccionar</option><option value="ispwatch">ispwatch</option><option value="converza">Converza</option></select></div>
                            <div>
                                <label class="block text-xs font-semibold text-gray-700 mb-1">Plan del catálogo</label>
                                <select v-model="productForm.plan_key" @change="applyCatalogPlan" :disabled="!productForm.product" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg bg-white focus:outline-none focus:ring-2 focus:ring-amber-400 disabled:bg-gray-50">
                                    <option value="">Personalizado</option>
                                    <option v-for="p in productPlanOptions" :key="p.key" :value="p.key">{{ p.name }} — {{ p.limit }}{{ p.price != null ? ` · $${p.price} ${baseCurrency}` : ' · a medida' }}</option>
                                </select>
                            </div>
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Etiqueta del plan</label><input v-model="productForm.plan" type="text" placeholder="Básico, Pro…" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Estado</label><select v-model="productForm.status" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="active">Activo</option><option value="paused">Pausado</option><option value="cancelled">Cancelado</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Ciclo</label><select v-model="productForm.billing_cycle" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="monthly">Mensual</option><option value="quarterly">Trimestral</option><option value="yearly">Anual</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Valor *</label><input v-model="productForm.amount" type="number" min="0" step="0.01" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Moneda</label><select v-model="productForm.currency" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="COP">COP</option><option value="USD">USD</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Inicio</label><input v-model="productForm.started_at" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Vence el</label><input v-model="productForm.renews_at" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                        </div>
                        <div class="flex justify-end gap-3 pt-2"><button type="button" @click="showProductModal=false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button><button type="submit" :disabled="productForm.processing" class="px-6 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-60 transition">{{ productForm.processing?'Guardando...':editingProduct?'Actualizar':'Agregar' }}</button></div>
                    </form>
                </div>
            </div>
        </Transition>

        <!-- Factura -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showInvoiceModal" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showInvoiceModal=false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between rounded-t-2xl"><h2 class="text-lg font-bold text-gray-900">{{ editingInvoice ? 'Editar factura' : 'Nueva factura' }}</h2><button @click="showInvoiceModal=false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button></div>
                    <form @submit.prevent="submitInvoice" class="p-6 space-y-4">
                        <div class="grid grid-cols-2 gap-4">
                            <div class="col-span-2"><label class="block text-xs font-semibold text-gray-700 mb-1">Concepto *</label><input v-model="invoiceForm.concept" required placeholder="Servicio mensual Converza…" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Número *</label><input v-model="invoiceForm.number" required :disabled="!!editingInvoice" placeholder="F-001" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 disabled:bg-gray-50" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Moneda *</label><select v-model="invoiceForm.currency" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="COP">COP</option><option value="USD">USD</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Subtotal *</label><input v-model="invoiceForm.amount" type="number" min="0" step="0.01" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">IVA</label><input v-model="invoiceForm.tax" type="number" min="0" step="0.01" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Emisión *</label><input v-model="invoiceForm.issued_at" type="date" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Vencimiento</label><input v-model="invoiceForm.due_at" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Período inicio</label><input v-model="invoiceForm.period_start" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Período fin</label><input v-model="invoiceForm.period_end" type="date" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                        </div>
                        <!-- Descuento automático del saldo a favor -->
                        <label v-if="!editingInvoice && creditIn(invoiceForm.currency) > 0"
                            class="flex items-start gap-2.5 bg-emerald-50 border border-emerald-200 rounded-lg px-4 py-3 cursor-pointer">
                            <input v-model="invoiceForm.apply_credit" type="checkbox" class="mt-0.5 rounded border-emerald-300 text-emerald-600 focus:ring-emerald-400" />
                            <span class="text-sm text-emerald-900">
                                Descontar el saldo a favor de <strong>{{ formatAmount(creditIn(invoiceForm.currency), invoiceForm.currency) }}</strong>
                                <span class="block text-xs text-emerald-700/80 mt-0.5">Se aplica hasta el total de la factura; lo que sobre queda de saldo para la siguiente.</span>
                            </span>
                        </label>
                        <div class="flex justify-end gap-3 pt-2"><button type="button" @click="showInvoiceModal=false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button><button type="submit" :disabled="invoiceForm.processing" class="px-6 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-60 transition">{{ invoiceForm.processing?'Guardando...':editingInvoice?'Actualizar':'Crear factura' }}</button></div>
                    </form>
                </div>
            </div>
        </Transition>

        <!-- Pago -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showPaymentModal" class="fixed inset-0 z-50 flex items-start justify-center pt-24 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showPaymentModal=false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-md">
                    <div class="border-b border-gray-100 px-6 py-4 flex items-center justify-between"><h2 class="text-lg font-bold text-gray-900">Registrar pago</h2><button @click="showPaymentModal=false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button></div>
                    <form @submit.prevent="submitPayment" class="p-6 space-y-4">
                        <div v-if="!payingInvoice"><label class="block text-xs font-semibold text-gray-700 mb-1">Factura (opcional)</label><select v-model="paymentForm.account_invoice_id" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="">Sin factura (abono libre)</option><option v-for="inv in invoices.filter(i=>!['paid','void'].includes(i.status))" :key="inv.id" :value="inv.id">#{{ inv.number }} — {{ formatAmount(inv.balance_due, inv.currency) }} pendiente</option></select></div>
                        <div v-else class="text-sm text-gray-600 bg-amber-50 border border-amber-200 rounded-lg px-4 py-2.5">Pago para factura <strong>#{{ payingInvoice.number }}</strong> — pendiente {{ formatAmount(payingInvoice.balance_due, payingInvoice.currency) }}</div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Monto *</label><input v-model="paymentForm.amount" type="number" min="0.01" step="0.01" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Moneda</label><select v-model="paymentForm.currency" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="COP">COP</option><option value="USD">USD</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Método</label><select v-model="paymentForm.method" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="transfer">Transferencia</option><option value="cash">Efectivo</option><option value="card">Tarjeta</option><option value="other">Otro</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Fecha pago *</label><input v-model="paymentForm.paid_at" type="date" required class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                        </div>
                        <div><label class="block text-xs font-semibold text-gray-700 mb-1">Referencia / comprobante</label><input v-model="paymentForm.reference" placeholder="N° de transacción, recibo…" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                        <div class="flex justify-end gap-3 pt-2"><button type="button" @click="showPaymentModal=false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button><button type="submit" :disabled="paymentForm.processing" class="px-6 py-2 text-sm font-medium text-white bg-green-600 rounded-lg hover:bg-green-700 disabled:opacity-60 transition">{{ paymentForm.processing?'Guardando...':'Registrar pago' }}</button></div>
                    </form>
                </div>
            </div>
        </Transition>

        <!-- Ticket -->
        <Transition enter-from-class="opacity-0" enter-active-class="transition duration-200" leave-to-class="opacity-0" leave-active-class="transition duration-150">
            <div v-if="showTicketModal" class="fixed inset-0 z-50 flex items-start justify-center pt-16 px-4">
                <div class="absolute inset-0 bg-black/40" @click="showTicketModal=false"></div>
                <div class="relative bg-white rounded-2xl shadow-2xl w-full max-w-lg max-h-[85vh] overflow-y-auto">
                    <div class="sticky top-0 bg-white border-b border-gray-100 px-6 py-4 flex items-center justify-between rounded-t-2xl"><h2 class="text-lg font-bold text-gray-900">{{ editingTicket ? 'Editar ticket' : 'Nuevo ticket' }}</h2><button @click="showTicketModal=false" class="p-2 rounded-lg hover:bg-gray-100 text-gray-400"><svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg></button></div>
                    <form @submit.prevent="submitTicket" class="p-6 space-y-4">
                        <div><label class="block text-xs font-semibold text-gray-700 mb-1">Asunto *</label><input v-model="activeTicketForm.subject" required placeholder="Describe el problema…" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400" /></div>
                        <div class="grid grid-cols-2 gap-4">
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Prioridad</label><select v-model="activeTicketForm.priority" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="low">Baja</option><option value="normal">Normal</option><option value="high">Alta</option><option value="urgent">Urgente</option></select></div>
                            <div v-if="editingTicket"><label class="block text-xs font-semibold text-gray-700 mb-1">Estado</label><select v-model="ticketUpdateForm.status" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="open">Abierto</option><option value="pending">Pendiente</option><option value="resolved">Resuelto</option><option value="closed">Cerrado</option></select></div>
                            <div v-else><label class="block text-xs font-semibold text-gray-700 mb-1">Origen</label><select v-model="ticketForm.source" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="manual">Manual</option><option value="whatsapp">WhatsApp</option><option value="email">Email</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Producto</label><select v-model="activeTicketForm.product" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="">General</option><option value="ispwatch">ispwatch</option><option value="converza">Converza</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Categoría</label><select v-model="activeTicketForm.category" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="">General</option><option value="technical">Técnico</option><option value="billing">Facturación</option><option value="onboarding">Onboarding</option><option value="other">Otro</option></select></div>
                            <div><label class="block text-xs font-semibold text-gray-700 mb-1">Asignar a</label><select v-model="activeTicketForm.assigned_to" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400"><option value="">Sin asignar</option><option v-for="u in internal_users" :key="u.id" :value="u.id">{{ u.name }}</option></select></div>
                        </div>
                        <div v-if="!editingTicket"><label class="block text-xs font-semibold text-gray-700 mb-1">Descripción inicial</label><textarea v-model="ticketForm.body" rows="3" placeholder="Detalle del problema…" class="w-full px-3 py-2 text-sm border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-amber-400 resize-none"></textarea></div>
                        <div class="flex justify-end gap-3 pt-2"><button type="button" @click="showTicketModal=false" class="px-4 py-2 text-sm text-gray-700 border border-gray-300 rounded-lg hover:bg-gray-50 transition">Cancelar</button><button type="submit" :disabled="editingTicket ? ticketUpdateForm.processing : ticketForm.processing" class="px-6 py-2 text-sm font-medium text-white bg-amber-600 rounded-lg hover:bg-amber-700 disabled:opacity-60 transition">{{ (editingTicket ? ticketUpdateForm.processing : ticketForm.processing) ? 'Guardando...' : (editingTicket ? 'Actualizar' : 'Crear ticket') }}</button></div>
                    </form>
                </div>
            </div>
        </Transition>

    </AppLayout>
</template>
