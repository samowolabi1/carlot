<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import Logo from '@/components/Logo.vue';
import CarCard, { type CarCardData } from '@/components/marketplace/CarCard.vue';
import ShareLocation from '@/components/marketplace/ShareLocation.vue';
import ShareMenu from '@/components/marketplace/ShareMenu.vue';
import type { Filters } from '@/components/marketplace/types';
import { toQuery } from '@/components/marketplace/types';
import type { PublicLot } from '@/components/marketplace/types-lot';
import { distanceKm, formatDistance, useLocation } from '@/composables/useLocation';
import { useShared } from '@/composables/useShared';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { recordIntent } from '@/lib/leads';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    lot: PublicLot;
    stock: { data: CarCardData[]; total: number; last_page: number; links: { url: string | null; label: string; active: boolean }[] };
    total: number;
    filters: Filters;
    preview: boolean;
    following: boolean;
    followers: number;
}>();

const { user, lots } = useShared();

function toggleFollow() {
    if (!user.value) {
        router.visit(route('login'));
        return;
    }
    const url = route('lots.follow', props.lot.slug);
    if (props.following) router.delete(url, { preserveScroll: true });
    else router.post(url, {}, { preserveScroll: true });
}

const tab = ref<'stock' | 'about'>('stock');
const { location } = useLocation();
const distance = computed(() => (location.value && props.lot.location ? formatDistance(distanceKm(location.value, props.lot.location)) : null));
const brand = computed(() => props.lot.brand_color ?? '#16302B');
function intent(source: 'whatsapp' | 'call') {
    if (user.value && !lots.value.some((l) => l.slug === props.lot.slug)) recordIntent(source, { lot: props.lot.slug });
}

const whatsappHref = computed(() => (props.lot.whatsapp ? `https://wa.me/${props.lot.whatsapp}?text=${encodeURIComponent(`Hi ${props.lot.name}, I found you on LotLink.`)}` : null));

// Quick filters from the W4 design.
const quick: { label: string; query: Partial<Filters> }[] = [
    { label: 'All', query: {} },
    { label: 'SUVs', query: { body: ['suv'] } },
    { label: 'Sedans', query: { body: ['sedan'] } },
    { label: 'Under ₦10m', query: { price_max: 10000000 } },
];
const isQuick = (q: Partial<Filters>) => JSON.stringify(toQuery({ body: props.filters.body, price_max: props.filters.price_max })) === JSON.stringify(toQuery({ body: [], price_max: null, ...q }));

function filter(query: Partial<Filters>) {
    router.get(route('lots.show', props.lot.slug), toQuery(query) as Record<string, string>, { preserveScroll: true, preserveState: true, only: ['stock', 'filters'] });
}
</script>

