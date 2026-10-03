<script setup lang="ts">
import LiveSearchInput from '@/components/marketplace/LiveSearchInput.vue';
import SearchBanner from '@/components/marketplace/SearchBanner.vue';
import type { AdBanner } from '@/lib/ads';
import Icon from '@/components/Icon.vue';
import CarCard, { type CarCardData } from '@/components/marketplace/CarCard.vue';
import FiltersPanel from '@/components/marketplace/FiltersPanel.vue';
import { toQuery, type FilterOptions, type Filters } from '@/components/marketplace/types';
import { useBudget } from '@/composables/useBudget';
import { useCompare } from '@/composables/useCompare';
import { useLocation } from '@/composables/useLocation';
import { useShared } from '@/composables/useShared';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { shortNaira } from '@/lib/finance';
import { formatNaira } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

const props = defineProps<{
    results: { data: CarCardData[]; total: number; current_page: number; last_page: number; links: { url: string | null; label: string; active: boolean }[] };
    lotCount: number;
    filters: Filters;
    activeFilters: number;
    options: FilterOptions;
    sponsored: CarCardData[];
    banner: AdBanner | null;
    landing: { heading: string; intro: string; related: { label: string; url: string; count: number }[]; url: string } | null;
    savedSearch: string | null;
}>();

const { user } = useShared();
// "Save search" (TDD M4): the filters in the URL, without the buyer's location.
const canSave = computed(() => !!props.filters.q || props.activeFilters - (props.filters.radius ? 1 : 0) > 0);
const saving = ref(false);
function saveSearch() {
    if (!user.value) {
        router.visit(route('login'));
        return;
    }
    const { lat: _lat, lng: _lng, radius: _radius, sort: _sort, ...rest } = props.filters;
    router.post(route('saved-searches.store'), { filters: toQuery(rest) as Record<string, string> }, { preserveScroll: true, onStart: () => (saving.value = true), onFinish: () => (saving.value = false) });
}

const q = ref(props.filters.q ?? '');
const sheetOpen = ref(false);
const compare = useCompare();
const budget = useBudget();
const budgetApplied = computed(() => budget.maxPrice.value !== null && props.filters.price_max === budget.maxPrice.value && !props.filters.price_min);
const { locate, locating, error: locationError } = useLocation();

const sorts = computed(() => [
    { value: 'newest', label: 'Newest' },
    ...(props.filters.lat !== null ? [{ value: 'nearest', label: 'Nearest first' }] : []),
    { value: 'price_asc', label: 'Lowest price' },
    { value: 'price_desc', label: 'Highest price' },
    { value: 'year_desc', label: 'Newest year' },
    { value: 'mileage_asc', label: 'Lowest mileage' },
]);

// Live search: typing and every filter change refresh just the results (the rest of the page stays),
// a newer change cancels the request still on its way, and the URL follows so it can be shared or saved.
const RESULTS = ['results', 'sponsored', 'lotCount', 'banner', 'filters', 'activeFilters', 'savedSearch', 'landing'];
const searching = ref(false);

function go(filters: Partial<Filters>, closeSheet = true) {
    if (closeSheet) sheetOpen.value = false;
    router.get(route('cars.index'), toQuery({ ...filters }) as Record<string, string>, {
        only: RESULTS,
        preserveState: true,
        preserveScroll: true,
        replace: true,
        onStart: () => (searching.value = true),
        onFinish: () => (searching.value = false),
    });
}

const apply = (next: Partial<Filters>, closeSheet = true) => go({ ...props.filters, ...next, q: q.value.trim() || null }, closeSheet);

let typing: ReturnType<typeof setTimeout> | undefined;
watch(q, (value) => {
    clearTimeout(typing);
    if (value.trim() === (props.filters.q ?? '')) return;
    typing = setTimeout(() => apply({}, false), 300);
});
onBeforeUnmount(() => clearTimeout(typing));

async function nearMe() {
    const here = await locate();
    if (here) apply({ lat: here.lat, lng: here.lng, sort: 'nearest' });
}

const label = (list: { value: string; label: string }[], v: string) => list.find((o) => o.value === v)?.label ?? v;

