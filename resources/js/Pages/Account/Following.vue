<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps<{ lots: { slug: string; url: string; name: string; initials: string; logo_url: string | null; city: string | null; verified: boolean }[] }>();

function unfollow(slug: string) {
    router.delete(route('lots.unfollow', slug), { preserveScroll: true });
}
</script>

<template>
    <Head title="Lots I follow" />
    <CustomerLayout active="account">
        <div class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-4 pb-28 md:pt-8">
            <div class="flex items-center gap-2">
                <Link :href="route('account')" aria-label="Back" class="-ml-3 flex h-11 w-11 items-center justify-center text-ink"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></Link>
                <h1 class="text-[22px] font-bold">Lots I follow</h1>
            </div>
            <p class="text-[14px] text-muted">We message you on WhatsApp when these lots list new cars, at most every 30 minutes.</p>

            <div v-if="lots.length === 0" class="card px-5 py-10 text-center text-[15px] text-muted">
                Follow a lot from its page to hear about new stock first.
                <Link :href="route('cars.index')" class="mt-2 block font-semibold">Browse cars</Link>
            </div>
            <ul v-else class="card divide-y divide-divider overflow-hidden">
                <li v-for="lot in lots" :key="lot.slug" class="flex items-center gap-3 px-4 py-3">
                    <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-11 w-11 rounded-xl object-cover" />
                    <span v-else class="flex h-11 w-11 items-center justify-center rounded-xl bg-forest text-[14px] font-bold text-white">{{ lot.initials }}</span>
                    <Link :href="lot.url" class="flex min-w-0 grow flex-col text-ink no-underline">
                        <span class="truncate font-semibold">{{ lot.name }}</span>
                        <span v-if="lot.city" class="text-[13px] text-muted">{{ lot.city }}</span>
                    </Link>
                    <button type="button" class="h-11 px-2 text-[14px] font-semibold text-muted hover:text-danger" @click="unfollow(lot.slug)">Unfollow</button>
                </li>
            </ul>
        </div>
    </CustomerLayout>
</template>
