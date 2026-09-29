<script setup lang="ts">
import BankDetailsCard, { type BankDetails } from '@/components/BankDetailsCard.vue';
import Icon from '@/components/Icon.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    reservation: {
        ulid: string;
        status: string;
        status_label: string;
        car: string;
        car_url: string;
        lot: string;
        deposit: string;
        price: string;
        until: string | null;
        left: string | null;
        end_reason: string | null;
        refunded: boolean;
        reference: string | null;
        hours: number;
        pay_by: string | null;
        sent: boolean;
        refund_due: boolean;
    };
    account: BankDetails | null;
    lot: { name: string; phone: string | null; whatsapp: string | null };
}>();

const sending = ref(false);
function sent() {
    sending.value = true;
    router.post(route('reservations.sent', props.reservation.ulid), {}, { preserveScroll: true, onFinish: () => (sending.value = false) });
}
</script>

<template>
    <Head title="Reservation" />
    <CustomerLayout active="bookings">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-5 pb-32">
            <Link :href="`${route('bookings.index')}#offers`" class="-mb-1 inline-flex min-h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline">
                <Icon name="chevronLeft" :size="18" /> My bookings
            </Link>
            <div>
                <h1 class="text-[24px] leading-tight font-bold">Reserve the {{ reservation.car }}</h1>
                <p class="text-[14px] text-muted">{{ reservation.deposit }} deposit to {{ reservation.lot }} · counts towards {{ reservation.price }}</p>
            </div>

            <template v-if="reservation.status === 'pending'">
                <ol class="card flex flex-col gap-3 p-4 text-[14px]">
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-forest text-[12px] font-bold text-white">1</span>
                        <span>Transfer <strong>{{ reservation.deposit }}</strong> to {{ lot.name }}'s account below. Put <strong>{{ reservation.reference }}</strong> in the narration.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full text-[12px] font-bold" :class="reservation.sent ? 'bg-forest text-white' : 'bg-sand text-ink'">2</span>
                        <span>Tap "I've sent it" so {{ lot.name }} checks their account.</span>
                    </li>
                    <li class="flex gap-3">
                        <span class="flex h-6 w-6 shrink-0 items-center justify-center rounded-full bg-sand text-[12px] font-bold text-ink">3</span>
                        <span>When they confirm, the car is held for you for {{ reservation.hours }} hours. We'll let you know.</span>
                    </li>
                </ol>

                <BankDetailsCard v-if="account" :account="account" :amount="reservation.deposit" :reference="reservation.reference" :title="`Pay ${lot.name}`" />
                <p v-else class="card p-4 text-[14px] text-muted">{{ lot.name }} hasn't added bank details. Call or message them before paying.</p>

                <p class="flex gap-2 rounded-xl bg-map px-3.5 py-3 text-[13px] text-forest">
                    <Icon name="shield" :size="18" class="shrink-0" />
                    <span>You pay {{ lot.name }} directly; LotLink never takes payment for cars. Check the account name matches the lot, and never pay anyone who contacts you with different details.</span>
                </p>

                <button v-if="!reservation.sent" type="button" class="btn btn-primary h-[52px] w-full rounded-[14px]" :disabled="sending" @click="sent">
                    <Icon name="check" :size="18" /> I've sent the transfer
                </button>
                <p v-else class="card flex items-center gap-2.5 px-4 py-3 text-[14px]" role="status">
                    <Icon name="clock" :size="20" class="shrink-0 text-clay" /> {{ lot.name }} has been told. They'll confirm once it's in their account.
                </p>
                <p v-if="reservation.pay_by" class="text-center text-[13px] text-muted">This request lapses if {{ lot.name }} hasn't confirmed a payment by {{ reservation.pay_by }}.</p>
            </template>

            <div v-else class="card flex flex-col gap-2 p-4 text-[14px]">
                <strong class="text-[16px]">{{ reservation.status_label }}</strong>
                <p v-if="reservation.status === 'active'">Held for you until {{ reservation.until }}<template v-if="reservation.left"> ({{ reservation.left }} left)</template>. Your deposit counts towards the price.</p>
                <p v-else-if="reservation.end_reason" class="text-muted">{{ reservation.end_reason }}</p>
                <p v-if="reservation.refund_due" class="text-clay-dark">{{ lot.name }} owes you the {{ reservation.deposit }} deposit back and will refund it directly.</p>
                <p v-else-if="reservation.refunded" class="text-success">Your deposit has been refunded.</p>
            </div>

            <div class="flex flex-wrap gap-2">
                <a v-if="lot.whatsapp" :href="`https://wa.me/${lot.whatsapp}`" target="_blank" rel="noopener" class="btn btn-outline h-11 no-underline"><Icon name="whatsapp" :size="18" /> WhatsApp {{ lot.name }}</a>
                <a v-if="lot.phone" :href="`tel:${lot.phone}`" class="btn btn-outline h-11 no-underline"><Icon name="phone" :size="18" /> Call</a>
                <Link :href="reservation.car_url" class="btn btn-outline h-11 no-underline">See the car</Link>
            </div>
        </div>
    </CustomerLayout>
</template>
