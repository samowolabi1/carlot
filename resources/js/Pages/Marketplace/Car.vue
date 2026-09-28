<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import CarFinance, { type CarFinanceData } from '@/components/finance/CarFinance.vue';
import CarCard, { type CarCardData } from '@/components/marketplace/CarCard.vue';
import LotBadge from '@/components/marketplace/LotBadge.vue';
import SaveButton from '@/components/marketplace/SaveButton.vue';
import ShareMenu from '@/components/marketplace/ShareMenu.vue';
import type { PublicLot } from '@/components/marketplace/types-lot';
import { useBudget } from '@/composables/useBudget';
import { useCompare } from '@/composables/useCompare';
import { useShared } from '@/composables/useShared';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { formatNaira } from '@/lib/format';
import { recordIntent } from '@/lib/leads';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    car: {
        ulid: string;
        url: string;
        title: string;
        make: string | null;
        model: string | null;
        make_id: number | null;
        price: string | null;
        negotiable: boolean;
        description: string | null;
        specs: { label: string; value: string }[];
        features: { group: string; items: string[] }[];
        photos: { src: string; srcset: string }[];
        new_arrival: boolean;
        reserved: boolean;
        listed_days: number | null;
    };
    lot: PublicLot;
    sold: boolean;
    preview: string | null;
    saved: boolean;
    similar: CarCardData[];
    finance: CarFinanceData | null;
    deals: { offers: boolean; reserve: string | null; my_offer: { status: string; text: string } | null; reserved_until: string | null } | null;
}>();

const budget = useBudget();
const withinBudget = computed(() => !props.sold && !!props.finance && budget.within(props.finance.price));

const slide = ref(0);
const track = ref<HTMLElement | null>(null);
const compare = useCompare();

function onScroll() {
    if (track.value) slide.value = Math.round(track.value.scrollLeft / track.value.clientWidth);
}

function goTo(i: number) {
    const n = props.car.photos.length;
    const index = (i + n) % n;
    track.value?.scrollTo({ left: index * track.value.clientWidth, behavior: 'smooth' });
}

const whatsappHref = computed(() => {
    if (!props.lot.whatsapp) return null;
    const text = `Hi ${props.lot.name}, I'm interested in the ${props.car.title}${props.car.price ? ` (${props.car.price})` : ''} I saw on LotLink: ${props.car.url}`;
    return `https://wa.me/${props.lot.whatsapp}?text=${encodeURIComponent(text)}`;
});
const bookHref = computed(() => route('bookings.create', { lot: props.lot.slug, car: props.car.ulid }));
const testDriveHref = computed(() => route('bookings.create', { lot: props.lot.slug, car: props.car.ulid, type: 'test_drive' }));
const { user, lots } = useShared();
// Staff looking at their own lot's car can't message themselves.
const ownLot = computed(() => !!user.value && !!props.lot.slug && lots.value.some((l) => l.slug === props.lot.slug));
const starting = ref(false);

function intent(source: 'whatsapp' | 'call') {
    if (user.value && !ownLot.value) recordIntent(source, { vehicle: props.car.ulid });
}

/** In-app chat with the lot (design 15). Guests sign in first and come back to the thread. */
function message() {
    if (!user.value) {
        router.visit(route('conversations.start', { vehicle: props.car.ulid }));
        return;
    }
    router.post(route('conversations.store'), { vehicle: props.car.ulid }, { onStart: () => (starting.value = true), onFinish: () => (starting.value = false) });
}

// Guests are sent through sign-in and brought back to these pages.
const offerHref = computed(() => route('offers.create', props.car.ulid));
const reserveHref = computed(() => route('reservations.create', props.car.ulid));
const tradeInHref = computed(() => route('trade-ins.create', { lot: props.lot.slug, car: props.car.ulid }));
const canDeal = computed(() => !!props.deals && !ownLot.value);

const shareText = computed(() => `${props.car.title}${props.car.price ? ` — ${props.car.price}` : ''} at ${props.lot.name}`);
</script>

