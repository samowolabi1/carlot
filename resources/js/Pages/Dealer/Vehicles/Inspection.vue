<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InspectionForm, { type ChecklistGroup } from '@/components/trust/InspectionForm.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

type Result = 'pass' | 'advisory' | 'fail';

defineProps<{
    vehicle: { ulid: string; title: string; status: string };
    groups: ChecklistGroup[];
    results: { value: string; label: string }[];
    previous: {
        checklist: Record<string, { status: Result; note?: string | null }>;
        summary: string | null;
        report: { score: number; label: string; inspector: string; date: string; independent: boolean; pdf_url: string };
    } | null;
    maxPhotos: number;
}>();

const { currentLot, user } = useShared();
const lot = computed(() => currentLot.value!);
</script>

<template>
    <Head :title="`Inspection · ${vehicle.title}`" />
    <DealerLayout>
        <Link :href="route('dealer.vehicles.index', lot.slug)" class="inline-flex h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline">
            <Icon name="chevronLeft" :size="18" /> Stock
        </Link>
        <div>
            <p class="text-[14px] font-semibold text-muted">40-point inspection</p>
            <h1 class="text-[28px] font-bold">{{ vehicle.title }}</h1>
            <p class="text-[14px] text-muted">{{ vehicle.status }} · buyers see the score, any advisories or fails, the photos and a PDF on the car page</p>
        </div>

        <div v-if="previous" class="flex flex-wrap items-center gap-3 rounded-xl bg-map px-4 py-3 text-[14px] text-forest">
            <Icon name="clipboard" :size="20" />
            <span class="grow">
                Current report: <strong>{{ previous.report.score }}/100</strong>, {{ previous.report.label.toLowerCase() }} ({{ previous.report.inspector }}, {{ previous.report.date }}).
                <template v-if="previous.report.independent"> A new check by your team is kept in the history but doesn't replace an independent report.</template>
            </span>
            <a :href="previous.report.pdf_url" target="_blank" rel="noopener" class="font-semibold">Open PDF</a>
        </div>

        <div class="max-w-3xl">
            <InspectionForm
                :groups="groups"
                :results="results"
                :previous="previous"
                :max-photos="maxPhotos"
                :action="route('dealer.vehicles.inspection.store', [lot.slug, vehicle.ulid])"
                ask-name
                :default-name="user?.name ?? ''"
                submit-label="Save inspection"
            />
        </div>
    </DealerLayout>
</template>
