<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { Customer, CustomerRef, StockCar, TradeInOption } from '@/components/manager/types';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { formatNaira, parseAmount } from '@/lib/format';
import { uuid } from '@/lib/offlineStore';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ stock: StockCar[]; tradeIns: TradeInOption[]; vehicle: string | null; customer: Customer | null; customers: CustomerRef[] }>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const orderable = computed(() => props.stock.filter((c) => c.orderable));

const mode = ref<'existing' | 'new'>(props.customer || props.customers.length ? 'existing' : 'new');
const customerSearch = ref('');

const form = useForm({
    vehicle: props.vehicle && orderable.value.some((c) => c.ulid === props.vehicle) ? props.vehicle : '',
    customer: props.customer?.ulid ?? '',
    name: '',
    phone: '',
    consent_whatsapp: false,
    agreed_price: '',
    discount: '',
    trade_in: '',
    trade_in_value: '',
    deposit_required: '',
    notes: '',
    client_uuid: uuid(),
});

const car = computed(() => props.stock.find((c) => c.ulid === form.vehicle));
const chosen = computed(() => props.customer?.ulid === form.customer ? props.customer : props.customers.find((c) => c.ulid === form.customer));
const customerMatches = computed(() => {
    const term = customerSearch.value.trim().toLowerCase();
    const digits = term.replace(/\D/g, '');
    return props.customers
        .filter((c) => !term || c.name.toLowerCase().includes(term) || (digits && c.phone_display.replace(/\D/g, '').includes(digits)))
        .slice(0, 6);
});

watch(car, (c) => {
    // A reserved car is sold to the buyer who paid the deposit, at the reserved price.
    if (c?.reservation) {
        form.agreed_price = formatNaira(c.reservation.price);
        if (c.reservation.customer) {
            mode.value = 'existing';
            form.customer = c.reservation.customer;
        }
    } else if (c?.price) {
        form.agreed_price = formatNaira(c.price);
    }
}, { immediate: true });

// The chosen customer's trade-ins first.
const tradeInOptions = computed(() => [...props.tradeIns].sort((a, b) => Number(b.customer === form.customer) - Number(a.customer === form.customer)));
watch(() => form.trade_in, (ulid) => {
    const t = props.tradeIns.find((x) => x.ulid === ulid);
    form.trade_in_value = t?.value ? formatNaira(t.value) : '';
});

const total = computed(() => Math.max(0, (parseAmount(form.agreed_price) ?? 0) - (parseAmount(form.discount) ?? 0) - (parseAmount(form.trade_in_value) ?? 0)));

function submit() {
    form.transform((data) => ({
        ...data,
        customer: mode.value === 'existing' ? data.customer : null,
        trade_in: data.trade_in || null,
        trade_in_value: data.trade_in ? data.trade_in_value : null,
        name: mode.value === 'new' ? data.name : null,
        phone: mode.value === 'new' ? data.phone : null,
    })).post(route('dealer.manager.orders.store', lot.value.slug));
}
</script>

