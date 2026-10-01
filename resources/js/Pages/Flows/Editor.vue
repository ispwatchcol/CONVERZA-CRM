<script setup>
// Editor del Workspace de flujos (CON-48). El lienzo es Vue Flow; el estado del
// grafo vive en su store y se serializa a {nodes, edges} solo para guardar,
// validar y simular. Todo lo que habla con el servidor va por JSON: recargar la
// página en cada acción perdería la posición del lienzo y la selección.
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, markRaw, nextTick, onBeforeUnmount, onMounted, provide, ref, watch } from 'vue';
import axios from 'axios';
import { MarkerType, VueFlow, useVueFlow } from '@vue-flow/core';
import { Background } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';
import FlowNode from '@/Components/Flows/FlowNode.vue';
import NodeInspector from '@/Components/Flows/NodeInspector.vue';
import FlowSimulator from '@/Components/Flows/FlowSimulator.vue';
import { NODE_TYPES, PALETTE, defaultData, newId, outputsOf } from '@/Components/Flows/nodeCatalog';

const props = defineProps({
    flow: { type: Object, required: true },
    graph: { type: Object, required: true },
    versions: { type: Array, default: () => [] },
    catalog: { type: Object, required: true },
});

const flow = ref({ ...props.flow });
const versions = ref([...props.versions]);
const name = ref(props.flow.name);
const description = ref(props.flow.description ?? '');

// ── Lienzo ───────────────────────────────────────────────────────────────────
const FLOW_ID = `flow-editor-${props.flow.id}`;
const {
    addNodes, addEdges, removeEdges, removeNodes, findNode, getNodes, getEdges, setNodes, setEdges,
    updateNodeInternals, screenToFlowCoordinate, onConnect, onNodeClick, onPaneClick, onEdgeDoubleClick,
    onNodesInitialized, setCenter, fitView, getViewport, setViewport, vueFlowRef,
} = useVueFlow(FLOW_ID);

const nodeTypes = Object.fromEntries(Object.keys(NODE_TYPES).map((type) => [type, markRaw(FlowNode)]));
const EDGE_DEFAULTS = { type: 'smoothstep', markerEnd: MarkerType.ArrowClosed };

function toFlowNodes(graph) {
    return (graph?.nodes ?? []).map((n) => ({
        id: n.id,
        type: n.type,
        position: { x: Number(n.position?.x ?? 0), y: Number(n.position?.y ?? 0) },
        data: JSON.parse(JSON.stringify(n.data ?? {})),
        // El Inicio no se borra: sin él el flujo no sabe cuándo arrancar.
        deletable: n.type !== 'start',
    }));
}

function toFlowEdges(graph) {
    return (graph?.edges ?? []).map((e) => ({
        id: e.id,
        source: e.source,
        sourceHandle: e.sourceHandle || 'next',
        target: e.target,
        ...EDGE_DEFAULTS,
    }));
}

const initialNodes = toFlowNodes(props.graph);
const initialEdges = toFlowEdges(props.graph);

/** El grafo tal como se guarda: misma forma que FlowGraph::normalize(). */
function currentGraph() {
    return {
        nodes: getNodes.value.map((n) => ({
            id: n.id,
            type: n.type,
            position: { x: Math.round(n.position.x * 10) / 10, y: Math.round(n.position.y * 10) / 10 },
            data: n.data ?? {},
        })),
        edges: getEdges.value.map((e) => ({
            id: e.id,
            source: e.source,
            sourceHandle: e.sourceHandle || 'next',
            target: e.target,
        })),
    };
}

// ── Cambios sin guardar ──────────────────────────────────────────────────────
// Se compara el JSON de lo que se GUARDA (ids, tipos, posiciones, datos), no
// los nodos internos de Vue Flow: seleccionar o medir un bloque no es un cambio.
const savedSnapshot = ref(null);
const serializedGraph = computed(() => JSON.stringify(currentGraph()));
const graphDirty = computed(() => savedSnapshot.value !== null && serializedGraph.value !== savedSnapshot.value);

function markBaseline() {
    savedSnapshot.value = serializedGraph.value;
}

