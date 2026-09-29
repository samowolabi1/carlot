<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import { useCompare } from '@/composables/useCompare';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

type Cell = { value: string | number | null; best: boolean };
const props = defineProps<{ cars: (CarCardData & { rows: Record<string, Cell> })[]; terms: { deposit_percent: number; months: number; rate: number } }>();

const compare = useCompare();

const rows: { key: string; label: string }[] = [
    { key: 'price', label: 'Price' },
    { key: 'monthly', label: 'Monthly (estimate)' },
    { key: 'year', label: 'Year' },
    { key: 'mileage', label: 'Mileage' },
    { key: 'engine', label: 'Engine' },
    { key: 'body', label: 'Body' },
    { key: 'gearbox', label: 'Gearbox' },
    { key: 'fuel', label: 'Fuel' },
    { key: 'condition', label: 'Condition' },
    { key: 'duty', label: 'Duty' },
    { key: 'inspection', label: 'Inspection report' },
    { key: 'lot', label: 'Lot' },
];

function remove(ulid: string) {
    compare.toggle(ulid);
    router.get(route('compare', { ids: props.cars.filter((c) => c.ulid !== ulid).map((c) => c.ulid).join(',') }));
}
</script>

<template>
    <Head title="Compare cars" />
    <CustomerLayout active="search">
        <div class="mx-auto flex max-w-4xl flex-col gap-4 px-5 py-4 md:py-8">
            <div class="flex items-center gap-2">
                <Link :href="route('cars.index')" aria-label="Back to search" class="-ml-2.5 flex h-11 w-11 items-center justify-center text-ink"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></Link>
                <h1 class="text-[22px] font-bold md:text-3xl">Compare</h1>
            </div>

            <div v-if="cars.length < 2" class="card flex flex-col items-center gap-2 px-6 py-10 text-center">
                <h2 class="text-xl font-bold">Pick two or three cars</h2>
                <p class="max-w-sm text-[15px] text-muted">Tick "Compare" on cars in your search results to see them side by side.</p>
                <Link :href="route('cars.index')" class="btn btn-primary mt-2">Browse cars</Link>
            </div>

            <div v-else class="card overflow-x-auto p-3.5">
                <table class="w-full min-w-[340px] table-fixed text-left">
                    <caption class="sr-only">Cars side by side</caption>
                    <thead>
                        <tr>
                            <th class="w-0 p-0"><span class="sr-only">Detail</span></th>
                            <th v-for="car in cars" :key="car.ulid" class="px-1.5 pb-2 align-top font-normal">
                                <Link :href="car.url" class="flex flex-col gap-1.5 text-ink no-underline">
                                    <span class="flex aspect-[4/3] items-center justify-center overflow-hidden rounded-[10px] bg-sand">
                                        <img v-if="car.image" :src="car.image.src" alt="" class="h-full w-full object-cover" />
                                        <CarGlyph v-else :width="56" />
                                    </span>
                                    <span class="text-[13px] leading-tight font-semibold">{{ car.title }}</span>
                                </Link>
                                <button type="button" class="mt-1 text-[12px] font-semibold text-muted hover:text-danger" @click="remove(car.ulid)">Remove</button>
                            </th>
                        </tr>
                    </thead>
                    <tbody>
                        <template v-for="row in rows" :key="row.key">
                            <tr>
                                <th :colspan="cars.length + 1" scope="colgroup" class="border-t border-divider px-1.5 pt-2.5 pb-0.5 text-[12px] font-normal text-muted">{{ row.label }}</th>
                            </tr>
                            <tr>
                                <td class="w-0 p-0" />
                                <td v-for="car in cars" :key="car.ulid" class="px-1.5 pb-1 text-[14px] font-semibold" :class="car.rows[row.key]?.best ? 'text-success' : ''">
                                    {{ car.rows[row.key]?.value ?? '—' }}<span v-if="car.rows[row.key]?.best" class="sr-only"> (best)</span>
                                </td>
                            </tr>
                        </template>
                    </tbody>
                </table>
            </div>
            <p v-if="cars.length >= 2" class="text-[12px] text-muted">Green marks the best value in each row: lowest price and mileage, newest year. Monthly figures assume {{ terms.deposit_percent }}% down over {{ terms.months }} months at {{ terms.rate }}% a year.</p>
        </div>
    </CustomerLayout>
</template>
