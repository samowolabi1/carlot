<script setup lang="ts">
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import type { BookingSummary } from '@/Pages/Bookings/Show.vue';
import { Head, Link, router } from '@inertiajs/vue3';

type BuyerOffer = {
    ulid: string;
    status: 'pending' | 'countered' | 'accepted';
    status_label: string;
    car: string;
    car_url: string;
    car_ulid: string;
    lot: string;
    amount: string;
    counter: string | null;
    counter_message: string | null;
    agreed: string | null;
    left: string | null;
    can_reserve: boolean;
    book_url: string;
};
type BuyerReservation = { ulid: string; car: string; car_url: string; lot: string; deposit: string; price: string; until: string | null; left: string | null; book_url: string };
type BuyerTradeIn = { ulid: string; status: 'submitted' | 'valued'; title: string; lot: string; towards: string | null; estimate: string | null; note: string | null; photos: number; book_url: string };
type PastDeal = { key: string; title: string; detail: string; status: string; at: string | null };

defineProps<{
    upcoming: BookingSummary[];
    past: BookingSummary[];
    offers: BuyerOffer[];
    reservations: BuyerReservation[];
    tradeIns: BuyerTradeIn[];
    pastDeals: PastDeal[];
}>();

const badge: Record<string, string> = {
    confirmed: 'bg-[#DCEFE3] text-[#166534]',
    pending: 'bg-blush text-clay-dark',
    awaiting_deposit: 'bg-blush text-clay-dark',
    completed: 'bg-divider text-muted',
    no_show: 'bg-divider text-muted',
    cancelled: 'bg-divider text-muted',
};

const sm = 'inline-flex h-[38px] items-center rounded-[10px] border border-line-strong bg-white px-3 text-[13px] font-semibold text-forest no-underline';
const smPrimary = 'inline-flex h-[38px] items-center rounded-[10px] bg-clay px-3 text-[13px] font-semibold text-white no-underline hover:text-white';

function acceptCounter(o: BuyerOffer, reserve: boolean) {
    router.post(route('offers.accept', o.ulid), { reserve }, { preserveScroll: true });
}

function declineCounter(o: BuyerOffer) {
    router.post(route('offers.decline', o.ulid), {}, { preserveScroll: true });
}

