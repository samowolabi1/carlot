<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { statusBadge, type Customer, type Option, type OrderRow } from '@/components/manager/types';
import { submitOrQueue, useOfflineQueue } from '@/composables/useOfflineQueue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { formatNaira } from '@/lib/format';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Payment {
    ulid: string;
    amount: string;
    refund: boolean;
    method: string;
    reference: string | null;
    receipt_no: string | null;
    when: string;
    by: string | null;
    void: boolean;
    void_reason: string | null;
    receipt_url: string;
}

type Order = OrderRow & {
    list_price: string;
    agreed_price: string;
    discount: string | null;
    trade_in: string | null;
    deposit_required: string | null;
    balance_major: number;
    staff: string | null;
    notes: string | null;
    cancelled_reason: string | null;
    delivered: string | null;
    vehicle_ulid: string | null;
    track_url: string;
};

interface InstalmentRow {
    sequence: number;
    due: string;
    amount: string;
    paid: string;
    status: 'pending' | 'part_paid' | 'paid' | 'overdue';
    status_label: string;
}

interface DocumentRow {
    ulid: string;
    name: string;
    mandatory: boolean;
    status: 'pending' | 'received' | 'handed_over';
    status_label: string;
    removable: boolean;
    when: string | null;
    file_url: string | null;
}

