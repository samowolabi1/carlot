<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { markSeen, whenVisible, type AdBanner } from '@/lib/ads';
import { onBeforeUnmount, onMounted, ref } from 'vue';

/** A seller's search banner among the results (counted once it's on screen). */
const props = defineProps<{ ad: AdBanner }>();
const root = ref<HTMLElement | null>(null);
let stop: () => void = () => undefined;

onMounted(() => {
    if (root.value) stop = whenVisible(root.value, () => markSeen(props.ad));
});
onBeforeUnmount(() => stop());
</script>

<template>
    <a
        ref="root"
        :href="ad.url"
        class="relative block overflow-hidden rounded-2xl bg-forest text-white no-underline hover:text-white sm:col-span-2 xl:col-span-3"
        :aria-label="`Sponsored by ${ad.lot}: ${ad.headline}`"
    >
        <div class="relative aspect-[3/1] sm:aspect-[4/1]">
            <img v-if="ad.image" :src="ad.image" alt="" loading="lazy" class="absolute inset-0 h-full w-full object-cover" />
            <span class="absolute inset-0 bg-gradient-to-r from-black/75 via-black/35 to-transparent" />
            <span class="absolute inset-y-0 left-4 flex max-w-[75%] flex-col justify-center gap-1 sm:left-6 sm:max-w-[60%]">
                <span class="text-[11px] font-medium text-white/80">Sponsored · {{ ad.lot }}</span>
                <span class="font-display text-[18px] leading-tight font-bold sm:text-[24px]">{{ ad.headline }}</span>
                <span v-if="ad.subtext" class="line-clamp-1 hidden text-[14px] text-white/90 sm:block">{{ ad.subtext }}</span>
                <span class="mt-1 inline-flex items-center gap-1 self-start text-[14px] font-semibold text-peach">{{ ad.cta }} <Icon name="chevronRight" :size="16" :stroke-width="2.2" /></span>
            </span>
        </div>
    </a>
</template>
