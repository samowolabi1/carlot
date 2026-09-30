<script setup lang="ts">
import StatusChip from '@/components/finance/StatusChip.vue';
import Icon from '@/components/Icon.vue';
import LenderLayout from '@/layouts/LenderLayout.vue';
import type { SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export type LenderRow = {
    ulid: string;
    car: string | null;
    lot: string | null;
    buyer: string | null;
    amount: string;
    approved: string | null;
    months: number;
    status: string;
    status_label: string;
    tone: string;
    assignee: string | null;
    unread: boolean;
    date: string;
    updated: string;
};

defineProps<{
    lender: { status: string; status_label: string; note: string | null; product: string; integration: string };
    stats: { new: number; open: number; documents: number; approved_month: number; disbursed_month: string; mine: number };
    recent: LenderRow[];
}>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.currentLender!.slug);
</script>

<template>
    <Head title="Lender portal" />
    <LenderLayout>
        <div class="flex flex-col gap-1">
            <h1 class="text-[30px] font-bold">Dashboard</h1>
            <p class="text-[14px] text-muted">{{ lender.product }} · {{ lender.integration }}</p>
        </div>

        <p v-if="lender.status !== 'active' && lender.note" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark" role="status">
            <strong>Note from LotLink:</strong> {{ lender.note }}
        </p>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Link :href="route('lender.applications.index', { lender: slug, tab: 'new' })" class="card flex flex-col gap-1 p-4 text-ink no-underline">
                <span class="text-[13px] text-muted">New</span><strong class="text-[26px]">{{ stats.new }}</strong>
            </Link>
            <Link :href="route('lender.applications.index', { lender: slug, tab: 'open' })" class="card flex flex-col gap-1 p-4 text-ink no-underline">
                <span class="text-[13px] text-muted">Open</span><strong class="text-[26px]">{{ stats.open }}</strong>
            </Link>
            <Link :href="route('lender.applications.index', { lender: slug, tab: 'mine' })" class="card flex flex-col gap-1 p-4 text-ink no-underline">
                <span class="text-[13px] text-muted">Given to me</span><strong class="text-[26px]">{{ stats.mine }}</strong>
            </Link>
            <div class="card flex flex-col gap-1 p-4">
                <span class="text-[13px] text-muted">Approved this month</span><strong class="text-[26px]">{{ stats.approved_month }}</strong>
            </div>
            <div class="card flex flex-col gap-1 p-4">
                <span class="text-[13px] text-muted">Paid to lots this month</span><strong class="text-[26px]">{{ stats.disbursed_month }}</strong>
            </div>
        </div>

        <section class="card flex flex-col gap-2 p-5" aria-labelledby="recent-title">
            <div class="flex items-center justify-between">
                <h2 id="recent-title" class="font-sans text-[17px] font-bold">Latest open applications</h2>
                <Link :href="route('lender.applications.index', slug)" class="text-[14px] font-semibold">See all</Link>
            </div>
            <p v-if="!recent.length" class="py-6 text-center text-[14px] text-muted">
                {{ lender.status === 'active' ? 'No open applications. New ones appear here and you get a notification.' : 'Applications arrive once LotLink approves you.' }}
            </p>
            <Link
                v-for="a in recent"
                :key="a.ulid"
                :href="route('lender.applications.show', [slug, a.ulid])"
                class="flex min-h-11 items-center gap-3 rounded-xl border border-line px-3 py-2.5 text-ink no-underline hover:border-line-strong"
            >
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="a.unread ? 'bg-clay' : 'bg-transparent'" />
                <span class="flex min-w-0 grow flex-col">
                    <strong class="truncate text-[15px]">{{ a.buyer }} · {{ a.car }}</strong>
                    <span class="truncate text-[13px] text-muted">{{ a.amount }} · {{ a.months }} months · {{ a.lot }} · {{ a.updated }}</span>
                </span>
                <StatusChip :tone="a.tone" :label="a.status_label" />
                <Icon name="chevronRight" :size="16" class="text-muted" />
            </Link>
        </section>
    </LenderLayout>
</template>
