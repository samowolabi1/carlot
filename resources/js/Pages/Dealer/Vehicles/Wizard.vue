<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import PhotoUploader, { type MediaItem } from '@/components/vehicles/PhotoUploader.vue';
import StepBar from '@/components/vehicles/StepBar.vue';
import { useShared } from '@/composables/useShared';
import WizardLayout from '@/layouts/WizardLayout.vue';
import { formatNaira, formatNumber, parseAmount, typedAmount } from '@/lib/format';
import { json } from '@/lib/http';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Option = { value: string; label: string };

interface Vehicle {
    ulid: string;
    title: string;
    status: string;
    listed: boolean;
    vin: string | null;
    make_id: number | null;
    make: string | null;
    vehicle_model_id: number | null;
    model: string | null;
    year: number | null;
    trim: string | null;
    body_type: string | null;
    mileage_km: number | null;
    condition: string | null;
    transmission: string | null;
    fuel: string | null;
    engine_cc: number | null;
    drivetrain: string | null;
    colour: string | null;
    interior_colour: string | null;
    duty_status: string | null;
    registered: boolean;
    description: string | null;
    feature_ids: number[];
    price: number | null;
    price_formatted: string | null;
    negotiable: boolean;
    media: (MediaItem & { urls: Record<string, string> })[];
}