// Removable chips for what's applied, as in the search design.
const chips = computed(() => {
    const f = props.filters;
    const out: { label: string; remove: Partial<Filters> }[] = [];
    if (budgetApplied.value) out.push({ label: `Within my budget (${shortNaira(f.price_max!)})`, remove: { price_min: null, price_max: null } });
    else if (f.price_min || f.price_max) out.push({ label: `${f.price_min ? formatNaira(f.price_min) : 'Any'} – ${f.price_max ? formatNaira(f.price_max) : 'Any'}`, remove: { price_min: null, price_max: null } });
    if (f.radius) out.push({ label: `Within ${f.radius} km`, remove: { radius: null } });
    f.make.forEach((id) => {
        // Removing a make also drops its models.
        const models = f.model.filter((m) => props.options.models.find((x) => x.id === m)?.make_id !== id);
        out.push({ label: props.options.makes.find((m) => m.id === id)?.name ?? 'Make', remove: { make: f.make.filter((m) => m !== id), model: models } });
    });
    f.model.forEach((id) => out.push({ label: props.options.models.find((m) => m.id === id)?.name ?? 'Model', remove: { model: f.model.filter((m) => m !== id) } }));
    f.body.forEach((b) => out.push({ label: label(props.options.body_types, b), remove: { body: f.body.filter((x) => x !== b) } }));
    if (f.transmission) out.push({ label: label(props.options.transmissions, f.transmission), remove: { transmission: null } });
    f.condition.forEach((c) => out.push({ label: label(props.options.conditions, c), remove: { condition: f.condition.filter((x) => x !== c) } }));
    f.fuel.forEach((c) => out.push({ label: label(props.options.fuels, c), remove: { fuel: f.fuel.filter((x) => x !== c) } }));
    f.drive.forEach((c) => out.push({ label: label(props.options.drivetrains, c), remove: { drive: f.drive.filter((x) => x !== c) } }));
    f.colour.forEach((c) => out.push({ label: label(props.options.colours, c), remove: { colour: f.colour.filter((x) => x !== c) } }));
    f.feature.forEach((id) => out.push({ label: props.options.features.find((x) => x.id === id)?.name ?? 'Feature', remove: { feature: f.feature.filter((x) => x !== id) } }));
    f.has.forEach((c) => out.push({ label: label(props.options.extras, c), remove: { has: f.has.filter((x) => x !== c) } }));
    if (f.year_min || f.year_max) out.push({ label: `${f.year_min ?? 'Any'}–${f.year_max ?? 'now'}`, remove: { year_min: null, year_max: null } });
    if (f.state) out.push({ label: f.state === 'FCT' ? 'FCT (Abuja)' : `${f.state} State`, remove: { state: null, city: null } });
    if (f.city) out.push({ label: f.city, remove: { city: null } });
    if (f.mileage_max) out.push({ label: `≤${f.mileage_max.toLocaleString('en-NG')} km`, remove: { mileage_max: null } });
    return out;
});

const empty: Partial<Filters> = {
    make: [], model: [], body: [], condition: [], fuel: [], drive: [], colour: [], feature: [], has: [], transmission: null,
    price_min: null, price_max: null, year_min: null, year_max: null, mileage_max: null, radius: null, city: null, state: null,
};

const heading = computed(() => {
    const n = props.results.total;
    return `${n.toLocaleString('en-NG')} ${n === 1 ? 'car' : 'cars'}` + (props.lotCount > 0 && props.results.last_page === 1 ? ` at ${props.lotCount} ${props.lotCount === 1 ? 'seller' : 'sellers'}` : '');
});
</script>

