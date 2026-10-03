<script setup lang="ts">
withDefaults(defineProps<{ inverse?: boolean; size?: 'sm' | 'md' | 'lg' }>(), { inverse: false, size: 'md' });
</script>

<template>
    <span
        class="logo"
        :class="[
            inverse ? 'text-white' : 'text-forest',
            { 'text-[22px]': size === 'sm', 'text-2xl': size === 'md', 'text-[26px]': size === 'lg' },
        ]"
        >Car<span :class="inverse ? 'text-clay-light' : 'text-clay'">Yard</span></span
    >
</template>
