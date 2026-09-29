<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useBudget } from '@/composables/useBudget';
import { usePwaInstall } from '@/composables/usePwaInstall';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { formatNaira } from '@/lib/format';
import InputError from '@/components/InputError.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { useShared } from '@/composables/useShared';
import { computed, ref } from 'vue';

const props = defineProps<{
    profile: { name: string | null; initials: string; phone: string | null; email: string | null };
    budget: string | null;
    counts: { saved: number; bookings: number; following: number };
    orders: { order_no: string; car: string | null; lot: string | null; status: string; balance: string | null; url: string }[];
    lots: { name: string; url: string }[];
}>();

const { unread } = useShared();
const local = useBudget();
const install = usePwaInstall();
// A budget worked out before signing in still shows until it is saved to the account.
const budgetLabel = computed(() => props.budget ?? (local.maxPrice.value !== null ? formatNaira(local.maxPrice.value) : null));

const row = 'flex min-h-[54px] items-center justify-between gap-3 border-t border-divider px-4 text-[15px] text-ink no-underline first:border-t-0 hover:bg-ivory';

// Privacy (TDD, NDPA): close the account; details are removed after 30 days.
const deleting = ref(false);
const deleteForm = useForm({ confirm: false });
const deleteAccount = () => deleteForm.delete(route('account.destroy'), { preserveScroll: true });
</script>

<template>
    <Head title="Account" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-6 pb-28 md:pb-16">
            <div class="flex items-center gap-3.5">
                <span class="flex h-[60px] w-[60px] items-center justify-center rounded-full bg-forest font-display text-[22px] font-bold text-white">{{ profile.initials }}</span>
                <div class="flex flex-col">
                    <h1 class="font-sans text-[20px] font-semibold">{{ profile.name ?? 'Your account' }}</h1>
                    <span class="text-[14px] text-muted">{{ profile.phone ?? profile.email }}</span>
                </div>
            </div>

            <nav class="card overflow-hidden" aria-label="Your LotLink">
                <Link :href="route('conversations.index')" :class="row"
                    >Messages<span v-if="unread?.messages" class="rounded-full bg-clay px-2 py-0.5 text-[12px] font-semibold text-white">{{ unread.messages }} new</span></Link
                >
                <Link :href="route('budget')" :class="row">My budget<span class="text-[13px] text-muted">{{ budgetLabel ? `Up to ${budgetLabel}` : 'Work it out' }}</span></Link>
                <Link :href="route('bookings.index')" :class="row">Bookings<span class="text-[13px] text-muted">{{ counts.bookings ? `${counts.bookings} upcoming` : '' }}</span></Link>
                <Link :href="route('saved')" :class="row">Saved cars<span class="text-[13px] text-muted">{{ counts.saved || '' }}</span></Link>
                <Link :href="`${route('bookings.index')}#offers`" :class="row">Offers and trade-ins<Icon name="chevronDown" :size="18" class="-rotate-90 text-muted" /></Link>
                <Link :href="route('finance.index')" :class="row">Finance applications<Icon name="chevronDown" :size="18" class="-rotate-90 text-muted" /></Link>
                <Link :href="route('following')" :class="row">Lots I follow<span class="text-[13px] text-muted">{{ counts.following || '' }}</span></Link>
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
                <Link :href="route('account.security')" :class="row">Sign-in and security<Icon name="chevronDown" :size="18" class="-rotate-90 text-muted" /></Link>
                <Link :href="route('notifications.settings')" :class="row">Notifications<Icon name="chevronDown" :size="18" class="-rotate-90 text-muted" /></Link>
                <button type="button" :class="row" class="w-full text-left" :aria-expanded="deleting" @click="deleting = !deleting">Privacy and my data<Icon name="chevronDown" :size="18" class="text-muted" :class="{ 'rotate-180': deleting }" /></button>
            </nav>

            <section v-if="deleting" class="card flex flex-col gap-3 p-4" aria-labelledby="delete-heading">
                <h2 id="delete-heading" class="font-sans text-[16px] font-bold">Delete my account</h2>
                <p class="text-[14px] text-muted">
                    Your account closes now. Your name, phone, email, saved cars, searches and budget are removed after 30 days; sign in before then if you change your mind.
                    Lots you bought from keep their own sales records, as the law requires.
                </p>
                <label class="flex cursor-pointer items-start gap-3 text-[14px]">
                    <input v-model="deleteForm.confirm" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-forest" />
                    I understand and want to delete my account.
                </label>
                <InputError :message="deleteForm.errors.confirm || (deleteForm.errors as Record<string, string>).account" />
                <button type="button" class="btn h-11 self-start border-danger bg-white text-danger" :disabled="!deleteForm.confirm || deleteForm.processing" @click="deleteAccount">Delete my account</button>
            </section>

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
