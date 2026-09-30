<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

export type VerificationState = {
    verified: boolean;
    verified_on: string | null;
    current: {
        status: 'submitted' | 'approved' | 'rejected';
        status_label: string;
        cac_number: string;
        notes: string | null;
        submitted_at: string | null;
        certificate_url: string;
        frontage_url: string;
    } | null;
    can_submit: boolean;
};

const props = defineProps<{ lotSlug: string; verification: VerificationState }>();

const form = useForm<{ cac_number: string; certificate: File | null; frontage: File | null }>({ cac_number: '', certificate: null, frontage: null });

const waiting = computed(() => props.verification.current?.status === 'submitted');

function pick(field: 'certificate' | 'frontage', event: Event) {
    form[field] = (event.target as HTMLInputElement).files?.[0] ?? null;
}

function submit() {
    form.post(route('dealer.verification.store', props.lotSlug), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div v-if="verification.verified" class="flex items-start gap-3 rounded-xl bg-map p-4 text-[14px] text-forest">
            <Icon name="shield" :size="22" :stroke-width="2" class="shrink-0" />
            <span><strong>Verified lot</strong> since {{ verification.verified_on }}. Buyers see the badge on your lot page and every car.</span>
        </div>

        <template v-else>
            <div v-if="waiting" class="flex items-start gap-3 rounded-xl bg-cream p-4 text-[14px] text-clay-dark" role="status">
                <Icon name="clock" :size="20" class="mt-0.5 shrink-0" />
                <span>
                    <strong>We're checking your documents</strong> ({{ verification.current!.cac_number }}, sent {{ verification.current!.submitted_at }}). This usually takes up to 2 working days.
                    You can list cars in the meantime.
                </span>
            </div>
            <div v-else-if="verification.current?.status === 'rejected'" class="flex items-start gap-3 rounded-xl bg-[#FDECEC] p-4 text-[14px] text-danger" role="alert">
                <Icon name="alert" :size="20" class="mt-0.5 shrink-0" />
                <span><strong>We couldn't verify your lot.</strong> {{ verification.current.notes }} Send the documents again below.</span>
            </div>
            <p v-else class="text-[14px] text-muted">
                Verified lots get a badge on their page and every car, and buyers trust them more. We check your CAC registration and that the lot is where your pin says.
            </p>

            <div v-if="verification.current" class="flex flex-wrap gap-2 text-[14px]">
                <a :href="verification.current.certificate_url" target="_blank" rel="noopener" class="btn btn-outline h-11 text-[14px]"><Icon name="file" :size="18" /> CAC certificate</a>
                <a :href="verification.current.frontage_url" target="_blank" rel="noopener" class="btn btn-outline h-11 text-[14px]"><Icon name="image" :size="18" /> Frontage photo</a>
            </div>

            <form v-if="verification.can_submit" class="flex flex-col gap-4" @submit.prevent="submit">
                <h3 v-if="waiting" class="font-sans text-[15px] font-bold">Replace the documents</h3>
                <label class="field-label max-w-xs">
                    CAC registration number
                    <input v-field="{ kind: 'text', max: 20 }" v-model="form.cac_number" class="field" placeholder="RC 1234567" autocomplete="off" inputmode="text" required />
                    <InputError :message="form.errors.cac_number" />
                </label>
                <div class="grid gap-4 md:grid-cols-2">
                    <label class="field-label">
                        CAC certificate
                        <span class="btn btn-outline h-12 cursor-pointer text-[14px]"><Icon name="upload" :size="18" /> <span class="truncate">{{ form.certificate ? form.certificate.name : 'Upload PDF or photo' }}</span></span>
                        <input type="file" accept="application/pdf,image/jpeg,image/png,image/webp" class="sr-only" required @change="pick('certificate', $event)" />
                        <span class="font-normal text-muted">Up to 10 MB. Only our team sees it.</span>
                        <InputError :message="form.errors.certificate" />
                    </label>
                    <label class="field-label">
                        Photo of your lot frontage
                        <span class="btn btn-outline h-12 cursor-pointer text-[14px]"><Icon name="camera" :size="18" /> <span class="truncate">{{ form.frontage ? form.frontage.name : 'Take or upload a photo' }}</span></span>
                        <input type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" required @change="pick('frontage', $event)" />
                        <span class="font-normal text-muted">From the road, with your sign in view.</span>
                        <InputError :message="form.errors.frontage" />
                    </label>
                </div>
                <div class="flex justify-end">
                    <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ waiting ? 'Send new documents' : 'Send for verification' }}</button>
                </div>
            </form>
            <p v-else-if="!verification.current" class="text-[14px] text-muted">Only the lot owner can send verification documents.</p>
        </template>
    </div>
</template>