<template>
    <Head title="New order" />
    <DealerLayout>
        <Link :href="route('dealer.manager.orders.index', lot.slug)" class="inline-flex h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline">
            <Icon name="chevronLeft" :size="18" /> Orders
        </Link>
        <h1 class="text-[30px] font-bold">New order</h1>

        <form class="grid max-w-3xl gap-5" @submit.prevent="submit">
            <section class="card flex flex-col gap-3 p-5">
                <h2 class="font-sans text-[16px] font-bold">Car</h2>
                <label class="field-label">
                    <span class="sr-only">Car</span>
                    <select v-model="form.vehicle" class="field" required>
                        <option value="" disabled>Pick a car from your stock</option>
                        <option v-for="c in orderable" :key="c.ulid" :value="c.ulid">{{ c.title }}{{ c.price_label ? ` · ${c.price_label}` : '' }}{{ c.status === 'reserved' ? ' (reserved)' : '' }}</option>
                    </select>
                    <InputError :message="form.errors.vehicle" />
                </label>
                <p v-if="car?.reservation" class="rounded-xl bg-cream px-3 py-2.5 text-[14px] text-clay-dark">
                    Reserved by <strong>{{ car.reservation.by }}</strong>. Their {{ car.reservation.deposit }} deposit is added to the order as a payment.
                </p>
                <p v-if="orderable.length === 0" class="text-[14px] text-muted">
                    Only cars listed on LotLink can be ordered.
                    <Link :href="route('dealer.vehicles.index', lot.slug)">List a car from your stock</Link>.
                </p>
            </section>

            <section class="card flex flex-col gap-3 p-5">
                <div class="flex items-center justify-between gap-3">
                    <h2 class="font-sans text-[16px] font-bold">Customer</h2>
                    <div class="flex rounded-xl bg-ivory p-1 text-[14px]" role="group" aria-label="Customer">
                        <button type="button" class="h-9 rounded-lg px-3" :class="mode === 'existing' ? 'bg-white font-semibold shadow-sm' : ''" :aria-pressed="mode === 'existing'" @click="mode = 'existing'">From your book</button>
                        <button type="button" class="h-9 rounded-lg px-3" :class="mode === 'new' ? 'bg-white font-semibold shadow-sm' : ''" :aria-pressed="mode === 'new'" @click="mode = 'new'">New</button>
                    </div>
                </div>

                <template v-if="mode === 'existing'">
                    <div v-if="chosen" class="flex items-center justify-between gap-3 rounded-xl bg-ivory px-3 py-2.5">
                        <span><strong>{{ chosen.name }}</strong> <span class="text-muted">{{ chosen.phone_display }}</span></span>
                        <button type="button" class="h-10 px-2 text-[14px] font-semibold text-forest" @click="form.customer = ''">Change</button>
                    </div>
                    <template v-else>
                        <label class="relative">
                            <span class="sr-only">Search customers</span>
                            <Icon name="search" class="absolute top-3.5 left-3 text-muted" :size="18" />
                            <input v-model="customerSearch" type="search" class="field pl-10" placeholder="Search name or phone" />
                        </label>
                        <ul class="divide-y divide-divider rounded-xl border border-line">
                            <li v-for="c in customerMatches" :key="c.ulid">
                                <button type="button" class="flex h-12 w-full items-center justify-between gap-2 px-3 text-left text-[14px] hover:bg-ivory" @click="form.customer = c.ulid">
                                    <span class="truncate font-semibold">{{ c.name }}</span><span class="shrink-0 text-muted">{{ c.phone_display }}</span>
                                </button>
                            </li>
                            <li v-if="customerMatches.length === 0" class="px-3 py-3 text-[14px] text-muted">No match. Switch to "New".</li>
                        </ul>
                    </template>
                    <InputError :message="form.errors.customer" />
                </template>

                <template v-else>
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="field-label">Name<input v-model="form.name" class="field" required /><InputError :message="form.errors.name" /></label>
                        <label class="field-label">Phone<input v-model="form.phone" class="field" type="tel" inputmode="tel" required /><InputError :message="form.errors.phone" /></label>
                    </div>
                    <label class="flex min-h-11 items-center gap-3 text-[14px]">
                        <input v-model="form.consent_whatsapp" type="checkbox" class="h-5 w-5 accent-forest" /> They agreed to receipts and updates on WhatsApp
                    </label>
                </template>
            </section>

            <section class="card flex flex-col gap-3 p-5">
                <h2 class="font-sans text-[16px] font-bold">Price</h2>
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="field-label">Agreed price<input v-model="form.agreed_price" class="field" inputmode="numeric" required /><InputError :message="form.errors.agreed_price" /></label>
                    <label class="field-label">Discount<input v-model="form.discount" class="field" inputmode="numeric" placeholder="₦0" /><InputError :message="form.errors.discount" /></label>
                    <label class="field-label">Deposit needed<input v-model="form.deposit_required" class="field" inputmode="numeric" placeholder="Optional" /></label>
                </div>
                <p class="text-[14px]">
                    Customer pays <strong class="font-display text-[18px] text-forest">{{ formatNaira(total) }}</strong>
                    <span v-if="car?.price && total !== car.price" class="text-muted"> (listed at {{ car.price_label }})</span>
                </p>
                <div v-if="tradeIns.length" class="grid gap-3 sm:grid-cols-3">
                    <label class="field-label sm:col-span-2">
                        Trade-in
                        <select v-model="form.trade_in" class="field">
                            <option value="">No trade-in</option>
                            <option v-for="t in tradeInOptions" :key="t.ulid" :value="t.ulid">{{ t.label }}</option>
                        </select>
                        <InputError :message="form.errors.trade_in" />
                    </label>
                    <label v-if="form.trade_in" class="field-label">Trade-in value<input v-model="form.trade_in_value" class="field" inputmode="numeric" /><InputError :message="form.errors.trade_in_value" /></label>
                </div>
                <p class="text-[13px] text-muted">Instalment plans arrive with Lot Manager Pro.</p>
                <label class="field-label">Notes<textarea v-model="form.notes" class="field h-20 py-2.5" maxlength="1000" /></label>
            </section>

            <div class="flex gap-3">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Create order</button>
                <Link :href="route('dealer.manager.orders.index', lot.slug)" class="btn btn-outline">Cancel</Link>
            </div>
        </form>
    </DealerLayout>
</template>
