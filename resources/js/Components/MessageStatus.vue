<script setup>
/**
 * Indicador de estado de un mensaje SALIENTE.
 *
 * Existe por un fallo silencioso real: WhatsApp acepta el envío (200 + wamid) y
 * recién después reporta `failed` por webhook — típicamente porque pasaron más
 * de 24 h desde el último mensaje del cliente. Hasta ahora el chat pintaba el
 * MISMO ✓ azul para `sent`, `delivered`, `read` y `failed`, así que el asesor
 * daba por entregado algo que el cliente nunca recibió, y el cliente daba por
 * ignorada una pregunta que sí había hecho.
 *
 * Regla: un mensaje fallido NUNCA puede parecerse a uno entregado.
 */
defineProps({
    status: { type: String, default: null },
    /** Motivo legible del fallo, ya traducido en el backend. */
    reason: { type: String, default: null },
    /** 'sm' para las burbujas compactas (sticker, reacción, overlay de imagen). */
    size:   { type: String, default: 'base' },
    /** El icono va sobre un fondo oscuro (overlay de imagen/video). */
    onDark: { type: Boolean, default: false },
});

const LABELS = {
    read:      'Leído',
    delivered: 'Entregado',
    sent:      'Enviado',
};
</script>

<template>
    <span
        v-if="status === 'failed'"
        class="inline-flex items-center shrink-0"
        :title="reason || 'No se entregó: el cliente no recibió este mensaje.'"
    >
        <svg
            class="shrink-0"
            :class="[size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5', onDark ? 'text-red-300' : 'text-red-500']"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.2"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
        </svg>
    </span>

    <span v-else class="inline-flex items-center shrink-0" :title="LABELS[status] || 'Enviado'">
        <svg
            class="shrink-0"
            :class="[
                size === 'sm' ? 'h-3 w-3' : 'h-3.5 w-3.5',
                onDark ? 'text-blue-300' : (status === 'read' ? 'text-blue-500' : 'text-gray-400'),
            ]"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"
        >
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
    </span>
</template>
