<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

export type ChecklistGroup = { key: string; label: string; items: { key: string; label: string }[] };
type Result = 'pass' | 'advisory' | 'fail';
type Entry = { status: Result; note: string };

const props = defineProps<{
    groups: ChecklistGroup[];
    results: { value: string; label: string }[];
    previous: { checklist: Record<string, { status: Result; note?: string | null }>; summary: string | null } | null;
    maxPhotos: number;
    action: string;
    askName?: boolean;
    defaultName?: string;
    submitLabel: string;
}>();

const checklist: Record<string, Entry> = {};
for (const g of props.groups) {
    for (const item of g.items) {
        const prev = props.previous?.checklist[item.key];
        checklist[item.key] = { status: prev?.status ?? 'pass', note: prev?.note ?? '' };
    }
}

const form = useForm<{ checklist: Record<string, Entry>; summary: string; inspector_name: string; photos: Record<string, File[]> }>({
    checklist,
    summary: props.previous?.summary ?? '',
    inspector_name: props.defaultName ?? '',
    photos: {},
});

const total = computed(() => Object.keys(form.checklist).length);
const points: Record<Result, number> = { pass: 1, advisory: 0.5, fail: 0 };
const score = computed(() => Math.round((Object.values(form.checklist).reduce((sum, e) => sum + points[e.status], 0) / total.value) * 100));
const counts = computed(() => {
    const c = { pass: 0, advisory: 0, fail: 0 };
    Object.values(form.checklist).forEach((e) => c[e.status]++);
    return c;
});
const photoCount = computed(() => Object.values(form.photos).reduce((n, files) => n + files.length, 0));

const tone: Record<Result, string> = {
    pass: 'bg-forest text-white border-forest',
    advisory: 'bg-[#FDF1DC] text-[#8A5A0B] border-[#E9C98A]',
    fail: 'bg-[#FDECEC] text-danger border-[#F2B8B5]',
};

function addPhotos(item: string, event: Event) {
    const input = event.target as HTMLInputElement;
    const room = props.maxPhotos - photoCount.value;
    const files = Array.from(input.files ?? []).slice(0, Math.max(0, room));
    form.photos = { ...form.photos, [item]: [...(form.photos[item] ?? []), ...files] };
    input.value = '';
}

function removePhoto(item: string, index: number) {
    const files = [...(form.photos[item] ?? [])];
    files.splice(index, 1);
    form.photos = { ...form.photos, [item]: files };
}

const errorFor = (key: string, field: 'status' | 'note') => (form.errors as Record<string, string>)[`checklist.${key}.${field}`];

