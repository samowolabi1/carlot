<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import Icon from '@/components/Icon.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

type Search = { ulid: string; name: string; url: string; channel: string; last_alert: string | null };
type FollowedLot = { slug: string; url: string; name: string; initials: string; logo_url: string | null; city: string | null; verified: boolean; brand_color: string | null };

defineProps<{
    cars: (CarCardData & { sold: boolean; unavailable: boolean; price_drop: string | null; old_price: string | null })[];
    searches: Search[];
    channels: { value: string; label: string }[];
    lots: FollowedLot[];
    hasEmail: boolean;
}>();

// Design 12: Cars · Searches · Lots.
const initial = typeof window !== 'undefined' ? window.location.hash.slice(1) : '';
const tab = ref<'cars' | 'searches' | 'lots'>(['cars', 'searches', 'lots'].includes(initial) ? (initial as 'cars') : 'cars');

function select(key: 'cars' | 'searches' | 'lots') {
    tab.value = key;
    history.replaceState(history.state, '', `#${key}`);
}

function remove(ulid: string) {
    router.delete(route('favourites.destroy', ulid), { preserveScroll: true });
}

const setChannel = (s: Search, channel: string) => router.patch(route('saved-searches.update', s.ulid), { channel }, { preserveScroll: true });
const deleteSearch = (s: Search) => router.delete(route('saved-searches.destroy', s.ulid), { preserveScroll: true });
</script>

