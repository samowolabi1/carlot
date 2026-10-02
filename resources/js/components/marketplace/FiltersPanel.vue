<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import FacetList from '@/components/marketplace/FacetList.vue';
import FilterSection from '@/components/marketplace/FilterSection.vue';
import type { FilterOptions, Filters } from '@/components/marketplace/types';
import { useLocation } from '@/composables/useLocation';
import { shortNaira } from '@/lib/finance';
import { formatNaira, typedAmount } from '@/lib/format';
import { computed, onBeforeUnmount, reactive, watch } from 'vue';

// Every choice comes from what is on sale (SearchFacets), with how many cars have it; groups fold away and long
// lists show the popular few with "Show all" and a search box.
const props = defineProps<{ filters: Filters; options: FilterOptions; live?: boolean }>();
const emit = defineEmits<{ apply: [filters: Filters]; clear: [] }>();

// A plain copy. With `live`, every change applies after a short pause (typing a price doesn't send each digit);
// without it, edits stay local until the form is submitted.
const copy = (f: Filters): Filters => JSON.parse(JSON.stringify(f));
const state = reactive<Filters>(copy(props.filters));

let pause: ReturnType<typeof setTimeout> | undefined;
watch(
    state,
    () => {
        if (!props.live) return;
        clearTimeout(pause);
        if (JSON.stringify(state) === JSON.stringify(props.filters)) return;
        pause = setTimeout(() => emit('apply', copy(state)), 350);
    },
    { deep: true },
);
// Filters changed elsewhere (a chip removed, "Clear all", back button): show them here too.
watch(
    () => props.filters,
    (filters) => {
        if (JSON.stringify(filters) !== JSON.stringify(state)) Object.assign(state, copy(filters));
    },
    { deep: true },
);
onBeforeUnmount(() => clearTimeout(pause));
const { locate, locating, error: locationError } = useLocation();

const thisYear = new Date().getFullYear();
// Years on sale (newest first), so nobody picks a year with no cars.
const years = computed(() => {
    const max = props.options.years?.max ?? thisYear + 1;
    const min = Math.min(props.options.years?.min ?? 1995, max);
    return Array.from({ length: max - min + 1 }, (_, i) => max - i);
});
const mileages = [30000, 50000, 80000, 100000, 150000, 200000];

function toggle<T>(list: T[], value: T) {
    const i = list.indexOf(value);
    if (i >= 0) list.splice(i, 1);
    else list.push(value);
}

function money(field: 'price_min' | 'price_max', e: Event) {
    state[field] = typedAmount((e.target as HTMLInputElement).value);
}

async function setRadius(km: number | null) {
    if (km !== null && state.lat === null) {
        const here = await locate();
        if (!here) return;
        state.lat = here.lat;
        state.lng = here.lng;
    }
    state.radius = km;
}

const makes = computed(() => props.options.makes.map((m) => ({ value: m.id, label: m.name, count: m.count })));
// Models of the chosen makes only.
const models = computed(() => props.options.models.filter((m) => state.make.includes(m.make_id)).map((m) => ({ value: m.id, label: m.name, count: m.count })));
function toggleMake(id: number) {
    toggle(state.make, id);
    const allowed = new Set(props.options.models.filter((m) => state.make.includes(m.make_id)).map((m) => m.id));
    state.model = state.model.filter((m) => allowed.has(m));
}

// Towns with cars, in the chosen state.
const cities = computed(() => props.options.cities.filter((c) => !state.state || c.state === state.state));
watch(
    () => state.state,
    () => {
        if (state.city && !cities.value.some((c) => c.value === state.city)) state.city = null;
    },
);

const features = computed(() => props.options.features.map((f) => ({ value: f.id, label: f.name, count: f.count, group: f.group })));
const featureGroups = { comfort: 'Comfort', safety: 'Safety', tech: 'Tech' };

const pill =
    'flex min-h-9 cursor-pointer items-center justify-center gap-1 rounded-full border border-line bg-white px-3 text-[13px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-forest/30';
const pillButton = (on: boolean) => ['flex min-h-9 items-center gap-1 rounded-full border px-3 text-[13px]', on ? 'border-forest bg-forest text-white' : 'border-line bg-white'];

defineExpose({ state });
</script>

