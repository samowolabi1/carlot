<script setup lang="ts">
import FinanceThread, { type FinanceThreadMessage } from '@/components/finance/FinanceThread.vue';
import StatusChip from '@/components/finance/StatusChip.vue';
import Icon from '@/components/Icon.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    application: {
        ulid: string;
        car: string | null;
        car_url: string | null;
        lot: string | null;
        lender: string | null;
        amount: string;
        deposit: string;
        price: string | null;
        approved: string | null;
        months: number;
        status: string;
        status_label: string;
        tone: string;
        open: boolean;
        message: string | null;
        reference: string | null;
        image: string | null;
        date: string;
        consented_at: string | null;
        continue: { steps: string | null; phone: string | null; email: string | null; website: string | null } | null;
        offer: { rate: string | null; months: number | null; disbursed: string | null; disbursed_reference: string | null; disbursed_at: string | null };
        messages: FinanceThreadMessage[];
    };
    upload: { max_kb: number; mimes: string[] };
}>();

const steps = [
    { key: 'submitted', label: 'Sent' },
    { key: 'received', label: 'Being reviewed' },
    { key: 'pre_approved', label: 'Pre-approved' },
    { key: 'approved', label: 'Approved (finish with the lender)' },
    { key: 'disbursed', label: 'Paid to the lot by the lender' },
];
const order = ['submitted', 'received', 'documents_requested', 'pre_approved', 'approved', 'disbursed'];
const reached = (key: string) => order.indexOf(props.application.status) >= order.indexOf(key);
const withdrawing = ref(false);

function withdraw() {
    router.post(route('finance.withdraw', props.application.ulid), {}, { preserveScroll: true, onFinish: () => (withdrawing.value = false) });
}
</script>