<template>
    <Head title="Saved cars" />
    <CustomerLayout active="saved">
        <div class="mx-auto flex max-w-3xl flex-col gap-4 px-5 py-6">
            <h1 class="text-[28px] font-bold">Saved</h1>

            <div role="tablist" aria-label="Saved" class="flex gap-5 border-b border-line">
                <button
                    v-for="t in [
                        { key: 'cars', label: `Cars (${cars.length})` },
                        { key: 'searches', label: `Searches (${searches.length})` },
                        { key: 'lots', label: `Lots (${lots.length})` },
                    ] as const"
                    :key="t.key"
                    type="button"
                    role="tab"
                    :aria-selected="tab === t.key"
                    class="h-11 border-b-2 text-[15px]"
                    :class="tab === t.key ? 'border-clay font-semibold' : 'border-transparent text-muted'"
                    @click="select(t.key)"
                >
                    {{ t.label }}
                </button>
            </div>

            <template v-if="tab === 'searches'">
                <div v-if="!searches.length" class="card flex flex-col items-center gap-2 px-6 py-10 text-center">
                    <h2 class="text-xl font-bold">No saved searches yet</h2>
                    <p class="max-w-sm text-[15px] text-muted">Search for the car you want, then tap <strong>Save search</strong>. We'll tell you when a new one is listed or gets cheaper.</p>
                    <Link :href="route('cars.index')" class="btn btn-primary mt-2">Search cars</Link>
                </div>
                <section v-else class="flex flex-col gap-2.5" aria-labelledby="alerts-heading">
                    <h2 id="alerts-heading" class="font-sans text-[15px] font-bold">Saved search alerts</h2>
                    <div v-for="s in searches" :key="s.ulid" class="card flex flex-col gap-2 p-3.5">
                        <div class="flex items-start justify-between gap-3">
                            <Link :href="s.url" class="text-[15px] font-semibold text-ink no-underline">{{ s.name }}</Link>
                            <button type="button" class="-mt-2 -mr-2 flex h-11 w-11 shrink-0 items-center justify-center text-muted hover:text-danger" :aria-label="`Delete ${s.name}`" @click="deleteSearch(s)">
                                <Icon name="close" :size="18" />
                            </button>
                        </div>
                        <div class="flex flex-wrap items-center gap-2 text-[13px]">
                            <label class="flex items-center gap-2">
                                <span class="whitespace-nowrap text-muted">Alert by</span>
                                <select :value="s.channel" class="field h-10 w-auto py-0 text-[14px]" @change="setChannel(s, ($event.target as HTMLSelectElement).value)">
                                    <option v-for="c in channels" :key="c.value" :value="c.value" :disabled="c.value === 'mail' && !hasEmail">{{ c.label }}{{ c.value === 'mail' && !hasEmail ? ' (add an email in Account)' : '' }}</option>
                                </select>
                            </label>
                            <span v-if="s.last_alert" class="text-muted">Last alert {{ s.last_alert }}</span>
                        </div>
                    </div>
                    <p class="text-[12px] text-muted">At most one alert per search every 6 hours. Distance and "near me" aren't saved, because we don't keep your location.</p>
                </section>
            </template>

            <template v-else-if="tab === 'lots'">
                <div v-if="!lots.length" class="card flex flex-col items-center gap-2 px-6 py-10 text-center">
                    <h2 class="text-xl font-bold">You don't follow any lots yet</h2>
                    <p class="max-w-sm text-[15px] text-muted">Tap <strong>Follow</strong> on a lot's page to hear about its new stock.</p>
                </div>
                <ul v-else class="flex flex-col gap-2.5">
                    <li v-for="lot in lots" :key="lot.slug">
                        <Link :href="lot.url" class="card flex items-center gap-3 p-3 text-ink no-underline">
                            <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-11 w-11 shrink-0 rounded-xl object-cover" />
                            <span v-else class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl font-display font-bold text-white" :style="{ background: lot.brand_color ?? '#16302B' }">{{ lot.initials }}</span>
                            <span class="flex min-w-0 flex-col">
                                <span class="flex items-center gap-1 text-[15px] font-semibold">{{ lot.name }} <Icon v-if="lot.verified" name="shield" :size="15" class="text-forest" /></span>
                                <span class="text-[13px] text-muted">{{ lot.city }}</span>
                            </span>
                        </Link>
                    </li>
                </ul>
            </template>

            <div v-else-if="!cars.length" class="card flex flex-col items-center gap-2 px-6 py-10 text-center">
                <h2 class="text-xl font-bold">No saved cars yet</h2>
                <p class="max-w-sm text-[15px] text-muted">Tap the heart on any car to keep it here and see when its price drops.</p>
                <Link :href="route('cars.index')" class="btn btn-primary mt-2">Browse cars</Link>
            </div>

            <ul v-else-if="tab === 'cars'" class="flex flex-col gap-2.5">
                <li v-for="car in cars" :key="car.ulid" class="card relative flex gap-3 p-2.5" :class="car.sold || car.unavailable ? 'opacity-70' : ''">
                    <Link :href="car.url" class="absolute inset-0" :aria-label="car.title" />
                    <span class="flex h-[78px] w-[104px] shrink-0 items-center justify-center overflow-hidden rounded-[10px] bg-sand">
                        <img v-if="car.image" :src="car.image.src" alt="" class="h-full w-full object-cover" />
                        <CarGlyph v-else :width="56" />
                    </span>
                    <span class="flex min-w-0 grow flex-col justify-center gap-0.5">
                        <span v-if="car.sold" class="self-start rounded-lg bg-divider px-2 py-0.5 text-[11px] font-semibold text-muted">Sold</span>
                        <span v-else-if="car.unavailable" class="self-start rounded-lg bg-divider px-2 py-0.5 text-[11px] font-semibold text-muted">No longer listed</span>
                        <span v-else-if="car.price_drop" class="self-start rounded-lg bg-[#DCEFE3] px-2 py-0.5 text-[11px] font-semibold text-[#166534]">Price dropped {{ car.price_drop }}</span>
                        <span v-else-if="car.reserved" class="self-start rounded-lg bg-ink px-2 py-0.5 text-[11px] font-semibold text-white">Reserved</span>
                        <span class="truncate text-[15px] font-semibold">{{ car.title }}</span>
                        <span v-if="!car.sold" class="text-[15px] font-bold text-forest">
                            {{ car.price }} <s v-if="car.old_price" class="text-[12px] font-normal text-muted">{{ car.old_price }}</s>
                        </span>
                        <span class="text-[12px] text-muted">{{ car.lot.name }}<template v-if="car.lot.city"> · {{ car.lot.city }}</template></span>
                    </span>
                    <button type="button" class="relative z-10 inline-flex min-h-11 items-center self-center px-2 text-[13px] font-semibold text-muted hover:text-danger" :aria-label="`Remove ${car.title}`" @click="remove(car.ulid)">Remove</button>
                </li>
            </ul>
        </div>
    </CustomerLayout>
</template>
