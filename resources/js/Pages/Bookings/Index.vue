<script setup lang="ts">
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import type { BookingSummary } from '@/Pages/Bookings/Show.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ upcoming: BookingSummary[]; past: BookingSummary[] }>();

const badge: Record<string, string> = {
    confirmed: 'bg-[#DCEFE3] text-[#166534]',
    pending: 'bg-blush text-clay-dark',
    completed: 'bg-divider text-muted',
    no_show: 'bg-divider text-muted',
    cancelled: 'bg-divider text-muted',
};
</script>

<template>
    <Head title="Your bookings" />
    <CustomerLayout active="bookings">
        <div class="mx-auto flex max-w-xl flex-col gap-2.5 px-5 py-6">
            <h1 class="mb-1 text-[28px] font-bold">Bookings</h1>

            <h2 class="mt-1.5 font-sans text-[13px] font-semibold tracking-wide text-muted uppercase">Upcoming</h2>
            <p v-if="!upcoming.length" class="card px-4 py-6 text-center text-[15px] text-muted">
                No upcoming visits. <Link :href="route('cars.index')" class="font-semibold">Find a car</Link> and book a viewing or test drive.
            </p>
            <article v-for="b in upcoming" :key="b.ulid" class="card flex flex-col gap-2 p-3.5">
                <div class="flex items-start justify-between gap-3">
                    <Link :href="b.url" class="text-[15px] font-semibold text-ink no-underline">{{ b.when }}</Link>
                    <span class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="badge[b.status]">{{ b.status_label }}</span>
                </div>
                <span class="text-[14px] text-[#4A4D53]">{{ b.type }}<template v-if="b.car"> · {{ b.car.title }}</template> · {{ b.lot.name }}</span>
                <div class="flex gap-2">
                    <a v-if="b.lot.directions_url" :href="b.lot.directions_url" target="_blank" rel="noopener" class="inline-flex h-[38px] items-center rounded-[10px] bg-clay px-3 text-[13px] font-semibold text-white no-underline hover:text-white">Directions</a>
                    <Link :href="b.url" class="inline-flex h-[38px] items-center rounded-[10px] border border-line-strong bg-white px-3 text-[13px] font-semibold text-forest no-underline">Manage</Link>
                </div>
            </article>

            <template v-if="past.length">
                <h2 class="mt-3 font-sans text-[13px] font-semibold tracking-wide text-muted uppercase">Past</h2>
                <Link v-for="b in past" :key="b.ulid" :href="b.url" class="card flex flex-col gap-1 p-3.5 text-ink no-underline">
                    <span class="flex justify-between gap-3">
                        <span class="text-[15px] font-semibold">{{ b.when }} · {{ b.type }}</span>
                        <span class="text-[12px] text-muted">{{ b.status_label }}</span>
                    </span>
                    <span class="text-[14px] text-[#4A4D53]"><template v-if="b.car">{{ b.car.title }} · </template>{{ b.lot.name }}</span>
                </Link>
            </template>
        </div>
    </CustomerLayout>
</template>
