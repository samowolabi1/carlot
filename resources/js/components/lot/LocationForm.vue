<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import MapPin, { type PlaceParts } from '@/components/lot/MapPin.vue';
import type { LotSettings } from '@/types';
import { useForm } from '@inertiajs/vue3';

const props = defineProps<{ lot: LotSettings; onboarding?: boolean }>();

const { regions } = useShared();

/** Maps say "Lagos State" or "Abuja"; pick the matching state from the list. */
function matchState(name: string): string {
    const clean = name.replace(/\s+state$/i, '').trim().toLowerCase();
    const alias: Record<string, string> = { abuja: 'FCT', 'federal capital territory': 'FCT', nassarawa: 'Nasarawa', 'akwa-ibom': 'Akwa Ibom' };
    const wanted = alias[clean] ?? clean;
    return regions.value.find((r) => r.value.toLowerCase() === wanted.toLowerCase())?.value ?? '';
}

const form = useForm({
    latitude: props.lot.latitude,
    longitude: props.lot.longitude,
    address: props.lot.address ?? '',
    landmark: props.lot.landmark ?? '',
    city: props.lot.city ?? '',
    state: props.lot.state ?? '',
    onboarding: !!props.onboarding,
});

// Fill blanks from the pin; never overwrite what the owner typed.
function applyPlace(parts: PlaceParts) {
    if (!form.address && parts.address) form.address = parts.address;
    if (!form.city && parts.city) form.city = parts.city;
    if (!form.state && parts.state) form.state = matchState(parts.state);
}

function submit() {
    form.put(route('dealer.settings.location', props.lot.slug), { preserveScroll: true });
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <MapPin v-model:latitude="form.latitude" v-model:longitude="form.longitude" @place="applyPlace" />
        <InputError :message="form.errors.latitude ?? form.errors.longitude" />

        <div class="grid gap-3 md:grid-cols-[2fr_1fr_1fr]">
            <label class="field-label">
                Street address
                <input v-field="{ kind: 'text', min: 5, max: 255 }" v-model="form.address" class="field" required autocomplete="street-address" placeholder="e.g. 12 Allen Avenue" />
                <InputError :message="form.errors.address" />
            </label>
            <label class="field-label">
                Area / city
                <input v-field="'place'" v-model="form.city" class="field" required placeholder="Ikeja" />
                <InputError :message="form.errors.city" />
            </label>
            <label class="field-label">
                State
                <select v-model="form.state" class="field" required>
                    <option value="" disabled>Choose a state</option>
                    <option v-for="r in regions" :key="r.value" :value="r.value">{{ r.label }}</option>
                </select>
                <InputError :message="form.errors.state" />
            </label>
        </div>
        <label class="field-label">
            Landmark buyers will recognise
            <input v-field="{ kind: 'text', max: 255 }" v-model="form.landmark" class="field" placeholder="e.g. Opposite the big church, after the filling station" />
            <InputError :message="form.errors.landmark" />
        </label>

        <slot name="actions" :processing="form.processing">
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Save changes</button>
            </div>
        </slot>
    </form>
</template>
