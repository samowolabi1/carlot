<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { computed, ref } from 'vue';

export type BankDetails = { bank_name: string; account_number: string; account_name: string };

/**
 * A seller's bank details with one-tap copy, for customers paying the seller directly
 * (CarYard never takes payment for cars). Optional amount and reference for the transfer.
 */
const props = withDefaults(
    defineProps<{ account: BankDetails; amount?: string | null; reference?: string | null; shareText?: string | null; title?: string; whatsapp?: string | null }>(),
    { amount: null, reference: null, shareText: null, title: 'Pay by bank transfer', whatsapp: null },
);

const copied = ref<string | null>(null);

const rows = computed(() =>
    [
        { key: 'bank', label: 'Bank', value: props.account.bank_name, copy: false },
        { key: 'number', label: 'Account number', value: props.account.account_number, copy: true },
        { key: 'name', label: 'Account name', value: props.account.account_name, copy: false },
        props.amount ? { key: 'amount', label: 'Amount', value: props.amount, copy: true } : null,
        props.reference ? { key: 'reference', label: 'Reference / narration', value: props.reference, copy: true } : null,
    ].filter((r) => r !== null),
);

async function copy(key: string, value: string) {
    try {
        await navigator.clipboard.writeText(key === 'amount' ? value.replace(/[^\d]/g, '') : value);
        copied.value = key;
        setTimeout(() => (copied.value = copied.value === key ? null : copied.value), 2000);
    } catch {
        copied.value = null;
    }
}

const waHref = computed(() => (props.shareText ? `https://wa.me/${props.whatsapp ?? ''}?text=${encodeURIComponent(props.shareText)}` : null));
</script>

<template>
    <section class="card flex flex-col gap-3 p-4" :aria-label="title">
        <h2 class="flex items-center gap-2 font-sans text-[16px] font-bold"><Icon name="card" :size="20" class="text-forest" /> {{ title }}</h2>
        <dl class="flex flex-col divide-y divide-divider">
            <div v-for="row in rows" :key="row.key" class="flex items-center justify-between gap-3 py-2">
                <div class="flex min-w-0 flex-col">
                    <dt class="text-[12px] text-muted">{{ row.label }}</dt>
                    <dd class="truncate text-[16px] font-semibold" :class="{ 'font-display tabular-nums tracking-wide': row.key === 'number' || row.key === 'reference' }">{{ row.value }}</dd>
                </div>
                <button
                    v-if="row.copy"
                    type="button"
                    class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-lg px-2.5 text-[13px] font-semibold text-forest hover:bg-map"
                    :aria-label="`Copy ${row.label.toLowerCase()}`"
                    @click="copy(row.key, row.value)"
                >
                    <Icon :name="copied === row.key ? 'check' : 'copy'" :size="16" /> {{ copied === row.key ? 'Copied' : 'Copy' }}
                </button>
            </div>
        </dl>
        <div v-if="shareText" class="flex flex-wrap gap-2">
            <button type="button" class="btn btn-outline h-11" @click="copy('all', shareText!)"><Icon :name="copied === 'all' ? 'check' : 'copy'" :size="16" /> {{ copied === 'all' ? 'Copied' : 'Copy all' }}</button>
            <a v-if="waHref" :href="waHref" target="_blank" rel="noopener" class="btn h-11 border-[#25D366] bg-[#25D366] text-[#0B3D1F] no-underline hover:text-[#0B3D1F]">
                <Icon name="whatsapp" :size="18" /> Send on WhatsApp
            </a>
        </div>
        <slot />
    </section>
</template>
