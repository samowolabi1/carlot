<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { type FinanceDefaults, loanFor } from '@/lib/finance';
import { formatNaira } from '@/lib/format';
import { computed, ref } from 'vue';

export interface CarFinanceData {
    price: number;
    defaults: FinanceDefaults;
}

const props = defineProps<{ finance: CarFinanceData; applyHref?: string | null }>();

const depositPercent = ref(props.finance.defaults.deposit_percent);
const months = ref(props.finance.defaults.tenor_months);
const rate = ref(props.finance.defaults.interest_rate);
// Repayments stay folded away until the buyer asks: most buyers pay cash, so no monthly figure shows by default.
const open = ref(false);

const loan = computed(() => loanFor(props.finance.price, depositPercent.value, months.value, Number(rate.value) || 0));
</script>

<template>
    <section v-if="applyHref" class="card flex flex-col gap-4 p-4" aria-labelledby="loan-heading">
        <div class="flex flex-col gap-1">
            <h2 id="loan-heading" class="font-sans text-[16px] font-bold">Buying with a car loan?</h2>
            <p class="text-[13px] text-muted">Apply to lenders who lend for this car. Each lender gives you its own rate and terms.</p>
        </div>
        <div class="flex flex-wrap gap-2">
            <Link :href="applyHref" class="btn btn-primary h-11 no-underline">Apply for a car loan</Link>
            <button type="button" class="btn btn-outline h-11" :aria-expanded="open" aria-controls="loan-calculator" @click="open = !open">
                {{ open ? 'Hide repayments' : 'Work out repayments' }}
            </button>
        </div>

        <div v-if="open" id="loan-calculator" class="flex flex-col gap-4">
            <label class="flex flex-col gap-1.5 text-[13px] font-semibold">
                <span class="flex justify-between"><span>Deposit</span><span>{{ depositPercent }}% · {{ formatNaira(loan.deposit) }}</span></span>
                <input v-model.number="depositPercent" type="range" min="10" max="90" step="5" class="h-11 accent-forest" />
            </label>

            <fieldset>
                <legend class="mb-1.5 text-[13px] font-semibold">Loan length</legend>
                <div class="grid grid-cols-4 gap-2">
                    <label
                        v-for="m in finance.defaults.tenors"
                        :key="m"
                        class="flex h-11 cursor-pointer items-center justify-center rounded-xl border border-line bg-white text-[14px] font-semibold has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-clay"
                    >
                        <input v-model="months" type="radio" :name="`months-${finance.price}`" :value="m" class="sr-only" />{{ m }} mo
                    </label>
                </div>
            </fieldset>

            <label class="field-label">
                Interest rate per year (%)
                <input v-model.number="rate" type="number" inputmode="decimal" min="0" max="99" step="0.5" class="field" />
            </label>

            <dl class="grid grid-cols-2 gap-3 rounded-2xl bg-ivory p-3.5 text-[14px]" aria-live="polite">
                <div class="col-span-2">
                    <dt class="text-[12px] text-muted">Monthly repayment</dt>
                    <dd class="font-display text-[26px] font-bold text-forest">{{ formatNaira(loan.monthly) }}</dd>
                </div>
                <div>
                    <dt class="text-[12px] text-muted">Loan</dt>
                    <dd class="font-semibold">{{ formatNaira(loan.principal) }}</dd>
                </div>
                <div>
                    <dt class="text-[12px] text-muted">Total you pay</dt>
                    <dd class="font-semibold">{{ formatNaira(loan.total) }}</dd>
                </div>
            </dl>
            <p class="text-[12px] text-muted">Estimate only, not a loan offer. Interest over the loan: about {{ formatNaira(loan.interest) }}.</p>
        </div>
    </section>
</template>