function submit() {
    form.transform((data) => ({ ...data, photos: Object.fromEntries(Object.entries(data.photos).filter(([, f]) => f.length)) })).post(props.action, {
        forceFormData: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <form class="flex flex-col gap-4" @submit.prevent="submit">
        <div class="sticky top-0 z-10 -mx-1 flex items-center gap-4 rounded-2xl bg-white px-4 py-3 shadow-sm ring-1 ring-line" role="status" aria-live="polite">
            <div class="flex flex-col">
                <span class="font-display text-[28px] leading-none font-bold">{{ score }}<span class="text-[15px] font-normal text-muted"> / 100</span></span>
                <span class="text-[12px] text-muted">Score so far</span>
            </div>
            <div class="flex flex-wrap gap-2 text-[13px]">
                <span class="rounded-lg bg-map px-2 py-1 font-semibold text-forest">{{ counts.pass }} pass</span>
                <span class="rounded-lg bg-[#FDF1DC] px-2 py-1 font-semibold text-[#8A5A0B]">{{ counts.advisory }} advisory</span>
                <span class="rounded-lg bg-[#FDECEC] px-2 py-1 font-semibold text-danger">{{ counts.fail }} fail</span>
            </div>
        </div>

        <p class="text-[14px] text-muted">
            Every check starts as a pass{{ previous ? ' or where the last report left it' : '' }}: change only what isn't. Say what's wrong for any fail. Photos help buyers trust the report ({{ photoCount }}/{{ maxPhotos }}).
        </p>

        <section v-for="group in groups" :key="group.key" class="card overflow-hidden" :aria-labelledby="`group-${group.key}`">
            <h2 :id="`group-${group.key}`" class="border-b border-divider px-4 py-3 font-sans text-[15px] font-bold">{{ group.label }}</h2>
            <ul class="divide-y divide-divider">
                <li v-for="item in group.items" :key="item.key" class="flex flex-col gap-2 px-4 py-3">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span :id="`item-${item.key}`" class="text-[14px] font-medium">{{ item.label }}</span>
                        <div role="radiogroup" :aria-labelledby="`item-${item.key}`" class="flex gap-1">
                            <label
                                v-for="r in results"
                                :key="r.value"
                                class="flex h-11 min-w-[76px] cursor-pointer items-center justify-center rounded-[10px] border px-2 text-[13px] font-semibold focus-within:ring-2 focus-within:ring-clay"
                                :class="form.checklist[item.key].status === r.value ? tone[r.value as Result] : 'border-line bg-white text-muted'"
                            >
                                <input v-model="form.checklist[item.key].status" type="radio" :name="`status-${item.key}`" :value="r.value" class="sr-only" />
                                {{ r.label }}
                            </label>
                        </div>
                    </div>
                    <template v-if="form.checklist[item.key].status !== 'pass'">
                        <div class="flex flex-wrap items-start gap-2">
                            <label class="grow">
                                <span class="sr-only">What's wrong with {{ item.label.toLowerCase() }}</span>
                                <input v-field="{ kind: 'text', max: 200 }" v-model="form.checklist[item.key].note" class="field h-11" :placeholder="form.checklist[item.key].status === 'fail' ? 'What failed? (required)' : 'Note for buyers (optional)'" />
                            </label>
                            <label v-if="photoCount < maxPhotos" class="btn btn-outline h-11 cursor-pointer px-3 text-[13px]">
                                <Icon name="camera" :size="18" /> Photo
                                <input type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="addPhotos(item.key, $event)" />
                            </label>
                        </div>
                        <InputError :message="errorFor(item.key, 'note')" />
                        <ul v-if="form.photos[item.key]?.length" class="flex flex-wrap gap-2">
                            <li v-for="(file, i) in form.photos[item.key]" :key="file.name + i" class="flex items-center gap-1 rounded-lg bg-sand py-1 pr-1 pl-2 text-[12px]">
                                <span class="max-w-[140px] truncate">{{ file.name }}</span>
                                <button type="button" class="flex h-8 w-8 items-center justify-center rounded-md hover:bg-white" :aria-label="`Remove ${file.name}`" @click="removePhoto(item.key, i)">
                                    <Icon name="close" :size="14" />
                                </button>
                            </li>
                        </ul>
                    </template>
                </li>
            </ul>
        </section>

        <div class="card flex flex-col gap-4 p-4">
            <label class="field-label">
                <span>Summary for buyers <span class="font-normal text-muted">(optional)</span></span>
                <textarea v-field="{ kind: 'text', max: 1000 }" v-model="form.summary" rows="3" class="field h-auto py-2" placeholder="e.g. Serviced last month; front tyres due in about 5,000 km." />
                <InputError :message="form.errors.summary" />
            </label>
            <label v-if="askName" class="field-label max-w-sm">
                Inspected by
                <input v-field="'business_name'" v-model="form.inspector_name" class="field" placeholder="Name of the person who checked the car" />
                <InputError :message="form.errors.inspector_name" />
            </label>
            <InputError :message="(form.errors as Record<string, string>).photos" />
            <p v-if="Object.keys(form.errors).length" class="text-[14px] text-danger" role="alert">Some items need attention: look for the red notes above.</p>
            <div class="flex justify-end">
                <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ submitLabel }}</button>
            </div>
        </div>
    </form>
</template>
