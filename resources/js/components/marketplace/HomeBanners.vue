<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { markSeen, whenVisible, type AdBanner } from '@/lib/ads';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/** Homepage banners lots pay for: one at a time, rotating every 6 seconds (paused on hover, focus or reduced motion). */
const props = defineProps<{ banners: AdBanner[] }>();

const index = ref(0);
const paused = ref(false);
const visible = ref(false);
const root = ref<HTMLElement | null>(null);
const current = computed(() => props.banners[index.value]);
const reduceMotion = typeof window !== 'undefined' && window.matchMedia?.('(prefers-reduced-motion: reduce)').matches;
let timer: ReturnType<typeof setInterval> | undefined;
let stopWatching: () => void = () => undefined;
let startX: number | null = null;

function go(to: number) {
    index.value = (to + props.banners.length) % props.banners.length;
}

watch([current, visible], ([ad, isVisible]) => {
    if (ad && isVisible) markSeen(ad);
});

onMounted(() => {
    if (root.value) stopWatching = whenVisible(root.value, () => (visible.value = true));
    if (props.banners.length > 1 && !reduceMotion) {
        timer = setInterval(() => {
            if (!paused.value && document.visibilityState === 'visible') go(index.value + 1);
        }, 6000);
    }
});
onBeforeUnmount(() => {
    clearInterval(timer);
    stopWatching();
});

function touchStart(e: TouchEvent) {
    startX = e.touches[0].clientX;
}
function touchEnd(e: TouchEvent) {
    if (startX === null) return;
    const dx = e.changedTouches[0].clientX - startX;
    if (Math.abs(dx) > 50) go(index.value + (dx < 0 ? 1 : -1));
    startX = null;
}
</script>

<template>
    <section
        v-if="banners.length"
        ref="root"
        class="relative overflow-hidden rounded-2xl bg-forest text-white"
        aria-roledescription="carousel"
        aria-label="Sponsored"
        @mouseenter="paused = true"
        @mouseleave="paused = false"
        @focusin="paused = true"
        @focusout="paused = false"
        @touchstart.passive="touchStart"
        @touchend="touchEnd"
    >
        <div class="relative aspect-[16/9] sm:aspect-[8/3]">
            <template v-for="(ad, i) in banners" :key="ad.ulid">
                <a
                    v-show="i === index"
                    :href="ad.url"
                    class="absolute inset-0 block text-white no-underline hover:text-white"
                    role="group"
                    aria-roledescription="slide"
                    :aria-label="`${i + 1} of ${banners.length}: ${ad.headline}, from ${ad.lot}`"
                >
                    <img v-if="ad.image" :src="ad.image" alt="" class="absolute inset-0 h-full w-full object-cover" :loading="i === 0 ? 'eager' : 'lazy'" />
                    <span class="absolute inset-0 bg-gradient-to-t from-black/75 via-black/25 to-transparent sm:bg-gradient-to-r sm:from-black/70 sm:via-black/30" />
                    <span class="absolute inset-x-4 bottom-4 flex flex-col gap-1.5 sm:inset-x-8 sm:bottom-8 sm:max-w-[60%]">
                        <span class="flex items-center gap-2 text-[12px] font-medium text-white/85">
                            <img v-if="ad.logo" :src="ad.logo" alt="" class="h-5 w-5 rounded object-cover" />
                            Sponsored · {{ ad.lot }}
                        </span>
                        <span class="font-display text-[22px] leading-tight font-bold sm:text-[34px]">{{ ad.headline }}</span>
                        <span v-if="ad.subtext" class="line-clamp-2 text-[14px] text-white/90 sm:text-[16px]">{{ ad.subtext }}</span>
                        <span class="mt-1 inline-flex h-11 items-center gap-1.5 self-start rounded-xl bg-clay px-4 text-[14px] font-semibold">
                            {{ ad.cta }} <Icon name="chevronRight" :size="16" :stroke-width="2.2" />
                        </span>
                    </span>
                </a>
            </template>
        </div>

        <template v-if="banners.length > 1">
            <button type="button" class="absolute top-1/2 left-2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 hover:bg-black/60 md:flex" aria-label="Previous banner" @click="go(index - 1)">
                <Icon name="chevronLeft" :size="22" />
            </button>
            <button type="button" class="absolute top-1/2 right-2 hidden h-11 w-11 -translate-y-1/2 items-center justify-center rounded-full bg-black/40 hover:bg-black/60 md:flex" aria-label="Next banner" @click="go(index + 1)">
                <Icon name="chevronRight" :size="22" />
            </button>
            <div class="absolute right-3 bottom-3 flex gap-0.5 sm:right-5 sm:bottom-5">
                <button
                    v-for="(ad, i) in banners"
                    :key="ad.ulid"
                    type="button"
                    class="flex h-8 w-6 items-center justify-center"
                    :aria-label="`Show banner ${i + 1}`"
                    :aria-current="i === index"
                    @click="go(i)"
                >
                    <span class="block h-2 rounded-full bg-white transition-all" :class="i === index ? 'w-5' : 'w-2 opacity-50'" />
                </button>
            </div>
        </template>
    </section>
</template>