const metaDirty = computed(() => name.value !== flow.value.name || (description.value || '') !== (flow.value.description || ''));
const dirty = computed(() => graphDirty.value || metaDirty.value);

function onFlowInit() {
    markBaseline();
}

// Encuadre inicial, una sola vez y con los bloques ya medidos. Un flujo grande
// encuadrado entero queda ilegible: por debajo de cierto zoom se prefiere
// mostrar el comienzo (el Inicio a la izquierda) a un tamaño que se lea.
let framed = false;

async function frame() {
    await fitView({ padding: 0.15, maxZoom: 1 });

    if (getViewport().zoom >= 0.65) return;

    const start = getNodes.value.find((n) => n.type === 'start') ?? getNodes.value[0];
    const rect = vueFlowRef.value?.getBoundingClientRect();
    if (!start || !rect) return;

    const zoom = 0.75;
    await setViewport({ x: 40 - start.position.x * zoom, y: rect.height / 2 - (start.position.y + 60) * zoom, zoom });
}

onNodesInitialized(() => {
    if (framed) return;
    framed = true;
    frame();
});

// ── Selección y panel lateral ────────────────────────────────────────────────
const selectedId = ref(null);
const panel = ref('node'); // node | review | simulator | versions
const selectedNode = computed(() => (selectedId.value ? findNode(selectedId.value) ?? null : null));

onNodeClick(({ node }) => {
    selectedId.value = node.id;
    if (panel.value !== 'simulator') panel.value = 'node';
});
onPaneClick(() => { selectedId.value = null; });

function focusNode(id) {
    const node = findNode(id);
    if (!node) return;
    selectedId.value = id;
    panel.value = 'node';
    setCenter(node.position.x + 125, node.position.y + 60, { zoom: 1, duration: 300 });
}

// ── Conexiones ───────────────────────────────────────────────────────────────
// Cada salida lleva a UN solo bloque: conectar de nuevo reemplaza la conexión.
onConnect((params) => {
    const handle = params.sourceHandle || 'next';
    const stale = getEdges.value.filter((e) => e.source === params.source && (e.sourceHandle || 'next') === handle);
    if (stale.length) removeEdges(stale.map((e) => e.id));

    addEdges([{
        id: `e_${params.source}_${handle}_${params.target}`,
        source: params.source,
        sourceHandle: handle,
        target: params.target,
        ...EDGE_DEFAULTS,
    }]);
});

onEdgeDoubleClick(({ edge }) => removeEdges([edge.id]));

function isValidConnection(connection) {
    return connection.source !== connection.target && findNode(connection.target)?.type !== 'start';
}

// Si cambian las salidas de un bloque (opción borrada, pregunta que deja de
// validar), las conexiones huérfanas se van y Vue Flow vuelve a medir los
// puntos de conexión, que se movieron.
const outputsSignature = computed(() => getNodes.value
    .map((n) => `${n.id}:${outputsOf(n.type, n.data).map((o) => o.handle).join(',')}`)
    .join('|'));

watch(outputsSignature, () => {
    const valid = new Map(getNodes.value.map((n) => [n.id, new Set(outputsOf(n.type, n.data).map((o) => o.handle))]));
    const stale = getEdges.value.filter((e) => !valid.get(e.source)?.has(e.sourceHandle || 'next'));
    if (stale.length) removeEdges(stale.map((e) => e.id));
    nextTick(() => updateNodeInternals(getNodes.value.map((n) => n.id)));
});

// ── Agregar, duplicar y borrar bloques ───────────────────────────────────────
function centerPosition() {
    const rect = vueFlowRef.value?.getBoundingClientRect();
    if (!rect) return { x: 0, y: 0 };
    const p = screenToFlowCoordinate({ x: rect.left + rect.width / 2, y: rect.top + rect.height / 3 });
    return { x: p.x - 125 + (Math.random() * 60 - 30), y: p.y + (Math.random() * 60 - 30) };
}

function addNode(type, position = null) {
    const id = newId(type);
    addNodes([{ id, type, position: position ?? centerPosition(), data: defaultData(type), deletable: true }]);
    nextTick(() => {
        selectedId.value = id;
        panel.value = 'node';
    });
}

