<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import CarCard, { type CarCardData } from '@/components/marketplace/CarCard.vue';
import type { FilterOptions } from '@/components/marketplace/types';
import { useLocation } from '@/composables/useLocation';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ arrivals: CarCardData[]; carCount: number; lotCount: number; nearMe: boolean; options: FilterOptions }>();

const q = ref('');
const { location, locate, locating, error } = useLocation();

function search() {
    router.get(route('cars.index'), q.value ? { q: q.value } : {});
}

async function showNearMe() {
    const here = location.value ?? (await locate());
    if (here) router.get(route('home'), { lat: here.lat, lng: here.lng }, { preserveScroll: true, preserveState: true });
}

// Quick filters from the home design; "Within my budget" arrives with the budget tools (S6).
const chips = computed(() => [
    { label: 'SUVs', query: { body: ['suv'] } },
    { label: 'Under ₦10m', query: { price_max: 10000000 } },
    { label: 'Foreign used', query: { condition: ['foreign_used'] } },
    ...props.options.makes.slice(0, 4).map((m) => ({ label: m.name, query: { make: [m.id] } })),
]);
</script>

<template>
    <Head title="Find your next car at lots near you" />
    <CustomerLayout active="home">
        <section class="mx-auto flex max-w-6xl flex-col gap-5 px-5 pt-3 md:pt-12">
            <h1 class="max-w-xl text-[30px] leading-[1.1] font-bold tracking-tight md:text-5xl">Find your next car at lots near you</h1>
            <form class="flex max-w-xl gap-2" role="search" @submit.prevent="search">
                <label class="flex h-[52px] grow items-center gap-2.5 rounded-[14px] border border-line bg-white px-4">
                    <Icon name="search" :stroke-width="2" class="text-muted" />
                    <input v-model="q" type="search" class="w-full bg-transparent text-[15px] outline-none" placeholder="Search make, model or lot" aria-label="Search cars" enterkeyhint="search" />
                </label>
                <Link :href="route('cars.index')" aria-label="All filters" class="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-[14px] bg-forest text-white hover:text-white">
                    <Icon name="filters" :stroke-width="2" />
                </Link>
            </form>
            <div class="-mr-5 flex gap-2 overflow-x-auto pr-5">
                <Link
                    v-for="chip in chips"
                    :key="chip.label"
                    :href="route('cars.index', chip.query)"
                    class="flex h-9 shrink-0 items-center rounded-full border border-line bg-white px-3.5 text-[14px] text-ink no-underline hover:border-forest hover:text-forest"
                    >{{ chip.label }}</Link
                >
            </div>
        </section>

        <section class="mx-auto mt-7 flex max-w-6xl flex-col gap-3 px-5">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-xl font-bold">{{ nearMe ? 'Cars near you' : 'New arrivals' }}</h2>
                <div class="flex items-center gap-4">
                    <button v-if="!nearMe && arrivals.length" type="button" class="flex items-center gap-1 text-[14px] font-semibold text-forest" :disabled="locating" @click="showNearMe">
                        <Icon name="locate" :size="16" /> {{ locating ? 'Finding you…' : 'Near me' }}
                    </button>
                    <Link :href="route('cars.index')" class="text-[14px] font-medium">See all {{ carCount.toLocaleString('en-NG') }}</Link>
                </div>
            </div>
            <p v-if="error" class="text-[13px] text-danger" role="alert">{{ error }}</p>

            <div v-if="arrivals.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <CarCard v-for="car in arrivals" :key="car.ulid" :car="car" />
            </div>
            <div v-else class="card flex flex-col items-center gap-3 px-6 py-10 text-center">
                <div class="flex h-24 w-40 items-center justify-center rounded-2xl bg-sand"><CarGlyph /></div>
                <h3 class="text-xl font-bold">Cars are on their way</h3>
                <p class="max-w-sm text-[15px] text-muted">Lots near you are setting up their showrooms. New arrivals will show up here.</p>
            </div>
        </section>

        <section class="mx-auto mt-8 mb-10 max-w-6xl px-5">
            <div class="flex flex-col justify-between gap-4 rounded-2xl bg-forest p-6 text-white md:flex-row md:items-center">
                <div class="flex flex-col gap-2">
                    <span class="text-[13px] font-semibold tracking-wide text-peach uppercase">For car lots</span>
                    <h2 class="text-2xl font-bold">Own a car lot?</h2>
                    <p class="max-w-lg text-[15px] text-mist">
                        Put your stock online, get your own mini-site, and send buyers straight to your gate.
                        <template v-if="lotCount > 0"> {{ lotCount }} {{ lotCount === 1 ? 'lot is' : 'lots are' }} already on LotLink.</template>
                    </p>
                </div>
                <Link :href="route('dealer.home')" class="btn btn-primary shrink-0 self-start md:self-auto">List your lot</Link>
            </div>
        </section>
    </CustomerLayout>
</template>
