<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { LotSettings } from '@/types';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ lot: LotSettings; onboarding?: boolean }>();

// Show Monday first, as lots think of their week.
const order = [1, 2, 3, 4, 5, 6, 0];
const days = [...(props.lot.hours?.days ?? [])].sort((a, b) => order.indexOf(a.weekday) - order.indexOf(b.weekday));

const form = useForm({
    days: days.map((d) => ({ weekday: d.weekday, label: d.label, is_closed: d.is_closed, opens_at: d.opens_at ?? '09:00', closes_at: d.closes_at ?? '17:00' })),
    slot_minutes: props.lot.hours?.slot_minutes ?? 30,
    slot_capacity: props.lot.hours?.slot_capacity ?? 2,
    onboarding: !!props.onboarding,
});

function copyMondayToWeekdays() {
    const monday = form.days[0];
    form.days.slice(1, 5).forEach((d) => Object.assign(d, { is_closed: monday.is_closed, opens_at: monday.opens_at, closes_at: monday.closes_at }));
}

function submit() {
    form.transform((data) => ({
        ...data,
        days: data.days.map(({ weekday, is_closed, opens_at, closes_at }) => ({
            weekday,
            is_closed,
            opens_at: is_closed ? null : opens_at,
            closes_at: is_closed ? null : closes_at,
        })),
    })).put(route('dealer.settings.hours', props.lot.slug), { preserveScroll: true });
}

const error = (i: number, field: string) => (form.errors as Record<string, string>)[`days.${i}.${field}`];
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <div class="card divide-y divide-divider">
            <div v-for="(day, i) in form.days" :key="day.weekday" class="flex flex-wrap items-center gap-3 px-4 py-3">
                <span class="w-28 text-[15px] font-semibold">{{ day.label }}</span>
                <label class="flex items-center gap-2 text-[14px]">
                    <input v-model="day.is_closed" type="checkbox" :true-value="false" :false-value="true" class="h-5 w-5 accent-forest" />
                    Open
                </label>
                <template v-if="!day.is_closed">
                    <input v-model="day.opens_at" type="time" class="field h-11 w-32" :aria-label="`${day.label} opens`" />
                    <span class="text-muted">to</span>
                    <input v-model="day.closes_at" type="time" class="field h-11 w-32" :aria-label="`${day.label} closes`" />
                </template>
                <span v-else class="text-[14px] text-muted">Closed</span>
                <button v-if="i === 0" type="button" class="ml-auto text-[13px] font-semibold text-clay" @click="copyMondayToWeekdays">Copy to Tue–Fri</button>
                <div class="w-full"><InputError :message="error(i, 'opens_at') ?? error(i, 'closes_at')" /></div>
            </div>
        </div>

        <div class="flex flex-col gap-2">
            <h3 class="text-[16px] font-bold">Booking rules</h3>
            <div class="grid gap-3 md:grid-cols-2">
                <label class="field-label">
                    Slot length
                    <select v-model.number="form.slot_minutes" class="field">
                        <option v-for="m in [15, 30, 45, 60, 90, 120]" :key="m" :value="m">{{ m }} min</option>
                    </select>
                    <InputError :message="form.errors.slot_minutes" />
                </label>
                <label class="field-label">
                    Visits per slot
                    <input v-model.number="form.slot_capacity" type="number" min="1" max="20" class="field" />
                    <InputError :message="form.errors.slot_capacity" />
                </label>
            </div>
            <p class="text-[13px] text-muted">Buyers can book viewings and test drives in these slots once bookings go live.</p>
        </div>

        <slot name="actions" :processing="form.processing">
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Save changes</button>
            </div>
        </slot>
    </form>
</template>
