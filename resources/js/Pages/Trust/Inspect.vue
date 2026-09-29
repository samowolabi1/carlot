<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import InspectionForm, { type ChecklistGroup } from '@/components/trust/InspectionForm.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

type Result = 'pass' | 'advisory' | 'fail';

defineProps<{
    car: CarCardData;
    inspector: string;
    groups: ChecklistGroup[];
    results: { value: string; label: string }[];
    previous: { checklist: Record<string, { status: Result; note?: string | null }>; summary: string | null } | null;
    maxPhotos: number;
}>();
</script>

<template>
    <Head :title="`Inspect ${car.title}`" />
    <CustomerLayout bare>
        <div class="mx-auto flex w-full max-w-3xl flex-col gap-4 px-4 py-5">
            <Link :href="car.url" class="inline-flex h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline"><Icon name="chevronLeft" :size="18" /> Back to the car</Link>
            <div class="flex items-center gap-3">
                <img v-if="car.image" :src="car.image.src" alt="" class="h-14 w-20 shrink-0 rounded-[10px] object-cover" />
                <span v-else class="flex h-14 w-20 shrink-0 items-center justify-center rounded-[10px] bg-sand"><CarGlyph :width="44" /></span>
                <div class="flex min-w-0 flex-col">
                    <h1 class="truncate text-[22px] font-bold">{{ car.title }}</h1>
                    <span class="truncate text-[14px] text-muted">{{ car.lot.name }}<template v-if="car.lot.city">, {{ car.lot.city }}</template></span>
                </div>
            </div>
            <p class="rounded-xl bg-map px-4 py-3 text-[14px] text-forest">
                <Icon name="shield" :size="18" class="mr-1 inline align-[-3px]" /> You're signing this report as <strong>{{ inspector }}</strong>. The car will show
                <strong>Independently inspected</strong>, and the lot can't replace your report with their own.
            </p>
            <InspectionForm :groups="groups" :results="results" :previous="previous" :max-photos="maxPhotos" :action="route('inspector.store', car.ulid)" submit-label="Sign and publish report" />
        </div>
    </CustomerLayout>
</template>
