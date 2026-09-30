<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { router, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    lotSlug: string;
    booking: { auto_confirm: boolean; min_notice_minutes: number; closures: { id: number; date: string; label: string; reason: string | null }[] };
}>();

const rules = useForm({ booking_auto_confirm: props.booking.auto_confirm, booking_min_notice_minutes: props.booking.min_notice_minutes });
const closure = useForm({ date: '', reason: '' });

const notices = [
    { value: 0, label: 'No minimum' },
    { value: 30, label: '30 minutes' },
    { value: 60, label: '1 hour' },
    { value: 120, label: '2 hours' },
    { value: 240, label: '4 hours' },
    { value: 1440, label: '1 day' },
];

const today = new Date().toISOString().slice(0, 10);

function addClosure() {
    closure.post(route('dealer.settings.closures.store', props.lotSlug), { preserveScroll: true, onSuccess: () => closure.reset() });
}

function removeClosure(id: number) {
    router.delete(route('dealer.settings.closures.destroy', [props.lotSlug, id]), { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-6">
        <form class="flex flex-col gap-4" @submit.prevent="rules.put(route('dealer.settings.booking', lotSlug), { preserveScroll: true })">
            <h3 class="font-sans text-[16px] font-bold">Booking rules</h3>
            <label class="flex items-start justify-between gap-4 border-b border-divider pb-4 text-[15px]">
                <span class="flex flex-col">
                    Confirm bookings automatically
                    <span class="text-[13px] text-muted">Off: you confirm each request. Requests left for 4 hours are sent to the owner.</span>
                </span>
                <input v-model="rules.booking_auto_confirm" type="checkbox" role="switch" class="peer sr-only" />
                <span class="relative mt-1 h-[26px] w-11 shrink-0 rounded-full bg-line-strong transition peer-checked:bg-forest peer-focus-visible:ring-2 peer-focus-visible:ring-forest/30 after:absolute after:top-[3px] after:left-[3px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-[18px]" />
            </label>
            <label class="field-label">
                Minimum notice
                <select v-model.number="rules.booking_min_notice_minutes" class="field md:w-64">
                    <option v-for="n in notices" :key="n.value" :value="n.value">{{ n.label }}</option>
                </select>
                <span class="font-normal text-muted">How soon before a slot buyers can still book it.</span>
                <InputError :message="rules.errors.booking_min_notice_minutes" />
            </label>
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" :disabled="rules.processing">Save rules</button>
            </div>
        </form>

        <section class="flex flex-col gap-3 border-t border-divider pt-5">
            <h3 class="font-sans text-[16px] font-bold">Closures</h3>
            <p class="text-[14px] text-muted">Public holidays, stock-taking, or any day buyers shouldn't book.</p>
            <ul v-if="booking.closures.length" class="divide-y divide-divider rounded-xl border border-line">
                <li v-for="c in booking.closures" :key="c.id" class="flex items-center justify-between gap-3 px-3.5 py-2.5 text-[14px]">
                    <span><strong>{{ c.label }}</strong><template v-if="c.reason"> · {{ c.reason }}</template></span>
                    <button type="button" class="text-[13px] font-semibold text-clay" @click="removeClosure(c.id)">Remove</button>
                </li>
            </ul>
            <form class="flex flex-col gap-2 md:flex-row" @submit.prevent="addClosure">
                <label class="field-label">
                    <span class="sr-only">Date</span>
                    <input v-model="closure.date" type="date" :min="today" class="field" required />
                </label>
                <label class="field-label grow">
                    <span class="sr-only">Reason</span>
                    <input v-field="{ kind: 'text', max: 120 }" v-model="closure.reason" class="field" placeholder="Reason (optional), e.g. Independence Day" />
                </label>
                <button type="submit" class="btn btn-dark" :disabled="closure.processing">Add closure</button>
            </form>
            <InputError :message="closure.errors.date" />
        </section>
    </div>
</template>
