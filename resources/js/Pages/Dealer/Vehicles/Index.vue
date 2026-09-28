<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import SpotlightSheet from '@/components/billing/SpotlightSheet.vue';
import Icon from '@/components/Icon.vue';
import ShareMenu from '@/components/marketplace/ShareMenu.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Row {
    ulid: string;
    title: string;
    vin_tail: string | null;
    thumb_url: string | null;
    photos: number;
    price: string | null;
    status: 'draft' | 'available' | 'reserved' | 'sold' | 'hidden';
    days_listed: number | null;
    ageing: boolean;
    new_arrival: boolean;
    next_statuses: string[];
    order: { ulid: string; order_no: string } | null;
    share_url: string | null;
    spotlight_until: string | null;
}

const props = defineProps<{
    vehicles: { data: Row[]; links: { url: string | null; label: string; active: boolean }[]; total: number; last_page: number };
    filters: { status: string | null; q: string };
    counts: Record<string, number>;
    stats: { sold_this_month: number; ageing: number };
    canManage: boolean;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const search = ref(props.filters.q);
const spotlighting = ref<Row | null>(null);

const tabs = computed(() => [
    { key: null, label: 'All', count: props.counts.all },
    { key: 'available', label: 'Available', count: props.counts.available },
    { key: 'reserved', label: 'Reserved', count: props.counts.reserved },
    { key: 'draft', label: 'Draft', count: props.counts.draft },
    { key: 'hidden', label: 'Hidden', count: props.counts.hidden },
    { key: 'sold', label: 'Sold', count: props.counts.sold },
]);

const live = computed(() => props.counts.available + props.counts.reserved);
const monthName = new Date().toLocaleDateString('en-GB', { month: 'long' });

function filter(status: string | null) {
    router.get(route('dealer.vehicles.index', lot.value.slug), { status: status ?? undefined, q: search.value || undefined }, { preserveState: true, preserveScroll: true });
}

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => filter(props.filters.status), 350);
});

const badge: Record<Row['status'], string> = {
    available: 'bg-[#E3F1E8] text-success',
    reserved: 'bg-blush text-clay-dark',
    draft: 'bg-sand text-muted',
    hidden: 'bg-sand text-muted',
    sold: 'bg-forest text-white',
};

// "List on LotLink" is the M3 publish flow; hiding takes a car off the marketplace.
const actionLabel = (row: Row, next: string) =>
    ({ hidden: 'Take off LotLink', available: row.status === 'reserved' ? 'Release reservation' : 'List on LotLink', reserved: 'Mark reserved' })[next] ?? next;

function setStatus(row: Row, status: string) {
    router.patch(route('dealer.vehicles.status', [lot.value.slug, row.ulid]), { status }, { preserveScroll: true });
}

function remove(row: Row) {
    if (confirm(`Delete ${row.title}? This can't be undone.`)) {
        router.delete(route('dealer.vehicles.destroy', [lot.value.slug, row.ulid]), { preserveScroll: true });
    }
}

function editHref(row: Row) {
    // Drafts resume where they're missing something; listed cars open their details.
    const step = row.status === 'draft' ? (row.photos === 0 ? 'photos' : 'price') : 'details';
    return route('dealer.vehicles.edit', [lot.value.slug, row.ulid, step]);
}
</script>

