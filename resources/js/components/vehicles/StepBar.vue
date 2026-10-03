<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

defineProps<{
    steps: { key: string; label: string; href?: string }[];
    current: string;
    /** Index of the furthest step reached; later ones show as not done. */
    reached: number;
}>();
</script>

<template>
    <ol aria-label="Steps" class="grid grid-cols-4 gap-1.5">
        <li v-for="(step, i) in steps" :key="step.key" :aria-current="step.key === current ? 'step' : undefined">
            <component
                :is="step.href ? Link : 'span'"
                :href="step.href"
                class="flex min-h-11 flex-col gap-1.5 no-underline"
            >
                <span class="h-1 rounded-full" :class="step.key === current ? 'bg-clay' : i <= reached ? 'bg-forest' : 'bg-[#DDD7CC]'" />
                <span
                    class="text-[11px] leading-tight"
                    :class="step.key === current ? 'font-semibold text-clay' : i <= reached ? 'font-semibold text-forest' : 'text-muted'"
                    >{{ step.label }}</span
                >
            </component>
        </li>
    </ol>
</template>
