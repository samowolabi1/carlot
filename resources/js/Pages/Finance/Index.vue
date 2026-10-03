<script setup lang="ts">
import StatusChip from '@/components/finance/StatusChip.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    applications: {
        ulid: string;
        car: string | null;
        lot: string | null;
        lender: string | null;
        amount: string;
        approved: string | null;
        months: number;
        status: string;
        status_label: string;
        tone: string;
        message: string | null;
        unread: boolean;
        date: string;
    }[];
}>();
</script>

<template>
    <Head title="Car loan applications" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-6 pb-28 md:pb-16">
            <h1 class="text-[26px] font-bold">Car loan applications</h1>
            <p v-if="!applications.length" class="card px-5 py-8 text-center text-[15px] text-muted">
                Nothing yet. On any car, tap <strong>Apply for a car loan</strong> to pick a lender and apply.
            </p>
            <Link v-for="a in applications" :key="a.ulid" :href="route('finance.show', a.ulid)" class="card flex flex-col gap-1.5 p-4 text-ink no-underline hover:border-line-strong">
                <span class="flex items-start justify-between gap-3">
                    <span class="flex items-center gap-2 text-[15px] font-semibold">
                        <span v-if="a.unread" class="h-2.5 w-2.5 shrink-0 rounded-full bg-clay" aria-label="New update" />
                        {{ a.car ?? 'Car no longer listed' }}
                    </span>
                    <StatusChip :tone="a.tone" :label="a.status_label" />
                </span>
                <span class="text-[14px] text-[#4A4D53]">{{ a.amount }} over {{ a.months }} months<template v-if="a.lender"> · {{ a.lender }}</template> · {{ a.date }}</span>
                <span v-if="a.approved" class="text-[14px] font-semibold text-[#166534]">Approved up to {{ a.approved }}</span>
                <span v-if="a.message" class="line-clamp-2 text-[14px]">{{ a.message }}</span>
            </Link>
            <p class="text-[12px] text-muted">The lender decides and may ask for documents. CarYard doesn't lend money.</p>
        </div>
    </CustomerLayout>
</template>
