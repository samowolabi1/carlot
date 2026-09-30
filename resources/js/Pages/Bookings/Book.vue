<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Slot = { time: string; starts_at: string; remaining: number; available: boolean };
type Day = { date: string; weekday: string; day: number; month: string; closed: boolean; slots: Slot[] };

const props = defineProps<{
    lot: { slug: string; name: string; city: string | null; initials: string; logo_url: string | null };
    car: CarCardData | null;
    types: { value: string; label: string }[];
    days: Day[];
    reschedule: { ulid: string; type: string; starts_at: string } | null;
    defaultType: string;
}>();

const bookable = (d: Day) => !d.closed && d.slots.some((s) => s.available);
const firstOpen = props.days.findIndex(bookable);
const dayIndex = ref(firstOpen >= 0 ? firstOpen : 0);
const day = computed(() => props.days[dayIndex.value]);

const form = useForm({
    lot: props.lot.slug,
    type: props.reschedule?.type ?? props.defaultType,
    starts_at: '',
    vehicle: props.car?.ulid ?? null,
    notes: '',
    whatsapp_reminders: true,
});

const chosen = computed(() => day.value?.slots.find((s) => s.starts_at === form.starts_at));
const typeLabel = computed(() => props.types.find((t) => t.value === form.type)?.label ?? 'visit');
const summary = computed(() => (chosen.value ? `${day.value.weekday} ${day.value.day} ${day.value.month.split(' ')[0].slice(0, 3)}, ${chosen.value.time}` : null));

const goBack = () => window.history.back();

function pickDay(i: number) {
    dayIndex.value = i;
    form.starts_at = '';
}

function submit() {
    if (props.reschedule) {
        form.transform((d) => ({ starts_at: d.starts_at })).patch(route('bookings.update', props.reschedule.ulid));
    } else {
        form.post(route('bookings.store'));
    }
}
</script>

