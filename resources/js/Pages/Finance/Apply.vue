<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import { loanFor } from '@/lib/finance';
import { formatNaira } from '@/lib/format';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    car: CarCardData;
    price: number;
    partner: string;
    defaults: { deposit: number; tenor_months: number; rate: number; monthly_income: number | null; monthly_commitments: number | null };
    tenors: number[];
    employment: { value: string; label: string }[];
}>();

const form = useForm({
    monthly_income: props.defaults.monthly_income ? String(props.defaults.monthly_income) : '',
    monthly_commitments: props.defaults.monthly_commitments !== null ? String(props.defaults.monthly_commitments) : '0',
    employment: 'salaried',
    employer: '',
    deposit: String(props.defaults.deposit),
    tenor_months: props.defaults.tenor_months,
    consent: false,
});

const num = (v: string) => Number(String(v).replace(/[^\d]/g, '')) || 0;
const depositPercent = computed(() => Math.min(100, Math.round((num(form.deposit) / props.price) * 100)));
const loan = computed(() => loanFor(props.price, depositPercent.value, form.tenor_months, props.defaults.rate));
const amount = computed(() => Math.max(0, props.price - num(form.deposit)));

function submit() {
    form.post(route('finance.store', props.car.ulid), { preserveScroll: true });
}
</script>

<template>
    <Head title="Check if you qualify" />
    <div class="flex min-h-dvh items-end justify-center bg-[#3A3D42] md:items-center md:p-6">
        <form class="flex max-h-dvh w-full max-w-lg flex-col gap-4 overflow-y-auto rounded-t-3xl bg-white p-5 pb-8 md:rounded-3xl" @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <h1 class="text-[22px] font-bold">Check if you qualify</h1>
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
            <p class="rounded-xl bg-map px-3 py-2.5 text-[14px] text-forest">
                Loan of <strong>{{ formatNaira(amount) }}</strong> · about <strong>{{ formatNaira(loan.monthly) }}/month</strong> at {{ defaults.rate }}% a year (estimate).
            </p>

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
                    I agree to LotLink sending my name, phone, email, income, commitments and work details, with this car and loan, to <strong>{{ partner }}</strong> so they can
                    pre-qualify me. {{ car.lot.name }} will know that I applied for this car and, if I'm pre-approved, for how much, but never sees my income, commitments or work details, or a decline.
                </span>
            </label>
            <InputError :message="form.errors.consent" />

            <p class="text-[12px] text-muted">A pre-qualification isn't a loan offer. {{ partner }} makes the decision and may ask for documents and a credit check. LotLink doesn't lend money.</p>
            <button type="submit" class="btn btn-primary h-[52px] rounded-[14px]" :disabled="form.processing || !form.consent">Send to {{ partner }}</button>
        </form>
    </div>
</template>
