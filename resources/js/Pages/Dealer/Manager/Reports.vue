<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { formatNaira } from '@/lib/format';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

type Column = { key: string; label: string; money?: boolean; percent?: boolean };
type Value = string | number | null;

const props = defineProps<{
    type: string;
    period: string;
    types: { value: string; label: string; locked: boolean }[];
    periods: { value: string; label: string }[];
    report: { title: string; columns: Column[]; rows: Record<string, Value>[]; totals: Record<string, Value> | null };
    pro: boolean;
    hasPeriod: boolean;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);

const show = (c: Column, v: Value) => {
    if (v === '' || v === null) return '';
    if (c.money && typeof v === 'number') return formatNaira(v);
    if (c.percent) return `${v}%`;
    return typeof v === 'number' ? v.toLocaleString('en-NG') : v;
};

const go = (params: Record<string, string>) => router.get(route('dealer.manager.reports', { lot: lot.value.slug, type: props.type, period: props.period, ...params }), {}, { preserveScroll: true });
const exportUrl = computed(() => route('dealer.manager.reports', { lot: lot.value.slug, type: props.type, period: props.period, export: 'xlsx' }));
</script>

<template>
    <Head title="Reports" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-[30px] font-bold">Reports</h1>
                <p class="text-[14px] text-muted">Sales, balances, walk-ins and staff from Lot Manager</p>
            </div>
            <a v-if="pro" :href="exportUrl" class="btn btn-outline h-11 text-[14px]"><Icon name="download" :size="18" /> Excel</a>
            <Link v-else :href="route('dealer.billing', lot.slug)" class="text-[14px] font-semibold">Profit, staff report and Excel export come with Pro</Link>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div role="tablist" aria-label="Report" class="flex flex-wrap gap-1 rounded-xl bg-[#EAE6DE] p-1">
                <button
                    v-for="t in types"
                    :key="t.value"
                    type="button"
                    role="tab"
                    :aria-selected="type === t.value"
                    :disabled="t.locked"
                    class="h-9 rounded-[9px] px-3.5 text-[13px] disabled:opacity-50"
                    :class="type === t.value ? 'bg-white font-semibold text-ink' : 'font-medium text-muted'"
                    :title="t.locked ? 'Comes with the Pro plan' : undefined"
                    @click="go({ type: t.value })"
                >
                    {{ t.label }}
                </button>
            </div>
            <label v-if="hasPeriod" class="flex items-center gap-2 text-[14px]">
                <span class="sr-only">Period</span>
                <select :value="period" class="field h-11 w-auto" @change="go({ period: ($event.target as HTMLSelectElement).value })">
                    <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
                </select>
            </label>
        </div>

        <section class="card overflow-x-auto" :aria-label="report.title">
            <p v-if="report.rows.length === 0" class="px-5 py-10 text-center text-[15px] text-muted">Nothing to show for this period yet.</p>
            <table v-else class="w-full min-w-[640px] text-left text-[14px]">
                <thead>
                    <tr class="border-b border-divider text-[12px] text-muted uppercase">
                        <th v-for="c in report.columns" :key="c.key" scope="col" class="px-4 py-3 font-semibold" :class="{ 'text-right': c.money || c.percent || typeof report.rows[0][c.key] === 'number' }">{{ c.label }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-divider">
                    <tr v-for="(row, i) in report.rows" :key="i">
                        <td v-for="c in report.columns" :key="c.key" class="px-4 py-2.5" :class="{ 'text-right tabular-nums': typeof row[c.key] === 'number', 'text-danger': c.key === 'overdue' && Number(row[c.key]) > 0 }">
                            {{ show(c, row[c.key]) }}
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="report.totals">
                    <tr class="border-t-2 border-line font-semibold">
                        <td v-for="c in report.columns" :key="c.key" class="px-4 py-3" :class="{ 'text-right tabular-nums': typeof report.totals[c.key] === 'number' }">{{ show(c, report.totals[c.key]) }}</td>
                    </tr>
                </tfoot>
            </table>
        </section>
    </DealerLayout>
</template>
