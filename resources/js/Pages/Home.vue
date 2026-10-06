<script setup lang="ts">
import LiveSearchInput from '@/components/marketplace/LiveSearchInput.vue';
import HomeBanners from '@/components/marketplace/HomeBanners.vue';
import type { AdBanner } from '@/lib/ads';
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import CarCard, { type CarCardData } from '@/components/marketplace/CarCard.vue';
import type { FilterOptions } from '@/components/marketplace/types';
import { useBudget } from '@/composables/useBudget';
import { useLocation } from '@/composables/useLocation';
import { usePwaInstall } from '@/composables/usePwaInstall';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { formatNaira } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface FeaturedLot {
    slug: string;
    url: string;
    name: string;
    initials: string;
    logo_url: string | null;
    city: string | null;
    verified: boolean;
    cars: number;
}

const props = defineProps<{
    arrivals: CarCardData[];
    spotlight: CarCardData[];
    featuredLots: FeaturedLot[];
    banners: AdBanner[];
    carCount: number;
    lotCount: number;
    nearMe: boolean;
    options: FilterOptions;
}>();

const q = ref('');
const { location, locate, locating, error } = useLocation();

function search() {
    router.get(route('cars.index'), q.value ? { q: q.value } : {});
}

async function showNearMe() {
    const here = location.value ?? (await locate());
    if (here) router.get(route('home'), { lat: here.lat, lng: here.lng }, { preserveScroll: true, preserveState: true });
}

const budget = useBudget();
const install = usePwaInstall();

// Quick filters from the home design. "Within my budget" goes to the calculator until a budget is set.
const chips = computed(() => [
    budget.maxPrice.value !== null
        ? { label: 'Within my budget', href: route('cars.index', { price_max: budget.maxPrice.value }) }
        : { label: 'What can I afford?', href: route('budget') },
    { label: 'SUVs', href: route('cars.index', { body: ['suv'] }) },
    { label: 'Under ₦10m', href: route('cars.index', { price_max: 10000000 }) },
    { label: 'Foreign used', href: route('cars.index', { condition: ['foreign_used'] }) },
    ...[...props.options.makes].sort((a, b) => b.count - a.count).slice(0, 4).map((m) => ({ label: m.name, href: route('cars.index', { make: [m.id] }) })),
]);
</script>

