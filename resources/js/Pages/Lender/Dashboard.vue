<script setup lang="ts">
import StatusChip from '@/components/finance/StatusChip.vue';
import Icon from '@/components/Icon.vue';
import LenderLayout from '@/layouts/LenderLayout.vue';
import type { SharedProps } from '@/types';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
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
    lender: { status: string; status_label: string; note: string | null; product: string; integration: string; terms_version: string; is_admin: boolean };
    stats: { new: number; open: number; documents: number; approved_month: number; disbursed_month: string; mine: number };
    recent: LenderRow[];
}>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.currentLender!.slug);
const terms = useForm({ agree: false });
function acceptTerms() {
    terms.post(route('lender.terms', slug.value), { preserveScroll: true });
}
</script>

<template>
    <Head title="Lender portal" />
    <LenderLayout>
        <div class="flex flex-col gap-1">
            <h1 class="text-[30px] font-bold">Dashboard</h1>
            <p class="text-[14px] text-muted">{{ lender.product }} · {{ lender.integration }}</p>
        </div>

        <form v-if="!page.props.currentLender!.terms_ok" class="card flex flex-col gap-3 border-clay p-5" aria-labelledby="terms-title" @submit.prevent="acceptTerms">
            <h2 id="terms-title" class="font-sans text-[17px] font-bold">Accept the Lender Terms</h2>
            <p class="text-[14px] text-[#4A4D53]">
                Before your team can work applications, one of your admins must accept the
                <Link :href="route('legal.show', 'lender-terms')" target="_blank">Lender Terms</Link> (effective {{ lender.terms_version }}). They cover your licence or identity,
                fair treatment of buyers, data protection and how loans continue with you outside LotLink.
            </p>
            <template v-if="lender.is_admin">
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3 text-[14px]">
                    <input v-model="terms.agree" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-forest" />
                    <span>I'm authorised to act for {{ page.props.currentLender!.name }} and accept the Lender Terms for it.</span>
                </label>
                <InputError :message="terms.errors.agree" />
                <button type="submit" class="btn btn-primary h-11 self-start" :disabled="terms.processing || !terms.agree">Accept for {{ page.props.currentLender!.name }}</button>
            </template>
            <p v-else class="text-[14px] font-semibold">Ask one of your admins to sign in and accept them.</p>
        </form>

        <p v-if="lender.status !== 'active' && lender.note" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark" role="status">
            <strong>Note from LotLink:</strong> {{ lender.note }}
        </p>

        <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
            <Link :href="route('lender.applications.index', { lender: slug, tab: 'new' })" class="card flex flex-col justify-between gap-1 p-4 text-ink no-underline">
                <span class="text-[13px] text-muted">New</span><strong class="text-[26px]">{{ stats.new }}</strong>
            </Link>
            <Link :href="route('lender.applications.index', { lender: slug, tab: 'open' })" class="card flex flex-col justify-between gap-1 p-4 text-ink no-underline">
                <span class="text-[13px] text-muted">Open</span><strong class="text-[26px]">{{ stats.open }}</strong>
            </Link>
            <Link :href="route('lender.applications.index', { lender: slug, tab: 'mine' })" class="card flex flex-col justify-between gap-1 p-4 text-ink no-underline">
                <span class="text-[13px] text-muted">Given to me</span><strong class="text-[26px]">{{ stats.mine }}</strong>
            </Link>
            <div class="card flex flex-col justify-between gap-1 p-4">
                <span class="text-[13px] text-muted">Approved this month</span><strong class="text-[26px]">{{ stats.approved_month }}</strong>
            </div>
            <div class="card flex flex-col justify-between gap-1 p-4">
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
