<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { CarCardData } from '@/components/marketplace/CarCard.vue';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

const props = defineProps<{
    lot: { slug: string; name: string };
    car: CarCardData | null;
    makes: { id: number; name: string; models: { id: number; name: string }[] }[];
    conditions: { value: string; label: string }[];
    maxPhotos: number;
}>();

const form = useForm<{
    make_id: number | '';
    vehicle_model_id: number | '' | 'other';
    model_name: string;
    year: string;
    mileage_km: string;
    condition: string;
    notes: string;
    photos: File[];
    car: string | null;
}>({
    make_id: '',
    vehicle_model_id: '',
    model_name: '',
    year: '',
    mileage_km: '',
    condition: 'good',
    notes: '',
    photos: [],
    car: props.car?.ulid ?? null,
});

const useTowards = ref(!!props.car);
const models = computed(() => props.makes.find((m) => m.id === form.make_id)?.models ?? []);
const previews = ref<string[]>([]);
const fileInput = ref<HTMLInputElement | null>(null);
const labels = ['Front', 'Back', 'Interior', 'Dashboard', 'Engine', 'Left side', 'Right side', 'Tyres'];
const years = Array.from({ length: 36 }, (_, i) => new Date().getFullYear() + 1 - i);

function addPhotos(event: Event) {
    const files = Array.from((event.target as HTMLInputElement).files ?? []).slice(0, props.maxPhotos - form.photos.length);
    form.photos = [...form.photos, ...files];
    previews.value = [...previews.value, ...files.map((f) => URL.createObjectURL(f))];
    if (fileInput.value) fileInput.value.value = '';
}

function removePhoto(i: number) {
    URL.revokeObjectURL(previews.value[i]);
    form.photos = form.photos.filter((_, j) => j !== i);
    previews.value = previews.value.filter((_, j) => j !== i);
}

onBeforeUnmount(() => previews.value.forEach((u) => URL.revokeObjectURL(u)));

const photoError = computed(() => Object.entries(form.errors).find(([k]) => k.startsWith('photos'))?.[1]);

function submit() {
    form.transform((d) => ({
        ...d,
        vehicle_model_id: d.vehicle_model_id === 'other' ? '' : d.vehicle_model_id,
        model_name: d.vehicle_model_id === 'other' || models.value.length === 0 ? d.model_name : '',
        car: useTowards.value ? d.car : null,
    })).post(route('trade-ins.store', props.lot.slug), { forceFormData: true });
}

const goBack = () => window.history.back();
</script>

<template>
    <Head title="Trade in your car" />
    <CustomerLayout bare>
        <form class="mx-auto flex max-w-xl flex-col gap-4 px-5 pt-4 pb-60 md:pb-44" @submit.prevent="submit">
            <div class="flex items-center gap-2">
                <button type="button" aria-label="Back" class="-ml-2 flex h-11 w-11 items-center justify-center text-ink" @click="goBack"><Icon name="chevronLeft" :size="22" /></button>
                <div class="flex flex-col">
                    <h1 class="text-[22px] font-bold">Trade in your car</h1>
                    <span class="text-[13px] text-muted">Get a price range from {{ lot.name }}</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-2.5">
                <label class="field-label">
                    Make
                    <select v-model="form.make_id" class="field" required @change="form.vehicle_model_id = ''">
                        <option value="" disabled>Choose</option>
                        <option v-for="m in makes" :key="m.id" :value="m.id">{{ m.name }}</option>
                    </select>
                    <InputError :message="form.errors.make_id" />
                </label>
                <label v-if="models.length" class="field-label">
                    Model
                    <select v-model="form.vehicle_model_id" class="field" required>
                        <option value="" disabled>Choose</option>
                        <option v-for="m in models" :key="m.id" :value="m.id">{{ m.name }}</option>
                        <option value="other">Other</option>
                    </select>
                    <InputError :message="form.errors.vehicle_model_id" />
                </label>
                <label v-if="!models.length || form.vehicle_model_id === 'other'" class="field-label" :class="{ 'col-span-2': models.length }">
                    Model
                    <input v-field="'model'" v-model="form.model_name" class="field" required placeholder="e.g. Civic" />
                    <InputError :message="form.errors.model_name" />
                </label>
                <label class="field-label">
                    Year
                    <select v-model="form.year" class="field" required>
                        <option value="" disabled>Choose</option>
                        <option v-for="y in years" :key="y" :value="String(y)">{{ y }}</option>
                    </select>
                    <InputError :message="form.errors.year" />
                </label>
                <label class="field-label">
                    Mileage (km)
                    <input v-field="{ kind: 'count', min: 0, max: 2000000 }" v-model="form.mileage_km" class="field" inputmode="numeric" required placeholder="118,000" />
                    <InputError :message="form.errors.mileage_km" />
                </label>
            </div>

            <label class="field-label">
                Condition
                <select v-model="form.condition" class="field">
                    <option v-for="c in conditions" :key="c.value" :value="c.value">{{ c.label }}</option>
                </select>
            </label>

            <div class="field-label">
                Photos ({{ form.photos.length }} of {{ maxPhotos }})
                <div class="grid grid-cols-4 gap-2">
                    <div v-for="(src, i) in previews" :key="src" class="relative h-[84px] overflow-hidden rounded-xl bg-sand">
                        <img :src="src" :alt="labels[i] ?? 'Photo'" class="h-full w-full object-cover" />
                        <button type="button" class="absolute top-1 right-1 flex h-7 w-7 items-center justify-center rounded-full bg-white/90 text-ink" :aria-label="`Remove photo ${i + 1}`" @click="removePhoto(i)">
                            <Icon name="close" :size="14" />
                        </button>
                    </div>
                    <label v-if="form.photos.length < maxPhotos" class="flex h-[84px] cursor-pointer flex-col items-center justify-center rounded-xl border-2 border-dashed border-line-strong text-[12px] font-semibold text-forest">
                        <Icon name="plus" :size="18" /> Add
                        <input ref="fileInput" type="file" accept="image/*" multiple class="sr-only" @change="addPhotos" />
                    </label>
                </div>
                <span class="font-normal text-muted">Front, back, interior and dashboard help the lot give a closer price.</span>
                <InputError :message="photoError" />
            </div>

            <label class="field-label">
                Anything the lot should know?
                <textarea v-field="{ kind: 'text', max: 1000 }" v-model="form.notes" rows="3" class="field h-auto py-3 font-normal" placeholder="e.g. AC recently serviced. Papers up to date." />
            </label>

            <label v-if="car" class="flex min-h-11 items-center gap-2.5 text-[14px]">
                <input v-model="useTowards" type="checkbox" class="h-[18px] w-[18px] accent-forest" /> Use towards the {{ car.title }}
            </label>
        </form>

        <div class="fixed inset-x-0 bottom-[76px] z-30 border-t border-line bg-white md:bottom-0">
            <div class="mx-auto flex max-w-xl flex-col gap-2 px-5 pt-3 pb-3 md:pb-6">
                <button type="button" class="btn btn-primary h-[52px] w-full rounded-[14px]" :disabled="form.processing || form.photos.length === 0" @click="submit">
                    {{ form.processing ? 'Sending…' : 'Send for valuation' }}
                </button>
                <span class="text-center text-[12px] text-muted">You'll get a price range by WhatsApp. Nothing is binding until you visit.</span>
            </div>
        </div>
    </CustomerLayout>
</template>
