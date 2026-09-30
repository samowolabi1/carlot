<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import Icon, { type IconName } from '@/components/Icon.vue';
import Logo from '@/components/Logo.vue';
import SupportViewBar from '@/components/SupportViewBar.vue';
import { useShared } from '@/composables/useShared';
import type { SharedProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/** The lender portal (forest sidebar like the dealer dashboard): applications, team and settings. */
const page = usePage<SharedProps>();
const { unread } = useShared();
const lender = computed(() => page.props.currentLender!);
const others = computed(() => (page.props.lenders ?? []).filter((l) => l.slug !== lender.value.slug));
const supportView = computed(() => !!page.props.impersonating);
const menuOpen = ref(false);

type NavItem = { label: string; icon: IconName; route: string; match?: string; badge?: () => number };
const nav: NavItem[] = [
    { label: 'Dashboard', icon: 'grid', route: 'lender.dashboard' },
    { label: 'Applications', icon: 'file', route: 'lender.applications.index', match: 'lender.applications.*', badge: () => lender.value.new_badge + lender.value.unread_badge },
    { label: 'Team', icon: 'user', route: 'lender.team' },
    { label: 'Settings', icon: 'settings', route: 'lender.settings' },
];
const isActive = (item: NavItem) => route().current(item.match ?? item.route);
</script>

<template>
    <SupportViewBar />
    <div class="flex min-h-dvh bg-ivory">
        <div v-if="menuOpen" class="fixed inset-0 z-30 bg-ink/40 lg:hidden" @click="menuOpen = false" />

        <nav
            aria-label="Lender portal"
            class="fixed bottom-0 left-0 z-40 flex w-[248px] shrink-0 -translate-x-full flex-col gap-5 overflow-y-auto bg-forest px-3.5 py-5 text-mist transition lg:sticky lg:translate-x-0"
            :class="[{ 'translate-x-0': menuOpen }, supportView ? 'top-11 lg:top-11 lg:h-[calc(100dvh-2.75rem)]' : 'top-0 lg:top-0 lg:h-dvh']"
        >
            <div class="px-2.5"><Logo inverse size="sm" /></div>

            <div class="flex h-[52px] items-center gap-2.5 rounded-xl border border-forest-600 bg-forest-800 px-2.5">
                <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-lg bg-clay text-[13px] font-bold text-white">{{ lender.initials }}</span>
                <span class="flex min-w-0 flex-col">
                    <span class="truncate text-[14px] font-semibold text-white">{{ lender.name }}</span>
                    <span class="text-[11px]">Lender portal</span>
                </span>
            </div>

            <div class="flex flex-col gap-0.5">
                <Link
                    v-for="item in nav"
                    :key="item.label"
                    :href="route(item.route, lender.slug)"
                    class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] no-underline"
                    :class="isActive(item) ? 'bg-forest-700 font-semibold text-white' : 'text-mist hover:text-white'"
                    :aria-current="isActive(item) ? 'page' : undefined"
                >
                    <Icon :name="item.icon" :size="18" /><span class="truncate">{{ item.label }}</span>
                    <span v-if="item.badge?.()" class="ml-auto shrink-0 rounded-full bg-clay px-2 py-0.5 text-[11px] font-bold text-white">{{ item.badge() }}</span>
                </Link>
            </div>

            <div v-if="others.length" class="flex flex-col gap-0.5">
                <span class="px-2.5 pb-1 text-[11px] font-semibold tracking-wider text-sage uppercase">Other lenders</span>
                <Link
                    v-for="o in others"
                    :key="o.slug"
                    :href="route('lender.dashboard', o.slug)"
                    class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] text-mist no-underline hover:text-white"
                >
                    <Icon name="swap" :size="18" /><span class="truncate">{{ o.name }}</span>
                </Link>
            </div>

            <div class="mt-auto flex flex-col gap-0.5 border-t border-forest-600 pt-3">
                <Link
                    :href="route('notifications')"
                    class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] text-mist no-underline hover:text-white"
                >
                    <Icon name="bell" :size="18" />Notifications
                    <span v-if="unread?.notifications" class="ml-auto rounded-full bg-clay px-2 py-0.5 text-[11px] font-bold text-white">{{ unread.notifications }}</span>
                </Link>
                <Link :href="route('account.security')" class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] text-mist no-underline hover:text-white">
                    <Icon name="key" :size="18" />Sign-in and security
                </Link>
                <Link :href="route('home')" class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-[14px] text-mist no-underline hover:text-white">
                    <Icon name="home" :size="18" />Marketplace
                </Link>
                <Link :href="route('logout')" method="post" as="button" class="flex h-10 items-center gap-3 rounded-[10px] px-2.5 text-left text-[14px] text-mist hover:text-white">
                    <Icon name="logout" :size="18" />Sign out
                </Link>
            </div>
        </nav>

        <div class="flex min-w-0 grow flex-col">
            <div class="flex items-center justify-between border-b border-line bg-white px-4 py-3 lg:hidden">
                <button type="button" class="flex h-11 w-11 items-center justify-center rounded-xl border border-line" aria-label="Open menu" @click="menuOpen = true">
                    <Icon name="filters" />
                </button>
                <span class="truncate px-3 text-[15px] font-semibold">{{ lender.name }}</span>
                <Link :href="route('notifications')" class="relative flex h-11 w-11 items-center justify-center rounded-xl border border-line text-ink" aria-label="Notifications">
                    <Icon name="bell" />
                    <span v-if="unread?.notifications" class="absolute top-2 right-2 h-2.5 w-2.5 rounded-full bg-clay" />
                </Link>
            </div>

            <div v-if="lender.status !== 'active'" class="border-b border-apricot bg-cream px-5 py-3 text-[14px] text-clay-dark lg:px-8" role="status">
                <strong>{{ lender.status_label }}.</strong>
                <template v-if="lender.status === 'pending'"> LotLink is checking your details and licence. Buyers will see you once you're approved.</template>
                <template v-else-if="lender.status === 'suspended'"> You get no new applications. Contact LotLink to sort it out.</template>
                <template v-else> See the note on your dashboard.</template>
            </div>

            <main class="flex flex-col gap-5 px-5 py-6 lg:px-8 lg:py-7">
                <slot />
            </main>
        </div>

        <FlashMessage />
    </div>
</template>
