<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useBudget } from '@/composables/useBudget';
import { usePwaInstall } from '@/composables/usePwaInstall';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { formatNaira } from '@/lib/format';
import { Head, Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    profile: { name: string | null; initials: string; phone: string };
    budget: string | null;
    counts: { saved: number; bookings: number };
    orders: { order_no: string; car: string | null; lot: string | null; status: string; balance: string | null; url: string }[];
    lots: { name: string; url: string }[];
}>();

const local = useBudget();
const install = usePwaInstall();
// A budget worked out before signing in still shows until it is saved to the account.
const budgetLabel = computed(() => props.budget ?? (local.maxPrice.value !== null ? formatNaira(local.maxPrice.value) : null));

const row = 'flex min-h-[54px] items-center justify-between gap-3 border-t border-divider px-4 text-[15px] text-ink no-underline first:border-t-0 hover:bg-ivory';
</script>

<template>
    <Head title="Account" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-6 pb-28 md:pb-16">
            <div class="flex items-center gap-3.5">
                <span class="flex h-[60px] w-[60px] items-center justify-center rounded-full bg-forest font-display text-[22px] font-bold text-white">{{ profile.initials }}</span>
                <div class="flex flex-col">
                    <h1 class="font-sans text-[20px] font-semibold">{{ profile.name ?? 'Your account' }}</h1>
                    <span class="text-[14px] text-muted">{{ profile.phone }}</span>
                </div>
            </div>

            <nav class="card overflow-hidden" aria-label="Your LotLink">
                <Link :href="route('budget')" :class="row">My budget<span class="text-[13px] text-muted">{{ budgetLabel ? `Up to ${budgetLabel}` : 'Work it out' }}</span></Link>
                <Link :href="route('bookings.index')" :class="row">Bookings<span class="text-[13px] text-muted">{{ counts.bookings ? `${counts.bookings} upcoming` : '' }}</span></Link>
                <Link :href="route('saved')" :class="row">Saved cars<span class="text-[13px] text-muted">{{ counts.saved || '' }}</span></Link>
                <span :class="row" class="text-muted/60" aria-disabled="true">Offers and trade-ins<span class="rounded-full bg-sand px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase">Soon</span></span>
                <span :class="row" class="text-muted/60" aria-disabled="true">Lots I follow<span class="rounded-full bg-sand px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase">Soon</span></span>
            </nav>

            <section v-if="orders.length" class="card overflow-hidden" aria-labelledby="orders-heading">
                <h2 id="orders-heading" class="border-b border-divider px-4 py-3 font-sans text-[15px] font-bold">Payments and receipts</h2>
                <a v-for="o in orders" :key="o.order_no" :href="o.url" :class="row" class="py-2.5">
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate font-semibold">{{ o.car }}</span>
                        <span class="truncate text-[13px] text-muted">{{ o.lot }} · {{ o.order_no }}</span>
                    </span>
                    <span class="shrink-0 text-right text-[13px]">
                        <span class="block font-semibold">{{ o.status }}</span>
                        <span v-if="o.balance" class="block text-clay-dark">{{ o.balance }} to pay</span>
                    </span>
                </a>
            </section>

            <nav class="card overflow-hidden" aria-label="Settings">
                <button v-if="install.canPrompt.value" type="button" :class="row" class="w-full text-left" @click="install.prompt()">Install the LotLink app<Icon name="download" :size="18" class="text-muted" /></button>
                <span v-else-if="install.available.value" :class="row" class="py-3 text-[14px]">{{ install.hint.value }}</span>
                <span :class="row" class="text-muted/60" aria-disabled="true">Notifications<span class="rounded-full bg-sand px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase">Soon</span></span>
                <span :class="row" class="text-muted/60" aria-disabled="true">Privacy and my data<span class="rounded-full bg-sand px-2 py-0.5 text-[10px] font-semibold tracking-wide uppercase">Soon</span></span>
            </nav>

            <template v-if="lots.length">
                <Link v-for="lot in lots" :key="lot.url" :href="lot.url" class="flex items-center justify-between rounded-2xl bg-forest p-4 text-white no-underline hover:text-white">
                    <span class="flex flex-col"><span class="text-[15px] font-semibold">{{ lot.name }}</span><span class="text-[13px] text-mist">Open the dealer dashboard</span></span>
                    <Icon name="chevronDown" class="-rotate-90" />
                </Link>
            </template>
            <Link v-else :href="route('dealer.home')" class="flex flex-col gap-1 rounded-2xl bg-forest p-4 text-white no-underline hover:text-white">
                <span class="text-[15px] font-semibold">Own a car lot?</span>
                <span class="text-[13px] text-mist">List your stock free for up to 10 cars.</span>
            </Link>

            <Link :href="route('logout')" method="post" as="button" class="h-11 text-center text-[15px] font-semibold text-danger">Sign out</Link>
        </div>
    </CustomerLayout>
</template>
