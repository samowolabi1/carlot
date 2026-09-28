<script setup lang="ts">
import NairaInput from '@/components/finance/NairaInput.vue';
import Icon from '@/components/Icon.vue';
import { useBudget } from '@/composables/useBudget';
import { useShared } from '@/composables/useShared';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { affordability, type FinanceDefaults } from '@/lib/finance';
import { formatNaira } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

interface Saved {
    monthly_income: number;
    monthly_commitments: number;
    deposit: number;
    tenor_months: number;
    interest_rate: number;
    max_price: number;
}

const props = defineProps<{ saved: Saved | null; finance: FinanceDefaults }>();

const { user } = useShared();
const budget = useBudget();
const start = props.saved ?? budget.local.value;

const form = reactive({
    monthly_income: start?.monthly_income ?? 0,
    monthly_commitments: start?.monthly_commitments ?? 0,
    deposit: start?.deposit ?? 0,
    tenor_months: start?.tenor_months ?? props.finance.tenor_months,
    interest_rate: start?.interest_rate ?? props.finance.interest_rate,
});
const save = ref(true);
const saving = ref(false);
const count = ref<number | null>(null);

const result = computed(() =>
    affordability(form.monthly_income, form.monthly_commitments, form.deposit, form.tenor_months, Number(form.interest_rate) || 0, props.finance.affordability_ratio),
);
const ready = computed(() => form.monthly_income > 0);
const percent = computed(() => Math.round(props.finance.affordability_ratio * 100));

// "Show 31 cars within budget": counted on the server as the numbers change.
let timer: ReturnType<typeof setTimeout> | undefined;
watch(
    () => result.value.maxPrice,
    (max) => {
        clearTimeout(timer);
        if (!ready.value) {
            count.value = null;
            return;
        }
        timer = setTimeout(async () => {
            try {
                const response = await fetch(route('budget.count', { max }), { headers: { Accept: 'application/json' } });
                count.value = response.ok ? ((await response.json()) as { count: number }).count : null;
            } catch {
                count.value = null;
            }
        }, 300);
    },
    { immediate: true },
);

function showCars() {
    const max = result.value.maxPrice;
    const target = route('cars.index', { price_max: max });

    if (!save.value) {
        router.visit(target);
        return;
    }

    budget.remember({ ...form, interest_rate: Number(form.interest_rate) || 0, max_price: max });

    if (!user.value) {
        router.visit(target);
        return;
    }

    saving.value = true;
    router.put(route('budget.update'), { ...form }, {
        preserveScroll: true,
        onSuccess: () => router.visit(target),
        onFinish: () => (saving.value = false),
    });
}
</script>

<template>
    <Head title="What can I afford?" />
    <CustomerLayout>
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-2 pb-44 md:pt-8 md:pb-16">
            <div class="flex items-center gap-2">
                <Link :href="route('home')" aria-label="Back" class="-ml-3 flex h-11 w-11 items-center justify-center text-ink md:hidden"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></Link>
                <h1 class="text-[22px] font-bold md:text-[30px]">What can I afford?</h1>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <NairaInput v-model="form.monthly_income" label="Monthly income" />
                <NairaInput v-model="form.monthly_commitments" label="Monthly commitments" />
            </div>
            <NairaInput v-model="form.deposit" label="Deposit you can pay now" />

            <fieldset class="flex flex-col gap-1.5">
                <legend class="mb-1.5 text-[13px] font-semibold">Loan length</legend>
                <div class="grid grid-cols-4 gap-2">
                    <label
                        v-for="months in finance.tenors"
                        :key="months"
                        class="flex h-11 cursor-pointer items-center justify-center rounded-xl border border-line bg-white text-[14px] font-semibold has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-clay"
                    >
                        <input v-model="form.tenor_months" type="radio" name="tenor" :value="months" class="sr-only" />{{ months }} mo
                    </label>
                </div>
            </fieldset>

            <label class="field-label">
                Interest rate per year (%)
                <input v-model.number="form.interest_rate" class="field" type="number" inputmode="decimal" min="0" max="99" step="0.5" />
            </label>

            <section class="flex flex-col gap-2.5 rounded-[18px] bg-forest p-[18px] text-white" aria-live="polite">
                <span class="text-[13px] text-mist">You can look at cars up to</span>
                <span class="font-display text-[34px] leading-none font-bold tracking-tight">{{ ready ? formatNaira(result.maxPrice) : '₦—' }}</span>
                <div class="grid grid-cols-2 gap-2.5 border-t border-forest-600 pt-2.5">
                    <div>
                        <div class="text-[12px] text-mist">Monthly payment</div>
                        <div class="text-[16px] font-semibold">{{ ready ? formatNaira(result.monthly) : '—' }}</div>
                    </div>
                    <div>
                        <div class="text-[12px] text-mist">Loan amount</div>
                        <div class="text-[16px] font-semibold">{{ ready ? formatNaira(result.loan) : '—' }}</div>
                    </div>
                </div>
                <span class="text-[12px] text-mist">Keeps repayments at {{ percent }}% of what's left after commitments.</span>
            </section>

            <label class="flex min-h-11 cursor-pointer items-center gap-2.5 text-[14px]">
                <input v-model="save" type="checkbox" class="h-5 w-5 accent-forest" />
                Save as my budget and tag cars I can afford
            </label>
            <p v-if="save && !user" class="-mt-2 text-[13px] text-muted">
                Saved on this phone. <Link :href="route('login')">Sign in</Link> to keep it on all your devices.
            </p>
            <p class="text-[12px] text-muted">Estimates only, not a loan offer. Running costs (insurance, papers, fuel, servicing) show on each car page.</p>
        </div>

        <div class="fixed inset-x-0 bottom-[76px] z-30 border-t border-line bg-white px-5 pt-3 pb-3 md:static md:mx-auto md:max-w-xl md:border-0 md:bg-transparent md:pb-12">
            <button type="button" class="btn btn-primary h-[52px] w-full rounded-[14px]" :disabled="!ready || saving" @click="showCars">
                <template v-if="!ready">Enter your monthly income</template>
                <template v-else-if="count === null">Show cars within budget</template>
                <template v-else>Show {{ count }} {{ count === 1 ? 'car' : 'cars' }} within budget</template>
            </button>
        </div>
    </CustomerLayout>
</template>
