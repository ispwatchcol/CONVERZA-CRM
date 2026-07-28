<script setup>
import { computed } from 'vue';

const props = defineProps({
    text:      { type: String, default: '' },
    linkClass: { type: String, default: 'text-blue-600 underline decoration-blue-300 hover:text-blue-800 break-all' },
});

// URL (http/https o www.) o correo electrónico.
// El último carácter de la URL no puede ser puntuación, para que "mirá example.com/a."
// no se lleve el punto final dentro del enlace.
const LINK_RE = /((?:https?:\/\/|www\.)[^\s<]+[^\s<.,:;"')\]}!?]|[\w.+-]+@[\w-]+\.[\w.-]+)/gi;

// Se devuelve una lista de segmentos y se renderiza con <a> en el template en
// lugar de usar v-html: el texto lo escribe el cliente desde WhatsApp, así que
// inyectar HTML crudo sería un XSS.
const segments = computed(() => {
    const s = String(props.text ?? '');
    if (!s) return [];

    const out = [];
    let last = 0;

    for (const match of s.matchAll(LINK_RE)) {
        const raw = match[0];
        if (match.index > last) out.push({ text: s.slice(last, match.index) });

        const isUrl = /^https?:\/\//i.test(raw);
        out.push({
            text: raw,
            // Un dominio suelto ("www.foo.com") necesita esquema o el navegador lo
            // trataría como ruta relativa del CRM.
            href: isUrl ? raw : (raw.includes('@') ? `mailto:${raw}` : `https://${raw}`),
        });

        last = match.index + raw.length;
    }

    if (last < s.length) out.push({ text: s.slice(last) });
    return out;
});
</script>

<template><span><template v-for="(seg, i) in segments" :key="i"><a v-if="seg.href" :href="seg.href" target="_blank" rel="noopener noreferrer nofollow" :class="linkClass" @click.stop>{{ seg.text }}</a><template v-else>{{ seg.text }}</template></template></span></template>
