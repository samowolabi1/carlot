<script setup lang="ts">
defineProps<{ message?: string }>();
</script>

<template>
    <p v-if="message" class="text-[13px] font-medium text-danger" role="alert">{{ message }}</p>
</template>
