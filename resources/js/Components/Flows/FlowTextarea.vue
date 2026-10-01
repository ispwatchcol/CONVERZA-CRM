<script setup>
// Un textarea que sabe insertar {{variables}} donde está el cursor. Es la
// forma de que el admin no tenga que adivinar cómo se escribe una variable.
import { computed, nextTick, ref } from 'vue';

const props = defineProps({
    modelValue: { type: String, default: '' },
    variables: { type: Array, default: () => [] }, // [{ name, description, group }]
    rows: { type: Number, default: 4 },
    placeholder: { type: String, default: '' },
    maxlength: { type: Number, default: 4096 },
});

const emit = defineEmits(['update:modelValue']);

const textarea = ref(null);
const open = ref(false);
const filter = ref('');

const groups = computed(() => {
    const term = filter.value.trim().toLowerCase();
    const out = {};
    for (const v of props.variables) {
        if (term && !v.name.toLowerCase().includes(term) && !(v.description ?? '').toLowerCase().includes(term)) continue;
        (out[v.group] ??= []).push(v);
    }
    return out;
});

const length = computed(() => (props.modelValue ?? '').length);

// Se arma en JS: en la plantilla, un '}}' literal cerraría la interpolación.
const token = (name) => '{' + '{' + name + '}' + '}';

async function insert(name) {
    const el = textarea.value;
    const value = props.modelValue ?? '';
    const value_token = token(name);
    const start = el?.selectionStart ?? value.length;
    const end = el?.selectionEnd ?? value.length;

    emit('update:modelValue', value.slice(0, start) + value_token + value.slice(end));
    open.value = false;
    filter.value = '';

    await nextTick();
    if (el) {
        el.focus();
        el.selectionStart = el.selectionEnd = start + value_token.length;
    }
}
</script>

<template>
    <div class="relative">
        <textarea
            ref="textarea"
            :value="modelValue"
            :rows="rows"
            :placeholder="placeholder"
            :maxlength="maxlength"
            class="w-full px-3 py-2 border border-gray-200 rounded-xl text-sm focus:ring-2 focus:ring-accent/30 focus:border-accent resize-y"
            @input="emit('update:modelValue', $event.target.value)"
        />
        <div class="flex items-center justify-between mt-1">
            <button
                v-if="variables.length"
                type="button"
                class="text-xs font-medium text-accent hover:underline"
                @click="open = !open"
            >
                {{ open ? 'Cerrar variables' : '{ } Insertar variable' }}
            </button>
            <span class="text-[10px] text-gray-400 ml-auto">{{ length }}/{{ maxlength }}</span>
        </div>

        <div v-if="open" class="mt-1 rounded-xl border border-gray-200 bg-white shadow-lg max-h-64 overflow-y-auto z-20">
            <input
                v-model="filter"
                type="text"
                placeholder="Buscar variable…"
                class="w-full px-3 py-2 text-xs border-0 border-b border-gray-100 focus:ring-0"
            />
            <div v-for="(items, group) in groups" :key="group" class="py-1">
                <p class="px-3 pt-1 pb-0.5 text-[10px] uppercase tracking-wide text-gray-400 font-semibold">{{ group }}</p>
                <button
                    v-for="v in items"
                    :key="v.name"
                    type="button"
                    class="w-full text-left px-3 py-1.5 hover:bg-gray-50 flex flex-col"
                    @click="insert(v.name)"
                >
                    <code class="text-xs text-accent" v-text="token(v.name)"></code>
                    <span class="text-[11px] text-gray-500">{{ v.description }}</span>
                </button>
            </div>
            <p v-if="!Object.keys(groups).length" class="px-3 py-2 text-xs text-gray-400">Sin resultados.</p>
        </div>
    </div>
</template>
