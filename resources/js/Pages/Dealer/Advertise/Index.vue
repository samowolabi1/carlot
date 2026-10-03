<script setup lang="ts">
import Icon, { type IconName } from '@/components/Icon.vue';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { formatNaira } from '@/lib/format';
import { Head, Link } from '@inertiajs/vue3';

type Campaign = {
    ulid: string;
    placement: string;
    headline: string;
    image: string | null;
    state: 'unpaid' | 'in_review' | 'scheduled' | 'live' | 'ended' | 'rejected' | 'removed';
    state_label: string;
    dates: string;
    price: string;
    impressions: number;
    clicks: number;
    ctr: number | null;
    note: string | null;
};

defineProps<{
    campaigns: Campaign[];
    products: { key: string; label: string; description: string; from: number | null; url: string }[];
    live: boolean;
}>();

const icons: Record<string, IconName> = { home_banner: 'home', search_banner: 'search', spotlight: 'star', featured: 'grid' };
const tone: Record<Campaign['state'], string> = {
    unpaid: 'bg-sand text-ink',
    in_review: 'bg-blush text-clay-dark',
    scheduled: 'bg-[#E0ECF8] text-[#1E3A8A]',
    live: 'bg-[#DCEFE3] text-[#166534]',
    ended: 'bg-sand text-muted',
    rejected: 'bg-[#FDECEC] text-danger',
    removed: 'bg-[#FDECEC] text-danger',
};
</script>

<template>
    <Head title="Advertise" />
    <DealerLayout>
        <div>
            <h1 class="text-[30px] font-bold">Advertise</h1>
            <p class="text-[14px] text-muted">Put your business and cars in front of more buyers. Paid to CarYard with your card, like your plan.</p>
        </div>

        <p v-if="!live" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark" role="status">Adverts can run once CarYard has approved your business.</p>

        <section class="grid gap-3 sm:grid-cols-2 xl:grid-cols-4" aria-label="Ways to advertise">
            <Link v-for="p in products" :key="p.key" :href="p.url" class="card flex flex-col gap-2 p-4 text-ink no-underline hover:border-forest">
                <span class="flex h-10 w-10 items-center justify-center rounded-full bg-map text-forest"><Icon :name="icons[p.key] ?? 'megaphone'" :size="20" /></span>
                <span class="text-[16px] font-bold">{{ p.label }}</span>
                <span class="grow text-[13px] text-[#4A4D53]">{{ p.description }}</span>
                <span class="flex items-center justify-between text-[14px]">
                    <span v-if="p.from" class="text-muted">From <strong class="text-ink">{{ formatNaira(p.from) }}</strong></span>
                    <span class="inline-flex items-center gap-1 font-semibold text-clay">{{ p.key.includes('banner') ? 'Create' : 'Open' }} <Icon name="chevronRight" :size="16" /></span>
                </span>
            </Link>
        </section>

        <section class="flex flex-col gap-3" aria-labelledby="campaigns-heading">
            <h2 id="campaigns-heading" class="font-sans text-[17px] font-bold">Your banners</h2>
            <p v-if="!campaigns.length" class="card px-5 py-10 text-center text-[15px] text-muted">
                No banners yet. A homepage banner is seen by every buyer who opens CarYard.
            </p>
            <article v-for="c in campaigns" :key="c.ulid" class="card flex flex-col gap-3 p-3.5 md:flex-row md:items-center md:gap-5">
                <div class="aspect-[8/3] w-full shrink-0 overflow-hidden rounded-xl bg-forest md:w-[200px]">
                    <img v-if="c.image" :src="c.image" alt="" class="h-full w-full object-cover" />
                </div>
                <div class="flex min-w-0 grow flex-col gap-1">
                    <span class="flex flex-wrap items-center gap-2">
                        <strong class="text-[15px]">{{ c.headline }}</strong>
                        <span class="rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="tone[c.state]">{{ c.state_label }}</span>
                    </span>
                    <span class="text-[13px] text-muted">{{ c.placement }} · {{ c.dates }} · {{ c.price }}</span>
                    <span v-if="c.note" class="text-[13px] text-clay-dark">CarYard: {{ c.note }}</span>
                </div>
                <dl class="flex shrink-0 gap-5 text-[13px]">
                    <div class="flex flex-col"><dt class="text-muted">Views</dt><dd class="text-[16px] font-semibold tabular-nums">{{ c.impressions.toLocaleString('en-NG') }}</dd></div>
                    <div class="flex flex-col"><dt class="text-muted">Clicks</dt><dd class="text-[16px] font-semibold tabular-nums">{{ c.clicks.toLocaleString('en-NG') }}</dd></div>
                    <div class="flex flex-col"><dt class="text-muted">Click rate</dt><dd class="text-[16px] font-semibold tabular-nums">{{ c.ctr !== null ? `${c.ctr}%` : '—' }}</dd></div>
                </dl>
            </article>
        </section>
    </DealerLayout>
</template>
