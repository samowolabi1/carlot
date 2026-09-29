<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import Icon, { type IconName } from '@/components/Icon.vue';
import Logo from '@/components/Logo.vue';
import { useOfflineQueue } from '@/composables/useOfflineQueue';
import { usePwaInstall } from '@/composables/usePwaInstall';
import { useShared } from '@/composables/useShared';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const { currentLot, lots, unread } = useShared();
const page = usePage();
const menuOpen = ref(false);
const switcherOpen = ref(false);

const lot = computed(() => currentLot.value!);

type NavItem = { label: string; icon: IconName; route?: string; match?: string; badge?: () => number; managers?: boolean };

// Items without a route are later sprints; they stay visible so the dashboard matches the designs.
const nav: NavItem[] = [
    { label: 'Dashboard', icon: 'grid', route: 'dealer.dashboard' },
    { label: 'Stock', icon: 'car', route: 'dealer.vehicles.index', match: 'dealer.vehicles.*' },
    { label: 'Calendar', icon: 'calendar', route: 'dealer.calendar' },
    { label: 'Leads', icon: 'leads', route: 'dealer.leads.index', match: 'dealer.leads.*', badge: () => currentLot.value?.leads_badge ?? 0 },
    { label: 'Offers & trade-ins', icon: 'tag', route: 'dealer.offers.index', match: 'dealer.offers.*', badge: () => currentLot.value?.deals_badge ?? 0 },
    { label: 'Analytics', icon: 'chart', route: 'dealer.analytics', managers: true },
    { label: 'Reviews', icon: 'star', route: 'dealer.reviews' },
    { label: 'Mini-site & QR', icon: 'qr' },
    { label: 'Staff', icon: 'user', route: 'dealer.staff' },
    { label: 'Billing', icon: 'card', route: 'dealer.billing' },
    { label: 'Settings', icon: 'settings', route: 'dealer.settings' },
];

// Lot Manager (M19): the everyday tools for walk-ins, orders and payments.
const manager: NavItem[] = [
    { label: 'Today', icon: 'sun', route: 'dealer.manager.today' },
    { label: 'Walk-ins', icon: 'walkIn', route: 'dealer.manager.walk-ins.index' },
    { label: 'Customers', icon: 'book', route: 'dealer.manager.customers.index', match: 'dealer.manager.customers.*' },
    { label: 'Orders', icon: 'receipt', route: 'dealer.manager.orders.index', match: 'dealer.manager.orders.*' },
    { label: 'Reports', icon: 'receipt', route: 'dealer.manager.reports', match: 'dealer.manager.reports', managers: true },
];

const queue = useOfflineQueue(() => currentLot.value?.slug);
const install = usePwaInstall();

// Analytics and reports are for owners and managers.
const visible = (item: NavItem) => !item.managers || ['owner', 'manager'].includes(lot.value.role ?? '');
const isActive = (item: NavItem) => !!item.route && route().current(item.match ?? item.route);
const otherLots = computed(() => lots.value.filter((l) => l.slug !== lot.value.slug));
const showPendingBanner = computed(() => lot.value.status !== 'active' && !page.url.includes('/onboarding'));
</script>

