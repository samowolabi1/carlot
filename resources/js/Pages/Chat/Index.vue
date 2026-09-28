<script setup lang="ts">
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    conversations: { ulid: string | null; lot: string; initials: string; logo_url: string | null; car: string | null; last: string | null; mine: boolean; when: string; unread: number }[];
}>();
</script>

<template>
    <Head title="Messages" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-4 pb-28 md:pt-8">
            <h1 class="text-[26px] font-bold">Messages</h1>
            <div v-if="conversations.length === 0" class="card px-5 py-10 text-center text-[15px] text-muted">
                Chat with a lot from any car page. Replies show up here.
                <Link :href="route('cars.index')" class="mt-2 block font-semibold">Browse cars</Link>
            </div>
            <ul v-else class="card divide-y divide-divider overflow-hidden">
                <li v-for="c in conversations" :key="c.ulid ?? c.lot">
                    <Link v-if="c.ulid" :href="route('conversations.show', c.ulid)" class="flex items-center gap-3 px-4 py-3 text-ink no-underline hover:bg-ivory">
                        <img v-if="c.logo_url" :src="c.logo_url" alt="" class="h-11 w-11 rounded-xl object-cover" />
                        <span v-else class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-clay text-[14px] font-bold text-white">{{ c.initials }}</span>
                        <span class="flex min-w-0 grow flex-col">
                            <span class="flex items-baseline justify-between gap-2">
                                <span class="truncate text-[15px]" :class="c.unread ? 'font-bold' : 'font-semibold'">{{ c.lot }}</span>
                                <span class="shrink-0 text-[12px] text-muted">{{ c.when }}</span>
                            </span>
                            <span v-if="c.car" class="truncate text-[12px] text-muted">{{ c.car }}</span>
                            <span class="truncate text-[14px]" :class="c.unread ? 'font-semibold text-ink' : 'text-muted'"><template v-if="c.mine">You: </template>{{ c.last }}</span>
                        </span>
                        <span v-if="c.unread" class="flex h-6 min-w-6 items-center justify-center rounded-full bg-clay px-1.5 text-[12px] font-semibold text-white" :aria-label="`${c.unread} unread`">{{ c.unread }}</span>
                    </Link>
                </li>
            </ul>
        </div>
    </CustomerLayout>
</template>
