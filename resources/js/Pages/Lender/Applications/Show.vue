<script setup lang="ts">
import FinanceThread, { type FinanceThreadMessage } from '@/components/finance/FinanceThread.vue';
import StatusChip from '@/components/finance/StatusChip.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import LenderLayout from '@/layouts/LenderLayout.vue';
import type { SharedProps } from '@/types';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Action = 'review' | 'documents' | 'pre_approve' | 'approve' | 'disburse' | 'decline';

const props = defineProps<{
    application: {
        ulid: string;
        car: string | null;
        car_url: string | null;
        lot: string | null;
        lot_city: string | null;
        buyer: string | null;
        amount: string;
        deposit: string;
        price: string | null;
        approved: string | null;
        months: number;
        status: string;
        status_label: string;
        tone: string;
        message: string | null;
        reference: string | null;
        image: string | null;
        date: string;
        consented_at: string | null;
        numbers: { amount: number; approved: number | null };
        offer: { rate: string | null; months: number | null; disbursed: string | null; disbursed_reference: string | null; disbursed_at: string | null };
        applicant: {
            name: string | null;
            phone: string | null;
            email: string | null;
            monthly_income: string | null;
            monthly_commitments: string | null;
            employment: string | null;
            employer: string | null;
        };
        messages: FinanceThreadMessage[];
    };
    actions: Action[];
    team: { ulid: string; name: string }[];
    assigned: string | null;
    tenors: number[];
    rate: number;
    upload: { max_kb: number; mimes: string[] };
}>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.currentLender!.slug);
const a = computed(() => props.application);

const labels: Record<Action, string> = {
    review: 'Start review',
    documents: 'Ask for documents',
    pre_approve: 'Pre-approve',
    approve: 'Approve',
    disburse: 'Record payment to the lot',
    decline: 'Decline',
};
const open = ref<Action | null>(null);

const form = useForm({
    action: '' as Action | '',
    message: '',
    approved_amount: String(props.application.numbers.approved ?? props.application.numbers.amount),
    rate: props.rate,
    tenor_months: props.tenors.includes(props.application.months) ? props.application.months : props.tenors[0],
    disbursed_amount: String(props.application.numbers.approved ?? props.application.numbers.amount),
    disbursed_reference: '',
});

function start(action: Action) {
    if (action === 'review') return submit('review');
    open.value = action;
    form.clearErrors();
}

function submit(action: Action) {
    form.action = action;
    form.post(route('lender.applications.status', [slug.value, a.value.ulid]), {
        preserveScroll: true,
        onSuccess: () => {
            open.value = null;
            form.reset('message', 'disbursed_reference');
        },
    });
}

