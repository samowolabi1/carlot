<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { submitOrQueue, useOfflineQueue } from '@/composables/useOfflineQueue';
import { useShared } from '@/composables/useShared';
import { useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref, watch } from 'vue';
import type { ManagerOptions, StockCar } from './types';

const props = defineProps<{ open: boolean; stock: StockCar[]; options: ManagerOptions }>();
const emit = defineEmits<{ close: [] }>();

const { currentLot } = useShared();
const queue = useOfflineQueue(() => currentLot.value?.slug);

const form = useForm({
    name: '',
    phone: '',
    vehicles: [] as string[],
    interest: 'browsing',
    source: 'walk_in',
    next_step: 'none',
    budget_max: '',
    notes: '',
    consent_whatsapp: false,
    client_uuid: '',
});

const carSearch = ref('');
const nameInput = ref<HTMLInputElement>();
const queuedMessage = ref('');

const matches = computed(() => {
    const term = carSearch.value.trim().toLowerCase();
    if (!term) return [];
    return props.stock.filter((c) => !form.vehicles.includes(c.ulid) && c.title.toLowerCase().includes(term)).slice(0, 6);
});
const picked = computed(() => form.vehicles.map((u) => props.stock.find((c) => c.ulid === u)).filter((c): c is StockCar => !!c));

function pick(car: StockCar) {
    if (form.vehicles.length < 5) form.vehicles.push(car.ulid);
    carSearch.value = '';
}

watch(
    () => props.open,
    (open) => {
        if (open) {
            queuedMessage.value = '';
            void nextTick(() => nameInput.value?.focus());
        }
    },
);

function payload() {
    const { client_uuid: _ignored, ...data } = form.data();
    void _ignored;
    return { ...data, budget_max: data.budget_max ? Number(String(data.budget_max).replace(/[^\d]/g, '')) : null, visited_at: new Date().toISOString() };
}

function submit() {
    const data = payload();
    submitOrQueue({
        queue,
        type: 'walk_in',
        label: `Walk-in: ${form.name || form.phone}`,
        data,
        post: (clientUuid, handlers) => {
            form.transform(() => ({ ...data, client_uuid: clientUuid })).post(route('dealer.manager.walk-ins.store', currentLot.value!.slug), {
                preserveScroll: true,
                ...handlers,
            });
        },
        onSuccess: () => {
            form.reset();
            emit('close');
        },
        onQueued: () => {
            form.reset();
            queuedMessage.value = 'No signal: saved on this phone. It will sync when you are back online.';
        },
    });
}
</script>

