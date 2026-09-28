<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Link, useForm } from '@inertiajs/vue3';

export type DealSettings = {
    accepts_offers: boolean;
    reservation_deposit: number | null;
    reservation_refundable: boolean;
    test_drive_deposit: number | null;
    plan: { offers: boolean; deposits: boolean };
};

const props = defineProps<{ lotSlug: string; deals: DealSettings }>();

const naira = (n: number | null) => (n ? `₦${n.toLocaleString('en-NG')}` : '');
const form = useForm({
    accepts_offers: props.deals.accepts_offers,
    reservation_deposit: naira(props.deals.reservation_deposit),
    reservation_refundable: props.deals.reservation_refundable,
    test_drive_deposit: naira(props.deals.test_drive_deposit),
});

const toggle =
    'relative mt-1 h-[26px] w-11 shrink-0 rounded-full bg-line-strong transition peer-checked:bg-forest peer-focus-visible:ring-2 peer-focus-visible:ring-forest/30 peer-disabled:opacity-50 after:absolute after:top-[3px] after:left-[3px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-[18px]';
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="form.put(route('dealer.settings.deals', lotSlug), { preserveScroll: true })">
        <h3 class="font-sans text-[16px] font-bold">Offers and deposits</h3>
        <p v-if="!deals.plan.offers || !deals.plan.deposits" class="rounded-xl bg-cream px-3.5 py-2.5 text-[14px] text-clay-dark">
            Offers, reservations and test-drive deposits are part of the Pro plan. You can set them up now; they switch on when you
            <Link :href="route('dealer.billing', lotSlug)" class="font-semibold">upgrade</Link>.
        </p>

        <label class="flex items-start justify-between gap-4 border-b border-divider pb-4 text-[15px]">
            <span class="flex flex-col">
                Take offers from buyers
                <span class="text-[13px] text-muted">On cars marked negotiable. Offers must be at least half the asking price and expire after 48 hours.</span>
            </span>
            <input v-model="form.accepts_offers" type="checkbox" role="switch" class="peer sr-only" />
            <span :class="toggle" />
        </label>

        <label class="field-label">
            Reservation deposit
            <input v-model="form.reservation_deposit" class="field md:w-64" inputmode="numeric" placeholder="Off" />
            <span class="font-normal text-muted">Buyers pay this on Paystack to hold a car for 24, 48 or 72 hours. It counts towards the price. Leave empty to turn reservations off.</span>
            <InputError :message="form.errors.reservation_deposit" />
        </label>

        <label class="flex items-start justify-between gap-4 border-b border-divider pb-4 text-[15px]">
            <span class="flex flex-col">
                Refund the deposit if a reservation runs out
                <span class="text-[13px] text-muted">Off: you keep it when the buyer doesn't come back. Cancelling a reservation yourself always refunds it.</span>
            </span>
            <input v-model="form.reservation_refundable" type="checkbox" role="switch" class="peer sr-only" />
            <span :class="toggle" />
        </label>

        <label class="field-label">
            Test-drive deposit
            <input v-model="form.test_drive_deposit" class="field md:w-64" inputmode="numeric" placeholder="None" />
            <span class="font-normal text-muted">Refundable. Returned when the buyer arrives or cancels before the slot; kept for a no-show.</span>
            <InputError :message="form.errors.test_drive_deposit" />
        </label>

        <div class="flex justify-end">
            <button type="submit" class="btn btn-primary" :disabled="form.processing">Save</button>
        </div>
    </form>
</template>
