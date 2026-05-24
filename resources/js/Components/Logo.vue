<script setup>
defineProps({
    size: { type: String, default: 'md' }, // sm | md | lg
});

const sizeMap = {
    sm: { box: 'w-9 h-9', icon: 'w-5 h-5' },
    md: { box: 'w-10 h-10', icon: 'w-5 h-5' },
    lg: { box: 'w-11 h-11', icon: 'w-6 h-6' },
};
</script>

<template>
    <div
        :class="[sizeMap[size]?.box ?? sizeMap.md.box, 'rounded-xl bg-accent flex items-center justify-center shrink-0 shadow-sm']"
    >
        <svg
            :class="sizeMap[size]?.icon ?? sizeMap.md.icon"
            class="text-white"
            fill="none"
            viewBox="0 0 24 24"
            stroke="currentColor"
            stroke-width="2"
        >
            <path
                stroke-linecap="round"
                stroke-linejoin="round"
                d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"
            />
        </svg>
    </div>
</template>
