<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { FilterOptions, Filters } from '@/components/marketplace/types';
import { useLocation } from '@/composables/useLocation';
import { formatNaira, parseAmount } from '@/lib/format';
import { reactive } from 'vue';

const props = defineProps<{ filters: Filters; options: FilterOptions }>();
const emit = defineEmits<{ apply: [filters: Filters]; clear: [] }>();

// Plain copy: edits stay local until "Show cars".
const state = reactive<Filters>(JSON.parse(JSON.stringify(props.filters)));
const { locate, locating, error: locationError } = useLocation();

const thisYear = new Date().getFullYear();
const years = Array.from({ length: thisYear + 1 - 1995 + 1 }, (_, i) => thisYear + 1 - i);
const mileages = [30000, 50000, 80000, 100000, 150000, 200000];

function toggle<T>(list: T[], value: T) {
    const i = list.indexOf(value);
    if (i >= 0) list.splice(i, 1);
    else list.push(value);
}

function money(field: 'price_min' | 'price_max', e: Event) {
    state[field] = parseAmount((e.target as HTMLInputElement).value);
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

defineExpose({ state });
</script>

<template>
    <form class="flex flex-col" @submit.prevent="emit('apply', state)">
        <div class="flex flex-col gap-2.5 border-b border-divider pb-4">
            <h3 class="font-sans text-[15px] font-semibold">Price</h3>
            <div class="flex items-center gap-2.5">
                <input :value="formatNaira(state.price_min)" class="field h-[46px]" inputmode="numeric" placeholder="Min" aria-label="Minimum price" @input="money('price_min', $event)" />
                <span class="text-muted">–</span>
                <input :value="formatNaira(state.price_max)" class="field h-[46px]" inputmode="numeric" placeholder="Max" aria-label="Maximum price" @input="money('price_max', $event)" />
            </div>
        </div>

        <div class="flex flex-col gap-2.5 border-b border-divider py-4">
            <h3 class="font-sans text-[15px] font-semibold">Distance from you</h3>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="km in options.radii"
                    :key="km"
                    type="button"
                    class="h-9 rounded-full border px-3.5 text-[14px]"
                    :class="state.radius === km ? 'border-forest bg-forest text-white' : 'border-line bg-white'"
                    :aria-pressed="state.radius === km"
                    :disabled="locating"
                    @click="setRadius(km)"
                >
                    {{ km }} km
                </button>
                <button type="button" class="h-9 rounded-full border px-3.5 text-[14px]" :class="state.radius === null ? 'border-forest bg-forest text-white' : 'border-line bg-white'" @click="setRadius(null)">Any</button>
            </div>
            <InputError :message="locationError ?? undefined" />
        </div>

        <fieldset class="flex flex-col gap-2.5 border-b border-divider py-4">
            <legend class="mb-2.5 text-[15px] font-semibold">Body type</legend>
            <div class="grid grid-cols-3 gap-2">
                <label
                    v-for="o in options.body_types"
                    :key="o.value"
                    class="flex h-9 cursor-pointer items-center justify-center rounded-full border border-line bg-white px-2 text-[13px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-forest/30"
                >
                    <input type="checkbox" class="sr-only" :checked="state.body.includes(o.value)" @change="toggle(state.body, o.value)" />
                    {{ o.label }}
                </label>
            </div>
        </fieldset>

        <fieldset v-if="options.makes.length" class="flex flex-col gap-2.5 border-b border-divider py-4">
            <legend class="mb-2.5 text-[15px] font-semibold">Make</legend>
            <div class="flex flex-wrap gap-2">
                <label
                    v-for="make in options.makes"
                    :key="make.id"
                    class="flex h-9 cursor-pointer items-center rounded-full border border-line bg-white px-3.5 text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-forest/30"
                >
                    <input type="checkbox" class="sr-only" :checked="state.make.includes(make.id)" @change="toggle(state.make, make.id)" />
                    {{ make.name }}
                </label>
            </div>
        </fieldset>

        <div class="flex flex-col gap-2.5 border-b border-divider py-4">
            <h3 class="font-sans text-[15px] font-semibold">Year and mileage</h3>
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
        </div>

        <fieldset class="flex flex-col gap-2.5 py-4">
            <legend class="mb-2.5 text-[15px] font-semibold">Gearbox, condition and fuel</legend>
            <div class="flex flex-wrap gap-2">
                <button
                    v-for="o in options.transmissions"
                    :key="o.value"
                    type="button"
                    class="h-9 rounded-full border px-3.5 text-[14px]"
                    :class="state.transmission === o.value ? 'border-forest bg-forest text-white' : 'border-line bg-white'"
                    :aria-pressed="state.transmission === o.value"
                    @click="state.transmission = state.transmission === o.value ? null : o.value"
                >
                    {{ o.label }}
                </button>
                <label
                    v-for="o in options.conditions"
                    :key="o.value"
                    class="flex h-9 cursor-pointer items-center rounded-full border border-line bg-white px-3.5 text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-forest/30"
                >
                    <input type="checkbox" class="sr-only" :checked="state.condition.includes(o.value)" @change="toggle(state.condition, o.value)" />
                    {{ o.label }}
                </label>
                <label
                    v-for="o in options.fuels"
                    :key="o.value"
                    class="flex h-9 cursor-pointer items-center rounded-full border border-line bg-white px-3.5 text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-forest/30"
                >
                    <input type="checkbox" class="sr-only" :checked="state.fuel.includes(o.value)" @change="toggle(state.fuel, o.value)" />
                    {{ o.label }}
                </label>
            </div>
        </fieldset>

        <slot name="actions" />
    </form>
</template>
