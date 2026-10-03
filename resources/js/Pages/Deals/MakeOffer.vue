<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import { formatNaira, parseAmount } from '@/lib/format';
import { shortNaira } from '@/lib/finance';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    car: CarCardData;
    price: number;
    market: { low: number; median: number; high: number; count: number } | null;
}>();

// Design 09 starts a little under the asking price.
const form = useForm({ amount: formatNaira(Math.round((props.price * 0.95) / 10_000) * 10_000), message: '' });

const amount = computed(() => parseAmount(form.amount) ?? 0);
const percentOff = computed(() => (props.price > 0 && amount.value > 0 ? Math.round(((props.price - amount.value) / props.price) * 1000) / 10 : 0));
const tooLow = computed(() => amount.value > 0 && amount.value < props.price / 2);
const tooHigh = computed(() => amount.value > props.price);

const chips = [3, 5, 8];
const chipAmount = (pct: number) => Math.round((props.price * (1 - pct / 100)) / 10_000) * 10_000;
const pick = (pct: number) => (form.amount = formatNaira(chipAmount(pct)));

// Where the offer sits among similar listings (0–100 along the bar).
const span = computed(() => (props.market ? Math.max(1, props.market.high - props.market.low) : 1));
const at = (value: number) => (props.market ? Math.min(100, Math.max(0, ((value - props.market.low) / span.value) * 100)) : 0);
const position = computed(() => {
    if (!props.market || !amount.value) return null;
    const p = at(amount.value);
    if (p < 34) return 'Your offer sits in the lower third of similar listings.';
    if (p < 67) return 'Your offer is in the middle of similar listings.';
    return 'Your offer is in the upper third of similar listings.';
});

function submit() {
    form.post(route('offers.store', props.car.ulid));
}
</script>

<template>
    <Head title="Make an offer" />
    <div class="flex min-h-dvh items-end justify-center bg-[#3A3D42] md:items-center md:p-6">
        <form class="flex w-full max-w-lg flex-col gap-4 rounded-t-3xl bg-white p-5 pb-8 md:rounded-3xl" @submit.prevent="submit">
            <div class="flex items-center justify-between">
                <h1 class="text-[22px] font-bold">Make an offer</h1>
                <Link :href="car.url" aria-label="Close" class="-mr-2 flex h-11 w-11 items-center justify-center text-ink"><Icon name="close" :size="22" /></Link>
            </div>

            <div class="flex items-center gap-3">
                <img v-if="car.image" :src="car.image.src" alt="" class="h-12 w-16 shrink-0 rounded-[10px] object-cover" />
                <span v-else class="flex h-12 w-16 shrink-0 items-center justify-center rounded-[10px] bg-sand"><CarGlyph :width="40" /></span>
                <div class="flex min-w-0 flex-col">
                    <span class="truncate text-[15px] font-semibold">{{ car.title }}</span>
                    <span class="truncate text-[13px] text-muted">Asking {{ car.price }} · {{ car.lot.name }}</span>
                </div>
            </div>

            <label class="flex flex-col gap-1.5 text-[13px] font-semibold">
                Your offer
                <input v-field="'money'"
                    v-model="form.amount"
                    inputmode="numeric"
                    autocomplete="off"
                    class="h-16 w-full rounded-[14px] border-2 border-forest px-4 font-display text-[28px] font-bold text-ink focus:outline-none"
                    @blur="form.amount = amount ? formatNaira(amount) : ''"
                />
                <span v-if="tooLow" class="font-normal text-danger">Offers start at {{ formatNaira(Math.ceil(price / 2)) }}, half the asking price.</span>
                <span v-else-if="tooHigh" class="font-normal text-danger">That's above the asking price.</span>
                <span v-else-if="percentOff > 0" class="font-normal text-muted">{{ percentOff }}% below the asking price</span>
                <InputError :message="form.errors.amount" />
            </label>

            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="c in chips"
                    :key="c"
                    type="button"
                    class="h-11 rounded-xl border text-[14px] font-semibold"
                    :class="amount === chipAmount(c) ? 'border-forest bg-forest text-white' : 'border-line bg-white text-ink'"
                    :aria-pressed="amount === chipAmount(c)"
                    @click="pick(c)"
                >
                    {{ c }}% off
                </button>
            </div>

            <div v-if="market" class="flex flex-col gap-2 rounded-[14px] bg-ivory px-3.5 py-3">
                <span class="text-[13px] font-semibold">Similar cars on CarYard ({{ market.count }})</span>
                <div class="relative h-2 rounded bg-line" aria-hidden="true">
                    <div class="absolute inset-0 rounded bg-[#9DB8B0]" />
                    <div class="absolute -top-[3px] h-3.5 w-0.5 bg-forest" :style="{ left: `${at(market.median)}%` }" />
                    <div
                        v-if="amount"
                        class="absolute -top-[5px] -ml-[9px] h-[18px] w-[18px] rounded-full border-[3px] border-white bg-clay"
                        :style="{ left: `${at(amount)}%` }"
                    />
                </div>
                <div class="flex justify-between text-[12px] text-muted">
                    <span>{{ shortNaira(market.low) }}</span><span>median {{ shortNaira(market.median) }}</span><span>{{ shortNaira(market.high) }}</span>
                </div>
                <span v-if="position" class="text-[12px] font-semibold text-clay-dark">{{ position }}</span>
            </div>

            <label class="flex flex-col gap-1.5 text-[13px] font-semibold">
                <span>Message to the seller <span class="font-normal text-muted">(optional)</span></span>
                <textarea v-field="{ kind: 'text', max: 500 }" v-model="form.message" rows="3" class="field h-auto py-3 font-normal" placeholder="e.g. I can pay this week and would like to test drive first." />
                <InputError :message="form.errors.message" />
            </label>

            <p class="text-[12px] text-muted">Your offer lasts 48 hours. The seller can accept, decline or counter, and we'll tell you on WhatsApp.</p>

            <button type="submit" class="btn btn-primary h-[52px] w-full rounded-[14px]" :disabled="form.processing || !amount || tooLow || tooHigh">
                Send offer of {{ formatNaira(amount) }}
            </button>
        </form>
    </div>
</template>