function answerTradeIn(t: BuyerTradeIn, accept: boolean) {
    router.post(route('trade-ins.answer', t.ulid), { accept }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Bookings and offers" />
    <CustomerLayout active="bookings">
        <div class="mx-auto flex max-w-xl flex-col gap-2.5 px-5 py-6">
            <h1 class="mb-1 text-[28px] font-bold">Bookings and offers</h1>

            <h2 class="mt-1.5 font-sans text-[13px] font-semibold tracking-wide text-muted uppercase">Upcoming</h2>
            <p v-if="!upcoming.length" class="card px-4 py-6 text-center text-[15px] text-muted">
                No upcoming visits. <Link :href="route('cars.index')" class="font-semibold">Find a car</Link> and book a viewing or test drive.
            </p>
            <article v-for="b in upcoming" :key="b.ulid" class="card flex flex-col gap-2 p-3.5">
                <div class="flex items-start justify-between gap-3">
                    <Link :href="b.url" class="text-[15px] font-semibold text-ink no-underline">{{ b.when }}</Link>
                    <span class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="badge[b.status]">{{ b.status_label }}</span>
                </div>
                <span class="text-[14px] text-[#4A4D53]">{{ b.type }}<template v-if="b.car"> · {{ b.car.title }}</template> · {{ b.lot.name }}</span>
                <div class="flex gap-2">
                    <Link v-if="b.deposit?.state === 'due' && b.deposit.pay_url" :href="b.deposit.pay_url" method="post" as="button" :class="smPrimary">Pay {{ b.deposit.amount }} deposit</Link>
                    <a v-else-if="b.lot.directions_url" :href="b.lot.directions_url" target="_blank" rel="noopener" :class="smPrimary">Directions</a>
                    <Link :href="b.url" :class="sm">Manage</Link>
                </div>
            </article>

            <h2 id="offers" class="mt-3 scroll-mt-4 font-sans text-[13px] font-semibold tracking-wide text-muted uppercase">Offers and reservations</h2>
            <p v-if="!offers.length && !reservations.length && !tradeIns.length" class="card px-4 py-6 text-center text-[15px] text-muted">
                Make an offer, reserve a car or get a trade-in valuation from any car page.
            </p>

            <article v-for="r in reservations" :key="r.ulid" class="card flex flex-col gap-2 p-3.5">
                <div class="flex items-start justify-between gap-3">
                    <Link :href="r.car_url" class="text-[15px] font-semibold text-ink no-underline">Reserved: {{ r.car }}</Link>
                    <span v-if="r.left" class="shrink-0 text-[12px] font-semibold text-clay-dark">{{ r.left }}</span>
                </div>
                <span class="text-[14px] text-[#4A4D53]">{{ r.deposit }} deposit paid · held until {{ r.until }} · {{ r.lot }}</span>
                <span class="text-[13px] text-muted">The deposit counts towards {{ r.price }} when you buy.</span>
                <div class="flex gap-2"><Link :href="r.book_url" :class="smPrimary">Book a visit</Link></div>
            </article>

            <article v-for="o in offers" :key="o.ulid" class="card flex flex-col gap-2 p-3.5" :class="{ 'border-2 border-clay': o.status === 'countered' }">
                <div class="flex items-start justify-between gap-3">
                    <Link :href="o.car_url" class="text-[15px] font-semibold text-ink no-underline">
                        {{ o.status === 'countered' ? 'Counter-offer on' : o.status === 'accepted' ? 'Offer accepted:' : 'Offer on' }} {{ o.car }}
                    </Link>
                    <span v-if="o.left" class="shrink-0 text-[12px] font-semibold text-clay-dark">{{ o.left }}</span>
                </div>
                <span v-if="o.status === 'countered'" class="text-[14px] text-[#4A4D53]">
                    You offered {{ o.amount }} · {{ o.lot }} countered <strong class="text-ink">{{ o.counter }}</strong>
                </span>
                <span v-else-if="o.status === 'accepted'" class="text-[14px] text-[#4A4D53]">{{ o.lot }} agreed <strong class="text-ink">{{ o.agreed }}</strong></span>
                <span v-else class="text-[14px] text-[#4A4D53]">You offered {{ o.amount }} · waiting for {{ o.lot }}</span>
                <p v-if="o.counter_message && o.status === 'countered'" class="text-[13px] text-muted">“{{ o.counter_message }}”</p>

                <div v-if="o.status === 'countered'" class="flex flex-wrap gap-2">
                    <button type="button" :class="smPrimary" @click="acceptCounter(o, true)">Accept and reserve</button>
                    <button type="button" :class="sm" @click="acceptCounter(o, false)">Accept</button>
                    <Link :href="route('offers.create', o.car_ulid)" :class="sm">Counter</Link>
                    <button type="button" :class="sm" @click="declineCounter(o)">Decline</button>
                </div>
                <div v-else-if="o.status === 'accepted'" class="flex flex-wrap gap-2">
                    <Link v-if="o.can_reserve" :href="route('reservations.create', o.car_ulid)" :class="smPrimary">Reserve with deposit</Link>
                    <Link :href="o.book_url" :class="o.can_reserve ? sm : smPrimary">Book a visit</Link>
                </div>
            </article>

            <article v-for="t in tradeIns" :key="t.ulid" class="card flex flex-col gap-2 p-3.5" :class="{ 'border-2 border-forest': t.status === 'valued' }">
                <div class="flex items-start justify-between gap-3">
                    <span class="text-[15px] font-semibold">Trade-in: {{ t.title }}</span>
                    <span class="shrink-0 rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="t.status === 'valued' ? 'bg-[#DCEFE3] text-[#166534]' : 'bg-blush text-clay-dark'">
                        {{ t.status === 'valued' ? 'Valued' : 'Waiting' }}
                    </span>
                </div>
                <span v-if="t.status === 'valued'" class="text-[14px] text-[#4A4D53]">{{ t.lot }} values it at <strong class="text-ink">{{ t.estimate }}</strong></span>
                <span v-else class="text-[14px] text-[#4A4D53]">Sent to {{ t.lot }} with {{ t.photos }} photos<template v-if="t.towards"> · towards the {{ t.towards }}</template></span>
                <p v-if="t.note" class="text-[13px] text-muted">“{{ t.note }}”</p>
                <div v-if="t.status === 'valued'" class="flex flex-wrap gap-2">
                    <button type="button" :class="smPrimary" @click="answerTradeIn(t, true)">Use it</button>
                    <Link :href="t.book_url" :class="sm">Book a valuation visit</Link>
                    <button type="button" :class="sm" @click="answerTradeIn(t, false)">No thanks</button>
                </div>
            </article>

            <template v-if="past.length || pastDeals.length">
                <h2 class="mt-3 font-sans text-[13px] font-semibold tracking-wide text-muted uppercase">Past</h2>
                <Link v-for="b in past" :key="b.ulid" :href="b.url" class="card flex flex-col gap-1 p-3.5 text-ink no-underline">
                    <span class="flex justify-between gap-3">
                        <span class="text-[15px] font-semibold">{{ b.when }} · {{ b.type }}</span>
                        <span class="text-[12px] text-muted">{{ b.status_label }}</span>
                    </span>
                    <span class="text-[14px] text-[#4A4D53]"><template v-if="b.car">{{ b.car.title }} · </template>{{ b.lot.name }}</span>
                </Link>
                <div v-for="d in pastDeals" :key="d.key" class="card flex flex-col gap-1 p-3.5">
                    <span class="flex justify-between gap-3">
                        <span class="text-[15px] font-semibold">{{ d.title }}</span>
                        <span class="shrink-0 text-[12px] text-muted">{{ d.status }}</span>
                    </span>
                    <span class="text-[14px] text-[#4A4D53]">{{ d.detail }}<template v-if="d.at"> · {{ d.at }}</template></span>
                </div>
            </template>
        </div>
    </CustomerLayout>
</template>
