<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import { computed } from 'vue';

/** A lender's loan product: rate, loan range, deposit, lengths and the states it lends in (sign-up and settings). */
type ProductForm = {
    rate: number | string;
    min_amount: string;
    max_amount: string;
    min_deposit_percent: number | string;
    tenors: number[];
    states: string[];
    errors: Partial<Record<string, string>>;
};
const props = defineProps<{ form: ProductForm; tenors: number[]; minLoan: number; disabled?: boolean }>();

const { regions } = useShared();
const everywhere = computed({
    get: () => props.form.states.length === 0,
    set: (all: boolean) => {
        // eslint-disable-next-line vue/no-mutating-props
        props.form.states = all ? [] : ['Lagos'];
    },
});
</script>

<template>
    <fieldset class="flex flex-col gap-4" :disabled="disabled">
        <div class="grid gap-4 sm:grid-cols-3">
            <label class="field-label">
                Rate from (% a year)
                <input v-model="form.rate" type="number" min="1" max="99" step="0.01" inputmode="decimal" class="field h-11" required />
                <InputError :message="form.errors.rate" />
            </label>
            <label class="field-label">
                Smallest loan (₦)
                <input v-field="{ kind: 'money', min: minLoan }" v-model="form.min_amount" inputmode="numeric" class="field h-11" required />
                <InputError :message="form.errors.min_amount" />
            </label>
            <label class="field-label">
                Largest loan (₦)
                <input v-field="{ kind: 'money', min: minLoan }" v-model="form.max_amount" inputmode="numeric" class="field h-11" required />
                <InputError :message="form.errors.max_amount" />
            </label>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <label class="field-label">
                Smallest deposit (%)
                <input v-model="form.min_deposit_percent" type="number" min="0" max="90" step="1" inputmode="numeric" class="field h-11" required />
                <InputError :message="form.errors.min_deposit_percent" />
            </label>
            <div class="flex flex-col gap-1.5 sm:col-span-2">
                <span class="field-label">Loan lengths</span>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="t in tenors"
                        :key="t"
                        class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-3 text-[14px] font-semibold"
                        :class="form.tenors.includes(t) ? 'border-forest bg-map text-forest' : 'border-line bg-white'"
                    >
                        <input v-model="form.tenors" type="checkbox" :value="t" class="h-4 w-4 accent-forest" /> {{ t }} months
                    </label>
                </div>
                <InputError :message="form.errors.tenors" />
            </div>
        </div>
        <div class="flex flex-col gap-2">
            <span class="field-label">Where you lend</span>
            <label class="flex min-h-11 cursor-pointer items-center gap-2 text-[14px]">
                <input v-model="everywhere" type="checkbox" class="h-5 w-5 accent-forest" /> Every state (buyers anywhere in Nigeria)
            </label>
            <div v-if="!everywhere" class="grid max-h-56 grid-cols-2 gap-x-3 overflow-y-auto rounded-xl border border-line p-3 sm:grid-cols-3">
                <label v-for="r in regions" :key="r.value" class="flex min-h-9 cursor-pointer items-center gap-2 text-[14px]">
                    <input v-model="form.states" type="checkbox" :value="r.value" class="h-4 w-4 accent-forest" /> {{ r.label }}
                </label>
            </div>
            <InputError :message="form.errors.states" />
        </div>
    </fieldset>
</template>
