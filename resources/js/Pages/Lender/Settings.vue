<script setup lang="ts">
import LenderProductFields from '@/components/finance/LenderProductFields.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import LenderLayout from '@/layouts/LenderLayout.vue';
import type { SharedProps } from '@/types';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    values: {
        name: string;
        licence_type: string;
        licence_number: string;
        contact_name: string;
        contact_email: string;
        contact_phone: string;
        website: string;
        about: string;
        rate: number;
        min_amount: string;
        max_amount: string;
        min_deposit_percent: number;
        tenors: number[];
        states: string[];
        integration: 'portal' | 'api';
        api_url: string;
    };
    has_key: boolean;
    has_secret: boolean;
    webhook: { url: string; secret: string | null };
    demo: boolean;
    types: { value: string; label: string }[];
    tenors: number[];
    can_manage: boolean;
}>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.currentLender!.slug);
const form = useForm({ ...props.values, api_key: '' });
const copied = ref(false);

function save() {
    form.put(route('lender.settings.update', slug.value), { preserveScroll: true, onSuccess: () => form.reset('api_key') });
}

function rotate() {
    router.post(route('lender.settings.secret', slug.value), {}, { preserveScroll: true });
}

async function copy(text: string) {
    try {
        await navigator.clipboard.writeText(text);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        copied.value = false;
    }
}
</script>

<template>
    <Head title="Settings" />
    <LenderLayout>
        <div>
            <h1 class="text-[30px] font-bold">Settings</h1>
            <p class="text-[14px] text-muted">{{ values.name }} · {{ types.find((t) => t.value === values.licence_type)?.label }} {{ values.licence_number }}. To change these, contact LotLink.</p>
        </div>
        <p v-if="!can_manage" class="rounded-xl bg-sand px-4 py-3 text-[14px]">Only your team's admins can change settings.</p>

        <form class="flex flex-col gap-5" @submit.prevent="save">
            <fieldset class="card flex flex-col gap-4 p-5" :disabled="!can_manage">
                <legend class="sr-only">Contact</legend>
                <h2 class="font-sans text-[17px] font-bold">Contact and profile</h2>
                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field-label">
                        Contact person
                        <input v-field="'person_name'" v-model="form.contact_name" class="field h-11" required />
                        <InputError :message="form.errors.contact_name" />
                    </label>
                    <label class="field-label">
                        Contact phone
                        <input v-field="'phone'" v-model="form.contact_phone" type="tel" class="field h-11" required />
                        <InputError :message="form.errors.contact_phone" />
                    </label>
                    <label class="field-label">
                        Contact email
                        <input v-field="'email'" v-model="form.contact_email" type="email" class="field h-11" required />
                        <InputError :message="form.errors.contact_email" />
                    </label>
                    <label class="field-label">
                        Website
                        <input v-field="{ kind: 'text', max: 190 }" v-model="form.website" type="url" class="field h-11" placeholder="https://" />
                        <InputError :message="form.errors.website" />
                    </label>
                    <label class="field-label sm:col-span-2">
                        About your car loans <span class="font-normal text-muted">(buyers see this)</span>
                        <textarea v-field="{ kind: 'text', max: 600 }" v-model="form.about" rows="3" maxlength="600" class="field h-auto py-2.5" />
                        <InputError :message="form.errors.about" />
                    </label>
                </div>
            </fieldset>

            <section class="card flex flex-col gap-4 p-5">
                <h2 class="font-sans text-[17px] font-bold">Car loan</h2>
                <p class="text-[14px] text-muted">Buyers only see you for cars and terms that fit these.</p>
                <LenderProductFields :form="form" :tenors="tenors" :min-loan="100000" :disabled="!can_manage" />
            </section>

            <fieldset v-if="!demo" class="card flex flex-col gap-4 p-5" :disabled="!can_manage">
                <legend class="sr-only">How applications reach you</legend>
                <h2 class="font-sans text-[17px] font-bold">How applications reach you</h2>
                <div class="grid gap-2 sm:grid-cols-2">
                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border p-3" :class="form.integration === 'portal' ? 'border-forest bg-map' : 'border-line'">
                        <input v-model="form.integration" type="radio" value="portal" class="mt-1 h-5 w-5 accent-forest" />
                        <span><strong>In this portal</strong><br /><span class="text-[13px] text-muted">Your team gets a notification and works them here.</span></span>
                    </label>
                    <label class="flex min-h-11 cursor-pointer items-start gap-3 rounded-xl border p-3" :class="form.integration === 'api' ? 'border-forest bg-map' : 'border-line'">
                        <input v-model="form.integration" type="radio" value="api" class="mt-1 h-5 w-5 accent-forest" />
                        <span><strong>Our own system (API)</strong><br /><span class="text-[13px] text-muted">We post each application to you; you send updates back.</span></span>
                    </label>
                </div>
                <div v-if="form.integration === 'api'" class="grid gap-4 sm:grid-cols-2">
                    <label class="field-label">
                        API address
                        <input v-field="{ kind: 'text', max: 255 }" v-model="form.api_url" type="url" class="field h-11" placeholder="https://api.example.com/lotlink" />
                        <InputError :message="form.errors.api_url" />
                    </label>
                    <label class="field-label">
                        API key {{ has_key ? '(saved; leave blank to keep it)' : '' }}
                        <input v-field="{ kind: 'text', max: 255 }" v-model="form.api_key" type="password" autocomplete="off" class="field h-11" />
                        <InputError :message="form.errors.api_key" />
                    </label>
                    <div class="flex flex-col gap-1.5 text-[14px] sm:col-span-2">
                        <span class="field-label">Send updates to</span>
                        <code class="rounded-lg bg-sand px-3 py-2 break-all">{{ webhook.url }}</code>
                        <span class="text-[13px] text-muted">Sign each body with HMAC-SHA256 using your webhook secret, in the <code>X-LotLink-Signature</code> header. The API guide is in LotLink's docs.</span>
                    </div>
                    <div v-if="webhook.secret" class="flex flex-col gap-1.5 rounded-xl bg-cream p-3 text-[14px] sm:col-span-2" role="status">
                        <strong>Your webhook secret (shown once):</strong>
                        <code class="break-all">{{ webhook.secret }}</code>
                        <button type="button" class="btn btn-outline h-11 self-start" @click="copy(webhook.secret)"><Icon name="copy" :size="16" /> {{ copied ? 'Copied' : 'Copy' }}</button>
                    </div>
                    <button v-else-if="has_secret && can_manage" type="button" class="btn btn-outline h-11 self-start" @click="rotate">Make a new webhook secret</button>
                </div>
            </fieldset>

            <button v-if="can_manage" type="submit" class="btn btn-primary h-12 self-start" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save settings' }}</button>
        </form>
    </LenderLayout>
</template>
