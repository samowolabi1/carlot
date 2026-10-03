<script setup lang="ts">
import LenderProductFields from '@/components/finance/LenderProductFields.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import CustomerLayout from '@/layouts/CustomerLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    mine: { slug: string; name: string; status: string; status_label: string; note: string | null }[];
    types: { value: string; label: string }[];
    tenors: number[];
    licence: { max_kb: number; mimes: string[] };
    min_loan: number;
}>();

const { user } = useShared();
const individual = computed(() => form.licence_type === 'individual');
const picker = ref<HTMLInputElement | null>(null);

const form = useForm<{
    name: string;
    licence_type: string;
    licence_number: string;
    contact_name: string;
    contact_email: string;
    contact_phone: string;
    website: string;
    about: string;
    next_steps: string;
    rate: number | string;
    min_amount: string;
    max_amount: string;
    min_deposit_percent: number | string;
    tenors: number[];
    states: string[];
    licence: File | null;
    agree: boolean;
}>({
    name: '',
    licence_type: '',
    licence_number: '',
    contact_name: user.value?.name ?? '',
    contact_email: user.value?.email ?? '',
    contact_phone: '',
    website: '',
    about: '',
    next_steps: '',
    rate: 24,
    min_amount: '1000000',
    max_amount: '30000000',
    min_deposit_percent: 20,
    tenors: [12, 24, 36],
    states: [],
    licence: null,
    agree: false,
});

