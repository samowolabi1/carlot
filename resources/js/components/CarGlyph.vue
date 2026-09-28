<script setup lang="ts">
withDefaults(defineProps<{ width?: number }>(), { width: 96 });
</script>

<template>
    <svg :width="width" :height="width / 2" viewBox="0 0 64 32" aria-hidden="true" fill="none" stroke="#A39C90" stroke-width="1.6" stroke-linecap="round" stroke-linejoin="round">
        <path d="M4 22v-5l6-2 8-8h22l10 8 8 2v5" />
        <circle cx="16" cy="23" r="5" />
        <circle cx="48" cy="23" r="5" />
        <path d="M21 23h22" />
    </svg>
</template>
