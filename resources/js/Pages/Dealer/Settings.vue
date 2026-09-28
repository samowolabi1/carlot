<script setup lang="ts">
import BookingRulesForm from '@/components/lot/BookingRulesForm.vue';
import BrandingForm from '@/components/lot/BrandingForm.vue';
import BusinessForm from '@/components/lot/BusinessForm.vue';
import DealsForm, { type DealSettings } from '@/components/lot/DealsForm.vue';
import HoursForm from '@/components/lot/HoursForm.vue';
import LocationForm from '@/components/lot/LocationForm.vue';
import DealerLayout from '@/layouts/DealerLayout.vue';
import type { LotSettings } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    lot: LotSettings;
    booking: { auto_confirm: boolean; min_notice_minutes: number; closures: { id: number; date: string; label: string; reason: string | null }[] };
    deals: DealSettings;
}>();

const tabs = [
    { key: 'profile', label: 'Lot profile' },
    { key: 'branding', label: 'Logo and colours' },
    { key: 'location', label: 'Location' },
    { key: 'hours', label: 'Opening hours' },
    { key: 'booking', label: 'Booking rules and closures' },
    { key: 'deals', label: 'Offers and deposits' },
] as const;

const initial = typeof window !== 'undefined' ? window.location.hash.slice(1) : '';
const active = ref<string>(tabs.some((t) => t.key === initial) ? initial : 'profile');

function select(key: string) {
    active.value = key;
    history.replaceState(history.state, '', `#${key}`);
}
</script>

<template>
    <Head title="Settings" />
    <DealerLayout>
        <h1 class="text-[30px] font-bold">Settings</h1>

        <div class="flex flex-col gap-5 lg:flex-row">
            <nav aria-label="Settings sections" class="-mx-5 flex shrink-0 gap-1 overflow-x-auto px-5 lg:mx-0 lg:w-56 lg:flex-col lg:px-0">
                <button
                    v-for="tab in tabs"
                    :key="tab.key"
                    type="button"
                    class="h-10 shrink-0 rounded-[10px] px-3 text-left text-[14px]"
                    :class="active === tab.key ? 'bg-white font-semibold text-ink shadow-sm ring-1 ring-line' : 'text-muted hover:text-ink'"
                    :aria-current="active === tab.key ? 'true' : undefined"
                    @click="select(tab.key)"
                >
                    {{ tab.label }}
                </button>
            </nav>

            <section class="card max-w-3xl grow p-5 md:p-6">
                <BusinessForm v-if="active === 'profile'" :lot="lot" />
                <BrandingForm v-else-if="active === 'branding'" :lot="lot" />
                <template v-else-if="active === 'location'">
                    <h2 class="mb-1 font-sans text-[16px] font-bold">Where customers find you</h2>
                    <p class="mb-4 text-[14px] text-muted">Drag the pin to the lot gate. Customers get directions to this exact spot.</p>
                    <LocationForm :lot="lot" />
                </template>
                <HoursForm v-else-if="active === 'hours'" :lot="lot" />
                <DealsForm v-else-if="active === 'deals'" :lot-slug="lot.slug" :deals="deals" />
                <BookingRulesForm v-else :lot-slug="lot.slug" :booking="booking" />
            </section>
        </div>
    </DealerLayout>
</template>
