<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

type Image = { src: string; srcset: string } | null;
type DealerOffer = {
    ulid: string;
    status: 'pending' | 'countered' | 'accepted';
    car: string;
    image: Image;
    buyer: string;
    left: string | null;
    asking: string | null;
    amount: string;
    amount_value: number;
    asking_value: number | null;
    off: string | null;
    counter: string | null;
    message: string | null;
    lead_url: string | null;
    hint: string | null;
};
type DealerTradeIn = {
    ulid: string;
    status: 'submitted' | 'valued' | 'accepted';
    status_label: string;
    title: string;
    condition: string;
    buyer: string;
    towards: string | null;
    notes: string | null;
    photos: string[];
    estimate: string | null;
    low: number | null;
    high: number | null;
    lead_url: string | null;
};
type DealerReservation = { ulid: string; car: string; image: Image; buyer: string; deposit: string; price: string; until: string | null; left: string | null; order_url: string };

const props = defineProps<{
    tab: 'offers' | 'trade-ins' | 'reservations';
    takesOffers: boolean;
    planAllows: { offers: boolean; deposits: boolean };
    reservationsOn: boolean;
    offers: DealerOffer[];
    tradeIns: DealerTradeIn[];
    reservations: DealerReservation[];
    counts: { offers: number; tradeIns: number; reservations: number };
}>();

const { currentLot } = useShared();
const page = usePage();
// Quick actions (accept, decline, cancel) report problems such as an offer that just expired.
const actionError = computed(() => {
    const errors = page.props.errors as Record<string, string>;
    return errors.offer ?? errors.action ?? errors.reservation ?? errors.reason ?? errors.trade_in ?? null;
});
const lot = computed(() => currentLot.value!);
const active = ref(props.tab);

function select(tab: typeof props.tab) {
    active.value = tab;
    history.replaceState(history.state, '', route('dealer.offers.index', { lot: lot.value.slug, tab }));
}

const naira = (n: number | null) => (n ? `₦${n.toLocaleString('en-NG')}` : '');

// Offers: Accept / Decline straight away; Counter opens an amount box on the card.
const countering = ref<string | null>(null);
const counter = useForm({ action: 'counter', counter_amount: '', message: '' });

function respond(offer: DealerOffer, action: 'accept' | 'decline') {
    if (action === 'decline' && !confirm(`Decline ${offer.buyer}'s offer of ${offer.amount}?`)) return;
    router.post(route('dealer.offers.respond', [lot.value.slug, offer.ulid]), { action }, { preserveScroll: true });
}

function openCounter(offer: DealerOffer) {
    countering.value = offer.ulid;
    // Start halfway between the offer and the asking price.
    const mid = offer.asking_value ? Math.round((offer.amount_value + offer.asking_value) / 2 / 10_000) * 10_000 : offer.amount_value;
    counter.counter_amount = naira(mid);
    counter.message = '';
}

function sendCounter(offer: DealerOffer) {
    counter.post(route('dealer.offers.respond', [lot.value.slug, offer.ulid]), { preserveScroll: true, onSuccess: () => (countering.value = null) });
}

// Trade-ins: a low–high estimate per card.
const estimates = reactive<Record<string, { low: string; high: string; note: string }>>(
    Object.fromEntries(props.tradeIns.map((t) => [t.ulid, { low: naira(t.low), high: naira(t.high), note: '' }])),
);
const valuing = ref<string | null>(null);
const valuationErrors = ref<Record<string, string>>({});

function sendValuation(t: DealerTradeIn) {
    valuing.value = t.ulid;
    router.patch(
        route('dealer.trade-ins.update', [lot.value.slug, t.ulid]),
        { estimate_low: estimates[t.ulid].low, estimate_high: estimates[t.ulid].high, note: estimates[t.ulid].note },
        {
            preserveScroll: true,
            onError: (errors) => (valuationErrors.value = { ...valuationErrors.value, [t.ulid]: Object.values(errors)[0] ?? '' }),
            onSuccess: () => (valuationErrors.value = { ...valuationErrors.value, [t.ulid]: '' }),
            onFinish: () => (valuing.value = null),
        },
    );
}