const props = defineProps<{
    step: 'identity' | 'details' | 'photos' | 'price';
    steps: string[];
    vehicle: Vehicle | null;
    makes: { id: number; name: string; models: { id: number; name: string }[] }[];
    options: {
        body_types: Option[];
        conditions: Option[];
        transmissions: Option[];
        fuels: Option[];
        drivetrains: Option[];
        duty_statuses: Option[];
        features: { group: string; items: { id: number; name: string }[] }[];
    };
    missing: string[];
    guide?: { low: number; median: number; high: number; count: number } | null;
    canChangePrice: boolean;
    maxPhotos: number;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const isDraft = computed(() => !props.vehicle || props.vehicle.status === 'draft');

const labels: Record<string, string> = { identity: 'VIN & model', details: 'Details', photos: 'Photos', price: 'Price & publish' };
const titles: Record<string, string> = { identity: 'Start with the VIN', details: 'Car details', photos: 'Add photos', price: isDraft.value ? 'Price and publish' : 'Price' };

const stepIndex = computed(() => props.steps.indexOf(props.step));
const stepLinks = computed(() =>
    props.steps.map((key) => ({
        key,
        label: labels[key],
        href: props.vehicle ? route('dealer.vehicles.edit', [lot.value.slug, props.vehicle.ulid, key]) : undefined,
    })),
);
// Drafts fill in step by step; a listed car can jump to any section.
const reached = computed(() => (props.vehicle && !isDraft.value ? props.steps.length - 1 : stepIndex.value - 1));
const backHref = computed(() =>
    props.vehicle && stepIndex.value > 0 ? route('dealer.vehicles.edit', [lot.value.slug, props.vehicle.ulid, props.steps[stepIndex.value - 1]]) : route('dealer.vehicles.index', lot.value.slug),
);
const statusText = computed(() => (!props.vehicle ? lot.value.name : isDraft.value ? 'Draft saved' : props.vehicle.title));

/* ---------- Step 1: VIN and model ---------- */

const years = Array.from({ length: new Date().getFullYear() + 1 - 1990 + 1 }, (_, i) => new Date().getFullYear() + 1 - i);

type Decoded = { [key: string]: string | number | null };

type IdentityForm = {
    vin: string;
    make_id: number | null;
    vehicle_model_id: number | null;
    model_name: string;
    year: number | null;
    trim: string;
    decoded: Decoded | null;
    wizard: boolean;
};

const identity = useForm<IdentityForm>({
    vin: props.vehicle?.vin ?? '',
    make_id: props.vehicle?.make_id ?? null,
    vehicle_model_id: props.vehicle?.vehicle_model_id ?? null,
    model_name: '',
    year: props.vehicle?.year ?? null,
    trim: props.vehicle?.trim ?? '',
    decoded: null,
    wizard: isDraft.value,
});

// Existing cars show the make/model fields straight away.
const manual = ref(!!props.vehicle);
const otherModel = ref(false);
const decoding = ref(false);
const decodeMessage = ref<string | null>(null);
const decoded = ref<Decoded | null>(null);

const models = computed(() => props.makes.find((m) => m.id === identity.make_id)?.models ?? []);
const makeName = computed(() => props.makes.find((m) => m.id === identity.make_id)?.name);
const modelName = computed(() => (otherModel.value ? identity.model_name : models.value.find((m) => m.id === identity.vehicle_model_id)?.name));

async function decodeVin() {
    decodeMessage.value = null;
    decoded.value = null;
    identity.clearErrors('vin');
    const vin = identity.vin.replace(/\s+/g, '').toUpperCase();
    identity.vin = vin;

    if (!/^[A-HJ-NPR-Z0-9]{17}$/.test(vin)) {
        identity.setError('vin', 'A VIN has 17 letters and numbers, and never uses I, O or Q.');
        return;
    }

    decoding.value = true;
    try {
        const result = await json<{
            found: boolean;
            message?: string;
            decoded?: Decoded;
            make_id?: number | null;
            vehicle_model_id?: number | null;
            model_name?: string | null;
        }>('POST', route('dealer.vehicles.decode-vin', lot.value.slug), { vin });

        if (!result.found) {
            decodeMessage.value = result.message ?? "We couldn't find that VIN.";
            manual.value = true;
            return;
        }

        decoded.value = result.decoded!;
        identity.decoded = result.decoded!;
        identity.year = (result.decoded!.year as number) ?? identity.year;
        identity.trim = (result.decoded!.trim as string) ?? identity.trim;

        if (result.make_id) {
            identity.make_id = result.make_id;
            identity.vehicle_model_id = result.vehicle_model_id ?? null;
            otherModel.value = !result.vehicle_model_id && !!result.model_name;
            identity.model_name = otherModel.value ? (result.model_name ?? '') : '';
        } else {
            decodeMessage.value = `We decoded a ${result.decoded!.make} we don't list yet. Choose the closest make below.`;
            manual.value = true;
        }
    } catch (e) {
        decodeMessage.value = e instanceof Error ? e.message : 'The VIN service is not responding.';
        manual.value = true;
    } finally {
        decoding.value = false;
    }
}

function onModelChange(value: string) {
    otherModel.value = value === 'other';
    identity.vehicle_model_id = otherModel.value ? null : Number(value) || null;
}

function saveIdentity() {
    identity
        .transform((data) => ({
            ...data,
            vehicle_model_id: otherModel.value ? null : data.vehicle_model_id,
            model_name: otherModel.value ? data.model_name : null,
        }))
        [props.vehicle ? 'put' : 'post'](props.vehicle ? route('dealer.vehicles.identity', [lot.value.slug, props.vehicle.ulid]) : route('dealer.vehicles.store', lot.value.slug));
}

/* ---------- Step 2: details ---------- */

const details = useForm({
    body_type: props.vehicle?.body_type ?? '',
    mileage_km: props.vehicle?.mileage_km ?? (null as number | null),
    condition: props.vehicle?.condition ?? 'foreign_used',
    transmission: props.vehicle?.transmission ?? 'automatic',
    fuel: props.vehicle?.fuel ?? 'petrol',
    engine_cc: props.vehicle?.engine_cc ?? (null as number | null),
    drivetrain: props.vehicle?.drivetrain ?? '',
    colour: props.vehicle?.colour ?? '',
    interior_colour: props.vehicle?.interior_colour ?? '',
    duty_status: props.vehicle?.duty_status ?? '',
    registered: props.vehicle?.registered ?? false,
    description: props.vehicle?.description ?? '',
    feature_ids: props.vehicle?.feature_ids ?? ([] as number[]),
    wizard: isDraft.value,
});

function saveDetails() {
    details
        .transform((data) => ({ ...data, body_type: data.body_type || null, drivetrain: data.drivetrain || null, duty_status: data.duty_status || null }))
        .put(route('dealer.vehicles.details', [lot.value.slug, props.vehicle!.ulid]), { preserveScroll: true });
}

/* ---------- Step 3: photos ---------- */

const readyPhotos = ref(props.vehicle?.media.filter((m) => m.status === 'ready').length ?? 0);

/* ---------- Step 4: price and publish ---------- */

const price = useForm({
    price: props.vehicle?.price ? formatNaira(props.vehicle.price) : '',
    negotiable: props.vehicle?.negotiable ?? true,
    publish: false,
});

function onPriceInput(e: Event) {
    const amount = typedAmount((e.target as HTMLInputElement).value);
    price.price = amount ? formatNaira(amount) : '';
}

function savePrice(publish: boolean) {
    price.publish = publish;
    price.put(route('dealer.vehicles.price', [lot.value.slug, props.vehicle!.ulid]), { preserveScroll: true });
}

const cover = computed(() => props.vehicle?.media.find((m) => m.status === 'ready'));
// The server checks the saved price; a price typed here counts too.
const stillMissing = computed(() => props.missing.filter((m) => m !== 'a price' || !parseAmount(price.price)));

function nextStep() {
    router.visit(
        isDraft.value
            ? route('dealer.vehicles.edit', [lot.value.slug, props.vehicle!.ulid, props.steps[stepIndex.value + 1]])
            : route('dealer.vehicles.index', lot.value.slug),
    );
}
</script>

<template>
    <Head :title="titles[step]" />
    <WizardLayout :close-href="route('dealer.vehicles.index', lot.slug)" :title="titles[step]" :status="statusText" :wide="step === 'photos'">
        <template #steps>
            <StepBar :steps="stepLinks" :current="step" :reached="reached" />
        </template>

        <!-- Step 1: VIN and model -->
        <form v-if="step === 'identity'" id="step-form" class="flex flex-col gap-4" @submit.prevent="saveIdentity">
            <label class="field-label">
                17-character VIN
                <span class="flex gap-2">
                    <input v-field="'vin'"
                        v-model="identity.vin"
                        class="field h-[52px] min-w-0 grow border-2 border-forest text-[17px] font-semibold tracking-wider uppercase"
                        maxlength="20"
                        autocomplete="off"
                        autocapitalize="characters"
                        spellcheck="false"
                        placeholder="e.g. 4T1B11HK8JU654821"
                        @keydown.enter.prevent="decodeVin"
                    />
                    <button type="button" class="btn btn-dark h-[52px] shrink-0 px-4" :disabled="decoding" @click="decodeVin">
                        {{ decoding ? 'Checking…' : 'Look up' }}
                    </button>
                </span>
                <InputError :message="identity.errors.vin" />
            </label>
            <span class="-mt-2 text-[12px] text-muted">Find it on the dashboard through the windscreen, or on the driver's door frame.</span>

            <div v-if="decoded" class="card flex flex-col gap-3.5 p-4">
                <div class="flex items-center gap-2 text-[15px] font-semibold">
                    <Icon name="check" class="text-success" :stroke-width="2.4" /> We found this car
                </div>
                <dl class="grid grid-cols-3 gap-3">
                    <div v-for="[label, value] in [
                        ['Make', makeName ?? decoded.make],
                        ['Model', modelName ?? decoded.model],
                        ['Year', decoded.year],
                        ['Trim', decoded.trim || '—'],
                        ['Engine', decoded.engine_cc ? `${((decoded.engine_cc as number) / 1000).toFixed(1)}L` : '—'],
                        ['Fuel', decoded.fuel ? String(decoded.fuel).replace(/^\w/, (c) => c.toUpperCase()) : '—'],
                    ]" :key="String(label)" class="flex flex-col gap-0.5">
                        <dt class="text-[11px] text-muted">{{ label }}</dt>
                        <dd class="text-[15px] font-semibold">{{ value }}</dd>
                    </div>
                </dl>
                <span class="text-[12px] text-muted">You can change any of these below or on the next step.</span>
            </div>

            <p v-if="decodeMessage" class="rounded-xl bg-cream p-3 text-[14px] text-clay-dark" role="status">{{ decodeMessage }}</p>

            <button v-if="!manual && !decoded" type="button" class="min-h-11 text-center text-[14px] font-semibold text-clay" @click="manual = true">
                No VIN? Choose make and model instead
            </button>

            <div v-if="manual || decoded" class="grid grid-cols-2 gap-3">
                <label class="field-label col-span-2 sm:col-span-1">
                    Make
                    <select v-model.number="identity.make_id" class="field" required @change="(identity.vehicle_model_id = null), (otherModel = false)">
                        <option :value="null" disabled>Choose make</option>
                        <option v-for="make in makes" :key="make.id" :value="make.id">{{ make.name }}</option>
                    </select>
                    <InputError :message="identity.errors.make_id" />
                </label>
                <label class="field-label col-span-2 sm:col-span-1">
                    Model
                    <select
                        class="field"
                        :disabled="!identity.make_id"
                        :value="otherModel ? 'other' : (identity.vehicle_model_id ?? '')"
                        required
                        @change="onModelChange(($event.target as HTMLSelectElement).value)"
                    >
                        <option value="" disabled>Choose model</option>
                        <option v-for="model in models" :key="model.id" :value="model.id">{{ model.name }}</option>
                        <option value="other">Other (type it)</option>
                    </select>
                    <InputError :message="identity.errors.vehicle_model_id" />
                </label>
                <label v-if="otherModel" class="field-label col-span-2">
                    Model name
                    <input v-field="'model'" v-model="identity.model_name" class="field" required placeholder="e.g. Venza" />
                    <InputError :message="identity.errors.model_name" />
                </label>
                <label class="field-label">
                    Year
                    <select v-model.number="identity.year" class="field" required>
                        <option :value="null" disabled>Year</option>
                        <option v-for="year in years" :key="year" :value="year">{{ year }}</option>
                    </select>
                    <InputError :message="identity.errors.year" />
                </label>
                <label class="field-label">
                    <span>Trim <span class="font-normal text-muted">(optional)</span></span>
                    <input v-field="'model'" v-model="identity.trim" class="field" placeholder="e.g. SE, XLE" />
                    <InputError :message="identity.errors.trim" />
                </label>
            </div>
        </form>

        <!-- Step 2: details -->
        <form v-else-if="step === 'details'" id="step-form" class="flex flex-col gap-5" @submit.prevent="saveDetails">
            <div v-if="vehicle" class="card flex items-center gap-2.5 px-3.5 py-3">
                <Icon name="check" class="text-success" :stroke-width="2.4" />
                <div class="flex flex-col">
                    <span class="text-[15px] font-semibold">{{ vehicle.title }}</span>
                    <span v-if="vehicle.vin" class="text-[12px] text-muted">VIN ···{{ vehicle.vin.slice(-4) }}</span>
                </div>
            </div>

            <div class="grid grid-cols-2 gap-3">
                <label class="field-label col-span-2 sm:col-span-1">
                    Mileage (km)
                    <input v-field="{ kind: 'count', min: 0, max: 2000000 }"
                        :value="formatNumber(details.mileage_km)"
                        class="field"
                        inputmode="numeric"
                        required
                        placeholder="e.g. 48,200"
                        @input="details.mileage_km = typedAmount(($event.target as HTMLInputElement).value)"
                    />
                    <InputError :message="details.errors.mileage_km" />
                </label>
                <label class="field-label col-span-2 sm:col-span-1">
                    Condition
                    <select v-model="details.condition" class="field" required>
                        <option v-for="o in options.conditions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                    <InputError :message="details.errors.condition" />
                </label>
                <label class="field-label">
                    Transmission
                    <select v-model="details.transmission" class="field" required>
                        <option v-for="o in options.transmissions" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
                <label class="field-label">
                    Fuel
                    <select v-model="details.fuel" class="field" required>
                        <option v-for="o in options.fuels" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
                <label class="field-label">
                    Body type
                    <select v-model="details.body_type" class="field">
                        <option value="">Not set</option>
                        <option v-for="o in options.body_types" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
                <label class="field-label">
                    Engine (cc)
                    <input v-model.number="details.engine_cc" type="number" min="500" max="10000" step="100" class="field" inputmode="numeric" placeholder="e.g. 2500" />
                    <InputError :message="details.errors.engine_cc" />
                </label>
                <label class="field-label">
                    Drivetrain
                    <select v-model="details.drivetrain" class="field">
                        <option value="">Not set</option>
                        <option v-for="o in options.drivetrains" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
                <label class="field-label">
                    Customs duty
                    <select v-model="details.duty_status" class="field">
                        <option value="">Not set</option>
                        <option v-for="o in options.duty_statuses" :key="o.value" :value="o.value">{{ o.label }}</option>
                    </select>
                </label>
                <label class="field-label">
                    Colour
                    <input v-field="{ kind: 'place', max: 40 }" v-model="details.colour" class="field" placeholder="e.g. Silver" />
                </label>
                <label class="field-label">
                    Interior colour
                    <input v-field="{ kind: 'place', max: 40 }" v-model="details.interior_colour" class="field" placeholder="e.g. Black leather" />
                </label>
                <label class="col-span-2 flex h-11 items-center gap-3 text-[15px]">
                    <input v-model="details.registered" type="checkbox" class="h-5 w-5 accent-forest" />
                    Registered in Nigeria (has plate number)
                </label>
            </div>

            <fieldset v-for="group in options.features" :key="group.group" class="flex flex-col gap-2">
                <legend class="mb-2 text-[13px] font-semibold">{{ group.group }} features</legend>
                <div class="flex flex-wrap gap-2">
                    <label
                        v-for="feature in group.items"
                        :key="feature.id"
                        class="flex h-10 cursor-pointer items-center rounded-full border border-line bg-white px-3.5 text-[14px] has-[:checked]:border-forest has-[:checked]:bg-forest has-[:checked]:text-white has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-forest/30"
                    >
                        <input v-model="details.feature_ids" type="checkbox" :value="feature.id" class="sr-only" />
                        {{ feature.name }}
                    </label>
                </div>
            </fieldset>

            <label class="field-label">
                <span>Description <span class="font-normal text-muted">(optional)</span></span>
                <textarea v-field="{ kind: 'text', max: 3000 }"
                    v-model="details.description"
                    rows="4"
                    maxlength="3000"
                    class="field h-auto py-3"
                    placeholder="Service history, anything fixed or replaced, why it's a good buy."
                />
                <InputError :message="details.errors.description" />
            </label>
        </form>

        <!-- Step 3: photos -->
        <div v-else-if="step === 'photos' && vehicle" class="flex flex-col gap-4">
            <div class="card flex items-center gap-2.5 px-3.5 py-3">
                <Icon name="check" class="text-success" :stroke-width="2.4" />
                <span class="text-[15px] font-semibold">{{ vehicle.title }}</span>
            </div>
            <PhotoUploader :lot-slug="lot.slug" :vehicle-ulid="vehicle.ulid" :initial="vehicle.media" :max="maxPhotos" @change="readyPhotos = $event" />
        </div>

        <!-- Step 4: price and publish -->
        <form v-else-if="step === 'price' && vehicle" id="step-form" class="flex flex-col gap-4" @submit.prevent="savePrice(false)">
            <label class="field-label">
                Asking price
                <input v-field="{ kind: 'money', min: 10000 }"
                    :value="price.price"
                    class="field h-[60px] border-2 border-forest font-display text-[26px] font-bold"
                    inputmode="numeric"
                    required
                    placeholder="₦0"
                    :disabled="!canChangePrice"
                    @input="onPriceInput"
                />
                <InputError :message="price.errors.price" />
                <span v-if="guide" class="rounded-xl bg-ivory px-3 py-2.5 font-normal">
                    <strong>Pricing guide:</strong> {{ guide.count }} similar cars on LotLink are listed from {{ formatNaira(guide.low) }} to {{ formatNaira(guide.high) }}, median
                    <strong>{{ formatNaira(guide.median) }}</strong>.
                    <template v-if="parseAmount(price.price) && parseAmount(price.price)! > guide.median * 1.15"> Yours is above most of them.</template>
                    <template v-else-if="parseAmount(price.price) && parseAmount(price.price)! < guide.median * 0.85"> Yours is below most of them.</template>
                </span>
                <span v-if="!canChangePrice" class="font-normal text-muted">Ask the owner or a manager to change the price of a live car.</span>
            </label>

            <div class="card flex gap-3.5 p-3.5">
                <div class="h-[92px] w-[120px] shrink-0 overflow-hidden rounded-xl bg-sand">
                    <img v-if="cover" :src="cover.urls['400'] ?? cover.thumb_url ?? ''" alt="" class="h-full w-full object-cover" />
                </div>
                <div class="flex min-w-0 flex-col gap-1">
                    <span class="text-[15px] font-semibold">{{ vehicle.title }}</span>
                    <span class="font-display text-[20px] font-bold text-forest">{{ price.price || '₦—' }}</span>
                    <span class="text-[13px] text-muted">{{ lot.name }}</span>
                </div>
            </div>

            <label class="flex items-center justify-between gap-3 border-t border-divider py-3 text-[14px]">
                Price is negotiable
                <input v-model="price.negotiable" type="checkbox" role="switch" class="peer sr-only" />
                <span class="relative h-[26px] w-11 shrink-0 rounded-full bg-line-strong transition peer-checked:bg-forest peer-focus-visible:ring-2 peer-focus-visible:ring-forest/30 after:absolute after:top-[3px] after:left-[3px] after:h-5 after:w-5 after:rounded-full after:bg-white after:transition peer-checked:after:translate-x-[18px]" />
            </label>

            <div v-if="stillMissing.length && isDraft" class="rounded-2xl bg-cream px-3.5 py-3 text-[14px] text-clay-dark">
                <strong>Before you publish, add:</strong> {{ stillMissing.join(', ') }}.
            </div>
            <InputError :message="(price.errors as Record<string, string>).publish" />

            <p v-if="lot.status !== 'active'" class="text-[13px] text-muted">
                Your lot is still being reviewed. Published cars go on the marketplace as soon as it's approved.
            </p>
        </form>

        <template #actions>
            <Link v-if="step !== 'identity'" :href="backHref" class="btn btn-outline h-[52px] rounded-[14px] px-5">Back</Link>

            <template v-if="step === 'identity'">
                <button form="step-form" type="submit" class="btn btn-primary h-[52px] grow rounded-[14px]" :disabled="identity.processing || !(manual || decoded)">
                    {{ !vehicle ? 'Looks right, continue' : isDraft ? 'Save and continue' : 'Save changes' }}
                </button>
            </template>
            <button v-else-if="step === 'details'" form="step-form" type="submit" class="btn btn-primary h-[52px] grow rounded-[14px]" :disabled="details.processing">
                {{ isDraft ? 'Continue to photos' : 'Save changes' }}
            </button>
            <button v-else-if="step === 'photos'" type="button" class="btn btn-primary h-[52px] grow rounded-[14px]" @click="nextStep">
                {{ isDraft ? 'Continue to price' : 'Done' }}
            </button>
            <template v-else-if="step === 'price'">
                <template v-if="isDraft">
                    <button type="button" class="btn btn-outline h-[52px] rounded-[14px] px-4" :disabled="price.processing" @click="savePrice(false)">Save draft</button>
                    <button type="button" class="btn btn-primary h-[52px] grow rounded-[14px]" :disabled="price.processing || stillMissing.length > 0" @click="savePrice(true)">
                        Publish now
                    </button>
                </template>
                <button v-else form="step-form" type="submit" class="btn btn-primary h-[52px] grow rounded-[14px]" :disabled="price.processing || !canChangePrice">Save changes</button>
            </template>
        </template>
    </WizardLayout>
</template>
