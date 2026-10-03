<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { formatNaira } from '@/lib/format';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Plan {
    code: string;
    name: string;
    price: string;
    limits: string;
    blurb: string;
    current: boolean;
    self_serve: boolean;
    free: boolean;
}

const props = defineProps<{
    subscription: {
        plan: string | null;
        plan_code: string | null;
        price: string | null;
        status: 'trialing' | 'active' | 'past_due' | 'cancelled';
        status_label: string | null;
        trial_ends: string | null;
        trial_days_left: number | null;
        renews: string | null;
        ends: string | null;
        grace_ends: string | null;
        card: string | null;
        can_update_card: boolean;
        paid_with: string | null;
        coupon: string | null;
    };
    usage: Record<'listings' | 'staff' | 'spotlights', { used: number; limit: number | null }>;
    plans: Plan[];
    payments: { ulid: string; date: string | null; what: string; amount: string; status: string; status_label: string; invoice: string | null }[];
    spotlights: { what: string; placement: string; from: string | null; until: string | null; free: boolean }[];
    featured: { options: { days: number; price: number }[]; until: string | null };
    can: { manage: boolean; spotlight: boolean };
    sandbox: boolean;
    checkoutWith: string;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const busy = ref<string | null>(null);

const coupon = useForm({ coupon: '' });
const featuredDays = ref(props.featured.options[0]?.days ?? 7);

const meters = computed(() => [
    { label: 'listings', ...props.usage.listings },
    { label: 'staff', ...props.usage.staff },
    ...(props.usage.spotlights.limit ? [{ label: 'free spotlights this month', ...props.usage.spotlights }] : []),
]);

function choose(plan: Plan) {
    if (plan.free) {
        if (!confirm(props.subscription.status === 'active' ? `Stop renewing? Your ${props.subscription.plan} plan stays active until ${props.subscription.renews}, then moves to Free.` : 'Move to the Free plan now? Cars above its limit will be hidden (not deleted).')) return;
        router.post(route('dealer.billing.cancel', lot.value.slug), {}, { preserveScroll: true });
        return;
    }
    busy.value = plan.code;
    router.post(route('dealer.billing.checkout', lot.value.slug), { plan: plan.code }, { onFinish: () => (busy.value = null) });
}

function buyFeatured() {
    busy.value = 'featured';
    router.post(route('dealer.spotlight.featured', lot.value.slug), { days: featuredDays.value }, { onFinish: () => (busy.value = null) });
}

const statusTone: Record<string, string> = { success: 'text-success', refunded: 'text-muted', failed: 'text-danger' };
</script>

<template>
    <Head title="Billing" />
    <DealerLayout>
        <div>
            <h1 class="text-[30px] font-bold">Billing</h1>
            <span class="text-[14px] text-muted">
                <template v-if="subscription.paid_with">Paid with {{ subscription.paid_with }}</template><template v-else>Pay by card, bank transfer or USSD</template><template v-if="subscription.card"> · {{ subscription.card }}</template>
            </span>
        </div>

        <p v-if="sandbox" class="rounded-xl border border-apricot bg-cream px-4 py-3 text-[14px] text-clay-dark" role="note">
            <strong>Test mode.</strong> Payments go to a test checkout, not {{ checkoutWith }}. Set PAYMENT_DRIVER=live and the provider's keys to take real payments.
        </p>
        <p v-if="subscription.status === 'past_due'" class="rounded-xl border border-danger/30 bg-[#FDECEC] px-4 py-3 text-[14px] text-danger" role="alert">
            <strong>{{ subscription.trial_ends ? 'Your free trial has ended.' : 'Your last payment did not go through.' }}</strong>
            Choose a plan by {{ subscription.grace_ends }} to keep all your cars live. After that, cars above the Free plan's limit are hidden.
        </p>

        <div class="grid gap-3.5 lg:grid-cols-[1fr_1.4fr]">
            <section class="flex flex-col gap-2 rounded-2xl bg-forest p-[18px] text-white" aria-labelledby="plan-heading">
                <span class="text-[13px] text-mist">Current plan</span>
                <h2 id="plan-heading" class="font-display text-[28px] font-bold">{{ subscription.plan ?? 'Free' }}</h2>
                <span class="text-[14px] text-mist">
                    <template v-if="subscription.status === 'trialing'">Free trial · {{ subscription.trial_days_left }} {{ subscription.trial_days_left === 1 ? 'day' : 'days' }} left (until {{ subscription.trial_ends }})</template>
                    <template v-else-if="subscription.ends">{{ subscription.price }} a month · ends {{ subscription.ends }}, then Free</template>
                    <template v-else-if="subscription.status === 'active'">{{ subscription.price }} a month · renews {{ subscription.renews }}</template>
                    <template v-else-if="subscription.status === 'past_due'">Payment overdue</template>
                    <template v-else>No payment needed</template>
                </span>
                <span v-if="subscription.coupon" class="text-[13px] text-peach">Code {{ subscription.coupon }} applied</span>
                <Link v-if="can.manage" :href="route('dealer.referrals', lot.slug)" class="inline-flex min-h-11 items-center text-[13px] font-semibold text-peach hover:text-white">Refer a seller and get a free month</Link>
                <div v-if="can.manage && subscription.can_update_card" class="mt-1.5 flex gap-2">
                    <a :href="route('dealer.billing.card', lot.slug)" class="inline-flex h-11 items-center rounded-[10px] border border-forest-600 px-3.5 text-[13px] font-semibold text-white no-underline hover:text-white">Update card</a>
                </div>
            </section>

            <section class="card p-[18px]" aria-labelledby="usage-heading">
                <h2 id="usage-heading" class="mb-3 font-sans text-[15px] font-bold">This month's usage</h2>
                <div class="grid gap-4 sm:grid-cols-3">
                    <div v-for="m in meters" :key="m.label" class="text-[13px]">
                        <strong>{{ m.limit !== null ? `${m.used} of ${m.limit}` : m.used }}</strong> {{ m.label }}<span v-if="m.limit === null" class="text-muted"> · unlimited</span>
                        <div class="mt-1.5 h-2 rounded bg-divider">
                            <div class="h-2 rounded" :class="m.limit !== null && m.used >= m.limit ? 'bg-clay' : 'bg-forest'" :style="{ width: `${m.limit ? Math.min(100, (m.used / m.limit) * 100) : 15}%` }" />
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <section aria-labelledby="plans-heading" class="flex flex-col gap-3">
            <h2 id="plans-heading" class="sr-only">Plans</h2>
            <div class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div v-for="plan in plans" :key="plan.code" class="card flex flex-col gap-1.5 p-4 text-[13px]" :class="plan.current ? 'border-2 border-forest' : ''">
                    <span class="flex items-center gap-2 font-display text-[18px] font-bold">
                        {{ plan.name }}
                        <span v-if="plan.current" class="rounded-lg bg-forest px-2 py-0.5 font-sans text-[11px] font-semibold text-white">Current</span>
                    </span>
                    <span class="text-[14px] font-semibold text-muted">{{ plan.price }}</span>
                    <span>{{ plan.limits }}</span>
                    <span class="grow text-muted">{{ plan.blurb }}</span>
                    <template v-if="can.manage && !plan.current">
                        <button
                            v-if="plan.self_serve"
                            type="button"
                            class="btn mt-2 h-11 text-[14px]"
                            :class="plan.free ? 'btn-outline' : 'btn-primary'"
                            :disabled="busy !== null || (plan.free && subscription.status === 'cancelled') || (plan.free && !!subscription.ends)"
                            @click="choose(plan)"
                        >
                            {{ busy === plan.code ? `Opening ${checkoutWith}…` : plan.free ? 'Move to Free' : subscription.status === 'active' ? `Switch to ${plan.name}` : `Choose ${plan.name}` }}
                        </button>
                        <a v-else href="mailto:hello@caryardng.com?subject=Enterprise%20plan" class="btn btn-outline mt-2 h-11 text-[14px]">Talk to us</a>
                    </template>
                    <button
                        v-else-if="can.manage && plan.current && !plan.free && subscription.status !== 'active'"
                        type="button"
                        class="btn btn-primary mt-2 h-11 text-[14px]"
                        :disabled="busy !== null"
                        @click="choose(plan)"
                    >
                        {{ busy === plan.code ? `Opening ${checkoutWith}…` : `Pay for ${plan.name}` }}
                    </button>
                </div>
            </div>
            <p v-if="!can.manage" class="text-[13px] text-muted">Only the seller can change the plan.</p>
        </section>

        <div class="grid gap-3.5 lg:grid-cols-2">
            <section v-if="can.manage && subscription.status === 'trialing' && !subscription.coupon" class="card flex flex-col gap-2 p-[18px]" aria-labelledby="coupon-heading">
                <h2 id="coupon-heading" class="font-sans text-[15px] font-bold">Have a code?</h2>
                <form class="flex gap-2" @submit.prevent="coupon.post(route('dealer.billing.coupon', lot.slug), { preserveScroll: true, onSuccess: () => coupon.reset() })">
                    <label class="grow"><span class="sr-only">Code</span><input v-field="'code'" v-model="coupon.coupon" class="field h-11 uppercase" placeholder="LAUNCH3" /></label>
                    <button type="submit" class="btn btn-dark h-11 text-[14px]" :disabled="coupon.processing || !coupon.coupon">Apply</button>
                </form>
                <InputError :message="coupon.errors.coupon" />
            </section>

            <section v-if="can.spotlight" class="card flex flex-col gap-2 p-[18px]" aria-labelledby="featured-heading">
                <h2 id="featured-heading" class="font-sans text-[15px] font-bold">Feature your business on the home page</h2>
                <p class="text-[13px] text-muted">
                    <template v-if="featured.until">Featured until {{ featured.until }}. Buying more adds to the end.</template>
                    <template v-else>Your business shows in "Featured sellers" for every buyer who opens CarYard.</template>
                    To spotlight a single car, use Spotlight on the Stock page.
                </p>
                <div class="flex flex-wrap gap-2">
                    <label v-for="o in featured.options" :key="o.days" class="flex h-11 cursor-pointer items-center rounded-xl border border-line px-3 text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white">
                        <input v-model="featuredDays" type="radio" name="featured-days" :value="o.days" class="sr-only" />{{ o.days }} days · {{ formatNaira(o.price) }}
                    </label>
                </div>
                <button type="button" class="btn btn-primary h-11 self-start text-[14px]" :disabled="busy !== null" @click="buyFeatured">{{ busy === 'featured' ? `Opening ${checkoutWith}…` : 'Pay and feature my business' }}</button>
            </section>
        </div>

        <section v-if="spotlights.length" class="card p-[18px]" aria-labelledby="spots-heading">
            <h2 id="spots-heading" class="mb-2 font-sans text-[15px] font-bold">Running spotlights</h2>
            <ul class="divide-y divide-divider text-[14px]">
                <li v-for="(s, i) in spotlights" :key="i" class="flex flex-wrap justify-between gap-2 py-2">
                    <span><Icon name="bell" :size="14" class="mr-1 inline text-clay" />{{ s.what }} <span class="text-muted">· {{ s.placement }}<template v-if="s.free"> · free</template></span></span>
                    <span class="text-muted">{{ s.from }} – {{ s.until }}</span>
                </li>
            </ul>
        </section>

        <section class="card overflow-hidden" aria-labelledby="payments-heading">
            <h2 id="payments-heading" class="border-b border-divider px-[18px] py-3 font-sans text-[15px] font-bold">Payments</h2>
            <p v-if="payments.length === 0" class="px-[18px] py-6 text-[14px] text-muted">No payments yet.</p>
            <div v-else class="overflow-x-auto">
            <table class="w-full min-w-[520px] text-[13px]">
                <thead class="text-left text-[12px] text-muted">
                    <tr><th class="px-[18px] py-2 font-semibold">Date</th><th class="py-2 font-semibold">What</th><th class="py-2 font-semibold">Amount</th><th class="py-2 font-semibold">Status</th><th class="px-[18px] py-2"><span class="sr-only">Invoice</span></th></tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    <tr v-for="p in payments" :key="p.ulid">
                        <td class="px-[18px] py-2.5 whitespace-nowrap">{{ p.date }}</td>
                        <td class="py-2.5">{{ p.what }}</td>
                        <td class="py-2.5 whitespace-nowrap">{{ p.amount }}</td>
                        <td class="py-2.5 font-semibold" :class="statusTone[p.status]">{{ p.status_label }}</td>
                        <td class="px-[18px] py-2.5 text-right"><a v-if="p.invoice" :href="p.invoice" target="_blank" rel="noopener" class="tap font-semibold">Invoice</a></td>
                    </tr>
                </tbody>
            </table>
            </div>
        </section>
    </DealerLayout>
</template>
