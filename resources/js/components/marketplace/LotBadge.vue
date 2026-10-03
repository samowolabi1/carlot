<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import type { PublicLot } from '@/components/marketplace/types-lot';
import { distanceKm, formatDistance, useLocation } from '@/composables/useLocation';
import { Link } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ lot: PublicLot }>();
const { location } = useLocation();

const distance = computed(() => (location.value && props.lot.location ? formatDistance(distanceKm(location.value, props.lot.location)) + ' away' : null));
</script>

<template>
    <div class="card flex items-center gap-3 p-3">
        <Link :href="lot.url" class="flex min-w-0 grow items-center gap-3 text-ink no-underline">
            <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-11 w-11 shrink-0 rounded-xl object-cover" />
            <span v-else class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-forest font-display text-[16px] font-bold text-white" :style="lot.brand_color ? { background: lot.brand_color } : {}">{{ lot.initials }}</span>
            <span class="flex min-w-0 flex-col gap-0.5">
                <span class="flex items-center gap-1 truncate text-[15px] font-semibold">
                    {{ lot.name }}
                    <Icon v-if="lot.verified" name="shield" :size="16" class="shrink-0 text-forest" :stroke-width="2" />
                    <span v-if="lot.verified" class="sr-only">Verified seller</span>
                </span>
                <span class="truncate text-[12px] text-muted">
                    <template v-if="lot.rating"><span class="inline-flex items-baseline gap-0.5 font-semibold text-ink"><Icon name="star" filled :size="13" class="self-center text-clay" />{{ lot.rating.toFixed(1) }}<span class="sr-only"> out of 5</span></span> ({{ lot.reviews_count }}) · </template>
                    <template v-if="distance">{{ distance }} · </template>
                    <span v-if="lot.open" :class="lot.open.open ? 'font-semibold text-success' : ''">{{ lot.open.label }}</span>
                    <template v-else>{{ lot.city }}</template>
                </span>
            </span>
        </Link>
        <a v-if="lot.directions_url" :href="lot.directions_url" target="_blank" rel="noopener" class="inline-flex min-h-11 shrink-0 items-center text-[14px] font-semibold">Directions</a>
    </div>
</template>
