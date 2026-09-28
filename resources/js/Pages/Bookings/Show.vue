<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import type { PublicLot } from '@/components/marketplace/types-lot';
import { useShared } from '@/composables/useShared';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ShareLocation from '@/components/marketplace/ShareLocation.vue';

export interface BookingSummary {
    ulid: string;
    type: string;
    status: 'awaiting_deposit' | 'pending' | 'confirmed' | 'completed' | 'no_show' | 'cancelled';
    status_label: string;
    when: string;
    starts_at: string;
    upcoming: boolean;
    car: { title: string; url: string; image: { src: string } | null } | null;
    lot: { name: string; slug: string; city: string | null; directions_url: string | null };
    url: string;
    can_cancel: boolean;
    cancel_reason: string | null;
    deposit: { state: 'due' | 'paid' | 'refunded'; amount: string; left: string | null; pay_url: string | null } | null;
}

const props = defineProps<{
    booking: BookingSummary & { staff: string | null; notes: string | null; whatsapp_reminders: boolean };
    lot: PublicLot;
    justBooked: boolean;
    location?: { can_share: boolean; live: { ulid: string; mine: boolean; expires: string }[] };
    links: { calendar: string; cancel: string; reschedule: string };
}>();

const { user } = useShared();
const cancelling = ref(false);
const shareMinutes = ref(30);
const shareLive = () => router.post(route('location.start', props.booking.ulid), { minutes: shareMinutes.value });
const cancelForm = useForm({ reason: '' });

const heading = computed(() => {
    if (props.booking.status === 'cancelled') return 'Booking cancelled';
    if (props.booking.status === 'awaiting_deposit') return 'Pay the deposit';
    if (!props.booking.upcoming) return 'Your visit';
    if (props.booking.status === 'pending') return 'Request sent';
    return props.justBooked ? "You're booked" : 'Your booking';
});

const intro = computed(() => {
    const b = props.booking;
    const channel = b.whatsapp_reminders ? 'WhatsApp' : 'SMS';
    if (b.status === 'awaiting_deposit') return `Your slot is held while you pay. Pay the refundable deposit to confirm your ${b.type.toLowerCase()}.`;
    if (b.status === 'pending') return `${b.lot.name} will confirm your ${b.type.toLowerCase()} soon. We'll message you on ${channel} when they do.`;
    if (b.status === 'confirmed' && b.upcoming) return `${b.lot.name} is expecting you. We'll remind you on ${channel} 24 hours and 2 hours before.`;
    if (b.status === 'cancelled') return b.cancel_reason ? `Reason: ${b.cancel_reason}` : 'This booking was cancelled.';
    return null;
});

const whatsappHref = computed(() =>
    props.lot.whatsapp ? `https://wa.me/${props.lot.whatsapp}?text=${encodeURIComponent(`Hi ${props.lot.name}, about my ${props.booking.type.toLowerCase()} on ${props.booking.when}.`)}` : null,
);

function cancel() {
    cancelForm.post(props.links.cancel, { preserveScroll: true, onSuccess: () => (cancelling.value = false) });
}
</script>