<template>
    <Head title="Find your next car at sellers near you" />
    <CustomerLayout active="home">
        <section class="mx-auto flex max-w-6xl flex-col gap-5 px-5 pt-3 md:pt-12">
            <h1 class="max-w-xl text-[30px] leading-[1.1] font-bold tracking-tight md:text-5xl">Find your next car at sellers near you</h1>
            <form class="flex max-w-xl gap-2" role="search" @submit.prevent="search">
                <div class="relative flex h-[52px] grow items-center gap-2.5 rounded-[14px] border border-line bg-white px-4 focus-within:border-forest">
                    <Icon name="search" :stroke-width="2" class="text-muted" />
                    <LiveSearchInput v-model="q" @submit="search" />
                </div>
                <Link :href="route('cars.index')" aria-label="All filters" class="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-[14px] bg-forest text-white hover:text-white">
                    <Icon name="filters" :stroke-width="2" />
                </Link>
            </form>
            <div class="-mr-5 flex gap-2 overflow-x-auto pr-5">
                <Link
                    v-for="chip in chips"
                    :key="chip.label"
                    :href="chip.href"
                    class="flex h-9 shrink-0 items-center rounded-full border border-line bg-white px-3.5 text-[14px] text-ink no-underline hover:border-forest hover:text-forest tap"
                    >{{ chip.label }}</Link
                >
            </div>
        </section>

        <div v-if="banners.length" class="mx-auto mt-6 max-w-6xl px-5">
            <HomeBanners :banners="banners" />
        </div>

        <section v-if="spotlight.length" class="mx-auto mt-7 flex max-w-6xl flex-col gap-3 px-5" aria-labelledby="spotlight-heading">
            <div class="flex items-baseline justify-between gap-3">
                <h2 id="spotlight-heading" class="text-xl font-bold">Spotlight</h2>
                <Link :href="route('cars.index')" class="inline-flex min-h-11 items-center text-[14px] font-medium">See all</Link>
            </div>
            <div class="-mr-5 flex snap-x gap-3 overflow-x-auto pr-5 pb-1 [scrollbar-width:none]">
                <div v-for="car in spotlight" :key="car.ulid" class="w-[290px] shrink-0 snap-start"><CarCard :car="car" /></div>
            </div>
        </section>

        <section v-if="featuredLots.length" class="mx-auto mt-7 flex max-w-6xl flex-col gap-3 px-5" aria-labelledby="featured-heading">
            <h2 id="featured-heading" class="text-xl font-bold">Featured sellers</h2>
            <div class="-mr-5 flex gap-3 overflow-x-auto pr-5 pb-1 [scrollbar-width:none]">
                <Link v-for="lot in featuredLots" :key="lot.slug" :href="lot.url" class="card flex w-[240px] shrink-0 items-center gap-3 p-3 text-ink no-underline hover:border-forest">
                    <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-12 w-12 rounded-xl object-cover" />
                    <span v-else class="flex h-12 w-12 items-center justify-center rounded-xl bg-forest font-display text-[16px] font-bold text-white">{{ lot.initials }}</span>
                    <span class="flex min-w-0 flex-col">
                        <span class="flex items-center gap-1 truncate text-[15px] font-semibold">{{ lot.name }}<Icon v-if="lot.verified" name="shield" :size="15" class="shrink-0 text-forest" /></span>
                        <span class="truncate text-[13px] text-muted">{{ lot.cars }} {{ lot.cars === 1 ? 'car' : 'cars' }}<template v-if="lot.city"> · {{ lot.city }}</template></span>
                    </span>
                </Link>
            </div>
        </section>

        <section class="mx-auto mt-7 flex max-w-6xl flex-col gap-3 px-5">
            <div class="flex items-baseline justify-between gap-3">
                <h2 class="text-xl font-bold">{{ nearMe ? 'Cars near you' : 'New arrivals' }}</h2>
                <div class="flex items-center gap-4">
                    <button v-if="!nearMe && arrivals.length" type="button" class="flex min-h-11 items-center gap-1 text-[14px] font-semibold text-forest" :disabled="locating" @click="showNearMe">
                        <Icon name="locate" :size="16" /> {{ locating ? 'Finding you…' : 'Near me' }}
                    </button>
                    <Link :href="route('cars.index')" class="inline-flex min-h-11 items-center text-[14px] font-medium">See all<template v-if="carCount > 0"> {{ carCount.toLocaleString('en-NG') }}</template></Link>
                </div>
            </div>
            <p v-if="error" class="text-[13px] text-danger" role="alert">{{ error }}</p>

            <div v-if="arrivals.length" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <CarCard v-for="car in arrivals" :key="car.ulid" :car="car" />
            </div>
            <div v-else class="card flex flex-col items-center gap-3 px-6 py-10 text-center">
                <div class="flex h-24 w-40 items-center justify-center rounded-2xl bg-sand"><CarGlyph /></div>
                <h3 class="text-xl font-bold">Cars are on their way</h3>
                <p class="max-w-sm text-[15px] text-muted">Sellers near you are setting up their showrooms. New arrivals will show up here.</p>
            </div>
        </section>

        <section class="mx-auto mt-8 grid max-w-6xl gap-3 px-5 md:grid-cols-2">
            <!-- Alone (no app prompt), it spans the row with the button beside the text, so there's no empty half. -->
            <div class="card flex flex-col gap-2 p-5" :class="install.available.value ? '' : 'md:col-span-2 md:flex-row md:items-center md:justify-between md:gap-6'">
                <div class="flex flex-col gap-2">
                    <h2 class="text-xl font-bold">What can I afford?</h2>
                    <p class="text-[15px] text-muted">
                        <template v-if="budget.maxPrice.value !== null">Your budget is up to {{ formatNaira(budget.maxPrice.value) }}. Cars within reach are tagged.</template>
                        <template v-else>Tell us your income and deposit. We'll tag every car within reach and show the monthly cost.</template>
                    </p>
                </div>
                <Link :href="route('budget')" class="btn btn-outline mt-1 h-[46px] shrink-0 self-start md:self-center">{{ budget.maxPrice.value !== null ? 'Edit my budget' : 'Work out my budget' }}</Link>
            </div>
            <div v-if="install.available.value" class="card flex flex-col gap-2 p-5">
                <h2 class="text-xl font-bold">Get the CarYard app</h2>
                <p class="text-[15px] text-muted">Add CarYard to your home screen. It opens like an app and uses less data.</p>
                <button v-if="install.canPrompt.value" type="button" class="btn btn-dark mt-1 h-[46px] self-start" @click="install.prompt()">Install CarYard</button>
                <p v-else class="text-[14px] text-ink">{{ install.hint.value }}</p>
            </div>
        </section>

        <section class="mx-auto mt-3 mb-10 max-w-6xl px-5">
            <div class="flex flex-col justify-between gap-4 rounded-2xl bg-forest p-6 text-white md:flex-row md:items-center">
                <div class="flex flex-col gap-2">
                    <span class="text-[13px] font-semibold tracking-wide text-peach uppercase">For sellers</span>
                    <h2 class="text-2xl font-bold">Selling cars?</h2>
                    <p class="max-w-lg text-[15px] text-mist">
                        Put your stock online, get your own mini-site, and send buyers straight to your gate.
                        <template v-if="lotCount > 0"> {{ lotCount }} {{ lotCount === 1 ? 'seller is' : 'sellers are' }} already on CarYard.</template>
                    </p>
                </div>
                <Link :href="route('dealer.home')" class="btn btn-primary shrink-0 self-start md:self-auto">Start selling</Link>
            </div>
        </section>
    </CustomerLayout>
</template>
