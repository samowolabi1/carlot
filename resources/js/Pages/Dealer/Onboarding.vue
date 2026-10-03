<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import BrandingForm from '@/components/lot/BrandingForm.vue';
import BusinessForm from '@/components/lot/BusinessForm.vue';
import HoursForm from '@/components/lot/HoursForm.vue';
import InviteForm from '@/components/lot/InviteForm.vue';
import LocationForm from '@/components/lot/LocationForm.vue';
import StepActions from '@/components/lot/StepActions.vue';
import VerificationForm, { type VerificationState } from '@/components/lot/VerificationForm.vue';
import OnboardingLayout from '@/layouts/OnboardingLayout.vue';
import type { LotSettings } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    step: string;
    steps: string[];
    lot: LotSettings | null;
    defaults: { phone: string; email: string | null } | null;
    verification: VerificationState | null;
}>();

const copy: Record<string, { label: string; title: (name: string) => string; intro: string }> = {
    business: {
        label: 'Business details',
        title: () => 'Tell buyers about your business',
        intro: 'This is what buyers see on your seller page. You can change it any time.',
    },
    branding: {
        label: 'Logo and cover',
        title: () => 'Make it look like your business',
        intro: 'A logo and a photo of your yard help buyers trust you and recognise your gate.',
    },
    location: {
        label: 'Pin your location',
        title: (name) => `Where is ${name}?`,
        intro: 'Stand at your gate and tap "Use my current location", or drag the pin. Buyers get directions to this exact spot.',
    },
    hours: {
        label: 'Opening hours',
        title: () => 'When can buyers visit?',
        intro: 'Buyers only book visits when you are open. Add public holidays later in Settings.',
    },
    staff: {
        label: 'Invite staff',
        title: () => 'Bring in your sales team',
        intro: 'Staff can add cars, handle their own leads and bookings, and share cars. You stay in charge of prices, billing and staff.',
    },
    submit: {
        label: 'Verify with CAC',
        title: () => 'Verify and go live',
        intro: 'We check every seller before it appears on CarYard. Add your CAC documents for the Verified seller badge: you can list cars while we check.',
    },
};

const stepList = computed(() => props.steps.map((key) => ({ key, label: copy[key].label })));
const index = computed(() => props.steps.indexOf(props.step));
const previous = computed(() => (index.value > 0 ? props.steps[index.value - 1] : null));
const next = computed(() => props.steps[index.value + 1] ?? null);
const info = computed(() => copy[props.step]);
const backHref = computed(() => (props.lot && previous.value ? route('dealer.onboarding.show', [props.lot.slug, previous.value]) : ''));

const submitForm = useForm({});
</script>

<template>
    <Head :title="info.label" />
    <OnboardingLayout :steps="stepList" :current="step">
        <div class="flex flex-col gap-1.5">
            <span class="text-[13px] font-semibold text-muted">Step {{ index + 1 }} of {{ steps.length }}</span>
            <h1 class="text-[32px] leading-tight font-bold">{{ info.title(lot?.name ?? 'your business') }}</h1>
            <p class="max-w-2xl text-[15px] text-muted">{{ info.intro }}</p>
        </div>

        <div class="max-w-3xl">
            <BusinessForm v-if="step === 'business'" :lot="lot" :defaults="defaults" onboarding>
                <template #actions="{ processing }">
                    <div class="flex justify-end">
                        <button type="submit" class="btn btn-primary" :disabled="processing">Save and continue</button>
                    </div>
                </template>
            </BusinessForm>

            <template v-else-if="lot">
                <BrandingForm v-if="step === 'branding'" :lot="lot" onboarding>
                    <template #actions="{ processing }"><StepActions :back-href="backHref" :processing="processing" /></template>
                </BrandingForm>
                <LocationForm v-else-if="step === 'location'" :lot="lot" onboarding>
                    <template #actions="{ processing }"><StepActions :back-href="backHref" :processing="processing" /></template>
                </LocationForm>
                <HoursForm v-else-if="step === 'hours'" :lot="lot" onboarding>
                    <template #actions="{ processing }"><StepActions :back-href="backHref" :processing="processing" /></template>
                </HoursForm>

                <div v-else-if="step === 'staff'" class="flex flex-col gap-5">
                    <InviteForm :lot-slug="lot.slug" />
                    <ul v-if="lot.invitations?.length" class="card divide-y divide-divider">
                        <li v-for="invite in lot.invitations" :key="invite.id" class="flex justify-between px-4 py-3 text-[14px]">
                            <span>{{ invite.contact }}</span>
                            <span class="text-muted capitalize">{{ invite.role }} · invited</span>
                        </li>
                    </ul>
                    <div class="flex justify-between">
                        <Link :href="route('dealer.onboarding.show', [lot.slug, previous!])" class="btn btn-outline">Back</Link>
                        <Link :href="route('dealer.onboarding.show', [lot.slug, next!])" class="btn btn-primary">
                            {{ lot.invitations?.length ? 'Continue' : 'Skip for now' }}
                        </Link>
                    </div>
                </div>

                <div v-else-if="step === 'submit'" class="flex flex-col gap-5">
                    <section v-if="verification" class="card flex flex-col gap-3 p-5" aria-labelledby="verify-heading">
                        <h2 id="verify-heading" class="font-sans text-[16px] font-bold">Verified seller badge <span class="font-normal text-muted">(optional now)</span></h2>
                        <VerificationForm :lot-slug="lot.slug" :verification="verification" />
                    </section>
                    <div class="card flex flex-col gap-3 p-5">
                        <div class="flex items-center gap-3 text-[15px]">
                            <Icon name="check" class="text-success" :stroke-width="2.5" /> Business details for <strong>{{ lot.name }}</strong>
                        </div>
                        <div class="flex items-center gap-3 text-[15px]">
                            <Icon :name="lot.latitude !== null ? 'check' : 'close'" :class="lot.latitude !== null ? 'text-success' : 'text-danger'" :stroke-width="2.5" />
                            <span v-if="lot.latitude !== null">Pinned at {{ lot.address }}, {{ lot.city }}</span>
                            <span v-else>No map pin yet: <Link :href="route('dealer.onboarding.show', [lot.slug, 'location'])">add your location</Link></span>
                        </div>
                        <div class="flex items-center gap-3 text-[15px]">
                            <Icon name="check" class="text-success" :stroke-width="2.5" /> Opening hours set
                        </div>
                    </div>
                    <p v-if="lot.submitted" class="rounded-xl bg-cream p-4 text-[14px] text-clay-dark">You've already submitted. We'll let you know when your business is live.</p>
                    <div class="flex justify-between">
                        <Link :href="route('dealer.onboarding.show', [lot.slug, previous!])" class="btn btn-outline">Back</Link>
                        <button
                            type="button"
                            class="btn btn-primary"
                            :disabled="submitForm.processing || lot.latitude === null"
                            @click="submitForm.post(route('dealer.onboarding.submit', lot.slug))"
                        >
                            {{ lot.submitted ? 'Go to dashboard' : 'Submit for approval' }}
                        </button>
                    </div>
                </div>
            </template>
        </div>
    </OnboardingLayout>
</template>
