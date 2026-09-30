<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import { monthlyPayment } from '@/lib/finance';
import { formatNaira } from '@/lib/format';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';

type LenderOption = {
    slug: string;
    name: string;
    about: string | null;
    type: string;
    rate: number;
    rate_label: string;
    min_amount: number;
    max_amount: number;
    min_deposit_percent: number;
    tenors: number[];
    in_state: boolean;
    product: string;
    instant: boolean;
};

const props = defineProps<{
    car: CarCardData;
    price: number;
    lenders: LenderOption[];
    defaults: { deposit: number; tenor_months: number; monthly_income: number | null; monthly_commitments: number | null; lender: string | null };
    tenors: number[];
    employment: { value: string; label: string }[];
}>();

const form = useForm({
    lender: props.defaults.lender ?? '',
    monthly_income: props.defaults.monthly_income ? String(props.defaults.monthly_income) : '',
    monthly_commitments: props.defaults.monthly_commitments !== null ? String(props.defaults.monthly_commitments) : '0',
    employment: 'salaried',
    employer: '',
    deposit: String(props.defaults.deposit),
    tenor_months: props.defaults.tenor_months,
    consent: false,
});

const num = (v: string) => Number(String(v).replace(/[^\d]/g, '')) || 0;
const amount = computed(() => Math.max(0, props.price - num(form.deposit)));

/** Why a lender can't take this loan, or null when it can (the same rules as Lender::lendsFor on the server). */
function reason(l: LenderOption): string | null {
    if (!l.in_state) return "Doesn't lend in this lot's state";
    if (amount.value < l.min_amount) return `Lends from ${formatNaira(l.min_amount)}`;
    if (amount.value > l.max_amount) return `Lends up to ${formatNaira(l.max_amount)}`;
    if (num(form.deposit) * 100 < props.price * l.min_deposit_percent) return `Needs a ${l.min_deposit_percent}% deposit (${formatNaira(Math.ceil((props.price * l.min_deposit_percent) / 100))})`;
    if (!l.tenors.includes(form.tenor_months)) return `Offers ${l.tenors.join(', ')} months`;
    return null;
}

const options = computed(() =>
    props.lenders
        .map((l) => ({ ...l, why: reason(l), monthly: monthlyPayment(amount.value, l.rate, form.tenor_months) }))
        .sort((a, b) => Number(!!a.why) - Number(!!b.why) || a.rate - b.rate),
);
const chosen = computed(() => options.value.find((l) => l.slug === form.lender && !l.why) ?? null);

// Pick the cheapest lender that fits when none (or one that no longer fits) is chosen.
watch(
    options,
    (list) => {
        if (!chosen.value) form.lender = list.find((l) => !l.why)?.slug ?? '';
    },
    { immediate: true },
);

function submit() {
    form.post(route('finance.store', props.car.ulid), { preserveScroll: true });
}
</script>