function onDragStart(event, type) {
    event.dataTransfer.setData('application/x-converza-node', type);
    event.dataTransfer.effectAllowed = 'move';
}

function onDrop(event) {
    const type = event.dataTransfer.getData('application/x-converza-node');
    if (!type) return;
    const p = screenToFlowCoordinate({ x: event.clientX, y: event.clientY });
    addNode(type, { x: p.x - 125, y: p.y - 20 });
}

function deleteSelected() {
    const node = selectedNode.value;
    if (!node || node.type === 'start') return;
    removeNodes([node.id], true);
    selectedId.value = null;
}

function duplicateSelected() {
    const node = selectedNode.value;
    if (!node || node.type === 'start') return;
    const id = newId(node.type);
    addNodes([{
        id,
        type: node.type,
        position: { x: node.position.x + 40, y: node.position.y + 60 },
        data: JSON.parse(JSON.stringify(node.data ?? {})),
        deletable: true,
    }]);
    nextTick(() => { selectedId.value = id; });
}

// ── Variables disponibles para el panel ──────────────────────────────────────
const variables = computed(() => {
    const seen = new Set();
    const list = [];
    const push = (v) => { if (!seen.has(v.name)) { seen.add(v.name); list.push(v); } };

    props.catalog.variables.filter((v) => !v.name.startsWith('cliente.')).forEach(push);

    for (const n of getNodes.value) {
        if (n.type === 'question' && n.data?.save_as) {
            const question = String(n.data.text ?? '').slice(0, 40);
            push({ name: n.data.save_as, description: `Respuesta a «${question}${question.length >= 40 ? '…' : ''}»`, group: 'Respuestas guardadas' });
        }
    }

    // Los datos de ispwatch solo existen si el flujo tiene un bloque que los busque.
    if (getNodes.value.some((n) => n.type === 'ispwatch')) {
        props.catalog.variables.filter((v) => v.name.startsWith('cliente.')).forEach(push);
    }

    return list;
});

// ── Revisión (validador del servidor) ────────────────────────────────────────
const issues = ref({ errors: [], warnings: [] });
const validating = ref(false);

const issuesByNode = computed(() => {
    const map = {};
    for (const issue of [...issues.value.errors, ...issues.value.warnings]) {
        if (issue.node_id) (map[issue.node_id] ??= []).push(issue);
    }
    return map;
});

const generalIssues = computed(() => [...issues.value.errors, ...issues.value.warnings].filter((i) => !i.node_id));

async function validate(quiet = false) {
    validating.value = true;
    try {
        const { data } = await axios.post(route('flows.validate', flow.value.id), { graph: currentGraph() });
        issues.value = data;
        if (!quiet) {
            panel.value = 'review';
            toast(data.errors.length ? `Hay ${data.errors.length} error(es) por corregir.` : 'Sin errores: el flujo se puede publicar.', data.errors.length ? 'error' : 'success');
        }
    } catch {
        if (!quiet) toast('No se pudo revisar el flujo.', 'error');
    } finally {
        validating.value = false;
    }
}

// ── Guardar, publicar, encender ──────────────────────────────────────────────
const saving = ref(false);
const publishing = ref(false);
const showPublish = ref(false);
const publishNote = ref('');

async function save({ silent = false } = {}) {
    if (!name.value.trim()) {
        toast('El flujo necesita un nombre.', 'error');
        return false;
    }

    saving.value = true;
    try {
        const graph = currentGraph();
        const { data } = await axios.put(route('flows.update', flow.value.id), {
            name: name.value.trim(),
            description: description.value?.trim() || null,
            graph,
        });
        flow.value = data.flow;
        name.value = data.flow.name;
        description.value = data.flow.description ?? '';
        savedSnapshot.value = JSON.stringify(graph);
        if (!silent) toast('Borrador guardado. Lo publicado no cambia hasta que publiques.');
        validate(true);
        return true;
    } catch (e) {
        toast(e.response?.data?.message ?? 'No se pudo guardar el borrador.', 'error');
        return false;
    } finally {
        saving.value = false;
    }
}

