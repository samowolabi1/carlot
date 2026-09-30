<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { Option } from '@/components/manager/types';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

type Cost = { ulid: string; type: string; amount: string; supplier: string | null; note: string | null; date: string; by: string | null; receipt_url: string | null };

const props = defineProps<{
    vehicle: { ulid: string; title: string; price: string | null; status: string };
    costs: Cost[];
    total: string;
    profit: { revenue: string; costs: string; profit: string; margin: number | null; order_no: string | null; sold: boolean; negative: boolean } | null;
    types: Option[];
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const today = new Date().toISOString().slice(0, 10);

const form = useForm<{ type: string; amount: string; supplier: string; note: string; incurred_at: string; receipt: File | null }>({
    type: props.costs.some((c) => c.type === 'Purchase') ? 'repair' : 'purchase',
    amount: '',
    supplier: '',
    note: '',
    incurred_at: today,
    receipt: null,
});

function add() {
    form.post(route('dealer.vehicles.costs.store', [lot.value.slug, props.vehicle.ulid]), {
        preserveScroll: true,
        forceFormData: true,
        onSuccess: () => form.reset('amount', 'supplier', 'note', 'receipt'),
    });
}

function remove(c: Cost) {
    if (confirm(`Remove the ${c.type.toLowerCase()} cost of ${c.amount}?`)) {
        router.delete(route('dealer.vehicles.costs.destroy', [lot.value.slug, props.vehicle.ulid, c.ulid]), { preserveScroll: true });
    }
}
</script>

<template>
    <Head :title="`Costs · ${vehicle.title}`" />
    <DealerLayout>
        <Link :href="route('dealer.vehicles.index', lot.slug)" class="inline-flex h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline">
            <Icon name="chevronLeft" :size="18" /> Stock
        </Link>
        <div>
            <p class="text-[14px] font-semibold text-muted">Car costs and profit</p>
            <h1 class="text-[28px] font-bold">{{ vehicle.title }}</h1>
            <p class="text-[14px] text-muted">{{ vehicle.status }}<template v-if="vehicle.price"> · listed at {{ vehicle.price }}</template> · only owners and managers see this page</p>
        </div>

        <div class="grid gap-5 xl:grid-cols-[1fr_380px]">
            <div class="flex flex-col gap-5">
                <section v-if="profit" class="card grid gap-4 p-5 sm:grid-cols-3" aria-label="Profit">
                    <div class="flex flex-col">
                        <span class="text-[13px] text-muted">{{ profit.order_no ? (profit.sold ? `Sold on ${profit.order_no}` : `Agreed on ${profit.order_no}`) : 'At the list price' }}</span>
                        <span class="font-display text-[22px] font-bold">{{ profit.revenue }}</span>
                    </div>
                    <div class="flex flex-col"><span class="text-[13px] text-muted">Costs</span><span class="font-display text-[22px] font-bold">{{ profit.costs }}</span></div>
                    <div class="flex flex-col">
                        <span class="text-[13px] text-muted">{{ profit.negative ? 'Loss' : profit.sold ? 'Profit' : 'Expected profit' }}</span>
                        <span class="font-display text-[22px] font-bold" :class="profit.negative ? 'text-danger' : 'text-success'">
                            {{ profit.negative ? '−' : '' }}{{ profit.profit }}<span v-if="profit.margin !== null" class="text-[14px] font-semibold"> ({{ profit.margin }}%)</span>
                        </span>
                    </div>
                </section>

                <section class="card overflow-hidden" aria-labelledby="costs-heading">
                    <div class="flex items-center justify-between border-b border-divider px-4 py-3">
                        <h2 id="costs-heading" class="font-sans text-[16px] font-bold">Costs</h2>
                        <span class="text-[14px] font-semibold">{{ total }}</span>
                    </div>
                    <p v-if="costs.length === 0" class="px-4 py-6 text-[14px] text-muted">No costs yet. Start with what you paid for the car.</p>
                    <ul v-else class="divide-y divide-divider">
                        <li v-for="c in costs" :key="c.ulid" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                            <span class="flex min-w-0 grow flex-col">
                                <span class="font-semibold">{{ c.type }} · {{ c.amount }}</span>
                                <span class="text-[13px] text-muted">
                                    {{ c.date }}<template v-if="c.supplier"> · {{ c.supplier }}</template><template v-if="c.note"> · {{ c.note }}</template><template v-if="c.by"> · {{ c.by }}</template>
                                </span>
                            </span>
                            <span class="flex shrink-0 items-center gap-3">
                                <a v-if="c.receipt_url" :href="c.receipt_url" class="inline-flex h-11 items-center gap-1 text-[14px] font-semibold"><Icon name="download" :size="16" /> Receipt</a>
                                <button type="button" class="h-11 px-1 text-[13px] font-semibold text-muted hover:text-danger" @click="remove(c)">Remove</button>
                            </span>
                        </li>
                    </ul>
                </section>
            </div>

            <form class="card flex flex-col gap-3 self-start p-5" @submit.prevent="add">
                <h2 class="font-sans text-[16px] font-bold">Add a cost</h2>
                <label class="field-label">
                    What for
                    <select v-model="form.type" class="field">
                        <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                    </select>
                </label>
                <label class="field-label">Amount<input v-field="'money'" v-model="form.amount" class="field" inputmode="numeric" required placeholder="₦0" /><InputError :message="form.errors.amount" /></label>
                <label class="field-label">Date<input v-model="form.incurred_at" type="date" :max="today" class="field" required /><InputError :message="form.errors.incurred_at" /></label>
                <label class="field-label">Paid to (optional)<input v-field="'business_name'" v-model="form.supplier" class="field" placeholder="e.g. Apapa clearing agent" /></label>
                <label class="field-label">Note (optional)<input v-field="{ kind: 'text', max: 500 }" v-model="form.note" class="field" /></label>
                <label class="field-label">
                    Receipt (optional)
                    <input type="file" accept="image/*,application/pdf" class="text-[14px]" @change="form.receipt = ($event.target as HTMLInputElement).files?.[0] ?? null" />
                    <InputError :message="form.errors.receipt" />
                </label>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Add cost</button>
            </form>
        </div>
    </DealerLayout>
</template>
