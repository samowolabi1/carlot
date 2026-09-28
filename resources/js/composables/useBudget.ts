import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const KEY = 'lotlink:budget';

export interface BudgetInputs {
    monthly_income: number;
    monthly_commitments: number;
    deposit: number;
    tenor_months: number;
    interest_rate: number;
    max_price: number;
}

// Guests keep their budget on this device; signed-in buyers also save it to their account.
const local = ref<BudgetInputs | null>(read());

function read(): BudgetInputs | null {
    try {
        const raw = typeof localStorage !== 'undefined' ? localStorage.getItem(KEY) : null;
        return raw ? (JSON.parse(raw) as BudgetInputs) : null;
    } catch {
        return null;
    }
}

export function useBudget() {
    const page = usePage<SharedProps>();

    /** The maximum price in whole naira: the account's saved budget first, then this device's. */
    const maxPrice = computed<number | null>(() => page.props.budget ?? local.value?.max_price ?? null);

    function remember(inputs: BudgetInputs | null) {
        local.value = inputs;
        try {
            if (inputs) localStorage.setItem(KEY, JSON.stringify(inputs));
            else localStorage.removeItem(KEY);
        } catch {
            // Private browsing: the budget lasts for this visit only.
        }
    }

    const within = (price: number | null | undefined) => maxPrice.value !== null && price !== null && price !== undefined && price <= maxPrice.value;

    return { maxPrice, local, remember, within };
}
