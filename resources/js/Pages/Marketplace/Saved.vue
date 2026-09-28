<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';

defineProps<{ cars: (CarCardData & { sold: boolean; unavailable: boolean; price_drop: string | null; old_price: string | null })[] }>();

function remove(ulid: string) {
    router.delete(route('favourites.destroy', ulid), { preserveScroll: true });
}
</script>

<template>
    <Head title="Saved cars" />
    <CustomerLayout active="saved">
        <div class="mx-auto flex max-w-3xl flex-col gap-4 px-5 py-6">
            <h1 class="text-[28px] font-bold">Saved</h1>

            <div v-if="!cars.length" class="card flex flex-col items-center gap-2 px-6 py-10 text-center">
                <h2 class="text-xl font-bold">No saved cars yet</h2>
                <p class="max-w-sm text-[15px] text-muted">Tap the heart on any car to keep it here and see when its price drops.</p>
                <Link :href="route('cars.index')" class="btn btn-primary mt-2">Browse cars</Link>
            </div>

            <ul v-else class="flex flex-col gap-2.5">
                <li v-for="car in cars" :key="car.ulid" class="card relative flex gap-3 p-2.5" :class="car.sold || car.unavailable ? 'opacity-70' : ''">
                    <Link :href="car.url" class="absolute inset-0" :aria-label="car.title" />
                    <span class="flex h-[78px] w-[104px] shrink-0 items-center justify-center overflow-hidden rounded-[10px] bg-sand">
                        <img v-if="car.image" :src="car.image.src" alt="" class="h-full w-full object-cover" />
                        <CarGlyph v-else :width="56" />
                    </span>
                    <span class="flex min-w-0 grow flex-col justify-center gap-0.5">
                        <span v-if="car.sold" class="self-start rounded-lg bg-divider px-2 py-0.5 text-[11px] font-semibold text-muted">Sold</span>
                        <span v-else-if="car.unavailable" class="self-start rounded-lg bg-divider px-2 py-0.5 text-[11px] font-semibold text-muted">No longer listed</span>
                        <span v-else-if="car.price_drop" class="self-start rounded-lg bg-[#DCEFE3] px-2 py-0.5 text-[11px] font-semibold text-[#166534]">Price dropped {{ car.price_drop }}</span>
                        <span v-else-if="car.reserved" class="self-start rounded-lg bg-ink px-2 py-0.5 text-[11px] font-semibold text-white">Reserved</span>
                        <span class="truncate text-[15px] font-semibold">{{ car.title }}</span>
                        <span v-if="!car.sold" class="text-[15px] font-bold text-forest">
                            {{ car.price }} <s v-if="car.old_price" class="text-[12px] font-normal text-muted">{{ car.old_price }}</s>
                        </span>
                        <span class="text-[12px] text-muted">{{ car.lot.name }}<template v-if="car.lot.city"> · {{ car.lot.city }}</template></span>
                    </span>
                    <button type="button" class="relative z-10 self-center px-2 text-[13px] font-semibold text-muted hover:text-danger" :aria-label="`Remove ${car.title}`" @click="remove(car.ulid)">Remove</button>
                </li>
            </ul>
        </div>
    </CustomerLayout>
</template>
