<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { formatNaira } from '@/lib/format';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

type Option = { value: string; label: string };
type Placement = {
    value: 'home_banner' | 'search_banner';
    label: string;
    description: string;
    size: [number, number];
    targetable: boolean;
    options: { days: number; price: number }[];
    next_free: string;
};

const props = defineProps<{
    placement: Placement['value'];
    placements: Placement[];
    ctas: Option[];
    cars: (Option & { image: string | null })[];
    makes: { id: number; name: string }[];
    bodyTypes: Option[];
    minDate: string;
    maxDate: string;
    maxMb: number;
    lot: { name: string; logo_url: string | null; initials: string; brand_color: string | null };
}>();

const { currentLot } = useShared();
const lotSlug = computed(() => currentLot.value!.slug);

const form = useForm<{
    placement: Placement['value'];
    days: number;
    start: string;
    headline: string;
    subtext: string;
    cta: string;
    vehicle: string;
    image: File | null;
    make_id: string;
    body_type: string;
    city: string;
}>({
    placement: props.placement,
    days: 7,
    start: props.minDate,
    headline: '',
    subtext: '',
    cta: 'see_cars',
    vehicle: '',
    image: null,
    make_id: '',
    body_type: '',
    city: '',
});

const chosen = computed(() => props.placements.find((p) => p.value === form.placement)!);
const price = computed(() => chosen.value.options.find((o) => o.days === form.days)?.price ?? null);
const car = computed(() => props.cars.find((c) => c.value === form.vehicle) ?? null);
const upload = ref<string | null>(null);
const imageUrl = computed(() => upload.value ?? car.value?.image ?? null);
const input = ref<HTMLInputElement | null>(null);
const endDate = computed(() => {
    const d = new Date(`${form.start}T00:00:00`);
    d.setDate(d.getDate() + form.days);
    return d.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' });
});
const startLabel = computed(() => new Date(`${form.start}T00:00:00`).toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' }));
const nextFree = computed(() => (chosen.value.next_free > props.minDate ? new Date(`${chosen.value.next_free}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short' }) : null));

watch(
    () => form.vehicle,
    (v) => {
        if (v && form.cta === 'see_cars') form.cta = 'view_car';
        if (!v && form.cta === 'view_car') form.cta = 'see_cars';
    },
);
watch(
    () => form.placement,
    (p) => {
        if (!props.placements.find((x) => x.value === p)?.targetable) Object.assign(form, { make_id: '', body_type: '', city: '' });
    },
);

function pick(e: Event) {
    const file = (e.target as HTMLInputElement).files?.[0] ?? null;
    form.image = file;
    if (upload.value) URL.revokeObjectURL(upload.value);
    upload.value = file ? URL.createObjectURL(file) : null;
}
function clearImage() {
    form.image = null;
    if (upload.value) URL.revokeObjectURL(upload.value);
    upload.value = null;
    if (input.value) input.value.value = '';
}
onBeforeUnmount(() => upload.value && URL.revokeObjectURL(upload.value));

const ctaLabel = computed(() => props.ctas.find((c) => c.value === form.cta)?.label ?? '');
const submit = () => form.post(route('dealer.ads.store', lotSlug.value), { forceFormData: true, preserveScroll: true });
</script>

<template>
    <Head title="New banner" />
    <DealerLayout>
        <Link :href="route('dealer.ads.index', lotSlug)" class="-mb-2 inline-flex min-h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline"><Icon name="chevronLeft" :size="18" /> Advertise</Link>
        <h1 class="text-[28px] font-bold">New banner</h1>

        <form class="grid gap-6 lg:grid-cols-[minmax(0,1fr)_420px] lg:items-start" @submit.prevent="submit">
            <div class="flex min-w-0 flex-col gap-5">
                <fieldset class="flex flex-col gap-2">
                    <legend class="field-label mb-2">Where</legend>
                    <div class="grid gap-2 sm:grid-cols-2">
                        <label
                            v-for="p in placements"
                            :key="p.value"
                            class="flex cursor-pointer flex-col gap-1 rounded-xl border-2 bg-white p-3.5"
                            :class="form.placement === p.value ? 'border-forest' : 'border-line'"
                        >
                            <input v-model="form.placement" type="radio" name="placement" :value="p.value" class="sr-only" />
                            <span class="flex items-center gap-2 text-[15px] font-bold"><Icon :name="p.value === 'home_banner' ? 'home' : 'search'" :size="18" class="text-forest" /> {{ p.label }}</span>
                            <span class="text-[13px] text-[#4A4D53]">{{ p.description }}</span>
                        </label>
                    </div>
                </fieldset>

                <section class="card flex flex-col gap-4 p-4" aria-labelledby="creative-heading">
                    <h2 id="creative-heading" class="font-sans text-[16px] font-bold">Your banner</h2>
                    <label class="field-label">
                        What does it open?
                        <select v-model="form.vehicle" class="field h-11">
                            <option value="">My lot page</option>
                            <option v-for="c in cars" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                        <InputError :message="form.errors.vehicle" />
                    </label>

                    <div class="flex flex-col gap-1.5">
                        <span class="field-label">Image</span>
                        <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" class="sr-only" tabindex="-1" aria-hidden="true" @change="pick" />
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="btn btn-outline h-11" @click="input?.click()"><Icon name="image" :size="18" /> {{ form.image ? 'Change image' : 'Upload an image' }}</button>
                            <button v-if="form.image" type="button" class="min-h-11 px-2 text-[13px] font-semibold text-muted hover:text-danger" @click="clearImage">Remove</button>
                        </div>
                        <span class="text-[12px] text-muted">
                            Landscape, at least {{ chosen.size[0] / 2 }} px wide ({{ chosen.size[0] }} × {{ chosen.size[1] }} is ideal), up to {{ maxMb }} MB. It's cropped to fit.
                            <template v-if="car && !form.image"> Without one, the car's photo is used.</template>
                        </span>
                        <InputError :message="form.errors.image" />
                    </div>

                    <label class="field-label">
                        <span>Headline <span class="font-normal text-muted">{{ form.headline.length }}/60</span></span>
                        <input v-field="{ kind: 'text', min: 4, max: 60 }" v-model="form.headline" class="field h-11" required placeholder="e.g. December deals on Toyota SUVs" />
                        <InputError :message="form.errors.headline" />
                    </label>
                    <label class="field-label">
                        <span>Short line <span class="font-normal text-muted">(optional) {{ form.subtext.length }}/120</span></span>
                        <input v-field="{ kind: 'text', max: 120 }" v-model="form.subtext" class="field h-11" placeholder="e.g. Foreign-used, duty paid, inspected. Ikeja." />
                        <InputError :message="form.errors.subtext" />
                    </label>
                    <label class="field-label">
                        Button
                        <select v-model="form.cta" class="field h-11">
                            <option v-for="c in ctas" :key="c.value" :value="c.value">{{ c.label }}</option>
                        </select>
                    </label>
                </section>

                <section v-if="chosen.targetable" class="card flex flex-col gap-3 p-4" aria-labelledby="aim-heading">
                    <div>
                        <h2 id="aim-heading" class="font-sans text-[16px] font-bold">Who sees it <span class="font-normal text-muted">(optional)</span></h2>
                        <p class="text-[13px] text-muted">Leave empty to show on all searches. Aimed banners show first to buyers looking for that.</p>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="field-label">
                            Make
                            <select v-model="form.make_id" class="field h-11">
                                <option value="">Any make</option>
                                <option v-for="m in makes" :key="m.id" :value="String(m.id)">{{ m.name }}</option>
                            </select>
                        </label>
                        <label class="field-label">
                            Body type
                            <select v-model="form.body_type" class="field h-11">
                                <option value="">Any</option>
                                <option v-for="b in bodyTypes" :key="b.value" :value="b.value">{{ b.label }}</option>
                            </select>
                        </label>
                        <label class="field-label">
                            City
                            <input v-field="{ kind: 'place', max: 60 }" v-model="form.city" class="field h-11" placeholder="e.g. Lagos" />
                        </label>
                    </div>
                </section>

                <section class="card flex flex-col gap-3 p-4" aria-labelledby="when-heading">
                    <h2 id="when-heading" class="font-sans text-[16px] font-bold">When</h2>
                    <div class="grid grid-cols-3 gap-2">
                        <label
                            v-for="o in chosen.options"
                            :key="o.days"
                            class="flex min-h-14 cursor-pointer flex-col items-center justify-center rounded-xl border text-center"
                            :class="form.days === o.days ? 'border-forest bg-forest text-white' : 'border-line bg-white'"
                        >
                            <input v-model="form.days" type="radio" name="days" :value="o.days" class="sr-only" />
                            <span class="text-[14px] font-semibold">{{ o.days }} days</span>
                            <span class="text-[12px]" :class="form.days === o.days ? 'text-white/85' : 'text-muted'">{{ formatNaira(o.price) }}</span>
                        </label>
                    </div>
                    <InputError :message="form.errors.days" />
                    <label class="field-label">
                        Start date
                        <input v-model="form.start" type="date" class="field h-11 md:w-56" :min="minDate" :max="maxDate" required />
                        <span v-if="nextFree" class="font-normal text-clay-dark">Slots are busy: the next free start for a week is {{ nextFree }}.</span>
                        <InputError :message="form.errors.start" />
                    </label>
                </section>
                <InputError :message="form.errors.placement" />
            </div>

            <!-- Live preview and total -->
            <aside class="flex flex-col gap-3 lg:sticky lg:top-6">
                <span class="text-[13px] font-semibold text-muted">Preview</span>
                <div class="relative overflow-hidden rounded-2xl bg-forest text-white" :style="{ aspectRatio: `${chosen.size[0]} / ${chosen.size[1]}`, background: imageUrl ? undefined : (lot.brand_color ?? undefined) }">
                    <img v-if="imageUrl" :src="imageUrl" alt="" class="absolute inset-0 h-full w-full object-cover" />
                    <span class="absolute inset-0 bg-gradient-to-r from-black/75 via-black/30 to-transparent" />
                    <span class="absolute inset-y-0 left-4 flex max-w-[70%] flex-col justify-center gap-1">
                        <span class="text-[10px] text-white/80">Sponsored · {{ lot.name }}</span>
                        <span class="font-display text-[17px] leading-tight font-bold">{{ form.headline || 'Your headline' }}</span>
                        <span v-if="form.subtext && form.placement === 'home_banner'" class="line-clamp-2 text-[12px] text-white/90">{{ form.subtext }}</span>
                        <span class="mt-0.5 inline-flex h-7 items-center self-start rounded-lg bg-clay px-2.5 text-[11px] font-semibold">{{ ctaLabel }}</span>
                    </span>
                </div>

                <div class="card flex flex-col gap-2 p-4 text-[14px]">
                    <div class="flex justify-between"><span class="text-muted">{{ chosen.label }}</span><strong>{{ form.days }} days</strong></div>
                    <div class="flex justify-between"><span class="text-muted">Runs</span><strong>{{ startLabel }} – {{ endDate }}</strong></div>
                    <div class="flex items-baseline justify-between border-t border-divider pt-2"><span>Total</span><strong class="font-display text-[22px]">{{ formatNaira(price) }}</strong></div>
                    <p class="flex gap-2 rounded-xl bg-map px-3 py-2 text-[12px] text-forest">
                        <Icon name="shield" :size="16" class="mt-0.5 shrink-0" />
                        LotLink checks every banner before it runs, usually within a working day. If it isn't approved you're refunded in full. If the check finishes after your start date, you still get all {{ form.days }} days.
                    </p>
                    <button type="submit" class="btn btn-primary h-12 w-full" :disabled="form.processing || !form.headline.trim()">
                        {{ form.processing ? 'Opening payment…' : `Pay ${formatNaira(price)} and send for review` }}
                    </button>
                </div>
            </aside>
        </form>
    </DealerLayout>
</template>
