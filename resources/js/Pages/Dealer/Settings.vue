<script setup lang="ts">
import BankForm, { type BankState } from '@/components/lot/BankForm.vue';
import BookingRulesForm from '@/components/lot/BookingRulesForm.vue';
import BrandingForm from '@/components/lot/BrandingForm.vue';
import BusinessForm from '@/components/lot/BusinessForm.vue';
import DealsForm, { type DealSettings } from '@/components/lot/DealsForm.vue';
import HoursForm from '@/components/lot/HoursForm.vue';
import LocationForm from '@/components/lot/LocationForm.vue';
import SocialForm, { type SocialState } from '@/components/lot/SocialForm.vue';
import VerificationForm, { type VerificationState } from '@/components/lot/VerificationForm.vue';
import DealerLayout from '@/layouts/DealerLayout.vue';
import type { LotSettings } from '@/types';
import { Head } from '@inertiajs/vue3';
import { ref } from 'vue';

defineProps<{
    lot: LotSettings;
    booking: { auto_confirm: boolean; min_notice_minutes: number; closures: { id: number; date: string; label: string; reason: string | null }[] };
    deals: DealSettings;
    verification: VerificationState;
    social: SocialState;
    bank: BankState;
}>();

const tabs = [
    { key: 'profile', label: 'Business profile' },
    { key: 'branding', label: 'Logo and colours' },
    { key: 'location', label: 'Location' },
    { key: 'hours', label: 'Opening hours' },
    { key: 'booking', label: 'Booking rules and closures' },
    { key: 'bank', label: 'Bank details' },
    { key: 'deals', label: 'Offers and deals' },
    { key: 'verification', label: 'Verification' },
    { key: 'social', label: 'Social media' },
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
                    <p class="mb-4 text-[14px] text-muted">Drag the pin to the seller gate. Customers get directions to this exact spot.</p>
                    <LocationForm :lot="lot" />
                </template>
                <HoursForm v-else-if="active === 'hours'" :lot="lot" />
                <BankForm v-else-if="active === 'bank'" :lot-slug="lot.slug" :bank="bank" />
                <DealsForm v-else-if="active === 'deals'" :lot-slug="lot.slug" :deals="deals" :has-bank="bank.accounts.length > 0" />
                <SocialForm v-else-if="active === 'social'" :lot-slug="lot.slug" :social="social" />
                <template v-else-if="active === 'verification'">
                    <h2 class="mb-3 font-sans text-[16px] font-bold">Verified seller</h2>
                    <VerificationForm :lot-slug="lot.slug" :verification="verification" />
                </template>
                <BookingRulesForm v-else :lot-slug="lot.slug" :booking="booking" />
            </section>
        </div>
    </DealerLayout>
</template>