<template>
    <Head :title="car.title" />
    <CustomerLayout active="search">
        <div v-if="preview" class="bg-cream px-5 py-3 text-center text-[14px] text-clay-dark" role="status">{{ preview }}</div>

        <div class="mx-auto grid max-w-6xl gap-6 pb-32 md:px-5 md:pt-6 md:pb-12 lg:grid-cols-[1fr_380px]">
            <div class="flex min-w-0 flex-col gap-5">
                <!-- Gallery -->
                <div class="relative overflow-hidden bg-sand md:rounded-2xl">
                    <div v-if="car.photos.length" ref="track" class="flex aspect-[4/3] snap-x snap-mandatory overflow-x-auto [scrollbar-width:none] md:aspect-[16/10]" @scroll.passive="onScroll">
                        <img
                            v-for="(photo, i) in car.photos"
                            :key="photo.src"
                            :src="photo.src"
                            :srcset="photo.srcset"
                            sizes="(min-width: 1024px) 700px, 100vw"
                            :alt="`${car.title}, photo ${i + 1}`"
                            :loading="i === 0 ? 'eager' : 'lazy'"
                            class="h-full w-full shrink-0 snap-center object-cover"
                        />
                    </div>
                    <div v-else class="flex aspect-[4/3] items-center justify-center md:aspect-[16/10]"><CarGlyph :width="160" /></div>

                    <Link :href="route('cars.index')" aria-label="Back to search" class="absolute top-4 left-4 flex h-11 w-11 items-center justify-center rounded-full bg-white text-ink shadow-sm md:hidden">
                        <Icon name="chevronLeft" :size="20" :stroke-width="2" />
                    </Link>
                    <div class="absolute top-4 right-4 flex gap-2">
                        <ShareMenu :title="car.title" :text="shareText" :url="car.url" :vehicle="sold || preview ? undefined : car.ulid" images compact />
                        <SaveButton v-if="!sold" :ulid="car.ulid" :saved="saved" />
                    </div>
                    <template v-if="car.photos.length > 1">
                        <button type="button" class="absolute top-1/2 left-3 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-white/90 shadow md:flex" aria-label="Previous photo" @click="goTo(slide - 1)">
                            <Icon name="chevronLeft" :size="20" :stroke-width="2" />
                        </button>
                        <button type="button" class="absolute top-1/2 right-3 hidden h-11 w-11 -translate-y-1/2 rotate-180 items-center justify-center rounded-full bg-white/90 shadow md:flex" aria-label="Next photo" @click="goTo(slide + 1)">
                            <Icon name="chevronLeft" :size="20" :stroke-width="2" />
                        </button>
                        <span class="absolute right-4 bottom-3.5 rounded-xl bg-ink px-2.5 py-1 text-[12px] font-semibold text-white">{{ slide + 1 }} / {{ car.photos.length }}</span>
                    </template>
                </div>

                <div class="flex flex-col gap-4 px-5 md:px-0">
                    <div v-if="sold" class="rounded-2xl bg-forest p-4 text-white">
                        <strong class="text-[16px]">This car has been sold.</strong>
                        <p class="text-[14px] text-mist">Here are similar cars you might like.</p>
                    </div>

                    <div class="flex flex-wrap gap-1.5">
                        <span v-if="car.reserved" class="rounded-xl bg-ink px-2.5 py-1 text-[12px] font-semibold text-white">Reserved</span>
                        <span v-if="car.new_arrival" class="rounded-xl bg-blush px-2.5 py-1 text-[12px] font-semibold text-clay-dark">New arrival</span>
                        <span v-if="lot.verified" class="rounded-xl bg-map px-2.5 py-1 text-[12px] font-semibold text-forest">Verified lot</span>
                        <span v-if="withinBudget" class="rounded-xl bg-[#E3F1E8] px-2.5 py-1 text-[12px] font-semibold text-success">Within your budget</span>
                    </div>

                    <div class="flex flex-col gap-1 lg:hidden">
                        <h1 class="font-sans text-[22px] font-semibold">{{ car.title }}</h1>
                        <div v-if="!sold" class="flex items-baseline gap-2">
                            <span class="font-display text-[28px] font-bold text-forest">{{ car.price }}</span>
                            <span v-if="car.negotiable" class="text-[13px] text-muted">Negotiable</span>
                        </div>
                        <p v-if="!sold && finance" class="text-[13px] text-muted">
                            From <strong class="text-ink">{{ formatNaira(finance.from.monthly) }}/mo</strong> · {{ finance.from.deposit_percent }}% down, {{ finance.from.months }} mo,
                            {{ finance.from.rate }}% p.a. (estimate) · <Link :href="route('budget')">Check budget</Link>
                        </p>
                    </div>

                    <dl class="grid grid-cols-3 gap-2 sm:grid-cols-4">
                        <div v-for="spec in car.specs" :key="spec.label" class="card rounded-xl p-2.5">
                            <dt class="text-[11px] text-muted">{{ spec.label }}</dt>
                            <dd class="text-[14px] font-semibold">{{ spec.value }}</dd>
                        </div>
                    </dl>

                    <div class="lg:hidden"><LotBadge :lot="lot" /></div>

                    <div v-if="!sold && !preview" class="flex items-center gap-3">
                        <span class="grow text-[13px] text-muted">Send it to a friend or post it to your Status</span>
                        <ShareMenu :title="car.title" :text="shareText" :url="car.url" :vehicle="car.ulid" label="Share" images />
                    </div>

                    <section v-if="car.description" class="flex flex-col gap-2">
                        <h2 class="font-sans text-[16px] font-bold">About this car</h2>
                        <p class="text-[15px] whitespace-pre-line text-ink">{{ car.description }}</p>
                    </section>

                    <section v-if="car.features.length" class="flex flex-col gap-3">
                        <h2 class="font-sans text-[16px] font-bold">Features</h2>
                        <div v-for="group in car.features" :key="group.group" class="flex flex-col gap-1.5">
                            <h3 class="font-sans text-[13px] font-semibold text-muted">{{ group.group }}</h3>
                            <ul class="flex flex-wrap gap-2">
                                <li v-for="item in group.items" :key="item" class="flex items-center gap-1.5 rounded-full bg-white px-3 py-1.5 text-[13px] ring-1 ring-line">
                                    <Icon name="check" :size="14" class="text-success" :stroke-width="2.4" />{{ item }}
                                </li>
                            </ul>
                        </div>
                    </section>

                    <template v-if="!sold && finance"><CarFinance :finance="finance" /></template>

                    <button
                        v-if="!sold"
                        type="button"
                        class="self-start text-[14px] font-semibold"
                        :class="compare.has(car.ulid) ? 'text-forest' : 'text-clay'"
                        @click="compare.toggle(car.ulid)"
                    >
                        {{ compare.has(car.ulid) ? '✓ Added to compare' : 'Add to compare' }}
                    </button>
                    <Link v-if="compare.ids.value.length > 1" :href="compare.href.value" class="self-start text-[14px] font-semibold">Compare {{ compare.ids.value.length }} cars</Link>

                    <p v-if="deals?.reserved_until" class="card flex items-center gap-2.5 px-4 py-3 text-[14px]">
                        <Icon name="shield" :size="20" class="shrink-0 text-forest" /> Reserved for you until {{ deals.reserved_until }}.
                        <Link :href="route('bookings.index')" class="ml-auto shrink-0 font-semibold">Details</Link>
                    </p>
                    <p v-else-if="deals?.my_offer" class="card flex items-center gap-2.5 px-4 py-3 text-[14px]" :class="{ 'border-2 border-clay': deals.my_offer.status === 'countered' }">
                        <Icon name="tag" :size="20" class="shrink-0 text-clay" /> {{ deals.my_offer.text }}
                        <Link :href="`${route('bookings.index')}#offers`" class="ml-auto shrink-0 font-semibold">{{ deals.my_offer.status === 'countered' ? 'Answer' : 'View' }}</Link>
                    </p>

                    <!-- Design 06: Reserve with deposit · Trade in my car (and the lot's phone, on phones). -->
                    <div v-if="canDeal && !sold" class="flex flex-wrap gap-x-4 gap-y-1 text-[14px] font-semibold">
                        <Link v-if="deals?.reserve" :href="reserveHref" class="inline-flex min-h-11 items-center">Reserve with deposit</Link>
                        <Link :href="tradeInHref" class="inline-flex min-h-11 items-center">Trade in my car</Link>
                        <a v-if="lot.phone && deals?.offers" :href="`tel:${lot.phone}`" class="inline-flex min-h-11 items-center lg:hidden" @click="intent('call')">Call the lot</a>
                        <a v-if="whatsappHref && deals?.offers" :href="whatsappHref" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center lg:hidden" @click="intent('whatsapp')">WhatsApp</a>
                    </div>
                </div>
            </div>

            <!-- Desktop price and contact card -->
            <aside class="hidden lg:block">
                <div class="sticky top-6 flex flex-col gap-4">
                    <div class="card flex flex-col gap-3 p-5">
                        <h1 class="font-sans text-[22px] font-semibold">{{ car.title }}</h1>
                        <div v-if="!sold" class="flex items-baseline gap-2">
                            <span class="font-display text-[30px] font-bold text-forest">{{ car.price }}</span>
                            <span v-if="car.negotiable" class="text-[13px] text-muted">Negotiable</span>
                        </div>
                        <p v-if="!sold && finance" class="text-[13px] text-muted">
                            From <strong class="text-ink">{{ formatNaira(finance.from.monthly) }}/mo</strong> ({{ finance.from.deposit_percent }}% down, {{ finance.from.months }} mo, estimate) ·
                            <Link :href="route('budget')">What can I afford?</Link>
                        </p>
                        <template v-if="!sold && !preview">
                            <Link :href="bookHref" class="btn btn-primary w-full"><Icon name="calendar" :size="18" /> Book a viewing</Link>
                            <Link :href="testDriveHref" class="text-center text-[14px] font-semibold">or book a test drive</Link>
                            <Link v-if="canDeal && deals?.offers" :href="offerHref" class="btn btn-outline w-full"><Icon name="tag" :size="18" /> Make an offer</Link>
                            <button v-if="!ownLot" type="button" class="btn btn-outline w-full" :disabled="starting" @click="message"><Icon name="chat" :size="18" /> Message the lot</button>
                            <a v-if="whatsappHref" :href="whatsappHref" target="_blank" rel="noopener" class="btn btn-outline w-full" @click="intent('whatsapp')"><Icon name="whatsapp" :size="18" /> Chat on WhatsApp</a>
                            <a v-if="lot.phone" :href="`tel:${lot.phone}`" class="btn btn-outline w-full" @click="intent('call')"><Icon name="phone" :size="18" /> Call {{ lot.phone_display }}</a>
                        </template>
                    </div>
                    <LotBadge :lot="lot" />
                </div>
            </aside>

            <section v-if="similar.length" class="flex flex-col gap-3 px-5 md:px-0 lg:col-span-2">
                <h2 class="text-xl font-bold">Similar cars</h2>
                <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                    <CarCard v-for="c in similar" :key="c.ulid" :car="c" />
                </div>
            </section>
        </div>

        <!-- Phone action bar -->
        <div v-if="!sold && !preview" class="fixed inset-x-0 bottom-[76px] z-30 flex gap-2.5 border-t border-line bg-white px-5 pt-3 pb-3 md:hidden">
            <template v-if="!(canDeal && deals?.offers)">
                <a v-if="lot.phone" :href="`tel:${lot.phone}`" class="btn btn-outline h-[52px] w-[52px] shrink-0 rounded-[14px] px-0" aria-label="Call the lot" @click="intent('call')"><Icon name="phone" :size="20" /></a>
                <a v-if="whatsappHref" :href="whatsappHref" target="_blank" rel="noopener" class="btn btn-outline h-[52px] w-[52px] shrink-0 rounded-[14px] px-0" aria-label="WhatsApp the lot" @click="intent('whatsapp')"><Icon name="whatsapp" :size="20" /></a>
            </template>
            <button v-if="!ownLot" type="button" class="btn btn-outline h-[52px] shrink-0 rounded-[14px] px-3" :disabled="starting" @click="message"><Icon name="chat" :size="18" /> Chat</button>
            <Link v-if="canDeal && deals?.offers" :href="offerHref" class="btn btn-outline h-[52px] shrink-0 rounded-[14px] px-3.5">Offer</Link>
            <Link :href="bookHref" class="btn btn-primary h-[52px] grow rounded-[14px] px-3 whitespace-nowrap">Book viewing</Link>
        </div>
    </CustomerLayout>
</template>