<template>
    <form class="flex flex-col" @submit.prevent="emit('apply', state)">
        <FilterSection title="Price" open :selected="state.price_min || state.price_max ? 1 : 0">
            <div class="flex items-center gap-2.5">
                <input
                    v-field="{ kind: 'money', min: 0 }"
                    :value="formatNaira(state.price_min)"
                    class="field h-[46px]"
                    inputmode="numeric"
                    :placeholder="options.prices?.min ? shortNaira(options.prices.min) : 'Min'"
                    aria-label="Minimum price"
                    @input="money('price_min', $event)"
                />
                <span class="text-muted">–</span>
                <input
                    v-field="{ kind: 'money', min: 0 }"
                    :value="formatNaira(state.price_max)"
                    class="field h-[46px]"
                    inputmode="numeric"
                    :placeholder="options.prices?.max ? shortNaira(options.prices.max) : 'Max'"
                    aria-label="Maximum price"
                    @input="money('price_max', $event)"
                />
            </div>
        </FilterSection>

        <FilterSection v-if="options.extras.length" title="Car loans, deals and trust" open :selected="state.has.length">
            <FacetList label="Extras" :options="options.extras" :selected="state.has" :limit="10" @toggle="(v) => toggle(state.has, String(v))" />
        </FilterSection>

        <FilterSection v-if="makes.length" title="Make and model" open :selected="state.make.length + state.model.length">
            <FacetList label="Makes" :options="makes" :selected="state.make" @toggle="(v) => toggleMake(Number(v))" />
            <div v-if="models.length" class="mt-3 border-t border-divider pt-3">
                <p class="pb-1 text-[12px] font-semibold tracking-wide text-muted uppercase">Model</p>
                <FacetList label="Models" :options="models" :selected="state.model" @toggle="(v) => toggle(state.model, Number(v))" />
            </div>
        </FilterSection>

        <FilterSection v-if="options.body_types.length" title="Body type" open :selected="state.body.length">
            <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-2">
                <label v-for="o in options.body_types" :key="o.value" :class="pill">
                    <input type="checkbox" class="sr-only" :checked="state.body.includes(o.value)" @change="toggle(state.body, o.value)" />
                    {{ o.label }} <span class="opacity-70">{{ o.count }}</span>
                </label>
            </div>
        </FilterSection>

        <FilterSection title="Location" :selected="(state.radius ? 1 : 0) + (state.state ? 1 : 0) + (state.city ? 1 : 0)">
            <p class="pb-2 text-[13px] text-muted">Distance from you</p>
            <div class="flex flex-wrap gap-2">
                <button v-for="km in options.radii" :key="km" type="button" :class="pillButton(state.radius === km)" :aria-pressed="state.radius === km" :disabled="locating" @click="setRadius(km)">
                    {{ km }} km
                </button>
                <button type="button" :class="pillButton(state.radius === null)" :aria-pressed="state.radius === null" @click="setRadius(null)">Any</button>
            </div>
            <InputError :message="locationError ?? undefined" />
            <label class="mt-3 flex flex-col gap-1.5">
                <span class="text-[13px] text-muted">State</span>
                <select v-model="state.state" class="field h-[46px]">
                    <option :value="null">Anywhere in Nigeria</option>
                    <option v-for="r in options.states" :key="r.value" :value="r.value">{{ r.label }} ({{ r.count }})</option>
                </select>
            </label>
            <div v-if="cities.length > 1 || state.city" class="mt-3">
                <p class="pb-1 text-[13px] text-muted">Town or area</p>
                <FacetList label="Towns" :options="cities" :selected="state.city ? [state.city] : []" @toggle="(v) => (state.city = state.city === v ? null : String(v))" />
            </div>
        </FilterSection>

        <FilterSection title="Year and mileage" :selected="(state.year_min || state.year_max ? 1 : 0) + (state.mileage_max ? 1 : 0)">
            <div class="grid grid-cols-3 gap-2.5">
                <select v-model.number="state.year_min" class="field h-[46px] px-2" aria-label="Year from">
                    <option :value="null">From</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <select v-model.number="state.year_max" class="field h-[46px] px-2" aria-label="Year to">
                    <option :value="null">To</option>
                    <option v-for="y in years" :key="y" :value="y">{{ y }}</option>
                </select>
                <select v-model.number="state.mileage_max" class="field h-[46px] px-2" aria-label="Maximum mileage">
                    <option :value="null">Any km</option>
                    <option v-for="m in mileages" :key="m" :value="m">≤{{ m.toLocaleString('en-NG') }}</option>
                </select>
            </div>
        </FilterSection>

        <FilterSection v-if="options.transmissions.length || options.drivetrains.length" title="Gearbox and drive" :selected="(state.transmission ? 1 : 0) + state.drive.length">
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="o in options.transmissions"
                    :key="o.value"
                    type="button"
                    :class="pillButton(state.transmission === o.value)"
                    :aria-pressed="state.transmission === o.value"
                    @click="state.transmission = state.transmission === o.value ? null : o.value"
                >
                    {{ o.label }} <span class="opacity-70">{{ o.count }}</span>
                </button>
            </div>
            <div v-if="options.drivetrains.length" class="mt-2 flex flex-wrap gap-2">
                <label v-for="o in options.drivetrains" :key="o.value" :class="pill">
                    <input type="checkbox" class="sr-only" :checked="state.drive.includes(o.value)" @change="toggle(state.drive, o.value)" />
                    {{ o.label }} <span class="opacity-70">{{ o.count }}</span>
                </label>
            </div>
        </FilterSection>

        <FilterSection v-if="options.conditions.length" title="Condition" :selected="state.condition.length">
            <FacetList label="Conditions" :options="options.conditions" :selected="state.condition" @toggle="(v) => toggle(state.condition, String(v))" />
        </FilterSection>

        <FilterSection v-if="options.fuels.length" title="Fuel" :selected="state.fuel.length">
            <FacetList label="Fuels" :options="options.fuels" :selected="state.fuel" @toggle="(v) => toggle(state.fuel, String(v))" />
        </FilterSection>

        <FilterSection v-if="options.colours.length" title="Colour" :selected="state.colour.length">
            <FacetList label="Colours" :options="options.colours" :selected="state.colour" @toggle="(v) => toggle(state.colour, String(v))" />
        </FilterSection>

        <FilterSection v-if="features.length" title="Features" :selected="state.feature.length">
            <p class="pb-1 text-[13px] text-muted">Cars with every feature you tick, as listed by the lot.</p>
            <FacetList label="Features" :options="features" :selected="state.feature" :limit="8" :groups="featureGroups" @toggle="(v) => toggle(state.feature, Number(v))" />
        </FilterSection>

        <slot name="actions" />
    </form>
</template>
