<script setup lang="ts">
import { useShared } from '@/composables/useShared';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

// An admin using "Log in as" for support (TDD M17) sees who they are and how to go back. It sits in
// the page flow (sticky) rather than over it, so nothing underneath is hidden.
const { user } = useShared();
const impersonating = computed(() => !!usePage().props.impersonating);
</script>

<template>
    <div v-if="impersonating" class="sticky top-0 z-[60] flex h-11 items-center justify-center gap-3 bg-clay px-4 text-[13px] font-semibold text-white" role="status">
        <span class="truncate">Support view: you're logged in as {{ user?.name ?? 'this user' }}.</span>
        <button type="button" class="min-h-8 shrink-0 rounded-lg bg-white/15 px-2.5 hover:bg-white/25" @click="router.post(route('impersonation.stop'))">Back to admin</button>
    </div>
</template>
