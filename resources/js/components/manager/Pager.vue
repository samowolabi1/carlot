<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{ links: { url: string | null; label: string; active: boolean }[]; lastPage: number }>();
</script>

<template>
    <nav v-if="lastPage > 1" aria-label="Pages" class="flex flex-wrap gap-1 border-t border-divider p-3">
        <template v-for="link in links" :key="link.label">
            <Link
                v-if="link.url"
                :href="link.url"
                preserve-scroll
                class="flex h-11 min-w-11 items-center justify-center rounded-lg px-2.5 text-[14px] no-underline"
                :class="link.active ? 'bg-forest text-white' : 'text-ink hover:bg-ivory'"
                ><span v-html="link.label"
            /></Link>
        </template>
    </nav>
</template>