<template>
    <div class="flex min-h-dvh bg-ivory">
        <div v-if="menuOpen" class="fixed inset-0 z-30 bg-ink/40 lg:hidden" @click="menuOpen = false" />

        <nav
            aria-label="Dealer"
            class="fixed inset-y-0 left-0 z-40 flex w-[248px] shrink-0 -translate-x-full flex-col gap-5 overflow-y-auto bg-forest px-3.5 py-5 text-mist transition lg:sticky lg:top-0 lg:h-dvh lg:translate-x-0"
            :class="{ 'translate-x-0': menuOpen }"
        >
            <div class="px-2.5"><Logo inverse size="sm" /></div>

            <div class="relative">
                <button
                    type="button"
                    class="flex h-[52px] w-full items-center gap-2.5 rounded-xl border border-forest-600 bg-forest-800 px-2.5 text-left"
                    :aria-expanded="switcherOpen"
                    @click="switcherOpen = !switcherOpen"
                >
                    <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-8 w-8 rounded-lg object-cover" />
                    <span v-else class="flex h-8 w-8 items-center justify-center rounded-lg bg-clay text-[13px] font-bold text-white">{{ lot.initials }}</span>
                    <span class="flex min-w-0 grow flex-col">
                        <span class="truncate text-[14px] font-semibold text-white">{{ lot.name }}</span>
                        <span class="text-[11px]">{{ lot.plan ?? 'No plan' }} plan</span>
                    </span>
                    <Icon name="chevronDown" :size="16" />
                </button>
                <div v-if="switcherOpen" class="absolute inset-x-0 top-14 z-10 rounded-xl border border-forest-600 bg-forest-800 p-1.5 shadow-xl">
                    <Link
                        v-for="other in otherLots"
                        :key="other.slug"
                        :href="route('dealer.dashboard', other.slug)"
                        class="flex h-10 items-center gap-2.5 rounded-lg px-2 text-[14px] text-mist no-underline hover:bg-forest-700 hover:text-white"
                    >
                        <span class="flex h-6 w-6 items-center justify-center rounded-md bg-forest-600 text-[11px] font-bold text-white">{{ other.initials }}</span>
                        {{ other.name }}
                    </Link>
                    <Link
                        :href="route('dealer.onboarding.start')"
                        class="flex h-10 items-center gap-2.5 rounded-lg px-2 text-[14px] text-peach no-underline hover:bg-forest-700 hover:text-white"
                    >
                        <Icon name="plus" :size="16" /> Add another lot
                    </Link>
                </div>
            </div>

            <div class="flex flex-col gap-0.5">
                <template v-for="item in nav.filter(visible)" :key="item.label">
                    <Link
                        v-if="item.route"
                        :href="route(item.route, lot.slug)"
                        class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] no-underline"
                        :class="isActive(item) ? 'bg-forest-700 font-semibold text-white' : 'text-mist hover:text-white'"
                        :aria-current="isActive(item) ? 'page' : undefined"
                    >
                        <Icon :name="item.icon" :size="18" /><span class="truncate">{{ item.label }}</span>
                        <span v-if="item.badge?.()" class="ml-auto shrink-0 rounded-full bg-clay px-2 py-0.5 text-[11px] font-bold text-white">{{ item.badge() }}</span>
                    </Link>
                    <span v-else class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] text-mist/45" aria-disabled="true">
                        <Icon :name="item.icon" :size="18" /><span class="truncate">{{ item.label }}</span>
                        <span class="ml-auto shrink-0 rounded-full bg-forest-800 px-2 py-0.5 text-[10px] font-semibold tracking-wide text-mist/70 uppercase">Soon</span>
                    </span>
                </template>
            </div>

            <div class="flex flex-col gap-0.5">
                <span class="px-2.5 pb-1 text-[11px] font-semibold tracking-wider text-sage uppercase">Lot Manager</span>
                <Link
                    v-for="item in manager.filter(visible)"
                    :key="item.label"
                    :href="route(item.route!, lot.slug)"
                    class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] no-underline"
                    :class="isActive(item) ? 'bg-forest-700 font-semibold text-white' : 'text-mist hover:text-white'"
                    :aria-current="isActive(item) ? 'page' : undefined"
                >
                    <Icon :name="item.icon" :size="18" /><span class="truncate">{{ item.label }}</span>
                </Link>
            </div>

            <div class="mt-auto flex flex-col gap-0.5 border-t border-forest-600 pt-3">
                <button v-if="install.canPrompt.value" type="button" class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-left text-[14px] text-peach hover:text-white" @click="install.prompt()">
                    <Icon name="download" :size="18" />Install the app
                </button>
                <Link
                    :href="route('notifications')"
                    class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] no-underline"
                    :class="route().current('notifications*') ? 'bg-forest-700 font-semibold text-white' : 'text-mist hover:text-white'"
                >
                    <Icon name="bell" :size="18" />Notifications
                    <span v-if="unread?.notifications" class="ml-auto rounded-full bg-clay px-2 py-0.5 text-[11px] font-bold text-white">{{ unread.notifications }}</span>
                </Link>
                <Link :href="route('home')" class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] text-mist no-underline hover:text-white">
                    <Icon name="home" :size="18" />Marketplace
                </Link>
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-left text-[14px] text-mist hover:text-white"
                >
                    <Icon name="logout" :size="18" />Sign out
                </Link>
            </div>
        </nav>

        <div class="flex min-w-0 grow flex-col">
            <div class="flex items-center justify-between border-b border-line bg-white px-4 py-3 lg:hidden">
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-xl border border-line" aria-label="Open menu" @click="menuOpen = true">
                    <Icon name="filters" />
                </button>
                <span class="truncate px-3 text-[15px] font-semibold">{{ lot.name }}</span>
                <Link
                    :href="route('notifications')"
                    class="relative flex h-11 w-11 items-center justify-center rounded-xl border border-line text-ink"
                    :aria-label="unread?.notifications ? `Notifications, ${unread.notifications} unread` : 'Notifications'"
                >
                    <Icon name="bell" />
                    <span v-if="unread?.notifications" class="absolute top-2 right-2 h-2.5 w-2.5 rounded-full bg-clay" />
                </Link>
            </div>

            <div v-if="showPendingBanner" class="border-b border-apricot bg-cream px-5 py-3 text-[14px] text-clay-dark lg:px-8">
                <strong>{{ lot.status_label }}.</strong>
                <template v-if="lot.status === 'suspended'"> Your lot is hidden from buyers. Contact LotLink support.</template>
                <template v-else-if="lot.submitted"> We are reviewing your lot. You can keep setting up while you wait.</template>
                <template v-else>
                    Finish setting up and submit your lot so buyers can find it.
                    <Link :href="route('dealer.onboarding.show', [lot.slug, 'submit'])" class="font-semibold">Continue setup</Link>
                </template>
            </div>

            <div
                v-if="queue.waiting.value || queue.failed.value.length"
                class="flex flex-wrap items-center gap-x-3 gap-y-1 border-b border-apricot bg-cream px-5 py-2.5 text-[14px] text-clay-dark lg:px-8"
                role="status"
            >
                <Icon name="cloudOff" :size="18" />
                <span v-if="queue.waiting.value">
                    <strong>{{ queue.waiting.value }} {{ queue.waiting.value === 1 ? 'item' : 'items' }} waiting to sync.</strong>
                    They'll send when you're back online.
                </span>
                <span v-if="queue.failed.value.length">
                    <strong>{{ queue.failed.value.length }} couldn't be saved:</strong>
                    <template v-for="(item, i) in queue.failed.value" :key="item.client_uuid">
                        {{ i ? '; ' : '' }}{{ item.label }} ({{ item.error }})
                        <button type="button" class="font-semibold underline" @click="queue.discard(item)">Discard</button>
                    </template>
                </span>
                <button
                    v-if="queue.waiting.value"
                    type="button"
                    class="ml-auto inline-flex h-9 items-center gap-1.5 rounded-lg border border-apricot bg-white px-3 font-semibold"
                    :disabled="queue.syncing.value"
                    @click="queue.sync()"
                >
                    <Icon name="refresh" :size="16" /> {{ queue.syncing.value ? 'Syncing…' : 'Sync now' }}
                </button>
            </div>

            <main class="flex flex-col gap-5 px-5 py-6 lg:px-8 lg:py-7">
                <slot />
            </main>
        </div>

        <FlashMessage />
    </div>
</template>