<template>
    <Head :title="landing ? landing.heading : filters.q ? `${filters.q} for sale` : 'Cars for sale'" />
    <CustomerLayout active="search">
        <div class="border-b border-line bg-white">
            <div class="mx-auto flex max-w-6xl flex-col gap-3 px-5 py-4">
                <form class="flex items-center gap-2" role="search" @submit.prevent="apply({})">
                    <div class="relative flex h-11 grow items-center gap-2 rounded-xl bg-ivory px-3">
                        <Icon name="search" :size="18" class="text-muted" :stroke-width="2" />
                        <!-- Cars show live in the results below, so the list suggests makes, models, places and lots. -->
                        <LiveSearchInput v-model="q" input-class="h-11 w-full bg-transparent text-[15px] font-medium outline-none" :with-cars="false" @submit="apply({})" />
                    </div>
                    <button type="button" class="flex h-11 shrink-0 items-center rounded-xl border border-forest bg-white px-3 text-[14px] font-semibold text-forest lg:hidden" @click="sheetOpen = true">
                        Filters<template v-if="activeFilters"> · {{ activeFilters }}</template>
                    </button>
                </form>
                <div v-if="chips.length" class="-mr-5 flex gap-2 overflow-x-auto pr-5">
                    <button v-for="chip in chips" :key="chip.label" type="button" class="flex h-8 shrink-0 items-center gap-1.5 rounded-full bg-map px-3 text-[13px] font-medium text-forest tap" :aria-label="`Remove ${chip.label}`" @click="apply(chip.remove)">
                        {{ chip.label }} <Icon name="close" :size="13" :stroke-width="2.2" />
                    </button>
                    <button type="button" class="h-8 shrink-0 px-2 text-[13px] font-semibold text-clay tap" @click="apply(empty)">Clear all</button>
                </div>
            </div>
        </div>

        <div class="mx-auto flex max-w-6xl gap-8 px-5 py-4 lg:py-6">
            <aside class="hidden w-72 shrink-0 lg:block" aria-label="Filters">
                <div class="sticky top-4 card max-h-[calc(100vh-2rem)] overflow-y-auto overscroll-contain px-4 py-2">
                    <FiltersPanel live :filters="filters" :options="options" @apply="(f) => apply(f, false)">
                        <template #actions>
                            <button v-if="activeFilters" type="button" class="btn btn-outline mt-2 w-full" @click="apply(empty)">Clear all filters</button>
                        </template>
                    </FiltersPanel>
                </div>
            </aside>

            <div class="flex min-w-0 grow flex-col gap-3">
                <!-- SEO landing pages (TDD M18): a real heading and intro, and narrower pages. -->
                <header v-if="landing" class="flex flex-col gap-2">
                    <h1 class="text-[26px] leading-tight font-bold md:text-[32px]">{{ landing.heading }}</h1>
                    <p class="max-w-3xl text-[15px] text-[#4A4D53]">{{ landing.intro }}</p>
                    <nav v-if="landing.related.length" aria-label="Related searches" class="-mr-5 flex gap-2 overflow-x-auto pr-5 pb-1">
                        <Link v-for="r in landing.related" :key="r.url" :href="r.url" class="flex h-9 shrink-0 items-center gap-1 rounded-full bg-white px-3 text-[13px] font-semibold text-ink no-underline ring-1 ring-line tap">
                            {{ r.label }} <span class="font-normal text-muted">{{ r.count }}</span>
                        </Link>
                    </nav>
                </header>
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <component :is="landing ? 'h2' : 'h1'" class="font-sans text-[15px] text-muted"><strong class="text-ink">{{ heading }}</strong></component>
                    <div class="flex flex-wrap items-center gap-2">
                        <button
                            v-if="budget.maxPrice.value !== null && !budgetApplied"
                            type="button"
                            class="flex h-9 items-center gap-1.5 rounded-full border border-line bg-white px-3 text-[13px] font-semibold text-forest tap"
                            @click="apply({ price_min: null, price_max: budget.maxPrice.value })"
                        >
                            Within my budget ({{ shortNaira(budget.maxPrice.value) }})
                        </button>
                        <Link :href="route('budget')" class="flex h-9 items-center px-1 text-[13px] font-semibold tap">{{ budget.maxPrice.value !== null ? 'Edit budget' : 'What can I afford?' }}</Link>
                        <button
                            v-if="filters.lat === null"
                            type="button"
                            class="flex h-9 items-center gap-1.5 rounded-full border border-line bg-white px-3 text-[13px] font-semibold text-forest tap"
                            :disabled="locating"
                            @click="nearMe"
                        >
                            <Icon name="locate" :size="16" /> {{ locating ? 'Finding you…' : 'Near me' }}
                        </button>
                        <button
                            v-if="canSave"
                            type="button"
                            class="flex h-9 items-center gap-1.5 rounded-full border px-3 text-[13px] font-semibold tap"
                            :class="savedSearch ? 'border-forest bg-forest text-white' : 'border-clay bg-white text-clay'"
                            :disabled="saving || !!savedSearch"
                            @click="saveSearch"
                        >
                            <Icon :name="savedSearch ? 'check' : 'bell'" :size="15" :stroke-width="2" /> {{ savedSearch ? 'Search saved' : 'Save search' }}
                        </button>
                        <label class="flex items-center gap-1.5 text-[13px]">
                            <span class="text-muted">Sort</span>
                            <select :value="filters.sort" class="h-11 rounded-lg border border-line bg-white px-2 font-medium" @change="apply({ sort: ($event.target as HTMLSelectElement).value })">
                                <option v-for="s in sorts" :key="s.value" :value="s.value">{{ s.label }}</option>
                            </select>
                        </label>
                    </div>
                </div>
                <p v-if="locationError" class="text-[13px] text-danger" role="alert">{{ locationError }}</p>

                <section v-if="sponsored.length" aria-labelledby="sponsored-heading" class="flex flex-col gap-2 rounded-2xl bg-map/60 p-3">
                    <h2 id="sponsored-heading" class="font-sans text-[12px] font-semibold tracking-wide text-forest uppercase">Sponsored</h2>
                    <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-3">
                        <CarCard v-for="car in sponsored" :key="`s-${car.ulid}`" :car="car" compare />
                    </div>
                </section>

                <div v-if="results.data.length" class="grid gap-3 transition-opacity sm:grid-cols-2 xl:grid-cols-3" :class="{ 'opacity-60': searching }" :aria-busy="searching">
                    <template v-for="(car, i) in results.data" :key="car.ulid">
                        <CarCard :car="car" compare />
                        <!-- A seller's search banner after the sixth car (or at the end of a short list). -->
                        <SearchBanner v-if="banner && (i === 5 || (i === results.data.length - 1 && results.data.length < 6))" :ad="banner" />
                    </template>
                </div>
                <div v-else class="card flex flex-col items-center gap-2 px-6 py-12 text-center">
                    <h2 class="text-xl font-bold">No cars match yet</h2>
                    <p class="max-w-sm text-[15px] text-muted">Try fewer filters or a wider distance. New stock arrives every day.</p>
                    <button v-if="chips.length" type="button" class="btn btn-outline mt-2" @click="apply(empty)">Clear filters</button>
                </div>

                <nav v-if="results.last_page > 1" aria-label="Pages" class="flex flex-wrap justify-center gap-1 pt-2">
                    <template v-for="link in results.links" :key="link.label">
                        <Link
                            v-if="link.url"
                            :href="link.url"
                            class="flex h-11 min-w-11 items-center justify-center rounded-lg px-3 text-[14px] no-underline"
                            :class="link.active ? 'bg-forest text-white' : 'bg-white text-ink ring-1 ring-line'"
                            ><span v-html="link.label"
                        /></Link>
                    </template>
                </nav>
            </div>
        </div>

        <!-- Compare tray -->
        <div v-if="compare.ids.value.length" class="fixed inset-x-4 bottom-24 z-30 mx-auto flex max-w-md items-center justify-between gap-3 rounded-2xl bg-forest px-4 py-3 text-white shadow-lg md:bottom-6">
            <span class="text-[14px]">{{ compare.ids.value.length }} of 3 selected</span>
            <span class="flex items-center gap-3">
                <button type="button" class="text-[13px] text-mist hover:text-white" @click="compare.clear()">Clear</button>
                <Link :href="compare.href.value" class="rounded-lg bg-clay px-3 py-2 text-[14px] font-semibold text-white no-underline hover:bg-clay-dark hover:text-white">Compare</Link>
            </span>
        </div>

        <!-- Filters sheet (phone) -->
        <div v-if="sheetOpen" class="fixed inset-0 z-50 bg-ink/60 lg:hidden" @click.self="sheetOpen = false">
            <div class="absolute inset-x-0 top-12 bottom-0 flex flex-col rounded-t-3xl bg-white" role="dialog" aria-modal="true" aria-labelledby="filters-title">
                <div class="flex items-center justify-between px-5 pt-4 pb-2">
                    <h2 id="filters-title" class="text-[22px] font-bold">Filters</h2>
                    <button type="button" class="flex h-11 w-11 items-center justify-center" aria-label="Close" @click="sheetOpen = false"><Icon name="close" :size="22" :stroke-width="2" /></button>
                </div>
                <div class="grow overflow-y-auto px-5 pb-28">
                    <FiltersPanel live :filters="filters" :options="options" @apply="(f) => apply(f, false)">
                        <template #actions>
                            <div class="fixed inset-x-0 bottom-0 flex gap-2.5 border-t border-line bg-white px-5 pt-3 pb-6">
                                <button type="button" class="btn btn-outline h-[52px] rounded-[14px]" @click="apply(empty, false)">Clear all</button>
                                <!-- Results update behind the sheet as filters change; this just shows them. -->
                                <button type="button" class="btn btn-primary h-[52px] grow rounded-[14px]" :aria-busy="searching" @click="sheetOpen = false">
                                    {{ searching ? 'Updating…' : `Show ${results.total.toLocaleString('en-NG')} ${results.total === 1 ? 'car' : 'cars'}` }}
                                </button>
                            </div>
                        </template>
                    </FiltersPanel>
                </div>
            </div>
        </div>
    </CustomerLayout>
</template>
