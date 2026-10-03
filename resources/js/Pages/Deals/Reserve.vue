<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    car: CarCardData;
    lot: { name: string };
    deposit: string;
    agreed: string | null;
    hours: number[];
    payWithin: number;
    refundable: boolean;
}>();

const form = useForm({ hours: 48 });

const goBack = () => window.history.back();
const submit = () => form.post(route('reservations.store', props.car.ulid));
</script>

<template>
    <Head title="Reserve this car" />
    <CustomerLayout bare>
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-4 pb-60 md:pb-44">
            <div class="flex items-center gap-2">
                <button type="button" aria-label="Back" class="-ml-2 flex h-11 w-11 items-center justify-center text-ink" @click="goBack"><Icon name="chevronLeft" :size="22" /></button>
                <h1 class="text-[22px] font-bold">Reserve this car</h1>
            </div>

            <div class="card flex items-center gap-3 p-2.5">
                <img v-if="car.image" :src="car.image.src" alt="" class="h-[54px] w-[72px] shrink-0 rounded-[10px] object-cover" />
                <span v-else class="flex h-[54px] w-[72px] shrink-0 items-center justify-center rounded-[10px] bg-sand"><CarGlyph :width="44" /></span>
                <div class="flex min-w-0 flex-col">
                    <span class="truncate text-[15px] font-semibold">{{ car.title }}</span>
                    <span class="truncate text-[13px] text-muted">
                        <template v-if="agreed">{{ agreed }} agreed</template><template v-else>{{ car.price }}</template> · {{ lot.name }}
                    </span>
                </div>
            </div>

            <fieldset class="flex flex-col gap-2.5">
                <legend class="mb-2.5 text-[15px] font-semibold">Hold it for</legend>
                <div class="grid grid-cols-3 gap-2">
                    <label
                        v-for="h in hours"
                        :key="h"
                        class="flex h-[52px] cursor-pointer items-center justify-center rounded-xl border text-[14px] font-semibold"
                        :class="form.hours === h ? 'border-forest bg-forest text-white' : 'border-line bg-white text-ink'"
                    >
                        <input v-model="form.hours" type="radio" name="hours" :value="h" class="sr-only" />{{ h }} hours
                    </label>
                </div>
                <InputError :message="form.errors.hours" />
            </fieldset>

            <div class="card flex flex-col gap-2.5 p-4">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-[15px] font-semibold">Reservation deposit</span>
                    <span class="font-display text-[24px] font-bold">{{ deposit }}</span>
                </div>
                <ul class="flex list-disc flex-col gap-1 pl-[18px] text-[13px] text-[#4A4D53]">
                    <li>Counts towards the price when you buy</li>
                    <li v-if="refundable">{{ lot.name }} refunds it if you don't buy by the end of the hold, or if they cancel</li>
                    <li v-else>{{ lot.name }} refunds it if they cancel or the car isn't as described. Kept if the hold ends without a sale</li>
                    <li>Held for {{ form.hours }} hours from when {{ lot.name }} confirms your payment. Other buyers see "Reserved"</li>
                </ul>
            </div>

            <div class="card flex flex-col gap-2 p-4">
                <span class="text-[15px] font-semibold">How it works</span>
                <ol class="flex list-decimal flex-col gap-1 pl-[18px] text-[13px] text-[#4A4D53]">
                    <li>Ask to reserve. You'll see {{ lot.name }}'s bank details and a payment reference.</li>
                    <li>Transfer the deposit from your bank app within {{ payWithin }} hours.</li>
                    <li>{{ lot.name }} confirms it arrived, and the car is held for you.</li>
                </ol>
                <p class="flex gap-2 rounded-xl bg-map px-3 py-2 text-[13px] text-forest">
                    <Icon name="shield" :size="18" class="shrink-0" /> You pay {{ lot.name }} directly. CarYard never takes payment for cars.
                </p>
            </div>
        </div>

        <div class="fixed inset-x-0 bottom-[76px] z-30 border-t border-line bg-white md:bottom-0">
            <div class="mx-auto flex max-w-xl flex-col gap-2 px-5 pt-3 pb-3 md:pb-6">
                <button type="button" class="btn btn-primary h-[52px] w-full rounded-[14px]" :disabled="form.processing" @click="submit">Ask to reserve · {{ deposit }} deposit</button>
                <span class="text-center text-[12px] text-muted">Nothing is charged here. You'll get the seller's bank details next.</span>
            </div>
        </div>
    </CustomerLayout>
</template>