async function openPublish() {
    if (dirty.value && !(await save({ silent: true }))) return;
    await validate(true);
    if (issues.value.errors.length) {
        panel.value = 'review';
        toast('Corrige los errores antes de publicar.', 'error');
        return;
    }
    showPublish.value = true;
}

async function publish() {
    publishing.value = true;
    try {
        const { data } = await axios.post(route('flows.publish', flow.value.id), { note: publishNote.value.trim() || null });
        flow.value = data.flow;
        versions.value = data.versions;
        issues.value = { errors: [], warnings: data.warnings ?? [] };
        showPublish.value = false;
        publishNote.value = '';
        toast(data.message);
    } catch (e) {
        const body = e.response?.data;
        if (e.response?.status === 422 && Array.isArray(body?.errors)) {
            issues.value = { errors: body.errors, warnings: body.warnings ?? [] };
            panel.value = 'review';
        }
        showPublish.value = false;
        toast(body?.message ?? 'No se pudo publicar.', 'error');
    } finally {
        publishing.value = false;
    }
}

async function toggleActive() {
    const next = !flow.value.is_active;

    if (next && (dirty.value || flow.value.has_unpublished_changes)
        && !confirm(`Se va a encender la última versión PUBLICADA (v${flow.value.version}), no tus cambios sin publicar. ¿Continuar?`)) return;
    if (!next && !confirm('Al apagarlo, las conversaciones que este flujo esté atendiendo pasan a tu equipo. ¿Apagar?')) return;

    try {
        const { data } = await axios.patch(route('flows.toggle', flow.value.id), { active: next });
        flow.value = data.flow;
        toast(data.message);
    } catch (e) {
        toast(e.response?.data?.message ?? 'No se pudo cambiar el estado.', 'error');
    }
}

async function restore(version) {
    if (dirty.value && !confirm('Tienes cambios sin guardar que se van a perder. ¿Seguir?')) return;
    if (!confirm(`El borrador pasará a ser la versión ${version.version}. No se publica hasta que tú lo publiques. ¿Seguir?`)) return;

    try {
        const { data } = await axios.post(route('flows.versions.restore', [flow.value.id, version.id]));
        setNodes(toFlowNodes(data.graph));
        setEdges(toFlowEdges(data.graph));
        flow.value = data.flow;
        selectedId.value = null;
        await nextTick();
        markBaseline();
        frame();
        toast(data.message);
        validate(true);
    } catch {
        toast('No se pudo restaurar la versión.', 'error');
    }
}

// ── Simulador ────────────────────────────────────────────────────────────────
const activeNode = ref(null);
const visitedNodes = ref([]);

watch(panel, (value) => {
    if (value !== 'simulator') {
        activeNode.value = null;
        visitedNodes.value = [];
    }
});

provide('flowIssues', issuesByNode);
provide('flowActiveNode', activeNode);
provide('flowVisitedNodes', visitedNodes);
provide('flowCatalog', props.catalog);

// ── Avisos ───────────────────────────────────────────────────────────────────
const toastMessage = ref(null);
const toastType = ref('success');
let toastTimer = null;

function toast(message, type = 'success') {
    toastMessage.value = message;
    toastType.value = type;
    clearTimeout(toastTimer);
    toastTimer = setTimeout(() => { toastMessage.value = null; }, 4500);
}

// ── Atajos y salida con cambios sin guardar ──────────────────────────────────
function onKeydown(event) {
    if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 's') {
        event.preventDefault();
        if (dirty.value && !saving.value) save();
    }
}

function onBeforeUnload(event) {
    if (!dirty.value) return;
    event.preventDefault();
    event.returnValue = '';
}

let removeRouterGuard = null;

onMounted(() => {
    window.addEventListener('keydown', onKeydown);
    window.addEventListener('beforeunload', onBeforeUnload);
    removeRouterGuard = router.on('before', (event) => {
        if (dirty.value && !confirm('Tienes cambios sin guardar en el flujo. ¿Salir igual?')) {
            event.preventDefault();
        }
    });
    validate(true);
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKeydown);
    window.removeEventListener('beforeunload', onBeforeUnload);
    removeRouterGuard?.();
    clearTimeout(toastTimer);
});

