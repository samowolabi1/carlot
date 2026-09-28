<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import SaveButton from '@/components/marketplace/SaveButton.vue';
import { useCompare } from '@/composables/useCompare';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface CarCardData {
    ulid: string;
    url: string;
    title: string;
    price: string | null;
    specs: string;
    image: { src: string; srcset: string } | null;
    lot: { name: string; slug: string; city: string | null };
    distance: string | null;
    new_arrival: boolean;
    reserved: boolean;
    saved: boolean;
}

withDefaults(defineProps<{ car: CarCardData; variant?: 'tile' | 'row'; compare?: boolean }>(), { variant: 'tile', compare: false });

const compareList = useCompare();
const compareFull = ref(false);

function toggleCompare(ulid: string) {
    compareFull.value = !compareList.toggle(ulid);
    if (compareFull.value) setTimeout(() => (compareFull.value = false), 2500);
}
</script>

<template>
    <article class="card relative overflow-hidden" :class="variant === 'row' ? 'flex gap-3 p-2.5' : 'flex flex-col'">
        <Link :href="car.url" class="absolute inset-0 z-[1]" :aria-label="car.title" />

        <div class="relative shrink-0 overflow-hidden bg-sand" :class="variant === 'row' ? 'h-[90px] w-[120px] rounded-[10px]' : 'aspect-[16/10]'">
            <img
                v-if="car.image"
                :src="car.image.src"
                :srcset="car.image.srcset"
                :sizes="variant === 'row' ? '120px' : '(min-width: 1024px) 33vw, (min-width: 640px) 50vw, 100vw'"
                :alt="car.title"
                loading="lazy"
                class="h-full w-full object-cover"
            />
            <div v-else class="flex h-full items-center justify-center"><CarGlyph :width="variant === 'row' ? 56 : 96" /></div>

            <span v-if="car.reserved" class="absolute top-3 left-3 rounded-xl bg-ink px-2.5 py-1 text-[12px] font-semibold text-white" :class="{ 'top-1.5 left-1.5 px-2 py-0.5 text-[11px]': variant === 'row' }">Reserved</span>
            <span v-else-if="car.new_arrival && variant === 'tile'" class="absolute top-3 left-3 rounded-xl bg-blush px-2.5 py-1 text-[12px] font-semibold text-clay-dark">New arrival</span>

            <div v-if="variant === 'tile'" class="absolute top-2 right-2 z-10">
                <SaveButton :ulid="car.ulid" :saved="car.saved" size="sm" />
            </div>
        </div>

        <div class="flex min-w-0 flex-col gap-1" :class="variant === 'row' ? 'justify-center py-0.5' : 'px-3.5 pt-3 pb-3.5'">
            <h3 class="truncate font-sans font-semibold" :class="variant === 'row' ? 'text-[15px]' : 'text-[16px]'">{{ car.title }}</h3>
            <div class="font-display font-bold text-forest" :class="variant === 'row' ? 'text-[16px]' : 'text-[20px]'">{{ car.price }}</div>
            <div class="truncate text-[13px] text-muted">{{ car.specs }}</div>
            <div class="flex items-center gap-1 truncate text-[13px] text-muted">
                <Icon name="pin" :size="14" class="shrink-0 text-clay" :stroke-width="2" />
                {{ car.lot.name }}<template v-if="car.distance"> · {{ car.distance }}</template><template v-else-if="car.lot.city"> · {{ car.lot.city }}</template>
            </div>
            <button
                v-if="compare"
                type="button"
                class="relative z-10 mt-1 flex h-9 items-center gap-2 self-start text-[13px] font-semibold"
                :class="compareList.has(car.ulid) ? 'text-forest' : 'text-muted hover:text-forest'"
                :aria-pressed="compareList.has(car.ulid)"
                @click="toggleCompare(car.ulid)"
            >
                <span class="flex h-[18px] w-[18px] items-center justify-center rounded border" :class="compareList.has(car.ulid) ? 'border-forest bg-forest text-white' : 'border-line-strong bg-white'">
                    <Icon v-if="compareList.has(car.ulid)" name="check" :size="12" :stroke-width="3" />
                </span>
                {{ compareFull ? 'Compare up to 3 cars' : 'Compare' }}
            </button>
        </div>
    </article>
</template>
