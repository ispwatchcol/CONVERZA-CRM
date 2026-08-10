<script setup>
/**
 * Manual dentro de la app (requiere sesión). El contenido vive en
 * Components/ManualContent.vue, compartido con la versión pública /ayuda,
 * para que no haya dos textos que mantener por separado.
 */
import AppLayout from '@/Layouts/AppLayout.vue';
import ManualContent from '@/Components/ManualContent.vue';
import { Head } from '@inertiajs/vue3';
</script>

<template>
    <Head title="Manual de Usuario" />

    <AppLayout>
        <ManualContent />
    </AppLayout>
</template>
