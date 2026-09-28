<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { LotSettings } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{ lot: LotSettings; onboarding?: boolean }>();

const form = useForm<{ logo: File | null; cover: File | null; brand_color: string; onboarding: boolean }>({
    logo: null,
    cover: null,
    brand_color: props.lot.brand_color ?? '#16302B',
    onboarding: !!props.onboarding,
});

const logoPreview = ref<string | null>(props.lot.logo_url);
const coverPreview = ref<string | null>(props.lot.cover_url);

function pick(field: 'logo' | 'cover', event: Event) {
    const file = (event.target as HTMLInputElement).files?.[0] ?? null;
    form[field] = file;
    const url = file ? URL.createObjectURL(file) : field === 'logo' ? props.lot.logo_url : props.lot.cover_url;
    if (field === 'logo') logoPreview.value = url;
    else coverPreview.value = url;
}

const initials = computed(() =>
    props.lot.name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((w) => w[0]?.toUpperCase())
        .join(''),
);

function submit() {
    form.post(route('dealer.settings.branding', props.lot.slug), { forceFormData: true, preserveScroll: true });
}
</script>

<template>
    <form class="flex flex-col gap-5" @submit.prevent="submit">
        <div class="card overflow-hidden">
            <div class="relative flex h-40 items-center justify-center bg-sand" :style="coverPreview ? `background:url(${coverPreview}) center/cover` : ''">
                <span v-if="!coverPreview" class="text-[14px] text-muted">Cover photo: your lot frontage or best cars</span>
            </div>
            <div class="flex items-center gap-4 px-5 pb-5">
                <div class="-mt-8 flex h-20 w-20 shrink-0 items-center justify-center overflow-hidden rounded-2xl border-4 border-white text-2xl font-bold text-white" :style="{ background: form.brand_color }">
                    <img v-if="logoPreview" :src="logoPreview" alt="" class="h-full w-full object-cover" />
                    <template v-else>{{ initials }}</template>
                </div>
                <div class="pt-3">
                    <div class="text-[16px] font-semibold">{{ lot.name }}</div>
                    <div class="text-[13px] text-muted">How your lot appears to buyers</div>
                </div>
            </div>
        </div>

        <div class="grid gap-4 md:grid-cols-3">
            <label class="field-label">
                Logo
                <span class="btn btn-outline h-12 cursor-pointer text-[14px]"><Icon name="upload" :size="18" /> {{ form.logo ? form.logo.name : 'Upload logo' }}</span>
                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="pick('logo', $event)" />
                <span class="font-normal text-muted">Square, at least 128 px</span>
                <InputError :message="form.errors.logo" />
            </label>
            <label class="field-label">
                Cover photo
                <span class="btn btn-outline h-12 cursor-pointer text-[14px]"><Icon name="upload" :size="18" /> {{ form.cover ? form.cover.name : 'Upload cover' }}</span>
                <input type="file" accept="image/png,image/jpeg,image/webp" class="sr-only" @change="pick('cover', $event)" />
                <span class="font-normal text-muted">Landscape, at least 800 px wide</span>
                <InputError :message="form.errors.cover" />
            </label>
            <label class="field-label">
                Brand colour
                <span class="flex items-center gap-3">
                    <input v-model="form.brand_color" type="color" class="h-12 w-16 cursor-pointer rounded-xl border border-line-strong bg-white p-1" />
                    <input v-model="form.brand_color" class="field" maxlength="7" pattern="#[0-9A-Fa-f]{6}" />
                </span>
                <InputError :message="form.errors.brand_color" />
            </label>
        </div>

        <slot name="actions" :processing="form.processing">
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">Save changes</button>
            </div>
        </slot>
    </form>
</template>
