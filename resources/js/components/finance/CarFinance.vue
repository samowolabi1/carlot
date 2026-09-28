<script setup lang="ts">
import { type FinanceDefaults, loanFor } from '@/lib/finance';
import { formatNaira } from '@/lib/format';
import { computed, ref } from 'vue';

export interface CarFinanceData {
    price: number;
    from: { monthly: number; deposit_percent: number; months: number; rate: number };
    ownership: { items: { label: string; amount: number }[]; total: number };
    defaults: FinanceDefaults;
}

const props = defineProps<{ finance: CarFinanceData }>();

const depositPercent = ref(props.finance.defaults.deposit_percent);
const months = ref(props.finance.defaults.tenor_months);
const rate = ref(props.finance.defaults.interest_rate);

const loan = computed(() => loanFor(props.finance.price, depositPercent.value, months.value, Number(rate.value) || 0));
</script>

<template>
    <section class="card flex flex-col gap-4 p-4" aria-labelledby="loan-heading">
        <div class="flex flex-col gap-1">
            <h2 id="loan-heading" class="font-sans text-[16px] font-bold">Pay monthly</h2>
            <p class="text-[13px] text-muted">If you buy with a loan or the lot's instalment plan. Rates vary by lender.</p>
        </div>

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
                <dt class="text-[12px] text-muted">Monthly payment</dt>
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
    </section>

    <section class="card flex flex-col gap-3 p-4" aria-labelledby="costs-heading">
        <div class="flex flex-col gap-1">
            <h2 id="costs-heading" class="font-sans text-[16px] font-bold">Running costs</h2>
            <p class="text-[13px] text-muted">What this car may cost to keep each year, on top of the price.</p>
        </div>
        <dl class="flex flex-col text-[14px]">
            <div v-for="item in finance.ownership.items" :key="item.label" class="flex justify-between gap-3 border-b border-divider py-2">
                <dt class="text-muted">{{ item.label }}</dt>
                <dd class="font-semibold">{{ formatNaira(item.amount) }}</dd>
            </div>
            <div class="flex justify-between gap-3 pt-2">
                <dt class="font-semibold">About a year</dt>
                <dd class="font-bold">{{ formatNaira(finance.ownership.total) }} <span class="font-normal text-muted">({{ formatNaira(Math.round(finance.ownership.total / 12 / 1000) * 1000) }}/mo)</span></dd>
            </div>
        </dl>
        <p class="text-[12px] text-muted">Estimates from typical Lagos prices. Fuel assumes about 1,200 km a month.</p>
    </section>
</template>