<template>
    <Head :title="reschedule ? 'Move your booking' : 'Book a visit'" />
    <CustomerLayout active="bookings">
        <div class="mx-auto flex max-w-xl flex-col gap-5 px-5 pt-4 pb-40 md:pt-8">
            <div class="flex items-center gap-2">
                <button type="button" aria-label="Back" class="-ml-2.5 flex h-11 w-11 items-center justify-center" @click="goBack"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></button>
                <h1 class="text-[22px] font-bold">{{ reschedule ? 'Move your booking' : 'Book a visit' }}</h1>
            </div>

            <div class="card flex items-center gap-3 p-2.5">
                <span class="flex h-[54px] w-[72px] shrink-0 items-center justify-center overflow-hidden rounded-[10px] bg-sand">
                    <img v-if="car?.image" :src="car.image.src" alt="" class="h-full w-full object-cover" />
                    <CarGlyph v-else-if="car" :width="40" />
                    <span v-else class="font-display text-[18px] font-bold text-forest">{{ lot.initials }}</span>
                </span>
                <span class="flex flex-col">
                    <span class="text-[15px] font-semibold">{{ car?.title ?? `Visit ${lot.name}` }}</span>
                    <span class="text-[13px] text-muted">{{ lot.name }}<template v-if="lot.city"> · {{ lot.city }}</template></span>
                </span>
            </div>

            <fieldset v-if="!reschedule" class="flex flex-col gap-2.5">
                <legend class="mb-2.5 text-[15px] font-semibold">What would you like to do?</legend>
                <div class="grid grid-cols-2 gap-2">
                    <label
                        v-for="t in types"
                        :key="t.value"
                        class="flex h-[52px] cursor-pointer items-center gap-2 rounded-xl border px-3 text-[14px]"
                        :class="form.type === t.value ? 'border-2 border-clay bg-cream font-semibold' : 'border-line bg-white'"
                    >
                        <input v-model="form.type" type="radio" name="type" :value="t.value" class="accent-clay" />{{ t.label }}
                    </label>
                </div>
            </fieldset>

            <div class="flex flex-col gap-2.5">
                <div class="flex items-baseline justify-between">
                    <span class="text-[15px] font-semibold">Pick a day</span>
                    <span class="text-[13px] text-muted">{{ day?.month }}</span>
                </div>
                <div class="-mr-5 flex gap-1.5 overflow-x-auto pr-5 pb-1" role="radiogroup" aria-label="Day">
                    <button
                        v-for="(d, i) in days"
                        :key="d.date"
                        type="button"
                        role="radio"
                        :aria-checked="i === dayIndex"
                        :disabled="!bookable(d)"
                        class="flex h-16 w-[54px] shrink-0 flex-col items-center justify-center gap-0.5 rounded-xl"
                        :class="i === dayIndex ? 'bg-forest text-white' : bookable(d) ? 'border border-line bg-white' : 'border border-dashed border-line-strong text-muted/60'"
                        @click="pickDay(i)"
                    >
                        <span class="text-[11px]" :class="i === dayIndex ? 'text-mist' : 'text-muted'">{{ d.weekday }}</span>
                        <span class="text-[17px] font-semibold">{{ d.day }}</span>
                    </button>
                </div>
            </div>

            <div class="flex flex-col gap-2.5">
                <span class="text-[15px] font-semibold">Pick a time</span>
                <div v-if="day && bookable(day)" class="grid grid-cols-4 gap-2" role="radiogroup" aria-label="Time">
                    <button
                        v-for="s in day.slots"
                        :key="s.starts_at"
                        type="button"
                        role="radio"
                        :aria-checked="form.starts_at === s.starts_at"
                        :disabled="!s.available"
                        class="h-11 rounded-[10px] text-[14px]"
                        :class="form.starts_at === s.starts_at ? 'bg-clay font-semibold text-white' : s.available ? 'border border-line bg-white font-medium' : 'border border-dashed border-[#D5CFC3] text-[#8E8A82] line-through'"
                        :aria-label="s.available ? s.time : `${s.time}, not available`"
                        @click="form.starts_at = s.starts_at"
                    >
                        {{ s.time }}
                    </button>
                </div>
                <p v-else class="card px-4 py-5 text-center text-[14px] text-muted">{{ lot.name }} is closed or fully booked this day. Try another day.</p>
                <p v-if="days.every((d) => !bookable(d))" class="text-[14px] text-muted">No free times in the next two weeks. Message the lot on WhatsApp to arrange a visit.</p>
                <InputError :message="form.errors.starts_at ?? form.errors.vehicle" />
            </div>

            <template v-if="!reschedule">
                <label class="field-label">
                    Anything the lot should know? <span class="font-normal text-muted">(optional)</span>
                    <textarea v-field="{ kind: 'text', max: 500 }" v-model="form.notes" rows="2" class="field h-auto py-3" placeholder="e.g. I'd like to bring my mechanic" />
                </label>
                <label class="card flex items-center gap-2.5 px-3.5 py-3 text-[14px]">
                    <input v-model="form.whatsapp_reminders" type="checkbox" class="h-[18px] w-[18px] accent-forest" />
                    Remind me on WhatsApp (otherwise by SMS)
                </label>
            </template>
        </div>

        <div class="fixed inset-x-0 bottom-[76px] z-30 border-t border-line bg-white md:bottom-0">
            <div class="mx-auto max-w-xl px-5 pt-3 pb-3 md:pb-6">
                <button type="button" class="btn btn-primary h-[54px] w-full rounded-[14px] text-[16px]" :disabled="!form.starts_at || form.processing" @click="submit">
                    <template v-if="summary">{{ reschedule ? 'Move to' : `Book ${typeLabel.toLowerCase()}` }} · {{ summary }}</template>
                    <template v-else>Pick a time</template>
                </button>
            </div>
        </div>
    </CustomerLayout>
</template>
