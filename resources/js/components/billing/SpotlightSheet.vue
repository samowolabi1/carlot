<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { json } from '@/lib/http';
import { formatNaira } from '@/lib/format';
import { router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

// Spotlight a car (TDD M5): first in matching searches ("Sponsored") and in the home carousel.
const props = defineProps<{ lot: string; car: { ulid: string; title: string; spotlight_until: string | null } | null }>();
const emit = defineEmits<{ close: [] }>();

const options = ref<{ days: number; price: number }[]>([]);
const freeLeft = ref(0);
const days = ref(7);
const useFree = ref(false);
const busy = ref(false);
const error = ref('');

watch(
    () => props.car,
    async (car) => {
        if (!car) return;
        error.value = '';
        const data = await json<{ car: { days: number; price: number }[]; free_left: number }>('GET', route('dealer.spotlight.options', props.lot));
        options.value = data.car;
        freeLeft.value = data.free_left;
        useFree.value = data.free_left > 0;
        days.value = 7;
    },
);

const canUseFree = computed(() => freeLeft.value > 0 && days.value === 7);
const price = computed(() => options.value.find((o) => o.days === days.value)?.price ?? 0);

function submit() {
    if (!props.car) return;
    busy.value = true;
    router.post(route('dealer.vehicles.spotlight', [props.lot, props.car.ulid]), { days: days.value, free: useFree.value && canUseFree.value }, {
        preserveScroll: true,
        onSuccess: () => emit('close'),
        onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Something went wrong.'),
        onFinish: () => (busy.value = false),
    });
}
</script>

<template>
    <div v-if="car" class="fixed inset-0 z-50 bg-ink/40" @click.self="emit('close')">
        <form
            class="absolute inset-x-0 bottom-0 flex flex-col gap-4 rounded-t-3xl bg-white p-5 shadow-2xl md:inset-y-0 md:right-0 md:left-auto md:w-[420px] md:rounded-none"
            role="dialog"
            aria-modal="true"
            aria-labelledby="spotlight-title"
            @submit.prevent="submit"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <h2 id="spotlight-title" class="text-[22px] font-bold">Spotlight this car</h2>
                    <p class="text-[14px] text-muted">{{ car.title }}</p>
                </div>
                <button type="button" class="flex h-11 w-11 shrink-0 items-center justify-center" aria-label="Close" @click="emit('close')"><Icon name="close" :size="22" :stroke-width="2" /></button>
            </div>
            <p class="text-[14px]">It shows first in matching searches, marked "Sponsored", and in the Spotlight row on the home page.</p>
            <p v-if="car.spotlight_until" class="rounded-xl bg-map px-3 py-2 text-[13px] text-forest">Already in the spotlight until {{ car.spotlight_until }}. More days are added to the end.</p>

            <fieldset>
                <legend class="mb-1.5 text-[13px] font-semibold">How long</legend>
                <div class="grid grid-cols-3 gap-2">
                    <label v-for="o in options" :key="o.days" class="flex h-16 cursor-pointer flex-col items-center justify-center rounded-xl border border-line text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white">
                        <input v-model="days" type="radio" name="spotlight-days" :value="o.days" class="sr-only" />
                        <span class="font-semibold">{{ o.days }} days</span><span class="text-[12px] opacity-80">{{ formatNaira(o.price) }}</span>
                    </label>
                </div>
            </fieldset>

            <label v-if="freeLeft > 0" class="flex min-h-11 items-center gap-3 rounded-xl bg-ivory p-3 text-[14px]" :class="canUseFree ? '' : 'opacity-50'">
                <input v-model="useFree" type="checkbox" class="h-5 w-5 accent-forest" :disabled="!canUseFree" />
                <span>Use a free spotlight ({{ freeLeft }} left this month, 7 days only)</span>
            </label>

            <InputError :message="error" />
            <button type="submit" class="btn btn-primary" :disabled="busy || options.length === 0">
                {{ busy ? 'Opening Paystack…' : useFree && canUseFree ? 'Start free spotlight' : `Pay ${formatNaira(price)}` }}
            </button>
        </form>
    </div>
</template>