function askForPhotos(t: DealerTradeIn) {
    router.post(route('dealer.trade-ins.ask-photos', [lot.value.slug, t.ulid]), {}, { preserveScroll: true });
}

function cancelReservation(r: DealerReservation) {
    const reason = prompt(`Cancel ${r.buyer}'s reservation and refund the ${r.deposit} deposit? Say why (the buyer sees this).`);
    if (reason) router.post(route('dealer.reservations.cancel', [lot.value.slug, r.ulid]), { reason }, { preserveScroll: true });
}

const btn = 'inline-flex h-10 items-center justify-center rounded-[10px] border border-line-strong bg-white px-3.5 text-[13px] font-semibold text-ink';
const btnDark = 'inline-flex h-10 items-center justify-center rounded-[10px] bg-forest px-3.5 text-[13px] font-semibold text-white';
</script>

<template>
    <Head title="Offers and trade-ins" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-[30px] font-bold">Offers and trade-ins</h1>
                <p class="text-[14px] text-muted">Reply within 48 hours or offers expire automatically</p>
            </div>
            <Link :href="`${route('dealer.settings', lot.slug)}#deals`" class="text-[14px] font-semibold">Offer and deposit settings</Link>
        </div>

        <p v-if="actionError" role="alert" class="rounded-xl bg-[#FDECEC] px-4 py-3 text-[14px] text-danger">{{ actionError }}</p>

        <div role="tablist" aria-label="Deals" class="flex gap-1 self-start rounded-xl bg-[#EAE6DE] p-1">
            <button
                v-for="t in [
                    { key: 'offers', label: 'Offers', n: counts.offers },
                    { key: 'trade-ins', label: 'Trade-ins', n: counts.tradeIns },
                    { key: 'reservations', label: 'Reservations', n: counts.reservations },
                ] as const"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="active === t.key"
                class="h-9 rounded-[9px] px-3.5 text-[13px]"
                :class="active === t.key ? 'bg-white font-semibold text-ink' : 'font-medium text-muted'"
                @click="select(t.key)"
            >
                {{ t.n ? `${t.label} ${t.n}` : t.label }}
            </button>
        </div>

        <!-- Offers -->
        <section v-if="active === 'offers'" class="flex flex-col gap-3">
            <p v-if="!planAllows.offers" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark">
                Offers are part of the Pro plan. <Link :href="route('dealer.billing', lot.slug)" class="font-semibold">Upgrade</Link> to let buyers make offers on your cars.
            </p>
            <p v-else-if="!takesOffers" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark">
                You've turned offers off. <Link :href="`${route('dealer.settings', lot.slug)}#deals`" class="font-semibold">Turn them on</Link> in settings.
            </p>
            <p v-if="offers.length === 0" class="card px-5 py-10 text-center text-[15px] text-muted">No offers right now. Buyers can make one from any negotiable car.</p>

            <template v-for="o in offers" :key="o.ulid">
                <article class="card flex flex-col gap-3 p-4 md:flex-row md:items-center md:gap-5" :class="{ 'border-2 border-clay': o.status === 'pending' }">
                    <img v-if="o.image" :src="o.image.src" alt="" class="h-[62px] w-[84px] shrink-0 rounded-[10px] object-cover" />
                    <span v-else class="flex h-[62px] w-[84px] shrink-0 items-center justify-center rounded-[10px] bg-sand"><CarGlyph :width="52" /></span>
                    <div class="flex min-w-0 flex-col gap-0.5 md:w-[210px]">
                        <span class="truncate text-[15px] font-semibold">{{ o.car }}</span>
                        <span class="text-[12px] text-muted">
                            {{ o.buyer }}
                            <template v-if="o.status === 'pending' && o.left"> · expires in {{ o.left.replace(' left', '') }}</template>
                            <template v-else-if="o.status === 'countered'"> · you countered {{ o.counter }}<template v-if="o.left">, {{ o.left }}</template></template>
                            <template v-else-if="o.status === 'accepted'"> · accepted</template>
                        </span>
                    </div>
                    <div class="flex gap-5">
                        <div class="flex flex-col md:w-[110px]"><span class="text-[12px] text-muted">Asking</span><span class="text-[15px] font-semibold">{{ o.asking }}</span></div>
                        <div class="flex flex-col md:w-[170px]">
                            <span class="text-[12px] text-muted">Offer</span>
                            <span class="text-[15px] font-semibold text-clay-dark">{{ o.amount }} <span v-if="o.off" class="text-[12px] font-medium">({{ o.off }})</span></span>
                        </div>
                    </div>
                    <div class="flex flex-wrap items-center gap-2 md:ml-auto">
                        <template v-if="o.status === 'pending'">
                            <button type="button" :class="btn" class="border-forest text-forest" @click="openCounter(o)">Counter</button>
                            <button type="button" :class="btnDark" @click="respond(o, 'accept')">Accept</button>
                            <button type="button" :class="btn" @click="respond(o, 'decline')">Decline</button>
                        </template>
                        <Link v-if="o.lead_url" :href="o.lead_url" :class="btn">Chat</Link>
                    </div>
                </article>
                <p v-if="o.message" class="-mt-1.5 px-1 text-[13px] text-muted">{{ o.buyer }}: “{{ o.message }}”</p>
                <form v-if="countering === o.ulid" class="card flex flex-col gap-3 p-4 md:flex-row md:items-end" @submit.prevent="sendCounter(o)">
                    <label class="field-label md:w-48">Your counter<input v-model="counter.counter_amount" class="field" inputmode="numeric" required /></label>
                    <label class="field-label grow">Message (optional)<input v-model="counter.message" class="field" maxlength="500" placeholder="e.g. We can include a full service at this price." /></label>
                    <div class="flex gap-2">
                        <button type="submit" :class="btnDark" class="h-12" :disabled="counter.processing">Send counter</button>
                        <button type="button" :class="btn" class="h-12" @click="countering = null">Cancel</button>
                    </div>
                    <InputError :message="Object.values(counter.errors)[0]" />
                </form>
                <p v-if="o.hint" class="rounded-xl bg-[#FFF6F0] px-3.5 py-2.5 text-[13px] text-clay-dark">{{ o.hint }}</p>
            </template>
        </section>

        <!-- Trade-ins -->
        <section v-else-if="active === 'trade-ins'" class="flex flex-col gap-3">
            <h2 class="font-sans text-[16px] font-bold">Trade-ins waiting for a valuation</h2>
            <p v-if="tradeIns.length === 0" class="card px-5 py-10 text-center text-[15px] text-muted">No trade-ins yet. Buyers send them from your cars and your lot page.</p>
            <div class="grid gap-3 xl:grid-cols-2">
                <article v-for="t in tradeIns" :key="t.ulid" class="card flex gap-4 p-4">
                    <div class="grid shrink-0 grid-cols-3 gap-1 self-start">
                        <a v-for="(src, i) in t.photos.slice(0, 3)" :key="src" :href="src" target="_blank" rel="noopener" class="block h-11 w-11 overflow-hidden rounded-lg bg-sand" :aria-label="`Photo ${i + 1}`">
                            <img :src="src" alt="" class="h-full w-full object-cover" loading="lazy" />
                        </a>
                        <span v-if="t.photos.length > 3" class="col-span-3 text-center text-[11px] text-muted">+{{ t.photos.length - 3 }} more</span>
                    </div>
                    <div class="flex min-w-0 grow flex-col gap-1.5">
                        <div class="flex items-start justify-between gap-2">
                            <span class="text-[15px] font-semibold">{{ t.title }}</span>
                            <span v-if="t.status !== 'submitted'" class="shrink-0 rounded-lg bg-[#DCEFE3] px-2 py-0.5 text-[12px] font-semibold text-[#166534]">{{ t.status_label }}</span>
                        </div>
                        <span class="text-[12px] text-muted">
                            {{ t.buyer }} · {{ t.condition }}<template v-if="t.towards"> · towards {{ t.towards }}</template><template v-else> · no target car yet</template>
                            <template v-if="t.notes"> · “{{ t.notes }}”</template>
                        </span>
                        <template v-if="t.status !== 'accepted'">
                            <div class="flex flex-wrap items-center gap-2">
                                <label class="sr-only" :for="`low-${t.ulid}`">Low estimate</label>
                                <input :id="`low-${t.ulid}`" v-model="estimates[t.ulid].low" inputmode="numeric" placeholder="Low" class="field h-10 w-32 text-[14px]" />
                                <span aria-hidden="true">–</span>
                                <label class="sr-only" :for="`high-${t.ulid}`">High estimate</label>
                                <input :id="`high-${t.ulid}`" v-model="estimates[t.ulid].high" inputmode="numeric" placeholder="High" class="field h-10 w-32 text-[14px]" />
                                <button type="button" :class="btnDark" :disabled="valuing === t.ulid" @click="sendValuation(t)">{{ t.status === 'valued' ? 'Update' : 'Send' }}</button>
                            </div>
                            <input v-model="estimates[t.ulid].note" maxlength="500" class="field h-10 text-[14px]" placeholder="Note to the buyer (optional), e.g. subject to inspection" />
                            <InputError :message="valuationErrors[t.ulid]" />
                        </template>
                        <span v-else class="text-[14px]">The buyer wants to use it at <strong>{{ t.estimate }}</strong>. Add it to their order.</span>
                        <div class="flex flex-wrap gap-2">
                            <button v-if="t.status === 'submitted'" type="button" :class="btn" @click="askForPhotos(t)">Ask for more photos</button>
                            <Link v-if="t.lead_url" :href="t.lead_url" :class="btn">Open lead</Link>
                        </div>
                    </div>
                </article>
            </div>
        </section>

        <!-- Reservations -->
        <section v-else class="flex flex-col gap-3">
            <p v-if="!planAllows.deposits" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark">
                Reservations with a deposit are part of the Pro plan. <Link :href="route('dealer.billing', lot.slug)" class="font-semibold">Upgrade</Link> to take them.
            </p>
            <p v-else-if="!reservationsOn" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark">
                Buyers can't reserve online yet. <Link :href="`${route('dealer.settings', lot.slug)}#deals`" class="font-semibold">Set a reservation deposit</Link> to turn it on.
            </p>
            <p v-if="reservations.length === 0" class="card px-5 py-10 text-center text-[15px] text-muted">No cars are reserved right now.</p>
            <article v-for="r in reservations" :key="r.ulid" class="card flex flex-col gap-3 p-4 md:flex-row md:items-center md:gap-5">
                <img v-if="r.image" :src="r.image.src" alt="" class="h-[62px] w-[84px] shrink-0 rounded-[10px] object-cover" />
                <span v-else class="flex h-[62px] w-[84px] shrink-0 items-center justify-center rounded-[10px] bg-sand"><CarGlyph :width="52" /></span>
                <div class="flex min-w-0 flex-col gap-0.5 md:w-[240px]">
                    <span class="truncate text-[15px] font-semibold">{{ r.car }}</span>
                    <span class="text-[12px] text-muted">{{ r.buyer }} · until {{ r.until }}<template v-if="r.left"> ({{ r.left }})</template></span>
                </div>
                <div class="flex gap-5">
                    <div class="flex flex-col"><span class="text-[12px] text-muted">Deposit paid</span><span class="text-[15px] font-semibold">{{ r.deposit }}</span></div>
                    <div class="flex flex-col"><span class="text-[12px] text-muted">Price</span><span class="text-[15px] font-semibold">{{ r.price }}</span></div>
                </div>
                <div class="flex flex-wrap gap-2 md:ml-auto">
                    <Link :href="r.order_url" :class="btnDark"><Icon name="receipt" :size="16" class="mr-1.5" />Create order</Link>
                    <button type="button" :class="btn" @click="cancelReservation(r)">Cancel and refund</button>
                </div>
            </article>
        </section>
    </DealerLayout>
</template>