<template>
    <Head title="Stock" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">Stock</h1>
                <span class="text-[14px] text-muted">{{ counts.all }} {{ counts.all === 1 ? 'car' : 'cars' }} · {{ live }} live on the marketplace</span>
            </div>
            <div class="flex gap-2.5">
                <span class="btn btn-outline h-11 cursor-not-allowed px-4 text-[14px] opacity-50" aria-disabled="true" title="Coming in a later update">Bulk import</span>
                <Link :href="route('dealer.vehicles.create', lot.slug)" class="btn btn-primary h-11 px-4 text-[14px]">
                    <Icon name="plus" :size="16" :stroke-width="2.4" /> Add car
                </Link>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Available</div>
                <div class="font-display text-[28px] font-bold">{{ counts.available }}</div>
            </div>
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Reserved</div>
                <div class="font-display text-[28px] font-bold">{{ counts.reserved }}</div>
            </div>
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Sold in {{ monthName }}</div>
                <div class="font-display text-[28px] font-bold">{{ stats.sold_this_month }}</div>
            </div>
            <div class="card p-[18px]" :class="stats.ageing ? 'border-apricot bg-cream' : ''">
                <div class="text-[13px]" :class="stats.ageing ? 'text-clay-dark' : 'text-muted'">Ageing (45+ days)</div>
                <div class="font-display text-[28px] font-bold">{{ stats.ageing }}</div>
            </div>
        </div>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <nav aria-label="Filter by status" class="-mx-5 flex gap-1 overflow-x-auto px-5 lg:mx-0 lg:px-0">
                <button
                    v-for="tab in tabs"
                    :key="tab.label"
                    type="button"
                    class="h-10 shrink-0 rounded-full px-3.5 text-[14px]"
                    :class="filters.status === tab.key ? 'bg-forest font-semibold text-white' : 'border border-line bg-white text-ink'"
                    :aria-pressed="filters.status === tab.key"
                    @click="filter(tab.key)"
                >
                    {{ tab.label }} {{ tab.count }}
                </button>
            </nav>
            <label class="relative lg:w-80">
                <span class="sr-only">Search stock</span>
                <Icon name="search" class="absolute top-3.5 left-3 text-muted" :size="18" />
                <input v-model="search" type="search" class="field h-11 pl-10" placeholder="Search make, model or VIN" />
            </label>
        </div>

        <div v-if="vehicles.data.length === 0" class="card flex flex-col items-center gap-3 px-6 py-12 text-center">
            <div class="flex h-20 w-32 items-center justify-center rounded-2xl bg-sand"><CarGlyph :width="80" /></div>
            <template v-if="counts.all === 0">
                <h2 class="text-xl font-bold">List your first car</h2>
                <p class="max-w-sm text-[15px] text-muted">Start with the VIN and we'll fill in the make, model and year. It takes about two minutes.</p>
                <Link :href="route('dealer.vehicles.create', lot.slug)" class="btn btn-primary">Add a car</Link>
            </template>
            <p v-else class="text-[15px] text-muted">No cars match. Try another filter or search.</p>
        </div>

        <div v-else class="card overflow-hidden">
            <ul class="divide-y divide-divider">
                <li v-for="row in vehicles.data" :key="row.ulid" class="flex flex-wrap items-center gap-x-4 gap-y-3 px-4 py-3 md:flex-nowrap">
                    <Link :href="editHref(row)" class="flex min-w-0 grow items-center gap-3 text-ink no-underline">
                        <span class="flex h-14 w-[74px] shrink-0 items-center justify-center overflow-hidden rounded-lg bg-sand">
                            <img v-if="row.thumb_url" :src="row.thumb_url" alt="" class="h-full w-full object-cover" loading="lazy" />
                            <CarGlyph v-else :width="44" />
                        </span>
                        <span class="flex min-w-0 flex-col">
                            <span class="truncate text-[15px] font-semibold">{{ row.title }}</span>
                            <span class="text-[13px] text-muted">
                                <template v-if="row.status === 'draft' && row.photos === 0">Needs photos</template>
                                <template v-else-if="row.vin_tail">VIN ···{{ row.vin_tail }}</template>
                                <template v-else>{{ row.photos }} photos</template>
                                <span v-if="row.new_arrival" class="ml-1.5 rounded-lg bg-blush px-1.5 py-0.5 text-[11px] font-semibold text-clay-dark">New arrival</span>
                                <span v-if="row.spotlight_until" class="ml-1.5 rounded-lg bg-forest px-1.5 py-0.5 text-[11px] font-semibold text-white">Spotlight to {{ row.spotlight_until }}</span>
                            </span>
                        </span>
                    </Link>

                    <span class="w-32 font-display text-[16px] font-bold text-forest">{{ row.price ?? '—' }}</span>

                    <span class="w-24">
                        <span class="rounded-full px-2.5 py-1 text-[12px] font-semibold capitalize" :class="badge[row.status]">{{ row.status }}</span>
                    </span>

                    <span class="w-28 text-[13px]" :class="row.ageing ? 'font-semibold text-clay-dark' : 'text-muted'">
                        <template v-if="row.days_listed !== null">{{ row.days_listed }} {{ row.days_listed === 1 ? 'day' : 'days' }}<template v-if="row.ageing"> · Ageing</template></template>
                        <template v-else>—</template>
                    </span>

                    <span class="ml-auto flex shrink-0 items-center gap-3 text-[13px] font-semibold">
                        <ShareMenu
                            v-if="row.share_url"
                            :title="row.title"
                            :text="`${row.title}${row.price ? ` — ${row.price}` : ''} at ${lot.name}`"
                            :url="row.share_url"
                            :vehicle="row.ulid"
                            label="Share"
                            images
                            compact
                        />
                        <Link v-if="row.status === 'draft'" :href="editHref(row)">List on LotLink</Link>
                        <Link v-else :href="editHref(row)">Edit</Link>
                        <Link v-if="row.order" :href="route('dealer.manager.orders.show', [lot.slug, row.order.ulid])" class="text-clay">Order {{ row.order.order_no }}</Link>
                        <Link
                            v-else-if="row.status === 'available' || row.status === 'reserved'"
                            :href="route('dealer.manager.orders.create', { lot: lot.slug, vehicle: row.ulid })"
                            class="text-clay"
                            >Mark sold</Link
                        >
                        <button v-if="canManage && row.status === 'available' && row.share_url" type="button" class="text-clay hover:text-clay-dark" @click="spotlighting = row">Spotlight</button>
                        <template v-if="canManage">
                            <button
                                v-for="next in row.next_statuses.filter((n) => !(row.order && n === 'available'))"
                                :key="next"
                                type="button"
                                class="text-forest hover:text-clay"
                                @click="setStatus(row, next)"
                            >
                                {{ actionLabel(row, next) }}
                            </button>
                            <button v-if="row.status === 'draft' || row.status === 'hidden'" type="button" class="text-muted hover:text-danger" @click="remove(row)">Delete</button>
                        </template>
                    </span>
                </li>
            </ul>

            <nav v-if="vehicles.last_page > 1" aria-label="Pages" class="flex flex-wrap gap-1 border-t border-divider p-3">
                <template v-for="link in vehicles.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="flex h-9 min-w-9 items-center justify-center rounded-lg px-2.5 text-[14px] no-underline"
                        :class="link.active ? 'bg-forest text-white' : 'text-ink hover:bg-ivory'"
                        ><span v-html="link.label"
                    /></Link>
                </template>
            </nav>
        </div>
        <SpotlightSheet :lot="lot.slug" :car="spotlighting" @close="spotlighting = null" />
    </DealerLayout>
</template>
