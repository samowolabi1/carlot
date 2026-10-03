<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import type { LotSettings } from '@/types';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ lot: LotSettings | null; onboarding?: boolean; defaults?: { phone: string; email: string | null } | null }>();

const form = useForm({
    name: props.lot?.name ?? '',
    tagline: props.lot?.tagline ?? '',
    about: props.lot?.about ?? '',
    phone: props.lot?.phone ?? props.defaults?.phone ?? '',
    whatsapp: props.lot?.whatsapp ?? '',
    email: props.lot?.email ?? props.defaults?.email ?? '',
    onboarding: !!props.onboarding,
});

function submit() {
    if (props.lot) {
        form.put(route('dealer.settings.profile', props.lot.slug), { preserveScroll: true });
    } else {
        form.post(route('dealer.lots.store'));
    }
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <label class="field-label">
            Lot name
            <input v-field="{ kind: 'business_name', max: 80 }" v-model="form.name" class="field" required placeholder="e.g. Prime Motors" autocomplete="organization" />
            <InputError :message="form.errors.name" />
        </label>
        <label class="field-label">
            <span>Tagline <span class="font-normal text-muted">(optional)</span></span>
            <input v-field="{ kind: 'text', max: 120 }" v-model="form.tagline" class="field" placeholder="e.g. Clean Tokunbo SUVs in Ikeja" />
            <InputError :message="form.errors.tagline" />
        </label>
        <div class="grid gap-4 md:grid-cols-2">
            <label class="field-label">
                Business phone
                <input v-field="'phone'" v-model="form.phone" class="field" required type="tel" inputmode="tel" placeholder="0803 123 4567" autocomplete="tel" />
                <InputError :message="form.errors.phone" />
            </label>
            <label class="field-label">
                <span>WhatsApp number <span class="font-normal text-muted">(if different)</span></span>
                <input v-field="'phone'" v-model="form.whatsapp" class="field" type="tel" inputmode="tel" placeholder="Same as business phone" />
                <InputError :message="form.errors.whatsapp" />
            </label>
        </div>
        <label class="field-label">
            <span>Email <span class="font-normal text-muted">(optional)</span></span>
            <input v-field="'email'" v-model="form.email" class="field" type="email" autocomplete="email" placeholder="sales@yourlot.com" />
            <InputError :message="form.errors.email" />
        </label>
        <label class="field-label">
            <span>About the lot <span class="font-normal text-muted">(optional)</span></span>
            <textarea v-field="{ kind: 'text', max: 2000 }"
                v-model="form.about"
                rows="4"
                maxlength="2000"
                class="field h-auto py-3"
                placeholder="What you sell, how long you've been in business, anything buyers should know."
            />
            <InputError :message="form.errors.about" />
        </label>
        <slot name="actions" :processing="form.processing">
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Save changes</button>
            </div>
        </slot>
    </form>
</template>
