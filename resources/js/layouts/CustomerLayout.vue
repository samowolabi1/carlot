<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import Icon, { type IconName } from '@/components/Icon.vue';
import Logo from '@/components/Logo.vue';
import { useShared } from '@/composables/useShared';
import { Link } from '@inertiajs/vue3';

const { user } = useShared();

// Search, Saved and Bookings arrive in sprints S3 and S4.
const tabs: { label: string; icon: IconName; href?: string; active?: boolean }[] = [
    { label: 'Home', icon: 'home', href: route('home'), active: true },
    { label: 'Search', icon: 'search' },
    { label: 'Saved', icon: 'heart' },
    { label: 'Bookings', icon: 'calendar' },
];
</script>

<template>
    <div class="min-h-dvh pb-24 md:pb-0">
        <header class="border-b border-line/0 md:border-line md:bg-white">
            <div class="mx-auto flex max-w-6xl items-center justify-between px-5 pt-5 pb-2 md:py-4">
                <Link :href="route('home')" class="no-underline" aria-label="LotLink home"><Logo /></Link>
                <nav class="hidden items-center gap-6 text-[15px] font-medium md:flex" aria-label="Main">
                    <Link :href="route('home')" class="text-ink no-underline hover:text-clay">Browse cars</Link>
                    <Link :href="route('dealer.home')" class="text-ink no-underline hover:text-clay">For car lots</Link>
                </nav>
                <div class="flex items-center gap-2">
                    <template v-if="user">
                        <Link
                            v-if="user.role === 'staff'"
                            :href="route('dealer.home')"
                            class="hidden h-11 items-center rounded-full border border-line bg-white px-4 text-[14px] font-semibold text-forest no-underline md:inline-flex"
                            >Dealer dashboard</Link
                        >
                        <Link
                            :href="route('logout')"
                            method="post"
                            as="button"
                            class="flex h-11 items-center gap-2 rounded-full border border-line bg-white px-4 text-[14px] font-medium"
                        >
                            <Icon name="logout" :size="18" />
                            <span class="hidden sm:inline">Sign out</span>
                        </Link>
                    </template>
                    <Link v-else :href="route('login')" class="btn btn-dark h-11 rounded-full px-4 text-[14px]">Sign in</Link>
                </div>
            </div>
        </header>

        <main>
            <slot />
        </main>

        <nav
            aria-label="Main"
            class="fixed inset-x-0 bottom-0 z-40 grid h-[76px] grid-cols-5 border-t border-line bg-white pb-2 md:hidden"
        >
            <template v-for="tab in tabs" :key="tab.label">
                <Link
                    v-if="tab.href"
                    :href="tab.href"
                    class="flex flex-col items-center justify-center gap-1 text-[11px] no-underline"
                    :class="tab.active ? 'font-semibold text-clay' : 'text-muted'"
                    :aria-current="tab.active ? 'page' : undefined"
                >
                    <Icon :name="tab.icon" :size="22" :stroke-width="tab.active ? 2 : 1.8" />{{ tab.label }}
                </Link>
                <span v-else class="flex flex-col items-center justify-center gap-1 text-[11px] text-muted/50" aria-disabled="true" title="Coming soon">
                    <Icon :name="tab.icon" :size="22" />{{ tab.label }}
                </span>
            </template>
            <Link
                :href="user ? (user.role === 'staff' ? route('dealer.home') : route('home')) : route('login')"
                class="flex flex-col items-center justify-center gap-1 text-[11px] text-muted no-underline"
            >
                <Icon name="user" :size="22" />Account
            </Link>
        </nav>

        <FlashMessage />
    </div>
</template>
