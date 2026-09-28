<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ lotCount: number }>();

const chips = ['Within my budget', 'SUVs', 'Toyota', 'Foreign used'];
</script>

<template>
    <Head title="Find your next car at lots near you" />
    <CustomerLayout>
        <section class="mx-auto flex max-w-6xl flex-col gap-5 px-5 pt-3 md:pt-12">
            <h1 class="max-w-xl text-[30px] leading-[1.1] font-bold tracking-tight md:text-5xl">Find your next car at lots near you</h1>
            <div class="flex max-w-xl gap-2">
                <div
                    class="flex h-[52px] grow items-center gap-2.5 rounded-[14px] border border-line bg-white px-4 text-[15px] text-muted"
                    aria-disabled="true"
                    title="Car search opens once the first lots are live"
                >
                    <Icon name="search" :stroke-width="2" />
                    Search make, model or lot
                </div>
                <span class="flex h-[52px] w-[52px] shrink-0 items-center justify-center rounded-[14px] bg-forest text-white" aria-hidden="true">
                    <Icon name="filters" :stroke-width="2" />
                </span>
            </div>
            <div class="-mr-5 flex gap-2 overflow-x-auto pr-5">
                <span
                    v-for="(chip, i) in chips"
                    :key="chip"
                    class="flex h-9 shrink-0 items-center rounded-full px-3.5 text-[14px]"
                    :class="i === 0 ? 'bg-forest font-medium text-white' : 'border border-line bg-white'"
                    >{{ chip }}</span
                >
            </div>
        </section>

        <section class="mx-auto mt-8 grid max-w-6xl gap-4 px-5 md:grid-cols-[1.4fr_1fr]">
            <div class="card flex flex-col items-center gap-3 px-6 py-10 text-center">
                <div class="flex h-24 w-40 items-center justify-center rounded-2xl bg-sand"><CarGlyph /></div>
                <h2 class="text-xl font-bold">Cars are on their way</h2>
                <p class="max-w-sm text-[15px] text-muted">
                    <template v-if="lotCount > 0">{{ lotCount }} {{ lotCount === 1 ? 'lot is' : 'lots are' }} getting their stock online.</template>
                    <template v-else>Lots near you are setting up their showrooms.</template>
                    Spotlight cars and new arrivals will show up here.
                </p>
            </div>

            <div class="flex flex-col justify-between gap-3 rounded-2xl bg-forest p-6 text-white">
                <div class="flex flex-col gap-2">
                    <span class="text-[13px] font-semibold tracking-wide text-peach uppercase">For car lots</span>
                    <h2 class="text-2xl font-bold">Own a car lot?</h2>
                    <p class="text-[15px] text-mist">Put your stock online, take bookings and share cars in one tap. Buyers get directions straight to your gate.</p>
                </div>
                <Link :href="route('dealer.home')" class="btn btn-primary self-start">List your lot</Link>
            </div>
        </section>
    </CustomerLayout>
</template>