<template>
    <Head title="Apply for a car loan" />
    <div class="flex min-h-dvh items-end justify-center bg-[#3A3D42] md:items-center md:p-6">
        <form class="flex max-h-dvh w-full max-w-lg flex-col gap-4 overflow-y-auto rounded-t-3xl bg-white p-5 pb-8 md:rounded-3xl" @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <h1 class="text-[22px] font-bold">Apply for a car loan</h1>
                <Link :href="car.url" aria-label="Close" class="-mr-2 flex h-11 w-11 items-center justify-center text-ink"><Icon name="close" :size="22" /></Link>
            </div>

            <div class="flex items-center gap-3">
                <img v-if="car.image" :src="car.image.src" alt="" class="h-12 w-16 shrink-0 rounded-[10px] object-cover" />
                <span v-else class="flex h-12 w-16 shrink-0 items-center justify-center rounded-[10px] bg-sand"><CarGlyph :width="40" /></span>
                <div class="flex min-w-0 flex-col">
                    <span class="truncate text-[15px] font-semibold">{{ car.title }}</span>
                    <span class="truncate text-[13px] text-muted">{{ car.price }} · {{ car.lot.name }}</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <label class="field-label">
                    Deposit (₦)
                    <input v-field="{ kind: 'money', min: 0 }" v-model="form.deposit" inputmode="numeric" class="field" />
                    <InputError :message="form.errors.deposit" />
                </label>
                <label class="field-label">
                    Loan term
                    <select v-model.number="form.tenor_months" class="field">
                        <option v-for="t in tenors" :key="t" :value="t">{{ t }} months</option>
                    </select>
                </label>
            </div>
            <p class="text-[14px] text-[#4A4D53]">You'd borrow <strong class="text-ink">{{ formatNaira(amount) }}</strong>.</p>

            <fieldset class="flex flex-col gap-2">
                <legend class="field-label mb-1">Choose a lender</legend>
                <p v-if="!lenders.length" class="rounded-xl bg-sand px-3 py-3 text-[14px]">No lenders are taking applications right now. Check back soon.</p>
                <label
                    v-for="l in options"
                    :key="l.slug"
                    class="flex min-h-11 items-start gap-3 rounded-xl border p-3"
                    :class="l.why ? 'cursor-not-allowed border-line bg-ivory opacity-70' : form.lender === l.slug ? 'cursor-pointer border-forest bg-map' : 'cursor-pointer border-line bg-white'"
                >
                    <input v-model="form.lender" type="radio" name="lender" :value="l.slug" :disabled="!!l.why" class="mt-1 h-5 w-5 shrink-0 accent-forest" />
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <span class="flex flex-wrap items-center gap-x-2">
                            <strong class="text-[15px]">{{ l.name }}</strong>
                            <span class="text-[12px] text-muted">{{ l.type }}</span>
                            <span v-if="l.instant" class="rounded-md bg-sand px-1.5 text-[11px] font-semibold">Instant answer</span>
                        </span>
                        <span class="text-[13px] text-[#4A4D53]">{{ l.product }}</span>
                        <span v-if="l.why" class="text-[13px] text-clay-dark">{{ l.why }}</span>
                        <span v-else class="text-[14px] text-forest">About <strong>{{ formatNaira(l.monthly) }}/month</strong> at {{ l.rate_label }} a year (estimate)</span>
                    </span>
                </label>
                <InputError :message="form.errors.lender" />
            </fieldset>

            <div class="grid grid-cols-2 gap-3">
                <label class="field-label">
                    Monthly income (₦)
                    <input v-field="{ kind: 'money', min: 30000, max: 1000000000 }" v-model="form.monthly_income" inputmode="numeric" class="field" required />
                    <InputError :message="form.errors.monthly_income" />
                </label>
                <label class="field-label">
                    Monthly loans and rent (₦)
                    <input v-field="{ kind: 'money', min: 0, max: 1000000000 }" v-model="form.monthly_commitments" inputmode="numeric" class="field" />
                    <InputError :message="form.errors.monthly_commitments" />
                </label>
            </div>
            <div class="grid grid-cols-2 gap-3">
                <label class="field-label">
                    Work
                    <select v-model="form.employment" class="field">
                        <option v-for="e in employment" :key="e.value" :value="e.value">{{ e.label }}</option>
                    </select>
                </label>
                <label class="field-label">
                    Employer <span class="font-normal text-muted">(optional)</span>
                    <input v-field="'business_name'" v-model="form.employer" class="field" />
                </label>
            </div>

            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3 text-[14px]">
                <input v-model="form.consent" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-forest" />
                <span>
                    I agree to LotLink sending my name, phone, email, income, commitments and work details, with this car and loan, to
                    <strong>{{ chosen?.name ?? 'the lender I picked' }}</strong> so they can consider my application, and to them contacting me about it.
                    {{ car.lot.name }} will know that I applied for this car and, if I'm approved, for how much, but never sees my income, commitments or work details, or a decline.
                </span>
            </label>
            <InputError :message="form.errors.consent" />

            <p class="text-[12px] text-muted">The lender makes the decision and may ask for documents and a credit check. LotLink doesn't lend money.</p>
            <button type="submit" class="btn btn-primary h-[52px] rounded-[14px]" :disabled="form.processing || !form.consent || !chosen">
                {{ chosen ? `Send to ${chosen.name}` : 'Pick a lender' }}
            </button>
        </form>
    </div>
</template>
