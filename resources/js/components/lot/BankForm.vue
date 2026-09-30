<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { router, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export type BankAccount = { ulid: string; bank_name: string; account_number: string; account_name: string; is_default: boolean };
export type BankState = { accounts: BankAccount[]; can_edit: boolean; banks: string[]; max: number };

const props = defineProps<{ lotSlug: string; bank: BankState }>();

const editing = ref<string | 'new' | null>(props.bank.accounts.length === 0 && props.bank.can_edit ? 'new' : null);
const form = useForm({ bank_name: '', account_number: '', account_name: '', is_default: false });

function start(account: BankAccount | null) {
    form.clearErrors();
    if (account) {
        form.defaults({ bank_name: account.bank_name, account_number: account.account_number, account_name: account.account_name, is_default: account.is_default });
        editing.value = account.ulid;
    } else {
        form.defaults({ bank_name: '', account_number: '', account_name: '', is_default: false });
        editing.value = 'new';
    }
    form.reset();
}

function save() {
    const done = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') form.post(route('dealer.bank-accounts.store', props.lotSlug), done);
    else form.put(route('dealer.bank-accounts.update', [props.lotSlug, editing.value]), done);
}

function remove(account: BankAccount) {
    if (!confirm(`Remove ${account.bank_name} ${account.account_number}? Customers won't see it any more.`)) return;
    router.delete(route('dealer.bank-accounts.destroy', [props.lotSlug, account.ulid]), { preserveScroll: true });
}

function makeDefault(account: BankAccount) {
    router.put(route('dealer.bank-accounts.update', [props.lotSlug, account.ulid]), { ...account, is_default: true }, { preserveScroll: true });
}
</script>

<template>
    <div class="flex flex-col gap-4">
        <div>
            <h2 class="font-sans text-[16px] font-bold">Bank details for customers</h2>
            <p class="text-[14px] text-muted">
                Customers pay your lot directly: LotLink never collects money for cars. Your team can send these details from an order, a chat or a reservation when the
                customer is ready to pay.
            </p>
        </div>

        <p class="flex gap-2 rounded-xl bg-cream px-3.5 py-2.5 text-[13px] text-clay-dark">
            <Icon name="shield" :size="18" class="shrink-0" />
            <span>Use an account in the business's name where you can; buyers trust it more. The owner and managers are told whenever these details change.</span>
        </p>

        <ul v-if="bank.accounts.length" class="flex flex-col gap-2">
            <li v-for="a in bank.accounts" :key="a.ulid" class="flex flex-wrap items-center gap-3 rounded-xl border border-line bg-white p-3.5">
                <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-map text-forest"><Icon name="card" :size="20" /></span>
                <span class="flex min-w-0 grow flex-col">
                    <span class="flex flex-wrap items-center gap-2 text-[15px] font-semibold">
                        {{ a.bank_name }} · <span class="tabular-nums">{{ a.account_number }}</span>
                        <span v-if="a.is_default" class="rounded-lg bg-forest px-2 py-0.5 text-[11px] font-semibold text-white">Shown first</span>
                    </span>
                    <span class="text-[13px] text-muted">{{ a.account_name }}</span>
                </span>
                <span v-if="bank.can_edit" class="flex gap-1">
                    <button v-if="!a.is_default" type="button" class="min-h-11 px-2 text-[13px] font-semibold text-forest" @click="makeDefault(a)">Show first</button>
                    <button type="button" class="min-h-11 px-2 text-[13px] font-semibold" @click="start(a)">Edit</button>
                    <button type="button" class="min-h-11 px-2 text-[13px] font-semibold text-muted hover:text-danger" @click="remove(a)">Remove</button>
                </span>
            </li>
        </ul>
        <p v-else-if="!bank.can_edit" class="text-[14px] text-muted">No bank details yet. Ask the lot owner to add them.</p>

        <form v-if="editing" class="flex flex-col gap-3 rounded-xl border border-line bg-ivory p-4" @submit.prevent="save">
            <h3 class="font-sans text-[15px] font-bold">{{ editing === 'new' ? 'Add an account' : 'Edit account' }}</h3>
            <div class="grid gap-3 sm:grid-cols-2">
                <label class="field-label">
                    Bank
                    <input v-field="{ kind: 'business_name', max: 80 }" v-model="form.bank_name" class="field h-11" list="lotlink-banks" required placeholder="e.g. GTBank" autocomplete="off" />
                    <datalist id="lotlink-banks"><option v-for="b in bank.banks" :key="b" :value="b" /></datalist>
                    <InputError :message="form.errors.bank_name" />
                </label>
                <label class="field-label">
                    Account number
                    <input v-field="'account_number'" v-model="form.account_number" class="field h-11 tabular-nums" inputmode="numeric" required placeholder="10 digits" autocomplete="off" />
                    <InputError :message="form.errors.account_number" />
                </label>
            </div>
            <label class="field-label">
                Account name
                <input v-field="'business_name'" v-model="form.account_name" class="field h-11" required placeholder="As it appears on the account, e.g. Prime Motors Ltd" />
                <InputError :message="form.errors.account_name" />
            </label>
            <label class="flex min-h-11 items-center gap-2 text-[14px]">
                <input v-model="form.is_default" type="checkbox" class="h-5 w-5 accent-forest" /> Show this account first
            </label>
            <div class="flex gap-2">
                <button type="submit" class="btn btn-primary h-11" :disabled="form.processing">Save account</button>
                <button v-if="bank.accounts.length" type="button" class="btn btn-outline h-11" @click="editing = null">Cancel</button>
            </div>
        </form>
        <button v-else-if="bank.can_edit && bank.accounts.length < bank.max" type="button" class="btn btn-outline h-11 self-start" @click="start(null)">
            <Icon name="plus" :size="18" /> Add another account
        </button>
        <p v-if="!bank.can_edit && bank.accounts.length" class="text-[13px] text-muted">Only the lot owner can change these.</p>
    </div>
</template>
