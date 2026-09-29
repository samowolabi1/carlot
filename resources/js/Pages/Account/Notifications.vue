<script setup lang="ts">
import Icon, { type IconName } from '@/components/Icon.vue';
import { usePush } from '@/composables/usePush';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

const props = defineProps<{ items: { id: string; kind: string; text: string; url: string | null; when: string | null; new: boolean }[]; pushKey: string | null }>();

// Offer push once the browser can do it and it's off here.
const push = usePush(props.pushKey);

const look: Record<string, { icon: IconName; tone: string }> = {
    booking: { icon: 'calendar', tone: 'bg-[#E0ECF8] text-[#1E3A8A]' },
    new_stock: { icon: 'car', tone: 'bg-map text-forest' },
    lead: { icon: 'leads', tone: 'bg-blush text-clay-dark' },
    follow_up: { icon: 'phone', tone: 'bg-blush text-clay-dark' },
    billing: { icon: 'card', tone: 'bg-sand text-ink' },
    offer: { icon: 'tag', tone: 'bg-blush text-clay-dark' },
    trade_in: { icon: 'swap', tone: 'bg-map text-forest' },
    reservation: { icon: 'shield', tone: 'bg-[#DCEFE3] text-[#166534]' },
    location: { icon: 'navigate', tone: 'bg-[#E0ECF8] text-[#1E3A8A]' },
    summary: { icon: 'chart', tone: 'bg-sand text-ink' },
    review: { icon: 'star', tone: 'bg-blush text-clay-dark' },
    verification: { icon: 'shield', tone: 'bg-map text-forest' },
    moderation: { icon: 'flag', tone: 'bg-[#FDECEC] text-danger' },
    price_drop: { icon: 'tag', tone: 'bg-[#DCEFE3] text-[#166534]' },
    alert: { icon: 'search', tone: 'bg-map text-forest' },
    import: { icon: 'upload', tone: 'bg-sand text-ink' },
    news: { icon: 'megaphone', tone: 'bg-blush text-clay-dark' },
    nudge: { icon: 'bell', tone: 'bg-map text-forest' },
    support: { icon: 'lifebuoy', tone: 'bg-[#E0ECF8] text-[#1E3A8A]' },
    info: { icon: 'bell', tone: 'bg-sand text-ink' },
};
</script>

<template>
    <Head title="Notifications" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col pb-28 md:pt-6">
            <div class="flex items-center justify-between px-5 py-4">
                <h1 class="text-[22px] font-bold">Notifications</h1>
                <Link :href="route('notifications.settings')" class="flex h-11 items-center text-[14px] font-semibold">Settings</Link>
            </div>
            <Link
                v-if="push.state.value === 'off'"
                :href="route('notifications.settings')"
                class="mx-5 mb-3 flex items-center gap-3 rounded-2xl bg-forest p-4 text-white no-underline hover:text-white"
            >
                <Icon name="bell" :size="22" class="shrink-0" />
                <span class="flex grow flex-col"><span class="text-[15px] font-semibold">Get these on your phone</span><span class="text-[13px] text-mist">Turn on push notifications: instant, free, even when LotLink is closed.</span></span>
                <Icon name="chevronRight" :size="20" class="shrink-0" />
            </Link>
            <p v-if="items.length === 0" class="mx-5 card px-5 py-10 text-center text-[15px] text-muted">Nothing yet. Booking updates, new cars from lots you follow and more show up here.</p>
            <ul v-else class="border-t border-divider">
                <li v-for="n in items" :key="n.id">
                    <component
                        :is="n.url ? 'a' : 'div'"
                        :href="n.url ?? undefined"
                        class="flex gap-3 border-b border-divider px-5 py-3.5 text-ink no-underline"
                        :class="n.new ? 'bg-[#FFF9F5]' : ''"
                    >
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full" :class="(look[n.kind] ?? look.info).tone"><Icon :name="(look[n.kind] ?? look.info).icon" :size="20" /></span>
                        <span class="flex flex-col gap-0.5">
                            <span class="text-[14px] leading-snug">{{ n.text }}</span>
                            <span class="text-[12px] text-muted">{{ n.when }}<template v-if="n.new"> · <strong class="text-clay">New</strong></template></span>
                        </span>
                    </component>
                </li>
            </ul>
        </div>
    </CustomerLayout>
</template>
