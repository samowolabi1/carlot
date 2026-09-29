<script setup lang="ts">
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    applications: {
        ulid: string;
        car: string | null;
        car_url: string | null;
        lot: string | null;
        amount: string;
        approved: string | null;
        months: number;
        status: 'submitted' | 'received' | 'pre_approved' | 'declined' | 'failed';
        status_label: string;
        message: string | null;
        reference: string | null;
        date: string;
    }[];
    partner: string;
}>();

const tone = { submitted: 'bg-sand text-ink', received: 'bg-cream text-clay-dark', pre_approved: 'bg-[#DCEFE3] text-[#166534]', declined: 'bg-divider text-muted', failed: 'bg-[#FDECEC] text-danger' } as const;
</script>

<template>
    <Head title="Finance applications" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 py-6">
            <h1 class="text-[26px] font-bold">Finance applications</h1>
            <p v-if="!applications.length" class="card px-5 py-8 text-center text-[15px] text-muted">
                Nothing yet. On any car, tap <strong>Check if you qualify</strong> to ask {{ partner }} for a pre-qualification.
            </p>
            <article v-for="a in applications" :key="a.ulid" class="card flex flex-col gap-1.5 p-4">
                <div class="flex items-start justify-between gap-3">
                    <Link v-if="a.car_url" :href="a.car_url" class="text-[15px] font-semibold text-ink no-underline">{{ a.car }}</Link>
                    <span v-else class="text-[15px] font-semibold">{{ a.car ?? 'Car no longer listed' }}</span>
                    <span class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="tone[a.status]">{{ a.status_label }}</span>
                </div>
                <span class="text-[14px] text-[#4A4D53]">{{ a.amount }} over {{ a.months }} months<template v-if="a.lot"> · {{ a.lot }}</template> · {{ a.date }}</span>
                <p v-if="a.approved" class="text-[14px] font-semibold text-[#166534]">Pre-approved up to {{ a.approved }}</p>
                <p v-if="a.message" class="text-[14px]">{{ a.message }}</p>
                <p v-if="a.reference" class="text-[12px] text-muted">{{ partner }} reference {{ a.reference }}</p>
            </article>
            <p class="text-[12px] text-muted">A pre-qualification isn't a loan offer. The lender decides and may ask for documents. LotLink doesn't lend money.</p>
        </div>
    </CustomerLayout>
</template>
