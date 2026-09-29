<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { ref } from 'vue';

export type InspectionData = {
    ulid: string;
    score: number;
    independent: boolean;
    label: string;
    inspector: string;
    date: string | null;
    summary: string | null;
    groups: { key: string; label: string; result: 'pass' | 'advisory' | 'fail'; result_label: string; issues: { label: string; result: 'advisory' | 'fail'; note: string | null }[] }[];
    photos: { item: string; label: string; url: string }[];
    pdf_url: string;
};

defineProps<{ inspection: InspectionData }>();

const open = ref(false);
const tone = { pass: 'bg-map text-forest', advisory: 'bg-[#FDF1DC] text-[#8A5A0B]', fail: 'bg-[#FDECEC] text-danger' } as const;
</script>

<template>
    <section class="card flex flex-col gap-3 p-4" aria-labelledby="inspection-heading">
        <div class="flex items-start justify-between gap-3">
            <div class="flex flex-col gap-0.5">
                <h2 id="inspection-heading" class="font-sans text-[16px] font-bold">Inspection report</h2>
                <span class="flex items-center gap-1 text-[13px] text-muted">
                    <Icon :name="inspection.independent ? 'shield' : 'clipboard'" :size="15" class="text-forest" :stroke-width="2" />
                    {{ inspection.label }} · {{ inspection.inspector }} · {{ inspection.date }}
                </span>
            </div>
            <span class="shrink-0 text-right">
                <span class="font-display text-[26px] leading-none font-bold text-forest">{{ inspection.score }}</span><span class="text-[14px] text-muted"> / 100</span>
            </span>
        </div>

        <p v-if="inspection.summary" class="text-[14px]">{{ inspection.summary }}</p>

        <ul class="flex flex-col">
            <li v-for="group in inspection.groups" :key="group.key" class="flex flex-col gap-1 border-t border-divider py-2 first:border-t-0">
                <div class="flex items-center justify-between gap-2 text-[14px]">
                    <span>{{ group.label }}</span>
                    <span class="rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="tone[group.result]">{{ group.result_label }}</span>
                </div>
                <ul v-if="group.issues.length && (open || group.result === 'fail')" class="flex flex-col gap-0.5 pl-3 text-[13px] text-[#4A4D53]">
                    <li v-for="issue in group.issues" :key="issue.label">
                        <strong :class="issue.result === 'fail' ? 'text-danger' : 'text-[#8A5A0B]'">{{ issue.result === 'fail' ? 'Fail' : 'Advisory' }}:</strong>
                        {{ issue.label }}<template v-if="issue.note"> — {{ issue.note }}</template>
                    </li>
                </ul>
            </li>
        </ul>

        <div v-if="open && inspection.photos.length" class="grid grid-cols-3 gap-2">
            <a v-for="p in inspection.photos" :key="p.url" :href="p.url" target="_blank" rel="noopener" class="block overflow-hidden rounded-lg">
                <img :src="p.url" :alt="p.label" loading="lazy" class="aspect-[4/3] w-full object-cover" />
            </a>
        </div>

        <div class="flex flex-wrap items-center gap-x-4 gap-y-1 text-[14px] font-semibold">
            <button v-if="inspection.groups.some((g) => g.issues.length) || inspection.photos.length" type="button" class="min-h-11 text-clay" :aria-expanded="open" @click="open = !open">
                {{ open ? 'Show less' : 'All notes and photos' }}
            </button>
            <a :href="inspection.pdf_url" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-1"><Icon name="download" :size="16" /> Full 40-point report (PDF)</a>
        </div>
    </section>
</template>
