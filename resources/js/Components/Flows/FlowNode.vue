<script setup>
// Tarjeta de un bloque en el lienzo. Una sola componente para todos los tipos:
// lo que cambia (color, resumen, salidas) sale de nodeCatalog.js.
import { computed, inject, ref } from 'vue';
import { Handle, Position } from '@vue-flow/core';
import { NODE_TYPES, outputsOf, summaryOf } from './nodeCatalog';

const props = defineProps({
    id: { type: String, required: true },
    type: { type: String, required: true },
    data: { type: Object, default: () => ({}) },
    selected: { type: Boolean, default: false },
});

// Los provee Editor.vue: problemas del validador por bloque, el bloque donde
// está parado el simulador y las etiquetas/equipos para los resúmenes.
const issues = inject('flowIssues', ref({}));
const activeNode = inject('flowActiveNode', ref(null));
const visitedNodes = inject('flowVisitedNodes', ref([]));
const catalog = inject('flowCatalog', {});

const meta = computed(() => NODE_TYPES[props.type] ?? { label: props.type, header: 'bg-gray-100 text-gray-700 border-gray-200', handle: '!bg-gray-500' });
const outputs = computed(() => outputsOf(props.type, props.data));
const summary = computed(() => summaryOf(props.type, props.data, catalog));
const nodeIssues = computed(() => issues.value?.[props.id] ?? []);
const hasError = computed(() => nodeIssues.value.some((i) => i.severity === 'error'));
const hasWarning = computed(() => !hasError.value && nodeIssues.value.length > 0);
const isActive = computed(() => activeNode.value === props.id);
const wasVisited = computed(() => !isActive.value && visitedNodes.value.includes(props.id));
</script>

<template>
    <div
        class="w-[250px] rounded-xl border bg-white shadow-sm transition-shadow"
        :class="[
            selected ? 'ring-2 ring-accent shadow-md' : '',
            hasError ? 'border-red-400' : hasWarning ? 'border-amber-300' : 'border-gray-200',
            isActive ? 'ring-4 ring-sky-400 animate-pulse' : '',
            wasVisited ? 'ring-2 ring-sky-200' : '',
        ]"
    >
        <Handle
            v-if="type !== 'start'"
            type="target"
            :position="Position.Left"
            class="!w-3 !h-3 !border-2 !border-white !bg-gray-400"
        />

        <div class="flex items-center gap-2 px-3 py-2 rounded-t-xl border-b text-xs font-semibold" :class="meta.header">
            <span class="w-2 h-2 rounded-full shrink-0" :class="meta.dot"></span>
            <span class="truncate">{{ meta.label }}</span>
            <span v-if="hasError" class="ml-auto inline-flex items-center justify-center w-4 h-4 rounded-full bg-red-500 text-white text-[10px]" :title="nodeIssues.map(i => i.message).join('\n')">!</span>
            <span v-else-if="hasWarning" class="ml-auto inline-flex items-center justify-center w-4 h-4 rounded-full bg-amber-400 text-white text-[10px]" :title="nodeIssues.map(i => i.message).join('\n')">!</span>
            <span v-else-if="type === 'start'" class="ml-auto text-[10px] font-normal opacity-70">disparador</span>
        </div>

        <p class="px-3 py-2 text-[11px] leading-snug text-gray-600 whitespace-pre-line break-words line-clamp-4">
            {{ summary }}
        </p>

        <div v-if="outputs.length" class="border-t border-gray-100 py-1">
            <div
                v-for="out in outputs"
                :key="out.handle"
                class="relative flex items-center justify-end gap-1 pl-3 pr-4 py-1 text-[11px] text-gray-500"
            >
                <span class="truncate" :title="out.label">{{ out.label }}</span>
                <Handle
                    :id="out.handle"
                    type="source"
                    :position="Position.Right"
                    class="!w-3 !h-3 !border-2 !border-white"
                    :class="meta.handle"
                />
            </div>
        </div>
    </div>
</template>