const props = defineProps<{
    order: Order;
    customer: Customer | null;
    payments: Payment[];
    steps: { value: string; label: string; done: boolean }[];
    can: { pay: boolean; papers: boolean; deliver: boolean; void: boolean; cancel: boolean };
    instalments: InstalmentRow[];
    plan: { allowed: boolean; can: boolean; frequencies: Option[]; first_due: string };
    documents: DocumentRow[];
    profit: { revenue: string; costs: string; profit: string; margin: number | null; negative: boolean; costs_url: string } | null;
    methods: Option[];
    share: string | null;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const queue = useOfflineQueue(() => currentLot.value?.slug);
const isOwner = computed(() => lot.value.role === 'owner');

const pay = useForm({ amount: props.order.balance_major ? formatNaira(props.order.balance_major) : '', method: 'transfer', reference: '', client_uuid: '' });
const queued = ref('');

// Suggest what is left to pay after each payment.
watch(
    () => props.order.balance_major,
    (left) => (pay.amount = left ? formatNaira(left) : ''),
);

function recordPayment() {
    const data = { order: props.order.ulid, amount: String(pay.amount).replace(/[^\d]/g, ''), method: pay.method, reference: pay.reference || null, paid_at: new Date().toISOString() };
    queued.value = '';
    submitOrQueue({
        queue,
        type: 'payment',
        label: `${formatNaira(Number(data.amount))} on ${props.order.order_no}`,
        data,
        post: (clientUuid, handlers) => {
            pay.transform(() => ({ ...data, client_uuid: clientUuid })).post(route('dealer.manager.orders.payments.store', [lot.value.slug, props.order.ulid]), {
                preserveScroll: true,
                ...handlers,
            });
        },
        onSuccess: () => pay.reset('reference'),
        onQueued: () => {
            queued.value = 'No signal: the payment is saved on this phone and gets its receipt number when it syncs.';
            pay.reset('reference');
        },
    });
}

const status = useForm({ status: '', override_reason: '' });
const overriding = ref(false);

function setStatus(value: 'papers_ready' | 'delivered') {
    if (value === 'delivered' && props.order.balance_minor > 0 && !overriding.value) {
        overriding.value = true;
        return;
    }
    if (value === 'delivered' && props.order.balance_minor <= 0 && !confirm('Hand over the car? It will be marked sold and taken off the marketplace.')) {
        return;
    }
    status.status = value;
    status.patch(route('dealer.manager.orders.update', [lot.value.slug, props.order.ulid]), { preserveScroll: true, onSuccess: () => (overriding.value = false) });
}

const voiding = ref<Payment | null>(null);
const voidForm = useForm({ reason: '' });

function voidPayment() {
    if (!voiding.value) return;
    voidForm.post(route('dealer.manager.payments.void', [lot.value.slug, voiding.value.ulid]), {
        preserveScroll: true,
        onSuccess: () => {
            voiding.value = null;
            voidForm.reset();
        },
    });
}

const cancelling = ref(false);
const cancelForm = useForm({ reason: '', money: '', refund_method: 'transfer' });
const hasPayments = computed(() => props.payments.some((p) => !p.void && !p.refund));

function cancelOrder() {
    cancelForm.post(route('dealer.manager.orders.cancel', [lot.value.slug, props.order.ulid]), { preserveScroll: true, onSuccess: () => (cancelling.value = false) });
}

// Instalment plan (TDD M19): split what is left into up to 24 dated amounts.
const planning = ref(false);
const planForm = useForm({ count: 3, first_due: props.plan.first_due, frequency: 'monthly' });
const perInstalment = computed(() => (planForm.count > 0 ? Math.floor(props.order.balance_major / planForm.count) : 0));

function savePlan() {
    planForm.put(route('dealer.manager.orders.instalments.update', [lot.value.slug, props.order.ulid]), { preserveScroll: true, onSuccess: () => (planning.value = false) });
}

function removePlan() {
    if (confirm('Remove the instalment plan? Payments already made stay on the order.')) {
        router.delete(route('dealer.manager.orders.instalments.destroy', [lot.value.slug, props.order.ulid]), { preserveScroll: true });
    }
}

// Papers and handover checklist.
function setDocument(d: DocumentRow, status: DocumentRow['status'], file?: File) {
    router.post(route('dealer.manager.orders.documents.update', [lot.value.slug, props.order.ulid, d.ulid]), { status, file: file ?? null }, { preserveScroll: true, forceFormData: !!file });
}

function uploadDocument(d: DocumentRow, event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (file) setDocument(d, d.status === 'pending' ? 'received' : d.status, file);
}

const extra = useForm({ label: '', mandatory: false });
function addDocument() {
    extra.post(route('dealer.manager.orders.documents.store', [lot.value.slug, props.order.ulid]), { preserveScroll: true, onSuccess: () => extra.reset() });
}

function removeDocument(d: DocumentRow) {
    router.delete(route('dealer.manager.orders.documents.destroy', [lot.value.slug, props.order.ulid, d.ulid]), { preserveScroll: true });
}

const missingPapers = computed(() => props.documents.filter((d) => d.mandatory && d.status === 'pending').map((d) => d.name));

const copied = ref(false);
async function copyLink() {
    await navigator.clipboard?.writeText(props.order.track_url);
    copied.value = true;
    setTimeout(() => (copied.value = false), 2000);
}

</script>

<template>
    <Head :title="order.order_no" />
    <DealerLayout>
        <Link :href="route('dealer.manager.orders.index', lot.slug)" class="inline-flex h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline">
            <Icon name="chevronLeft" :size="18" /> Orders
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p class="text-[14px] font-semibold text-muted">{{ order.order_no }}</p>
                <h1 class="text-[28px] font-bold">{{ order.car }}</h1>
                <p class="text-[14px] text-muted">
                    <Link v-if="customer" :href="route('dealer.manager.customers.show', [lot.slug, customer.ulid])">{{ customer.name }}</Link>
                    <template v-if="customer"> · {{ customer.phone_display }}</template>
                    <template v-if="order.staff"> · sold by {{ order.staff }}</template>
                </p>
            </div>
            <span class="rounded-full px-3 py-1.5 text-[13px] font-semibold" :class="statusBadge[order.status]">{{ order.status_label }}</span>
        </div>

        <ol v-if="order.status !== 'cancelled'" class="grid grid-cols-5 gap-1.5" aria-label="Order progress">
            <li v-for="s in steps" :key="s.value" class="flex flex-col gap-1.5">
                <span class="h-1.5 rounded-full" :class="s.done ? 'bg-forest' : 'bg-sand'" />
                <span class="text-[12px]" :class="s.done ? 'font-semibold text-forest' : 'text-muted'">{{ s.label }}</span>
            </li>
        </ol>
        <p v-else class="rounded-xl bg-sand px-4 py-3 text-[14px]">Cancelled: {{ order.cancelled_reason }}</p>

        <div class="grid gap-5 xl:grid-cols-[1fr_400px]">
            <div class="flex flex-col gap-5">
                <section class="card p-5" aria-labelledby="money-heading">
                    <h2 id="money-heading" class="mb-3 font-sans text-[16px] font-bold">Money</h2>
                    <dl class="flex flex-col gap-2 text-[14px]">
                        <div class="flex justify-between"><dt class="text-muted">Listed at</dt><dd>{{ order.list_price }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted">Agreed price</dt><dd>{{ order.agreed_price }}</dd></div>
                        <div v-if="order.discount" class="flex justify-between"><dt class="text-muted">Discount</dt><dd>−{{ order.discount }}</dd></div>
                        <div v-if="order.trade_in" class="flex justify-between"><dt class="text-muted">Trade-in</dt><dd>−{{ order.trade_in }}</dd></div>
                        <div class="flex justify-between border-t border-divider pt-2 font-semibold"><dt>Customer pays</dt><dd>{{ order.total }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted">Paid</dt><dd>{{ order.paid }}</dd></div>
                        <div v-if="order.deposit_required" class="flex justify-between"><dt class="text-muted">Deposit needed</dt><dd>{{ order.deposit_required }}</dd></div>
                    </dl>
                    <div class="mt-3 h-2 overflow-hidden rounded-full bg-sand"><div class="h-full rounded-full bg-forest" :style="{ width: `${order.progress}%` }" /></div>
                    <p class="mt-3 font-display text-[22px] font-bold" :class="order.balance_minor > 0 ? 'text-clay-dark' : 'text-success'">
                        {{ order.balance_minor > 0 ? `${order.balance} to pay` : 'Fully paid' }}
                    </p>
                </section>

                <section class="card overflow-hidden" aria-labelledby="payments-heading">
                    <h2 id="payments-heading" class="border-b border-divider px-4 py-3 font-sans text-[16px] font-bold">Payments and receipts</h2>
                    <p v-if="payments.length === 0" class="px-4 py-6 text-[14px] text-muted">No payments yet. The first payment reserves the car.</p>
                    <ul v-else class="divide-y divide-divider">
                        <li v-for="p in payments" :key="p.ulid" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3" :class="p.void ? 'opacity-60' : ''">
                            <span class="flex min-w-0 grow flex-col">
                                <span class="font-semibold" :class="p.void ? 'line-through' : ''">{{ p.refund ? 'Refund ' : '' }}{{ p.amount }}</span>
                                <span class="text-[13px] text-muted">{{ p.method }}<template v-if="p.reference"> · {{ p.reference }}</template> · {{ p.when }}<template v-if="p.by"> · {{ p.by }}</template></span>
                                <span v-if="p.void" class="text-[13px] font-semibold text-danger">Void: {{ p.void_reason }}</span>
                            </span>
                            <span class="flex shrink-0 items-center gap-3">
                                <a :href="p.receipt_url" target="_blank" rel="noopener" class="inline-flex h-11 items-center gap-1.5 text-[14px] font-semibold"><Icon name="download" :size="16" /> {{ p.receipt_no }}</a>
                                <button v-if="can.void && !p.void && !p.refund" type="button" class="h-11 px-1 text-[13px] font-semibold text-muted hover:text-danger" @click="voiding = p">Void</button>
                            </span>
                        </li>
                    </ul>
                    <form v-if="voiding" class="flex flex-col gap-2 border-t border-divider bg-ivory p-4" @submit.prevent="voidPayment">
                        <label class="field-label">
                            Why void receipt {{ voiding.receipt_no }}?
                            <input v-model="voidForm.reason" class="field" required maxlength="200" placeholder="Entered twice" />
                            <InputError :message="voidForm.errors.reason" />
                        </label>
                        <div class="flex gap-2">
                            <button type="submit" class="btn btn-dark h-11 text-[14px]" :disabled="voidForm.processing">Void payment</button>
                            <button type="button" class="btn btn-outline h-11 text-[14px]" @click="voiding = null">Keep it</button>
                        </div>
                    </form>
                </section>

                <section v-if="instalments.length || (plan.can && order.open)" class="card overflow-hidden" aria-labelledby="plan-heading">
                    <div class="flex items-center justify-between gap-3 border-b border-divider px-4 py-3">
                        <h2 id="plan-heading" class="font-sans text-[16px] font-bold">Instalment plan</h2>
                        <div v-if="plan.can && plan.allowed" class="flex gap-3 text-[14px] font-semibold">
                            <button type="button" class="h-11" @click="planning = !planning">{{ instalments.length ? 'Change' : 'Set up a plan' }}</button>
                            <button v-if="instalments.length" type="button" class="h-11 text-muted hover:text-danger" @click="removePlan">Remove</button>
                        </div>
                    </div>
                    <p v-if="!plan.allowed" class="px-4 py-4 text-[14px] text-muted">
                        Instalment plans with reminders come with the Starter plan. <Link :href="route('dealer.billing', lot.slug)">Upgrade</Link>
                    </p>
                    <p v-else-if="!instalments.length && !planning" class="px-4 py-4 text-[14px] text-muted">
                        Split the {{ order.balance }} balance into dated amounts. The customer gets a WhatsApp reminder 3 days before each one and on the day.
                    </p>
                    <form v-if="planning" class="grid gap-3 border-b border-divider bg-ivory p-4 sm:grid-cols-3" @submit.prevent="savePlan">
                        <label class="field-label">Instalments<input v-model.number="planForm.count" type="number" min="1" max="24" class="field" required /></label>
                        <label class="field-label">First one due<input v-model="planForm.first_due" type="date" class="field" required /></label>
                        <label class="field-label">
                            How often
                            <select v-model="planForm.frequency" class="field">
                                <option v-for="f in plan.frequencies" :key="f.value" :value="f.value">{{ f.label }}</option>
                            </select>
                        </label>
                        <p class="text-[13px] text-muted sm:col-span-2">
                            About {{ formatNaira(perInstalment) }} each. LotLink doesn't lend money: this is your own arrangement with the customer.
                        </p>
                        <button type="submit" class="btn btn-dark h-11 text-[14px]" :disabled="planForm.processing">Save plan</button>
                        <InputError class="sm:col-span-3" :message="planForm.errors.count || planForm.errors.first_due" />
                    </form>
                    <ul v-if="instalments.length" class="divide-y divide-divider">
                        <li v-for="i in instalments" :key="i.sequence" class="flex items-center justify-between gap-3 px-4 py-2.5 text-[14px]">
                            <span class="flex flex-col"><span class="font-semibold">{{ i.amount }}</span><span class="text-[13px] text-muted">{{ i.due }}</span></span>
                            <span class="text-right text-[13px]">
                                <span class="block font-semibold" :class="{ 'text-success': i.status === 'paid', 'text-danger': i.status === 'overdue', 'text-clay-dark': i.status === 'part_paid' }">{{ i.status_label }}</span>
                                <span v-if="i.status === 'part_paid' || i.status === 'overdue'" class="text-muted">{{ i.paid }} paid</span>
                            </span>
                        </li>
                    </ul>
                </section>

                <section v-if="profit" class="card p-5" aria-labelledby="profit-heading">
                    <div class="mb-3 flex items-center justify-between gap-3">
                        <h2 id="profit-heading" class="font-sans text-[16px] font-bold">Profit</h2>
                        <Link :href="profit.costs_url" class="text-[14px] font-semibold">Car costs</Link>
                    </div>
                    <dl class="flex flex-col gap-2 text-[14px]">
                        <div class="flex justify-between"><dt class="text-muted">Sale (after discount)</dt><dd>{{ profit.revenue }}</dd></div>
                        <div class="flex justify-between"><dt class="text-muted">Car costs</dt><dd>−{{ profit.costs }}</dd></div>
                        <div class="flex justify-between border-t border-divider pt-2 font-semibold">
                            <dt>{{ profit.negative ? 'Loss' : 'Profit' }}</dt>
                            <dd :class="profit.negative ? 'text-danger' : 'text-success'">{{ profit.negative ? '−' : '' }}{{ profit.profit }}<template v-if="profit.margin !== null"> ({{ profit.margin }}%)</template></dd>
                        </div>
                    </dl>
                    <p class="mt-2 text-[12px] text-muted">Only owners and managers see this.</p>
                </section>
            </div>

            <div class="flex flex-col gap-5">
                <section v-if="can.pay" class="card flex flex-col gap-3 p-5" aria-labelledby="pay-heading">
                    <h2 id="pay-heading" class="font-sans text-[16px] font-bold">Record a payment</h2>
                    <p v-if="queued" class="rounded-xl bg-cream px-3 py-2.5 text-[14px] text-clay-dark" role="status">{{ queued }}</p>
                    <form class="flex flex-col gap-3" @submit.prevent="recordPayment">
                        <label class="field-label">Amount<input v-model="pay.amount" class="field" inputmode="numeric" required /><InputError :message="pay.errors.amount" /></label>
                        <fieldset>
                            <legend class="mb-1.5 text-[13px] font-semibold">Method</legend>
                            <div class="grid grid-cols-2 gap-1.5">
                                <label v-for="m in methods" :key="m.value" class="flex h-11 cursor-pointer items-center justify-center rounded-xl border border-line-strong text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:font-semibold has-[:checked]:text-white">
                                    <input v-model="pay.method" type="radio" name="method" :value="m.value" class="sr-only" />{{ m.label }}
                                </label>
                            </div>
                            <InputError :message="pay.errors.method" />
                        </fieldset>
                        <label class="field-label">Reference (optional)<input v-model="pay.reference" class="field" maxlength="64" placeholder="Transfer or POS reference" /></label>
                        <button type="submit" class="btn btn-primary" :disabled="pay.processing">{{ pay.processing ? 'Saving…' : 'Save and send receipt' }}</button>
                        <p class="text-[13px] text-muted">
                            {{ customer?.consent_whatsapp ? 'The receipt goes to the customer on WhatsApp.' : 'The customer has not agreed to WhatsApp, so share the receipt yourself.' }}
                        </p>
                    </form>
                </section>

                <section class="card flex flex-col gap-3 p-5" aria-labelledby="handover-heading">
                    <h2 id="handover-heading" class="font-sans text-[16px] font-bold">Papers and handover</h2>
                    <ul class="flex flex-col divide-y divide-divider rounded-xl border border-line">
                        <li v-for="d in documents" :key="d.ulid" class="flex flex-col gap-2 px-3 py-2.5 text-[14px]">
                            <div class="flex items-start justify-between gap-2">
                                <span class="flex flex-col">
                                    <span class="font-semibold">{{ d.name }}<span v-if="d.mandatory" class="text-clay" title="Needed before papers are ready"> *</span></span>
                                    <span class="text-[12px] text-muted">{{ d.status_label }}<template v-if="d.when"> · {{ d.when }}</template></span>
                                </span>
                                <a v-if="d.file_url" :href="d.file_url" class="inline-flex h-9 items-center gap-1 text-[13px] font-semibold"><Icon name="download" :size="14" /> Scan</a>
                            </div>
                            <div v-if="order.open || d.status !== 'handed_over'" class="flex flex-wrap gap-1.5">
                                <button v-if="d.status === 'pending'" type="button" class="h-9 rounded-lg border border-line-strong px-2.5 text-[13px] font-semibold" @click="setDocument(d, 'received')">Received</button>
                                <button v-if="d.status === 'received'" type="button" class="h-9 rounded-lg border border-line-strong px-2.5 text-[13px] font-semibold" @click="setDocument(d, 'handed_over')">Handed over</button>
                                <button v-if="d.status !== 'pending'" type="button" class="h-9 px-1.5 text-[13px] text-muted" @click="setDocument(d, 'pending')">Undo</button>
                                <label class="inline-flex h-9 cursor-pointer items-center rounded-lg border border-dashed border-line-strong px-2.5 text-[13px] font-semibold">
                                    {{ d.file_url ? 'Replace scan' : 'Add scan' }}
                                    <input type="file" accept="image/*,application/pdf" class="sr-only" @change="uploadDocument(d, $event)" />
                                </label>
                                <button v-if="d.removable" type="button" class="h-9 px-1.5 text-[13px] text-muted hover:text-danger" @click="removeDocument(d)">Remove</button>
                            </div>
                        </li>
                    </ul>
                    <form v-if="order.open" class="flex gap-2" @submit.prevent="addDocument">
                        <label class="grow"><span class="sr-only">Another item</span><input v-model="extra.label" class="field h-11" maxlength="80" placeholder="Another item, e.g. service book" /></label>
                        <button type="submit" class="btn btn-outline h-11 text-[14px]" :disabled="!extra.label || extra.processing">Add</button>
                    </form>
                    <p v-if="can.papers && missingPapers.length" class="text-[13px] text-muted">Papers are ready once these are in: {{ missingPapers.join(', ') }}.</p>
                    <InputError :message="status.errors.status" />
                    <button v-if="can.papers" type="button" class="btn btn-outline h-11 text-[14px]" :disabled="status.processing || missingPapers.length > 0" @click="setStatus('papers_ready')">Papers are ready</button>
                    <button v-if="can.deliver" type="button" class="btn btn-dark h-11 text-[14px]" :disabled="status.processing" @click="setStatus('delivered')">
                        <Icon name="check" :size="18" /> Hand over the car
                    </button>
                    <p v-if="!can.deliver && order.balance_minor > 0 && order.status !== 'draft'" class="text-[13px] text-muted">The car can be handed over once it is fully paid. Only the owner can hand it over earlier.</p>
                    <form v-if="overriding && isOwner && order.balance_minor > 0" class="flex flex-col gap-2 rounded-xl bg-cream p-3" @submit.prevent="setStatus('delivered')">
                        <label class="field-label">
                            {{ order.balance }} is still owed. Why hand over now?
                            <input v-model="status.override_reason" class="field" required maxlength="200" placeholder="Balance by transfer on Friday" />
                            <InputError :message="status.errors.override_reason" />
                        </label>
                        <p class="text-[12px] text-muted">This is recorded in the audit log.</p>
                        <button type="submit" class="btn btn-dark h-11 text-[14px]" :disabled="status.processing">Hand over with balance</button>
                    </form>
                </section>

                <section class="card flex flex-col gap-3 p-5" aria-labelledby="share-heading">
                    <h2 id="share-heading" class="font-sans text-[16px] font-bold">Customer tracking link</h2>
                    <p class="text-[14px] text-muted">The customer can see the order status, payments and receipts here, without an account.</p>
                    <div class="flex flex-wrap gap-2">
                        <a v-if="share" :href="share" target="_blank" rel="noopener" class="btn btn-outline h-11 text-[14px]"><Icon name="whatsapp" :size="18" /> Send on WhatsApp</a>
                        <button type="button" class="btn btn-outline h-11 text-[14px]" @click="copyLink"><Icon name="copy" :size="18" /> {{ copied ? 'Copied' : 'Copy link' }}</button>
                    </div>
                </section>

                <section v-if="can.cancel" class="card p-5">
                    <button v-if="!cancelling" type="button" class="h-11 text-[14px] font-semibold text-clay" @click="cancelling = true">Cancel this order</button>
                    <form v-else class="flex flex-col gap-3" @submit.prevent="cancelOrder">
                        <label class="field-label">Reason<input v-model="cancelForm.reason" class="field" required maxlength="200" /><InputError :message="cancelForm.errors.reason" /></label>
                        <fieldset v-if="hasPayments" class="flex flex-col gap-2">
                            <legend class="mb-1 text-[13px] font-semibold">The customer has paid {{ order.paid }}</legend>
                            <label class="flex min-h-11 items-center gap-3 text-[14px]"><input v-model="cancelForm.money" type="radio" value="refund" class="h-5 w-5 accent-forest" /> Refund it</label>
                            <select v-if="cancelForm.money === 'refund'" v-model="cancelForm.refund_method" class="field" aria-label="Refund method">
                                <option v-for="m in methods" :key="m.value" :value="m.value">{{ m.label }}</option>
                            </select>
                            <label class="flex min-h-11 items-center gap-3 text-[14px]"><input v-model="cancelForm.money" type="radio" value="credit" class="h-5 w-5 accent-forest" /> Keep it as credit</label>
                            <InputError :message="cancelForm.errors.money || cancelForm.errors.refund_method" />
                        </fieldset>
                        <div class="flex gap-2">
                            <button type="submit" class="btn btn-dark h-11 text-[14px]" :disabled="cancelForm.processing">Cancel order</button>
                            <button type="button" class="btn btn-outline h-11 text-[14px]" @click="cancelling = false">Keep order</button>
                        </div>
                    </form>
                </section>

                <p v-if="order.notes" class="rounded-xl bg-white p-4 text-[14px] whitespace-pre-line"><strong>Notes</strong><br />{{ order.notes }}</p>
            </div>
        </div>
    </DealerLayout>
</template>