function pick(e: Event) {
    form.licence = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function submit() {
    form.post(route('lenders.store'), { forceFormData: true, preserveScroll: true });
}

const perks = [
    { icon: 'leads', title: 'Buyers who already chose a car', text: 'Each application comes with the car, the lot, the price, the deposit and the term.' },
    { icon: 'shield', title: "With the buyer's consent", text: 'Buyers agree to share their income and work details with you, and only you.' },
    { icon: 'chat', title: 'Decide here, finish with you', text: 'Review, ask for documents and pre-approve or approve here; the buyer then finishes with you (KYC, agreement, payment).' },
    { icon: 'settings', title: 'Or use your own system', text: 'Receive applications by API and send updates back to a signed webhook.' },
] as const;
</script>

<template>
    <Head title="Lend with LotLink" />
    <CustomerLayout>
        <div class="mx-auto flex max-w-3xl flex-col gap-6 px-5 py-6">
            <header class="flex flex-col gap-2">
                <h1 class="text-[30px] leading-tight font-bold">Lend with LotLink</h1>
                <p class="text-[16px] text-[#4A4D53]">
                    Banks, microfinance banks, finance companies, licensed money lenders and individual lenders: get car loan applications from buyers on LotLink and work them in the lender portal. LotLink doesn't lend and
                    never takes a cut of the car's price.
                </p>
            </header>

            <ul class="grid gap-3 sm:grid-cols-2">
                <li v-for="p in perks" :key="p.title" class="card flex gap-3 p-4">
                    <Icon :name="p.icon" :size="22" class="shrink-0 text-clay" />
                    <span class="flex flex-col gap-0.5">
                        <strong class="text-[15px]">{{ p.title }}</strong>
                        <span class="text-[14px] text-[#4A4D53]">{{ p.text }}</span>
                    </span>
                </li>
            </ul>

            <section v-if="mine.length" class="card flex flex-col gap-2 p-5" aria-labelledby="mine-title">
                <h2 id="mine-title" class="font-sans text-[17px] font-bold">Your lender accounts</h2>
                <Link
                    v-for="l in mine"
                    :key="l.slug"
                    :href="route('lender.dashboard', l.slug)"
                    class="flex min-h-11 items-center justify-between gap-3 rounded-xl border border-line px-3 py-2 text-ink no-underline"
                >
                    <span class="flex flex-col">
                        <strong>{{ l.name }}</strong>
                        <span v-if="l.note && l.status !== 'active'" class="text-[13px] text-muted">{{ l.note }}</span>
                    </span>
                    <span class="flex items-center gap-1 text-[13px] font-semibold">{{ l.status_label }} <Icon name="chevronRight" :size="16" /></span>
                </Link>
            </section>

            <section v-if="!user" class="card flex flex-col items-start gap-3 p-5">
                <h2 class="font-sans text-[17px] font-bold">Apply to become a lender</h2>
                <p class="text-[14px] text-[#4A4D53]">Sign in (or create an account with your work email) first. You'll come back here to fill in your details.</p>
                <Link :href="route('lender.home')" class="btn btn-primary h-11">Sign in to apply</Link>
            </section>

            <form v-else class="card flex flex-col gap-5 p-5" aria-labelledby="apply-title" @submit.prevent="submit">
                <h2 id="apply-title" class="font-sans text-[19px] font-bold">Apply to become a lender</h2>

                <div class="grid gap-4 sm:grid-cols-2">
                    <label class="field-label sm:col-span-2">
                        {{ individual ? 'Your full name (as on your ID)' : 'Company name' }}
                        <input v-field="'business_name'" v-model="form.name" class="field h-11" required />
                        <InputError :message="form.errors.name" />
                    </label>
                    <label class="field-label">
                        Type of lender
                        <select v-model="form.licence_type" class="field h-11" required>
                            <option value="" disabled>Choose</option>
                            <option v-for="t in types" :key="t.value" :value="t.value">{{ t.label }}</option>
                        </select>
                        <InputError :message="form.errors.licence_type" />
                    </label>
                    <label class="field-label">
                        {{ individual ? 'NIN, or moneylender licence number' : 'Licence number' }}
                        <input v-field="{ kind: 'reference', max: 40 }" v-model="form.licence_number" class="field h-11" required />
                        <InputError :message="form.errors.licence_number" />
                    </label>
                    <label class="field-label">
                        Contact person
                        <input v-field="'person_name'" v-model="form.contact_name" class="field h-11" required autocomplete="name" />
                        <InputError :message="form.errors.contact_name" />
                    </label>
                    <label class="field-label">
                        Contact phone
                        <input v-field="'phone'" v-model="form.contact_phone" type="tel" class="field h-11" required autocomplete="tel" />
                        <InputError :message="form.errors.contact_phone" />
                    </label>
                    <label class="field-label">
                        Contact email
                        <input v-field="'email'" v-model="form.contact_email" type="email" class="field h-11" required autocomplete="email" />
                        <InputError :message="form.errors.contact_email" />
                    </label>
                    <label class="field-label">
                        <span>Website <span class="font-normal text-muted">(optional)</span></span>
                        <input v-field="{ kind: 'text', max: 190 }" v-model="form.website" type="url" class="field h-11" placeholder="https://" />
                        <InputError :message="form.errors.website" />
                    </label>
                    <label class="field-label sm:col-span-2">
                        <span>About your car loans <span class="font-normal text-muted">(buyers see this)</span></span>
                        <textarea v-field="{ kind: 'text', max: 600 }" v-model="form.about" rows="3" maxlength="600" class="field h-auto py-2.5" />
                        <InputError :message="form.errors.about" />
                    </label>
                </div>

                <div class="flex flex-col gap-3 border-t border-line pt-4">
                    <h3 class="font-sans text-[16px] font-bold">Your car loan</h3>
                    <LenderProductFields :form="form" :tenors="tenors" :min-loan="min_loan" />
                </div>

                <div class="flex flex-col gap-1.5 border-t border-line pt-4">
                    <span class="field-label">{{ individual ? 'Your ID (NIN slip, passport or driver\'s licence) or moneylender licence' : 'Licence (CBN or state moneylender licence)' }}</span>
                    <input ref="picker" type="file" accept="application/pdf,image/jpeg,image/png" class="sr-only" tabindex="-1" aria-hidden="true" @change="pick" />
                    <div class="flex flex-wrap items-center gap-2">
                        <button type="button" class="btn btn-outline h-11" @click="picker?.click()"><Icon name="upload" :size="18" /> {{ form.licence ? 'Change file' : 'Add a copy' }}</button>
                        <span v-if="form.licence" class="text-[13px]">{{ form.licence.name }}</span>
                    </div>
                    <span class="text-[12px] text-muted">PDF or photo, up to {{ Math.round(props.licence.max_kb / 1024) }} MB. Only LotLink's team sees it.</span>
                    <InputError :message="form.errors.licence" />
                </div>

                <label class="field-label">
                    <span>Next steps with you <span class="font-normal text-muted">(optional; shown to buyers you approve)</span></span>
                    <textarea v-field="{ kind: 'text', max: 1000 }" v-model="form.next_steps" rows="2" maxlength="1000" class="field h-auto py-2.5" />
                    <InputError :message="form.errors.next_steps" />
                </label>

                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3 text-[14px]">
                    <input v-model="form.agree" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-forest" />
                    <span>
                        I have authority to act for this company and agree to the <Link :href="route('legal.show', 'lender-terms')" target="_blank">Lender Terms</Link>: we hold the
                        licence above, will use buyers' details only to consider their car loan, will tell buyers our decision through LotLink, and complete loans (KYC, agreement,
                        payment) ourselves, outside LotLink.
                    </span>
                </label>
                <InputError :message="form.errors.agree" />

                <button type="submit" class="btn btn-primary h-12 self-start" :disabled="form.processing || !form.agree">{{ form.processing ? 'Sending…' : 'Send for approval' }}</button>
            </form>
        </div>
    </CustomerLayout>
</template>
