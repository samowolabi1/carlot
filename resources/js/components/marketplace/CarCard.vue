<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import Icon from '@/components/Icon.vue';
import PhotoViewer, { type ViewerPhoto } from '@/components/marketplace/PhotoViewer.vue';
import SaveButton from '@/components/marketplace/SaveButton.vue';
import { useBudget } from '@/composables/useBudget';
import { useCompare } from '@/composables/useCompare';
import { Link } from '@inertiajs/vue3';
import { ref } from 'vue';

export interface CarCardData {
    ulid: string;
    url: string;
    title: string;
    price: string | null;
    price_value?: number | null;
    specs: string;
    image: { src: string; srcset: string; full?: string } | null;
    lot: { name: string; slug: string; city: string | null };
    distance: string | null;
    new_arrival: boolean;
    reserved: boolean;
    saved: boolean;
    sponsored?: boolean;
    inspected?: boolean;
}

withDefaults(defineProps<{ car: CarCardData; variant?: 'tile' | 'row'; compare?: boolean }>(), { variant: 'tile', compare: false });

const compareList = useCompare();
const budget = useBudget();
const compareFull = ref(false);

// Quick look: peek at every photo without leaving the list.
const peek = ref<{ photos: ViewerPhoto[]; loading: boolean; error: boolean } | null>(null);

async function quickLook(car: CarCardData) {
    peek.value = { photos: car.image ? [{ ...car.image, full: car.image.full ?? car.image.src }] : [], loading: true, error: false };
    try {
        const res = await fetch(route('cars.photos', car.ulid), { headers: { Accept: 'application/json' } });
        if (!res.ok) throw new Error(String(res.status));
        const data = (await res.json()) as { photos: ViewerPhoto[] };
        if (peek.value) peek.value = { photos: data.photos, loading: false, error: false };
    } catch {
        if (peek.value) peek.value = { ...peek.value, loading: false, error: true };
    }
}

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
            <span v-else-if="car.sponsored && variant === 'tile'" class="absolute top-3 left-3 rounded-xl bg-forest px-2.5 py-1 text-[12px] font-semibold text-white">Spotlight</span>
            <span v-else-if="car.new_arrival && variant === 'tile'" class="absolute top-3 left-3 rounded-xl bg-blush px-2.5 py-1 text-[12px] font-semibold text-clay-dark">New arrival</span>

            <div v-if="variant === 'tile'" class="absolute top-2 right-2 z-10">
                <SaveButton :ulid="car.ulid" :saved="car.saved" size="sm" />
            </div>
            <button
                v-if="car.image"
                type="button"
                class="absolute z-10 flex items-center justify-center gap-1.5 rounded-full bg-ink/70 font-semibold text-white backdrop-blur-sm transition hover:bg-ink/90"
                :class="variant === 'row' ? 'right-0.5 bottom-0.5 h-11 w-11' : 'right-2 bottom-2 h-11 px-3.5 text-[13px]'"
                :aria-label="`Quick look at photos of ${car.title}`"
                @click="quickLook(car)"
            >
                <Icon name="image" :size="variant === 'row' ? 16 : 18" />
                <span v-if="variant === 'tile'">Photos</span>
            </button>
        </div>

        <div class="flex min-w-0 flex-col gap-1" :class="variant === 'row' ? 'justify-center py-0.5' : 'px-3.5 pt-3 pb-3.5'">
            <h3 class="truncate font-sans font-semibold" :class="variant === 'row' ? 'text-[15px]' : 'text-[16px]'">{{ car.title }}</h3>
            <div class="flex flex-wrap items-center gap-x-2 gap-y-1">
                <span class="font-display font-bold text-forest" :class="variant === 'row' ? 'text-[16px]' : 'text-[20px]'">{{ car.price }}</span>
                <span v-if="budget.within(car.price_value)" class="rounded-lg bg-[#E3F1E8] px-1.5 py-0.5 text-[11px] font-semibold text-success">Within budget</span>
                <span v-if="car.inspected" class="flex items-center gap-0.5 rounded-lg bg-map px-1.5 py-0.5 text-[11px] font-semibold text-forest"><Icon name="clipboard" :size="12" :stroke-width="2" /> Inspected</span>
            </div>
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

        <PhotoViewer v-if="peek" :photos="peek.photos" :loading="peek.loading" :title="car.title" :price="car.price" :href="car.url" @close="peek = null" />
        <p v-if="peek?.error" class="sr-only" role="status">Couldn't load all the photos.</p>
    </article>
</template>
