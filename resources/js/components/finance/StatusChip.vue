<script setup lang="ts">
/** A car loan application's status. Tones come from FinanceStatus::tone(). */
defineProps<{ tone: string; label: string }>();

const tones: Record<string, string> = {
    waiting: 'bg-sand text-ink',
    action: 'bg-cream text-clay-dark',
    good: 'bg-[#DCEFE3] text-[#166534]',
    closed: 'bg-divider text-muted',
    bad: 'bg-[#FDECEC] text-danger',
};
</script>

<template>
    <span class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="tones[tone] ?? tones.waiting">{{ label }}</span>
</template>