<template>
    <Head :title="lot.name" />
    <CustomerLayout :active="null" bare>
        <div v-if="preview" class="bg-cream px-5 py-3 text-center text-[14px] text-clay-dark" role="status">Preview: your lot page goes public once LotLink approves your lot.</div>

        <div class="flex items-center justify-between border-b border-line bg-white px-5 py-2.5 text-[12px] text-muted">
            <span class="flex items-center gap-1.5">Powered by <Link :href="route('home')" class="no-underline"><Logo size="sm" /></Link></span>
            <Link :href="route('cars.index')" class="text-[13px] font-semibold">Browse all lots</Link>
        </div>

        <header class="relative h-36 md:h-56" :style="{ background: lot.cover_url ? `url(${lot.cover_url}) center/cover` : brand }">
            <div class="absolute inset-0 bg-gradient-to-t from-ink/40 to-transparent" />
        </header>

        <div class="mx-auto max-w-6xl px-5">
            <div class="relative -mt-9 flex flex-col gap-3 md:-mt-12">
                <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-[72px] w-[72px] rounded-[18px] border-4 border-ivory object-cover md:h-24 md:w-24" />
                <span v-else class="flex h-[72px] w-[72px] items-center justify-center rounded-[18px] border-4 border-ivory font-display text-[26px] font-bold text-white md:h-24 md:w-24" :style="{ background: brand }">{{ lot.initials }}</span>
                <div class="flex flex-col gap-1">
                    <h1 class="flex items-center gap-1.5 text-[26px] font-bold md:text-4xl">
                        {{ lot.name }}
                        <Icon v-if="lot.verified" name="shield" :size="22" class="text-forest" :stroke-width="2" />
                        <span v-if="lot.verified" class="sr-only">Verified lot</span>
                    </h1>
                    <p v-if="lot.tagline" class="text-[15px] text-muted">{{ lot.tagline }}</p>
                    <p class="text-[14px] text-muted">
                        <span v-if="lot.open" :class="lot.open.open ? 'font-semibold text-success' : ''">{{ lot.open.label }}</span>
                        <template v-if="lot.address || lot.city"> · {{ [lot.address, lot.city, lot.state].filter(Boolean).join(', ') }}</template>
                    </p>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-4 gap-2 md:flex md:gap-2.5">
                <a v-if="lot.directions_url" :href="lot.directions_url" target="_blank" rel="noopener" class="flex h-16 flex-col items-center justify-center gap-1 rounded-[14px] bg-clay text-[12px] font-semibold text-white no-underline hover:bg-clay-dark hover:text-white md:h-11 md:flex-row md:gap-2 md:px-4 md:text-[14px]">
                    <Icon name="navigate" :size="20" /> Directions
                </a>
                <a v-if="lot.phone" :href="`tel:${lot.phone}`" @click="intent('call')" class="flex h-16 flex-col items-center justify-center gap-1 rounded-[14px] border border-line bg-white text-[12px] font-semibold text-ink no-underline md:h-11 md:flex-row md:gap-2 md:px-4 md:text-[14px]">
                    <Icon name="phone" :size="20" /> Call
                </a>
                <a v-if="whatsappHref" :href="whatsappHref" target="_blank" rel="noopener" @click="intent('whatsapp')" class="flex h-16 flex-col items-center justify-center gap-1 rounded-[14px] border border-line bg-white text-[12px] font-semibold text-ink no-underline md:h-11 md:flex-row md:gap-2 md:px-4 md:text-[14px]">
                    <Icon name="whatsapp" :size="20" /> WhatsApp
                </a>
                <ShareMenu :title="lot.name" :text="`${lot.name} on LotLink`" :url="lot.url" :lot="preview ? undefined : lot.slug" label="Share" />
            </div>
            <div v-if="!preview" class="mt-2.5 flex flex-col gap-2.5 md:flex-row md:items-center">
                <Link :href="route('bookings.create', { lot: lot.slug })" class="btn btn-dark w-full md:w-auto"><Icon name="calendar" :size="18" /> Book a visit</Link>
                <Link
                    v-if="!lots.some((l) => l.slug === lot.slug)"
                    :href="user ? route('conversations.store') : route('conversations.start', { lot: lot.slug })"
                    :method="user ? 'post' : 'get'"
                    :data="user ? { lot: lot.slug } : undefined"
                    :as="user ? 'button' : 'a'"
                    class="btn btn-outline w-full md:w-auto"
                    ><Icon name="chat" :size="18" /> Message the lot</Link
                >
                <button
                    type="button"
                    class="btn h-12 w-full md:w-auto"
                    :class="following ? 'btn-outline' : 'border border-line bg-white text-ink hover:border-forest'"
                    :aria-pressed="following"
                    @click="toggleFollow"
                >
                    <Icon :name="following ? 'check' : 'bell'" :size="18" /> {{ following ? 'Following' : 'Follow for new stock' }}
                </button>
                <span v-if="followers > 0" class="text-center text-[13px] text-muted md:text-left">{{ followers }} {{ followers === 1 ? 'follower' : 'followers' }}</span>
            </div>

            <div class="mt-5 grid gap-6 pb-12 lg:grid-cols-[1fr_320px]">
                <div class="flex min-w-0 flex-col gap-4">
                    <div role="tablist" aria-label="Lot sections" class="flex gap-5 border-b border-line">
                        <button type="button" role="tab" :aria-selected="tab === 'stock'" class="h-10 border-b-2 text-[15px]" :class="tab === 'stock' ? 'border-clay font-semibold' : 'border-transparent text-muted'" @click="tab = 'stock'">Stock ({{ total }})</button>
                        <button type="button" role="tab" :aria-selected="tab === 'about'" class="h-10 border-b-2 text-[15px] lg:hidden" :class="tab === 'about' ? 'border-clay font-semibold' : 'border-transparent text-muted'" @click="tab = 'about'">About</button>
                    </div>

                    <template v-if="tab === 'stock'">
                        <div class="-mr-5 flex gap-2 overflow-x-auto pr-5">
                            <button
                                v-for="q in quick"
                                :key="q.label"
                                type="button"
                                class="h-9 shrink-0 rounded-full px-3.5 text-[14px]"
                                :class="isQuick(q.query) ? 'bg-forest font-semibold text-white' : 'border border-line bg-white'"
                                :aria-pressed="isQuick(q.query)"
                                @click="filter(q.query)"
                            >
                                {{ q.label }}
                            </button>
                        </div>
                        <div v-if="stock.data.length" class="grid grid-cols-2 gap-2.5 md:grid-cols-3 md:gap-3">
                            <CarCard v-for="car in stock.data" :key="car.ulid" :car="car" />
                        </div>
                        <p v-else class="card px-5 py-8 text-center text-[15px] text-muted">{{ total ? 'No cars match that filter.' : 'No cars listed yet. Check back soon.' }}</p>
                        <nav v-if="stock.last_page > 1" aria-label="Pages" class="flex flex-wrap justify-center gap-1">
                            <template v-for="link in stock.links" :key="link.label">
                                <Link v-if="link.url" :href="link.url" preserve-scroll class="flex h-10 min-w-10 items-center justify-center rounded-lg px-3 text-[14px] no-underline" :class="link.active ? 'bg-forest text-white' : 'bg-white text-ink ring-1 ring-line'"><span v-html="link.label" /></Link>
                            </template>
                        </nav>
                    </template>
                </div>

                <aside class="flex flex-col gap-4" :class="tab === 'about' ? '' : 'hidden lg:flex'">
                    <div class="card overflow-hidden">
                        <a v-if="lot.directions_url" :href="lot.directions_url" target="_blank" rel="noopener" class="relative block h-28 bg-map" aria-label="Open in Google Maps">
                            <span class="absolute inset-x-0 top-12 h-2.5 bg-white" />
                            <span class="absolute inset-y-0 left-[35%] w-2.5 bg-white" />
                            <span class="absolute inset-y-0 left-[75%] w-2 bg-white" />
                            <span class="absolute top-5 left-1/2 h-9 w-9 -translate-x-1/2 -rotate-45 rounded-[18px_18px_18px_4px] bg-clay" />
                        </a>
                        <div class="flex flex-col gap-1 p-3.5 text-[14px]">
                            <span v-if="lot.address">{{ lot.address }}, {{ lot.city }}</span>
                            <span v-if="lot.landmark" class="text-muted">{{ lot.landmark }}</span>
                            <span v-if="distance" class="text-muted">{{ distance }} from you</span>
                            <div v-if="lot.directions_url" class="mt-2"><ShareLocation :name="lot.name" :address="lot.address ? `${lot.address}, ${lot.city}` : lot.city" :directions="lot.directions_url" /></div>
                        </div>
                    </div>
                    <div v-if="lot.hours.length" class="card flex flex-col gap-2 p-3.5">
                        <h2 class="font-sans text-[15px] font-bold">Opening hours</h2>
                        <dl class="flex flex-col gap-1 text-[14px]">
                            <div v-for="row in lot.hours" :key="row.days" class="flex justify-between gap-3">
                                <dt>{{ row.days }}</dt>
                                <dd :class="row.hours === 'Closed' ? 'text-muted' : ''">{{ row.hours }}</dd>
                            </div>
                        </dl>
                    </div>
                    <div v-if="lot.about" class="card flex flex-col gap-2 p-3.5">
                        <h2 class="font-sans text-[15px] font-bold">About {{ lot.name }}</h2>
                        <p class="text-[14px] whitespace-pre-line">{{ lot.about }}</p>
                    </div>
                </aside>
            </div>
        </div>
    </CustomerLayout>
</template>