function formatDate(iso) {
    if (!iso) return '—';
    return new Intl.DateTimeFormat('es-CO', { day: '2-digit', month: 'short', year: 'numeric', hour: '2-digit', minute: '2-digit' }).format(new Date(iso));
}

const nextVersion = computed(() => (flow.value.version ?? 0) + 1);
</script>

<template>
    <Head :title="`Flujo · ${flow.name}`" />
    <AppLayout>
        <!-- En pantallas chicas el lienzo no se puede usar bien: se dice en vez de romperse. -->
        <div class="lg:hidden p-6">
            <div class="bg-white rounded-2xl border border-gray-100 p-6 text-center">
                <p class="text-sm font-semibold text-gray-900">El editor de flujos necesita una pantalla más grande</p>
                <p class="text-xs text-gray-500 mt-1">Ábrelo desde un portátil o un computador.</p>
                <Link :href="route('flows.index')" class="inline-block mt-4 text-sm text-accent font-medium">← Volver a los flujos</Link>
            </div>
        </div>

        <div class="hidden lg:flex flex-col h-[calc(100dvh-4rem)]">
            <!-- ═══ Barra superior ═══ -->
            <div class="flex items-center gap-3 px-4 py-2.5 bg-white border-b border-gray-200 shrink-0">
                <Link :href="route('flows.index')" class="text-sm text-gray-400 hover:text-accent shrink-0">← Flujos</Link>

                <input v-model="name" type="text" maxlength="120"
                       class="min-w-0 flex-1 max-w-sm px-2 py-1 text-base font-semibold text-gray-900 border border-transparent hover:border-gray-200 focus:border-accent rounded-lg bg-transparent" />

                <div class="flex items-center gap-1.5 shrink-0">
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-semibold"
                          :class="flow.is_active ? 'bg-emerald-100 text-emerald-700' : 'bg-gray-100 text-gray-500'">
                        {{ flow.is_active ? '● Atendiendo' : 'Apagado' }}
                    </span>
                    <span class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-gray-100 text-gray-600">
                        {{ flow.version ? `v${flow.version} publicada` : 'Sin publicar' }}
                    </span>
                    <span v-if="dirty" class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-amber-100 text-amber-700">Sin guardar</span>
                    <span v-else-if="flow.has_unpublished_changes && flow.version" class="px-2 py-0.5 rounded-full text-[11px] font-medium bg-sky-100 text-sky-700">Borrador sin publicar</span>
                </div>

                <div class="ml-auto flex items-center gap-2 shrink-0">
                    <Link :href="route('flows.runs', flow.id)" class="px-3 py-1.5 text-xs font-medium text-gray-600 hover:text-gray-900 rounded-lg hover:bg-gray-100">Actividad</Link>
                    <button type="button" class="px-3 py-1.5 text-xs font-medium rounded-lg border"
                            :class="panel === 'simulator' ? 'border-sky-300 bg-sky-50 text-sky-700' : 'border-gray-200 text-gray-700 hover:bg-gray-50'"
                            @click="panel = panel === 'simulator' ? 'node' : 'simulator'">▶ Probar</button>
                    <button type="button" class="px-3 py-1.5 text-xs font-medium rounded-lg border border-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-50"
                            :disabled="!dirty || saving" @click="save()">
                        {{ saving ? 'Guardando…' : 'Guardar' }}
                    </button>
                    <button type="button" class="px-3 py-1.5 text-xs font-semibold rounded-lg bg-accent hover:bg-accent-hover text-white disabled:opacity-50"
                            :disabled="publishing || saving" @click="openPublish">Publicar</button>
                    <button type="button" :title="flow.is_active ? 'Apagar el flujo' : (flow.version ? 'Encender el flujo' : 'Publica primero')"
                            class="relative w-11 h-6 rounded-full transition-colors disabled:opacity-40"
                            :class="flow.is_active ? 'bg-emerald-500' : 'bg-gray-300'"
                            :disabled="!flow.version" @click="toggleActive">
                        <span class="absolute top-0.5 left-0.5 w-5 h-5 bg-white rounded-full shadow transition-transform" :class="flow.is_active ? 'translate-x-5' : ''"></span>
                    </button>
                </div>
            </div>

            <div class="flex flex-1 min-h-0">
                <!-- ═══ Paleta ═══ -->
                <aside class="w-52 shrink-0 bg-white border-r border-gray-200 overflow-y-auto p-3 space-y-1.5">
                    <p class="text-[11px] uppercase tracking-wide text-gray-400 font-semibold px-1 mb-2">Bloques</p>
                    <button v-for="item in PALETTE" :key="item.type" type="button" draggable="true"
                            class="w-full text-left rounded-xl border border-gray-200 px-3 py-2 hover:border-gray-300 hover:shadow-sm transition cursor-grab active:cursor-grabbing"
                            @dragstart="onDragStart($event, item.type)" @click="addNode(item.type)">
                        <span class="flex items-center gap-2">
                            <span class="w-2 h-2 rounded-full" :class="item.dot"></span>
                            <span class="text-sm font-medium text-gray-800">{{ item.label }}</span>
                        </span>
                        <span class="block text-[11px] text-gray-500 mt-0.5 pl-4">{{ item.hint }}</span>
                    </button>

                    <div class="pt-3 mt-3 border-t border-gray-100 text-[11px] text-gray-500 space-y-1.5 px-1">
                        <p>Arrastra un bloque al lienzo, o haz clic para agregarlo.</p>
                        <p>Para conectar, arrastra desde el punto de la <strong>derecha</strong> de una salida hasta otro bloque.</p>
                        <p>Doble clic en una conexión la borra. <kbd class="px-1 rounded bg-gray-100">Supr</kbd> borra el bloque seleccionado.</p>
                        <p><kbd class="px-1 rounded bg-gray-100">Ctrl</kbd>+<kbd class="px-1 rounded bg-gray-100">S</kbd> guarda.</p>
                    </div>
                </aside>

                <!-- ═══ Lienzo ═══ -->
                <div class="flex-1 min-w-0 relative bg-gray-50" @dragover.prevent @drop.prevent="onDrop">
                    <VueFlow
                        :id="FLOW_ID"
                        :nodes="initialNodes"
                        :edges="initialEdges"
                        :node-types="nodeTypes"
                        :is-valid-connection="isValidConnection"
                        :delete-key-code="['Delete', 'Backspace']"
                        :min-zoom="0.2"
                        :max-zoom="1.5"
                        :default-edge-options="EDGE_DEFAULTS"
                        :zoom-on-double-click="false"
                        class="h-full w-full"
                        @init="onFlowInit"
                    >
                        <Background pattern-color="#cbd5e1" :gap="20" />
                        <Controls position="bottom-left" />
                        <MiniMap position="bottom-right" pannable zoomable :width="150" :height="100" :node-stroke-width="3" />
                    </VueFlow>

                    <!-- Aviso flotante -->
                    <Transition enter-active-class="transition duration-200" enter-from-class="opacity-0 translate-y-2" leave-active-class="transition duration-150" leave-to-class="opacity-0">
                        <div v-if="toastMessage" class="absolute top-3 left-1/2 -translate-x-1/2 z-10 max-w-md px-4 py-2.5 rounded-xl shadow-lg text-sm font-medium text-white"
                             :class="toastType === 'error' ? 'bg-red-500' : 'bg-accent'">
                            {{ toastMessage }}
                        </div>
                    </Transition>
                </div>

                <!-- ═══ Panel lateral ═══ -->
                <aside class="w-[380px] shrink-0 bg-white border-l border-gray-200 flex flex-col min-h-0">
                    <div class="flex border-b border-gray-200 text-xs font-medium shrink-0">
                        <button v-for="tab in [
                                    { key: 'node', label: 'Bloque' },
                                    { key: 'review', label: 'Revisión' },
                                    { key: 'simulator', label: 'Probar' },
                                    { key: 'versions', label: 'Versiones' },
                                ]" :key="tab.key" type="button"
                                class="flex-1 py-2.5 border-b-2 transition"
                                :class="panel === tab.key ? 'border-accent text-accent' : 'border-transparent text-gray-500 hover:text-gray-800'"
                                @click="panel = tab.key">
                            {{ tab.label }}
                            <span v-if="tab.key === 'review' && issues.errors.length" class="ml-1 inline-flex items-center justify-center min-w-[16px] h-4 px-1 rounded-full bg-red-500 text-white text-[10px]">{{ issues.errors.length }}</span>
                        </button>
                    </div>

                    <!-- Bloque seleccionado / resumen del flujo -->
                    <div v-show="panel === 'node'" class="flex-1 min-h-0 overflow-y-auto p-4">
                        <NodeInspector
                            v-if="selectedNode"
                            :key="selectedNode.id"
                            :node="selectedNode"
                            :catalog="catalog"
                            :variables="variables"
                            :issues="issuesByNode[selectedNode.id] ?? []"
                            @delete="deleteSelected"
                            @duplicate="duplicateSelected"
                        />
                        <div v-else class="space-y-4">
                            <div>
                                <label class="block text-xs font-medium text-gray-700 mb-1">Para qué es este flujo</label>
                                <textarea v-model="description" rows="3" maxlength="500" placeholder="Ej. Atención fuera de horario: saldo, fallas y pagos."
                                          class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm resize-y"></textarea>
                            </div>
                            <div class="rounded-xl bg-gray-50 p-4 text-xs text-gray-600 space-y-2">
                                <p class="font-semibold text-gray-800">Cómo se trabaja</p>
                                <p><strong>1.</strong> Arma el flujo y guárdalo: es un borrador, tus clientes no lo ven.</p>
                                <p><strong>2.</strong> Pruébalo en <em>Probar</em>: conversas con él sin que salga nada por WhatsApp.</p>
                                <p><strong>3.</strong> <em>Publicar</em> lo revisa y crea una versión. Las conversaciones que ya estaban en curso terminan con la versión anterior.</p>
                                <p><strong>4.</strong> Enciéndelo con el interruptor de arriba. Mientras esté encendido, el bot clásico queda en pausa.</p>
                            </div>
                            <p class="text-xs text-gray-500">Haz clic en un bloque del lienzo para editarlo.</p>
                        </div>
                    </div>

                    <!-- Revisión -->
                    <div v-show="panel === 'review'" class="flex-1 min-h-0 overflow-y-auto p-4 space-y-3">
                        <div class="flex items-center justify-between">
                            <p class="text-sm font-semibold text-gray-900">Revisión del flujo</p>
                            <button type="button" class="text-xs text-accent font-medium hover:underline disabled:opacity-50" :disabled="validating" @click="validate()">
                                {{ validating ? 'Revisando…' : 'Revisar de nuevo' }}
                            </button>
                        </div>
                        <p v-if="!issues.errors.length && !issues.warnings.length" class="text-sm text-emerald-700 bg-emerald-50 rounded-xl px-3 py-2">
                            Todo en orden: el flujo se puede publicar.
                        </p>
                        <p v-for="(issue, i) in generalIssues" :key="'g' + i" class="text-xs rounded-lg px-3 py-2"
                           :class="issue.severity === 'error' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'">{{ issue.message }}</p>
                        <button v-for="(issue, i) in [...issues.errors, ...issues.warnings].filter(x => x.node_id)" :key="i" type="button"
                                class="w-full text-left text-xs rounded-lg px-3 py-2 hover:ring-1 hover:ring-gray-300"
                                :class="issue.severity === 'error' ? 'bg-red-50 text-red-700' : 'bg-amber-50 text-amber-700'"
                                @click="focusNode(issue.node_id)">
                            {{ issue.message }}
                            <span class="block text-[10px] opacity-70 mt-0.5">Ver el bloque →</span>
                        </button>
                        <p class="text-[11px] text-gray-500">Los errores impiden publicar. Las advertencias no, pero conviene mirarlas.</p>
                    </div>

                    <!-- Simulador -->
                    <div v-show="panel === 'simulator'" class="flex-1 min-h-0 flex flex-col p-4">
                        <FlowSimulator
                            :flow-id="flow.id"
                            :get-graph="currentGraph"
                            :catalog="catalog"
                            @active-node="activeNode = $event"
                            @visited="visitedNodes = $event"
                        />
                    </div>

                    <!-- Versiones -->
                    <div v-show="panel === 'versions'" class="flex-1 min-h-0 overflow-y-auto p-4 space-y-3">
                        <p class="text-sm font-semibold text-gray-900">Versiones publicadas</p>
                        <p v-if="flow.draft_source_version" class="text-xs text-sky-700 bg-sky-50 rounded-lg px-3 py-2">
                            El borrador viene de la versión {{ flow.draft_source_version }}. Al publicarlo quedará anotado.
                        </p>
                        <p v-if="!versions.length" class="text-xs text-gray-500">Todavía no publicaste este flujo.</p>
                        <div v-for="v in versions" :key="v.id" class="rounded-xl border p-3" :class="v.is_current ? 'border-emerald-200 bg-emerald-50/50' : 'border-gray-200'">
                            <div class="flex items-center justify-between">
                                <p class="text-sm font-semibold text-gray-900">
                                    Versión {{ v.version }}
                                    <span v-if="v.is_current" class="ml-1 text-[10px] font-semibold text-emerald-700">· la que atiende</span>
                                </p>
                                <button type="button" class="text-xs text-accent font-medium hover:underline" @click="restore(v)">Restaurar</button>
                            </div>
                            <p class="text-[11px] text-gray-500">{{ formatDate(v.published_at) }}<span v-if="v.published_by"> · {{ v.published_by }}</span></p>
                            <p v-if="v.note" class="text-xs text-gray-700 mt-1">{{ v.note }}</p>
                        </div>
                        <p class="text-[11px] text-gray-500">Restaurar copia esa versión al borrador. Para que vuelva a atender, publícala.</p>
                    </div>
                </aside>
            </div>
        </div>

        <!-- ═══ Publicar ═══ -->
        <div v-if="showPublish" class="fixed inset-0 z-[95] bg-black/40 flex items-center justify-center p-4" @click.self="showPublish = false">
            <div class="bg-white rounded-2xl shadow-xl w-full max-w-md p-6 space-y-4">
                <div>
                    <h3 class="text-lg font-semibold text-gray-900">Publicar la versión {{ nextVersion }}</h3>
                    <p class="text-sm text-gray-500 mt-1">
                        <template v-if="flow.is_active">El flujo está encendido: las conversaciones nuevas usan esta versión desde ya. Las que estén en curso terminan con la anterior.</template>
                        <template v-else>El flujo está apagado: después de publicar, enciéndelo para que empiece a atender.</template>
                    </p>
                </div>
                <p v-if="issues.warnings.length" class="text-xs text-amber-700 bg-amber-50 rounded-lg px-3 py-2">
                    Hay {{ issues.warnings.length }} advertencia(s) en la revisión. No impiden publicar.
                </p>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">¿Qué cambió? <span class="text-gray-400 font-normal">(opcional)</span></label>
                    <input v-model="publishNote" type="text" maxlength="255" placeholder="Ej. Agregué la opción de medios de pago"
                           class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm" />
                </div>
                <div class="flex justify-end gap-2">
                    <button type="button" class="px-4 py-2 text-sm text-gray-600 hover:text-gray-900" @click="showPublish = false">Cancelar</button>
                    <button type="button" class="px-4 py-2 text-sm font-semibold rounded-xl bg-accent hover:bg-accent-hover text-white disabled:opacity-60" :disabled="publishing" @click="publish">
                        {{ publishing ? 'Publicando…' : 'Publicar' }}
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>

<style>
/* El lienzo de Vue Flow: conexiones algo más visibles y la seleccionada en el color de la app. */
.vue-flow__edge-path {
    stroke: #94a3b8;
    stroke-width: 1.6;
}
.vue-flow__edge.selected .vue-flow__edge-path,
.vue-flow__edge:hover .vue-flow__edge-path {
    stroke: #00a884;
    stroke-width: 2.2;
}
.dark .vue-flow__minimap {
    background-color: #161b22;
}
.dark .vue-flow__controls-button {
    background: #1c2128;
    border-bottom-color: #30363d;
    fill: #b1bac4;
}
</style>