const assignee = ref(props.assigned ?? '');
function assign() {
    router.post(route('lender.applications.assign', [slug.value, a.value.ulid]), { assigned_to: assignee.value || null }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Application" />
    <LenderLayout>
        <Link :href="route('lender.applications.index', slug)" class="inline-flex min-h-11 items-center gap-1 self-start text-[14px] font-semibold text-forest no-underline">
            <Icon name="chevronLeft" :size="18" /> Applications
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h1 class="text-[26px] leading-tight font-bold">{{ a.buyer }}</h1>
                <p class="text-[14px] text-muted">{{ a.amount }} over {{ a.months }} months · sent {{ a.date }}<template v-if="a.reference"> · your ref {{ a.reference }}</template></p>
            </div>
            <StatusChip :tone="a.tone" :label="a.status_label" />
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_320px] lg:items-start">
            <div class="flex min-w-0 flex-col gap-5">
                <section v-if="actions.length" class="card flex flex-col gap-3 p-5" aria-labelledby="actions-title">
                    <h2 id="actions-title" class="font-sans text-[17px] font-bold">Next step</h2>
                    <div class="flex flex-wrap gap-2">
                        <button
                            v-for="act in actions"
                            :key="act"
                            type="button"
                            class="btn h-11"
                            :class="act === 'decline' ? 'btn-outline text-danger' : act === 'approve' || act === 'disburse' || (act === 'pre_approve' && !actions.includes('approve')) ? 'btn-primary' : 'btn-outline'"
                            :aria-pressed="open === act"
                            :disabled="form.processing"
                            @click="start(act)"
                        >
                            {{ labels[act] }}
                        </button>
                    </div>

                    <form v-if="open" class="flex flex-col gap-3 rounded-xl border border-line bg-ivory p-4" @submit.prevent="submit(open)">
                        <strong class="text-[15px]">{{ labels[open] }}</strong>
                        <div v-if="open === 'pre_approve' || open === 'approve'" class="grid gap-3 sm:grid-cols-3">
                            <label class="field-label">
                                Amount (₦)
                                <input v-field="{ kind: 'money', min: 1, max: a.numbers.amount }" v-model="form.approved_amount" inputmode="numeric" class="field h-11" required />
                                <InputError :message="form.errors.approved_amount" />
                            </label>
                            <template v-if="open === 'approve'">
                                <label class="field-label">
                                    Rate (% a year)
                                    <input v-model="form.rate" type="number" min="1" max="99" step="0.01" inputmode="decimal" class="field h-11" required />
                                    <InputError :message="form.errors.rate" />
                                </label>
                                <label class="field-label">
                                    Term
                                    <select v-model.number="form.tenor_months" class="field h-11">
                                        <option v-for="t in tenors" :key="t" :value="t">{{ t }} months</option>
                                    </select>
                                    <InputError :message="form.errors.tenor_months" />
                                </label>
                            </template>
                        </div>
                        <div v-if="open === 'disburse'" class="grid gap-3 sm:grid-cols-2">
                            <label class="field-label">
                                Amount paid to {{ a.lot }} (₦)
                                <input v-field="{ kind: 'money', min: 1 }" v-model="form.disbursed_amount" inputmode="numeric" class="field h-11" required />
                                <InputError :message="form.errors.disbursed_amount" />
                            </label>
                            <label class="field-label">
                                Transfer reference
                                <input v-field="'reference'" v-model="form.disbursed_reference" class="field h-11" required />
                                <InputError :message="form.errors.disbursed_reference" />
                            </label>
                        </div>
                        <label class="field-label">
                            {{ open === 'documents' ? 'Which documents do you need?' : open === 'decline' ? 'Why, and what might help' : 'Note to the buyer (optional)' }}
                            <textarea
                                v-field="{ kind: 'text', max: 1000 }"
                                v-model="form.message"
                                rows="3"
                                maxlength="1000"
                                class="field h-auto py-2.5"
                                :required="open === 'documents' || open === 'decline'"
                                :placeholder="open === 'documents' ? 'e.g. 3 months of bank statements and a valid ID' : ''"
                            />
                            <InputError :message="form.errors.message" />
                        </label>
                        <InputError :message="(form.errors as Record<string, string>).status" />
                        <div class="flex gap-2">
                            <button type="submit" class="btn btn-primary h-11" :disabled="form.processing">{{ form.processing ? 'Saving…' : labels[open] }}</button>
                            <button type="button" class="btn btn-outline h-11" @click="open = null">Cancel</button>
                        </div>
                        <p class="text-[12px] text-muted">The buyer is told straight away. {{ open === 'decline' ? 'The car lot is never told about a decline.' : '' }}</p>
                    </form>
                    <InputError v-if="!open" :message="(form.errors as Record<string, string>).status" />
                </section>

                <FinanceThread
                    :messages="a.messages"
                    :action="route('lender.applications.message', [slug, a.ulid])"
                    :can-send="!['failed', 'withdrawn'].includes(a.status)"
                    hint="A question for the buyer, or what you need from them"
                    :max-kb="upload.max_kb"
                />
            </div>

            <aside class="flex flex-col gap-4">
                <section class="card flex flex-col gap-2 p-4" aria-labelledby="buyer-title">
                    <h2 id="buyer-title" class="font-sans text-[15px] font-bold">Applicant</h2>
                    <dl class="flex flex-col gap-1.5 text-[14px]">
                        <div class="flex justify-between gap-3"><dt class="text-muted">Phone</dt><dd class="text-right font-semibold">{{ a.applicant.phone ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Email</dt><dd class="text-right font-semibold break-all">{{ a.applicant.email ?? '—' }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Monthly income</dt><dd class="text-right font-semibold">{{ a.applicant.monthly_income }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Loans and rent</dt><dd class="text-right font-semibold">{{ a.applicant.monthly_commitments }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Work</dt><dd class="text-right font-semibold">{{ a.applicant.employment }}</dd></div>
                        <div v-if="a.applicant.employer" class="flex justify-between gap-3"><dt class="text-muted">Employer</dt><dd class="text-right font-semibold">{{ a.applicant.employer }}</dd></div>
                    </dl>
                    <p class="text-[12px] text-muted">Shared with you with the buyer's consent on {{ a.consented_at }}. Use it only for this loan.</p>
                </section>

                <section class="card flex flex-col gap-2 p-4" aria-labelledby="car-title">
                    <h2 id="car-title" class="font-sans text-[15px] font-bold">Car and loan</h2>
                    <img v-if="a.image" :src="a.image" alt="" class="aspect-[4/3] w-full rounded-xl object-cover" />
                    <a v-if="a.car_url" :href="a.car_url" target="_blank" rel="noopener" class="text-[15px] font-semibold">{{ a.car }}</a>
                    <span v-else class="text-[15px] font-semibold">{{ a.car }}</span>
                    <dl class="flex flex-col gap-1.5 text-[14px]">
                        <div class="flex justify-between gap-3"><dt class="text-muted">Lot</dt><dd class="text-right font-semibold">{{ a.lot }}<br /><span class="font-normal text-muted">{{ a.lot_city }}</span></dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Price</dt><dd class="font-semibold">{{ a.price }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Deposit</dt><dd class="font-semibold">{{ a.deposit }}</dd></div>
                        <div class="flex justify-between gap-3"><dt class="text-muted">Loan asked for</dt><dd class="font-semibold">{{ a.amount }}</dd></div>
                        <div v-if="a.approved" class="flex justify-between gap-3"><dt class="text-muted">Approved</dt><dd class="font-semibold text-[#166534]">{{ a.approved }}</dd></div>
                        <div v-if="a.offer.rate" class="flex justify-between gap-3"><dt class="text-muted">Offer</dt><dd class="font-semibold">{{ a.offer.rate }} · {{ a.offer.months }} months</dd></div>
                        <div v-if="a.offer.disbursed" class="flex justify-between gap-3"><dt class="text-muted">Paid</dt><dd class="text-right font-semibold">{{ a.offer.disbursed }}<br /><span class="font-normal text-muted">{{ a.offer.disbursed_reference }}</span></dd></div>
                    </dl>
                </section>

                <section class="card flex flex-col gap-2 p-4">
                    <label class="field-label">
                        Handled by
                        <select v-model="assignee" class="field h-11" @change="assign">
                            <option value="">Whole team</option>
                            <option v-for="m in team" :key="m.ulid" :value="m.ulid">{{ m.name }}</option>
                        </select>
                    </label>
                </section>
            </aside>
        </div>
    </LenderLayout>
</template>