<template>
    <div v-if="open" class="fixed inset-0 z-50 bg-ink/40" @click.self="emit('close')">
        <form
            class="absolute inset-x-0 bottom-0 flex max-h-[92dvh] flex-col gap-4 overflow-y-auto rounded-t-3xl bg-white p-5 shadow-2xl *:shrink-0 md:inset-y-0 md:right-0 md:left-auto md:max-h-none md:w-[440px] md:rounded-none"
            role="dialog"
            aria-modal="true"
            aria-labelledby="walk-in-title"
            @submit.prevent="submit"
        >
            <div class="flex items-center justify-between">
                <h2 id="walk-in-title" class="text-[22px] font-bold">New walk-in</h2>
                <button type="button" class="flex h-11 w-11 items-center justify-center" aria-label="Close" @click="emit('close')">
                    <Icon name="close" :size="22" :stroke-width="2" />
                </button>
            </div>

            <p v-if="queuedMessage" class="rounded-xl bg-cream px-3 py-2.5 text-[14px] text-clay-dark" role="status">{{ queuedMessage }}</p>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="field-label">
                    Name
                    <input v-field="'person_name'" ref="nameInput" v-model="form.name" class="field" autocomplete="off" required />
                    <InputError :message="form.errors.name" />
                </label>
                <label class="field-label">
                    Phone
                    <input v-field="'phone'" v-model="form.phone" class="field" type="tel" inputmode="tel" autocomplete="off" placeholder="0803 123 4567" required />
                    <InputError :message="form.errors.phone" />
                </label>
            </div>

            <div class="field-label">
                <label for="car-search">Cars they looked at</label>
                <div v-if="picked.length" class="flex flex-wrap gap-1.5">
                    <span v-for="car in picked" :key="car.ulid" class="inline-flex items-center gap-1 rounded-full bg-ivory py-1 pr-1 pl-3 text-[13px] font-medium">
                        {{ car.title }}
                        <button type="button" class="flex h-7 w-7 items-center justify-center rounded-full hover:bg-sand" :aria-label="`Remove ${car.title}`" @click="form.vehicles = form.vehicles.filter((u) => u !== car.ulid)">
                            <Icon name="close" :size="14" />
                        </button>
                    </span>
                </div>
                <div class="relative">
                    <input v-field="{ kind: 'text', max: 60 }" id="car-search" v-model="carSearch" class="field" type="search" autocomplete="off" placeholder="Search your stock" />
                    <ul v-if="matches.length" class="absolute inset-x-0 top-13 z-10 overflow-hidden rounded-xl border border-line bg-white shadow-lg">
                        <li v-for="car in matches" :key="car.ulid">
                            <button type="button" class="flex h-11 w-full items-center justify-between gap-2 px-3 text-left text-[14px] font-normal hover:bg-ivory" @click="pick(car)">
                                <span class="truncate">{{ car.title }}</span>
                                <span class="shrink-0 text-muted">{{ car.price_label }}</span>
                            </button>
                        </li>
                    </ul>
                </div>
            </div>

            <fieldset class="flex flex-col gap-1.5">
                <legend class="mb-1.5 text-[13px] font-semibold">Interest</legend>
                <div class="grid grid-cols-3 gap-1.5">
                    <label v-for="o in options.interests" :key="o.value" class="flex h-11 cursor-pointer items-center justify-center rounded-xl border border-line-strong text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:font-semibold has-[:checked]:text-white">
                        <input v-model="form.interest" type="radio" name="interest" :value="o.value" class="sr-only" />{{ o.label }}
                    </label>
                </div>
            </fieldset>

            <fieldset class="flex flex-col gap-1.5">
                <legend class="mb-1.5 text-[13px] font-semibold">Next step</legend>
                <div class="grid grid-cols-2 gap-1.5">
                    <label v-for="o in options.next_steps" :key="o.value" class="flex h-11 cursor-pointer items-center justify-center rounded-xl border border-line-strong text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:font-semibold has-[:checked]:text-white">
                        <input v-model="form.next_step" type="radio" name="next_step" :value="o.value" class="sr-only" />{{ o.label }}
                    </label>
                </div>
                <p v-if="form.next_step === 'call_back'" class="text-[13px] text-muted">We'll remind you to call them the next working day at 10:00.</p>
            </fieldset>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <label class="field-label">
                    How they heard about you
                    <select v-model="form.source" class="field">
                        <option v-for="o in options.sources" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
                <label class="field-label">
                    Budget (₦, optional)
                    <input v-field="{ kind: 'money', min: 0 }" v-model="form.budget_max" class="field" inputmode="numeric" placeholder="8,000,000" />
                </label>
            </div>

            <label class="field-label">
                Note
                <textarea v-field="{ kind: 'text', max: 1000 }" v-model="form.notes" class="field h-20 py-2.5" placeholder="Wants a 2015+ Camry, will bring spouse Saturday" />
            </label>

            <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl bg-ivory p-3 text-[14px]">
                <input v-model="form.consent_whatsapp" type="checkbox" class="mt-0.5 h-5 w-5 accent-forest" />
                <span>
                    <strong>They agreed to WhatsApp messages</strong><br />
                    <span class="text-muted">Receipts and order updates are only sent if they said yes.</span>
                </span>
            </label>

            <button type="submit" class="btn btn-primary w-full" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save walk-in' }}</button>
        </form>
    </div>
</template>
