<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    checklist: { key: string; label: string; done: boolean }[];
    staffCount: number;
}>();

const { user, currentLot } = useShared();
const lot = computed(() => currentLot.value!);

const greeting = computed(() => {
    const hour = new Date().getHours();
    return hour < 12 ? 'Good morning' : hour < 17 ? 'Good afternoon' : 'Good evening';
});
const today = new Date().toLocaleDateString('en-GB', { weekday: 'long', day: 'numeric', month: 'long' });

const remaining = computed(() => props.checklist.filter((i) => !i.done));
const progress = computed(() => Math.round(((props.checklist.length - remaining.value.length) / props.checklist.length) * 100));
const canEdit = computed(() => lot.value.role === 'owner' || lot.value.role === 'manager');

// KPI tiles from the D2 design; they fill in once stock (S2) and bookings (S4) exist.
const kpis = [
    { label: 'Views, last 7 days', note: 'Starts when your first car is live' },
    { label: 'New leads', note: 'Enquiries arrive with chat and offers' },
    { label: 'Visits booked this week', note: 'Bookings open in a later update' },
    { label: 'Sold this month', note: 'Record sales in Lot Manager' },
];
</script>

<template>
    <Head title="Dashboard" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] leading-tight font-bold">{{ greeting }}, {{ user?.name?.split(' ')[0] ?? lot.name }}</h1>
                <span class="text-[14px] text-muted">{{ today }} · {{ staffCount }} on the {{ lot.name }} team</span>
            </div>
            <div class="flex gap-2.5">
                <span class="btn btn-outline h-11 cursor-not-allowed px-4 text-[14px] opacity-50" aria-disabled="true">Share stock</span>
                <span class="btn btn-primary h-11 cursor-not-allowed px-4 text-[14px] opacity-50" aria-disabled="true"><Icon name="plus" :size="16" :stroke-width="2.4" /> Add car</span>
            </div>
        </div>

        <section v-if="remaining.length && canEdit" class="card flex flex-col gap-4 p-5" aria-labelledby="setup-heading">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 id="setup-heading" class="text-[18px] font-bold">Finish setting up {{ lot.name }}</h2>
                    <p class="text-[14px] text-muted">{{ remaining.length }} {{ remaining.length === 1 ? 'step' : 'steps' }} left before buyers can find you.</p>
                </div>
                <span class="font-display text-[28px] font-bold text-forest">{{ progress }}%</span>
            </div>
            <div class="h-2 overflow-hidden rounded-full bg-divider"><div class="h-full rounded-full bg-clay" :style="{ width: `${progress}%` }" /></div>
            <ol class="grid gap-2 md:grid-cols-3">
                <li v-for="item in checklist" :key="item.key">
                    <Link
                        :href="route('dealer.onboarding.show', [lot.slug, item.key])"
                        class="flex h-12 items-center gap-3 rounded-xl border px-3 text-[14px] no-underline"
                        :class="item.done ? 'border-divider text-muted' : 'border-line bg-ivory font-semibold text-ink hover:border-clay'"
                    >
                        <span
                            class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full"
                            :class="item.done ? 'bg-forest text-white' : 'border border-line-strong bg-white'"
                        >
                            <Icon v-if="item.done" name="check" :size="13" :stroke-width="2.6" />
                        </span>
                        {{ item.label }}
                    </Link>
                </li>
            </ol>
        </section>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div v-for="kpi in kpis" :key="kpi.label" class="card p-[18px]">
                <div class="text-[13px] text-muted">{{ kpi.label }}</div>
                <div class="font-display text-[28px] font-bold">0</div>
                <div class="text-[12px] text-muted">{{ kpi.note }}</div>
            </div>
        </div>

        <div class="grid gap-3 xl:grid-cols-[1.6fr_1fr]">
            <div class="card flex flex-col gap-2 p-[18px]">
                <h2 class="font-sans text-[16px] font-bold">Your stock</h2>
                <p class="text-[14px] text-muted">Adding cars (with VIN decode and photos) is the next update. Your listings, views and shares will show here.</p>
            </div>
            <div class="card flex flex-col gap-2 p-[18px]">
                <h2 class="font-sans text-[16px] font-bold">Today's visits</h2>
                <p class="text-[14px] text-muted">No visits booked. Customers will book viewings and test drives in your opening hours.</p>
                <Link v-if="canEdit" :href="route('dealer.settings', lot.slug)" class="text-[13px] font-semibold">Check your hours</Link>
            </div>
        </div>
    </DealerLayout>
</template>