<template>
    <Head title="Car loan application" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-6 pb-28 md:pb-16">
            <Link :href="route('finance.index')" class="inline-flex min-h-11 items-center gap-1 text-[14px] font-semibold text-forest no-underline">
                <Icon name="chevronLeft" :size="18" /> All applications
            </Link>

            <section class="card flex flex-col gap-3 p-5">
                <div class="flex items-start gap-3">
                    <img v-if="application.image" :src="application.image" alt="" class="h-14 w-20 shrink-0 rounded-[10px] object-cover" />
                    <div class="flex min-w-0 grow flex-col gap-0.5">
                        <h1 class="text-[20px] leading-tight font-bold">
                            <Link v-if="application.car_url" :href="application.car_url" class="text-ink no-underline">{{ application.car }}</Link>
                            <template v-else>{{ application.car ?? 'Car no longer listed' }}</template>
                        </h1>
                        <span class="text-[14px] text-muted">{{ application.lot }} · {{ application.price }}</span>
                    </div>
                    <StatusChip :tone="application.tone" :label="application.status_label" />
                </div>

                <dl class="grid grid-cols-2 gap-3 rounded-xl bg-ivory p-3 text-[14px]">
                    <div><dt class="text-muted">Lender</dt><dd class="font-semibold">{{ application.lender }}</dd></div>
                    <div><dt class="text-muted">Loan asked for</dt><dd class="font-semibold">{{ application.amount }}</dd></div>
                    <div><dt class="text-muted">Deposit</dt><dd class="font-semibold">{{ application.deposit }}</dd></div>
                    <div><dt class="text-muted">Term</dt><dd class="font-semibold">{{ application.months }} months</dd></div>
                    <div v-if="application.approved"><dt class="text-muted">Approved</dt><dd class="font-semibold text-[#166534]">{{ application.approved }}</dd></div>
                    <div v-if="application.offer.rate"><dt class="text-muted">Offer</dt><dd class="font-semibold">{{ application.offer.rate }} a year · {{ application.offer.months }} months</dd></div>
                    <div v-if="application.offer.disbursed"><dt class="text-muted">Paid to the lot</dt><dd class="font-semibold">{{ application.offer.disbursed }} · {{ application.offer.disbursed_at }}</dd></div>
                    <div v-if="application.reference"><dt class="text-muted">Lender's reference</dt><dd class="font-semibold">{{ application.reference }}</dd></div>
                </dl>

                <ol v-if="!['declined', 'withdrawn', 'failed'].includes(application.status)" class="flex flex-col gap-2" aria-label="Progress">
                    <li v-for="s in steps" :key="s.key" class="flex items-center gap-2.5 text-[14px]" :class="reached(s.key) ? 'text-ink' : 'text-muted'">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full" :class="reached(s.key) ? 'bg-forest text-white' : 'border border-line bg-white'">
                            <Icon v-if="reached(s.key)" name="check" :size="14" />
                        </span>
                        {{ s.label }}
                    </li>
                </ol>

                <p v-if="application.status === 'documents_requested'" class="rounded-xl bg-cream px-3 py-2.5 text-[14px] text-clay-dark" role="status">
                    <strong>{{ application.lender }} needs documents.</strong> {{ application.message }} Upload them below.
                </p>
                <p v-else-if="application.message" class="text-[14px]">{{ application.message }}</p>

                <div v-if="application.open" class="flex flex-wrap items-center gap-2 border-t border-line pt-3">
                    <button v-if="!withdrawing" type="button" class="btn btn-outline h-11" @click="withdrawing = true">Withdraw application</button>
                    <template v-else>
                        <span class="text-[14px]">Withdraw it? {{ application.lender }} will be told and stop working on it.</span>
                        <button type="button" class="btn btn-primary h-11" @click="withdraw">Yes, withdraw</button>
                        <button type="button" class="btn btn-outline h-11" @click="withdrawing = false">Keep it</button>
                    </template>
                </div>
            </section>

            <section v-if="application.continue" class="card flex flex-col gap-2 border-forest p-5" aria-labelledby="continue-title">
                <h2 id="continue-title" class="font-sans text-[17px] font-bold">Continue with {{ application.lender }}</h2>
                <p class="text-[14px] text-[#4A4D53]">
                    The loan itself is completed with {{ application.lender }}, not on LotLink: they check your identity and credit, give you the loan agreement to sign and pay
                    the lot directly.
                </p>
                <p v-if="application.continue.steps" class="rounded-xl bg-map px-3 py-2.5 text-[14px] whitespace-pre-line text-forest">{{ application.continue.steps }}</p>
                <div class="flex flex-wrap gap-2">
                    <a v-if="application.continue.phone" :href="`tel:${application.continue.phone.replace(/\s/g, '')}`" class="btn btn-outline h-11"><Icon name="phone" :size="18" /> {{ application.continue.phone }}</a>
                    <a v-if="application.continue.email" :href="`mailto:${application.continue.email}`" class="btn btn-outline h-11"><Icon name="mail" :size="18" /> Email</a>
                    <a v-if="application.continue.website" :href="application.continue.website" target="_blank" rel="noopener nofollow" class="btn btn-outline h-11">Website</a>
                </div>
                <p class="text-[12px] text-muted">Never pay a fee to anyone to "speed up" a loan. Pay the car's price only to the lot, and loan charges only to the lender's own official account.</p>
            </section>

            <FinanceThread
                :messages="application.messages"
                :action="route('finance.message', application.ulid)"
                :can-send="!['failed', 'withdrawn'].includes(application.status)"
                :hint="`A question for ${application.lender}, or a note with your payslip or ID`"
                :max-kb="upload.max_kb"
            />

            <p class="text-[12px] text-muted">
                You agreed to share your details with {{ application.lender }} on {{ application.consented_at }}. {{ application.lot }} never sees your income, work details, messages or
                documents. LotLink doesn't lend money or decide on loans: it passes your application to the lender you chose. See our
                <Link :href="route('legal.show', 'privacy')">Privacy Policy</Link> and <Link :href="route('legal.show', 'terms')">Terms</Link>.
            </p>
        </div>
    </CustomerLayout>
</template>