<template>
    <Head :title="heading" />
    <CustomerLayout active="bookings">
        <div class="mx-auto flex max-w-xl flex-col gap-[18px] px-5 py-7">
            <div
                class="flex h-16 w-16 items-center justify-center rounded-full"
                :class="booking.status === 'cancelled' ? 'bg-divider text-muted' : ['pending', 'awaiting_deposit'].includes(booking.status) ? 'bg-blush text-clay-dark' : 'bg-[#DCEFE3] text-success'"
            >
                <Icon :name="booking.status === 'cancelled' ? 'close' : ['pending', 'awaiting_deposit'].includes(booking.status) ? 'clock' : 'check'" :size="32" :stroke-width="2.4" />
            </div>
            <div class="flex flex-col gap-1.5">
                <h1 class="text-[30px] leading-tight font-bold">{{ heading }}</h1>
                <p v-if="intro" class="text-[15px] text-muted">{{ intro }}</p>
            </div>

            <div v-if="booking.deposit?.state === 'due'" class="card flex flex-col gap-3 border-2 border-clay p-4">
                <p class="text-[14px]">
                    <strong>Refundable deposit: {{ booking.deposit.amount }}.</strong> You get it back when you arrive, or if you cancel before the slot.
                    <template v-if="booking.deposit.left"> {{ booking.deposit.left }} to pay before the slot is released.</template>
                </p>
                <Link v-if="booking.deposit.pay_url && user" :href="booking.deposit.pay_url" method="post" as="button" class="btn btn-primary h-12 w-full">Pay {{ booking.deposit.amount }} deposit</Link>
            </div>
            <p v-else-if="booking.deposit" class="card flex items-center gap-2.5 px-4 py-3 text-[14px]">
                <Icon name="shield" :size="20" class="shrink-0 text-forest" />
                <span v-if="booking.deposit.state === 'paid'">Deposit of <strong>{{ booking.deposit.amount }}</strong> paid. It's refunded when you arrive.</span>
                <span v-else>Your <strong>{{ booking.deposit.amount }}</strong> deposit has been refunded.</span>
            </p>

            <dl class="card px-4 pt-1 pb-1.5 text-[14px]">
                <div class="flex justify-between gap-4 py-2.5"><dt class="text-muted">When</dt><dd class="text-right font-semibold">{{ booking.when }}</dd></div>
                <div class="flex justify-between gap-4 border-t border-divider py-2.5">
                    <dt class="text-muted">What</dt>
                    <dd class="text-right font-semibold">{{ booking.type }}<template v-if="booking.car"> · <Link :href="booking.car.url">{{ booking.car.title }}</Link></template></dd>
                </div>
                <div class="flex justify-between gap-4 border-t border-divider py-2.5"><dt class="text-muted">Where</dt><dd class="text-right font-semibold">{{ lot.name }}<template v-if="lot.city">, {{ lot.city }}</template></dd></div>
                <div v-if="booking.staff" class="flex justify-between gap-4 border-t border-divider py-2.5"><dt class="text-muted">With</dt><dd class="text-right font-semibold">{{ booking.staff }}</dd></div>
                <div class="flex justify-between gap-4 border-t border-divider py-2.5"><dt class="text-muted">Status</dt><dd class="text-right font-semibold">{{ booking.status_label }}</dd></div>
            </dl>

            <!-- Live location (TDD M8, design 14) -->
            <div v-if="location && (location.live.length || location.can_share)" class="card flex flex-col gap-3 p-4">
                <Link
                    v-for="l in location.live"
                    :key="l.ulid"
                    :href="route('location.show', l.ulid)"
                    class="flex items-center gap-2.5 text-[15px] font-semibold no-underline"
                >
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full bg-[#22C55E]" aria-hidden="true" />
                    {{ l.mine ? `You're sharing your live location until ${l.expires}` : `${lot.name} is sharing their live location` }}
                    <Icon name="chevronDown" :size="18" class="ml-auto -rotate-90" />
                </Link>
                <form v-if="location.can_share && !location.live.some((l) => l.mine)" class="flex flex-col gap-2" @submit.prevent="shareLive">
                    <p class="text-[14px] text-muted">On your way? Let {{ lot.name }} see where you are. Only they can see it, and it stops on its own.</p>
                    <div class="flex gap-2">
                        <label>
                            <span class="sr-only">For how long</span>
                            <select v-model.number="shareMinutes" class="field h-11 w-auto">
                                <option :value="15">15 min</option>
                                <option :value="30">30 min</option>
                                <option :value="60">1 hour</option>
                                <option :value="120">2 hours</option>
                            </select>
                        </label>
                        <button type="submit" class="btn btn-primary h-11 grow text-[14px]"><Icon name="navigate" :size="18" /> Share live location</button>
                    </div>
                </form>
            </div>

            <div v-if="booking.upcoming" class="card overflow-hidden">
                <a v-if="lot.directions_url" :href="lot.directions_url" target="_blank" rel="noopener" class="relative block h-[110px] bg-map" aria-label="Open directions in Google Maps">
                    <span class="absolute inset-x-0 top-[50px] h-2.5 bg-white" />
                    <span class="absolute inset-y-0 left-[38%] w-2.5 bg-white" />
                    <span class="absolute top-5 left-1/2 h-8 w-8 -translate-x-1/2 -rotate-45 rounded-[16px_16px_16px_4px] bg-clay" />
                </a>
                <div class="flex gap-2 p-3">
                    <a v-if="lot.directions_url" :href="lot.directions_url" target="_blank" rel="noopener" class="btn btn-primary h-11 grow px-3 text-[14px]"><Icon name="navigate" :size="18" /> Get directions</a>
                    <a :href="links.calendar" class="btn btn-outline h-11 px-3.5 text-[14px]"><Icon name="calendar" :size="18" /> Add to calendar</a>
                </div>
                <div v-if="lot.directions_url" class="px-3 pb-3">
                    <ShareLocation :name="lot.name" :address="lot.city" :directions="lot.directions_url" block />
                </div>
            </div>

            <div v-if="booking.can_cancel" class="flex flex-wrap justify-center gap-6 text-[14px] font-semibold">
                <Link v-if="user" :href="links.reschedule">Reschedule</Link>
                <button type="button" class="text-clay hover:text-clay-dark" @click="cancelling = !cancelling">Cancel booking</button>
                <a v-if="whatsappHref" :href="whatsappHref" target="_blank" rel="noopener">Message lot</a>
            </div>

            <form v-if="cancelling" class="card flex flex-col gap-3 p-4" @submit.prevent="cancel">
                <label class="field-label">
                    Reason <span class="font-normal text-muted">(optional, shared with the lot)</span>
                    <input v-model="cancelForm.reason" class="field" maxlength="200" placeholder="e.g. Something came up" />
                </label>
                <div class="flex gap-2">
                    <button type="button" class="btn btn-outline h-11 text-[14px]" @click="cancelling = false">Keep booking</button>
                    <button type="submit" class="btn btn-primary h-11 grow text-[14px]" :disabled="cancelForm.processing">Cancel booking</button>
                </div>
            </form>

            <Link v-if="user" :href="route('bookings.index')" class="self-center text-[14px] font-semibold">All your bookings</Link>
        </div>
    </CustomerLayout>
</template>
