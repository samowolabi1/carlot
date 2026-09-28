<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Car = {
    ulid: string;
    title: string;
    views: number;
    saves: number;
    leads: number;
    days: number | null;
    flag: 'good' | 'quiet' | 'ageing' | 'stale' | 'reserved';
    guide: 'above' | 'below' | null;
    price_url: string;
    spotlight: boolean;
};

type Data = {
    range: string;
    kpis: { key: string; label: string; value: number; change: number | null }[];
    funnel: { label: string; value: number; note: string | null }[];
    trend: { date: string; label: string; views: number; leads: number }[];
    cars: Car[];
    sources: { label: string; value: number }[] | null;
    shares: { label: string; value: number; clicks: number }[] | null;
    staff: { name: string; leads: number; reply: string | null; bookings: number | null; sold: number | null }[] | null;
};

const props = defineProps<{ allowed: boolean; full: boolean; period: string; periods: { value: string; label: string }[]; data: Data | null }>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const n = (v: number) => v.toLocaleString('en-NG');

const setPeriod = (period: string) => router.get(route('dealer.analytics', { lot: lot.value.slug, period }), {}, { preserveScroll: true });
const exportUrl = (type: string) => route('dealer.analytics', { lot: lot.value.slug, period: props.period, export: type });

// Funnel: bars below views aren't to scale (design D8), so the small stages stay readable.
const funnelWidth = (i: number, value: number) => {
    if (!props.data) return 0;
    if (i === 0) return 62;
    const leads = props.data.funnel[1].value || 1;
    return Math.max(5, Math.min(40, (value / leads) * 40));
};
const funnelTone = ['bg-forest', 'bg-[#3F6259]', 'bg-[#9DB8B0]', 'bg-clay'];

const maxSource = computed(() => Math.max(1, ...(props.data?.sources ?? []).map((s) => s.value)));
const maxShare = computed(() => Math.max(1, ...(props.data?.shares ?? []).map((s) => s.value)));

// Daily views: one series, one axis; hover or focus a day for its numbers.
const maxViews = computed(() => Math.max(1, ...(props.data?.trend ?? []).map((d) => d.views)));
const hovered = ref<number | null>(null);
const hoverDay = computed(() => (hovered.value !== null ? props.data?.trend[hovered.value] : null));
const totalTrendViews = computed(() => (props.data?.trend ?? []).reduce((a, d) => a + d.views, 0));

const flag: Record<Car['flag'], { label: string; tone: string }> = {
    good: { label: 'Doing well', tone: 'text-success' },
    quiet: { label: 'No leads yet', tone: 'text-muted' },
    ageing: { label: 'Ageing', tone: 'text-clay-dark' },
    stale: { label: 'Stale', tone: 'text-danger' },
    reserved: { label: 'Reserved', tone: 'text-forest' },
};
</script>

<template>
    <Head title="Analytics" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-[30px] font-bold">Analytics</h1>
                <p class="text-[14px] text-muted">{{ data ? `${data.range} · all staff` : 'Views, leads and sales for your cars' }}</p>
            </div>
            <div v-if="allowed" class="flex flex-wrap gap-2">
                <label>
                    <span class="sr-only">Period</span>
                    <select :value="period" class="field h-11 w-auto text-[14px]" @change="setPeriod(($event.target as HTMLSelectElement).value)">
                        <option v-for="p in periods" :key="p.value" :value="p.value">{{ p.label }}</option>
                    </select>
                </label>
                <template v-if="full">
                    <a :href="exportUrl('stock')" class="btn btn-outline h-11 text-[14px]"><Icon name="download" :size="16" /> Stock CSV</a>
                    <a :href="exportUrl('leads')" class="btn btn-outline h-11 text-[14px]"><Icon name="download" :size="16" /> Leads CSV</a>
                    <a :href="exportUrl('sales')" class="btn btn-outline h-11 text-[14px]"><Icon name="download" :size="16" /> Sales CSV</a>
                </template>
            </div>
        </div>

        <section v-if="!allowed || !data" class="card flex max-w-xl flex-col gap-3 p-6">
            <h2 class="font-sans text-[18px] font-bold">Analytics come with Starter</h2>
            <p class="text-[15px] text-muted">See how many people view each car, where your leads come from, and which cars are ageing.</p>
            <Link :href="route('dealer.billing', lot.slug)" class="btn btn-primary self-start">See plans</Link>
        </section>

        <template v-else>
            <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-5">
                <div v-for="k in data.kpis" :key="k.key" class="card flex flex-col gap-0.5 px-[18px] py-4">
                    <span class="text-[13px] text-muted">{{ k.label }}</span>
                    <span class="font-display text-[26px] font-bold">{{ n(k.value) }}</span>
                    <span v-if="k.change !== null" class="text-[12px] font-semibold" :class="k.change >= 0 ? 'text-success' : 'text-clay-dark'">
                        {{ k.change >= 0 ? '▲' : '▼' }} {{ Math.abs(k.change) }}% on the previous period
                    </span>
                </div>
            </div>

            <div class="grid gap-3 xl:grid-cols-[1.3fr_1fr]">
                <section class="card px-[18px] py-4" aria-labelledby="funnel-heading">
                    <h2 id="funnel-heading" class="mb-3 font-sans text-[15px] font-bold">From view to sale</h2>
                    <ol class="flex flex-col gap-2.5">
                        <li v-for="(f, i) in data.funnel" :key="f.label" class="flex items-center gap-3">
                            <span class="h-[34px] shrink-0 rounded-[6px]" :class="funnelTone[i]" :style="{ width: `${funnelWidth(i, f.value)}%` }" aria-hidden="true" />
                            <span class="min-w-0 text-[13px] font-semibold">{{ n(f.value) }} {{ f.label }}<template v-if="f.note"> · {{ f.note }}</template></span>
                        </li>
                    </ol>
                    <p class="mt-2 text-[12px] text-muted">Bars below views are not to scale, so the smaller stages stay readable.</p>
                </section>

                <section v-if="data.sources" class="card px-[18px] py-4" aria-labelledby="sources-heading">
                    <h2 id="sources-heading" class="mb-3 font-sans text-[15px] font-bold">Where leads came from</h2>
                    <p v-if="data.sources.length === 0" class="text-[14px] text-muted">No leads in this period yet.</p>
                    <ul v-else class="flex flex-col gap-2">
                        <li v-for="s in data.sources" :key="s.label" class="grid grid-cols-[100px_1fr_40px] items-center gap-2.5 text-[13px]">
                            <span>{{ s.label }}</span>
                            <span class="h-3 rounded-[6px] bg-[#F1EEE8]"><span class="block h-3 rounded-[6px] bg-forest" :style="{ width: `${(s.value / maxSource) * 100}%` }" /></span>
                            <span class="text-right tabular-nums">{{ s.value }}</span>
                        </li>
                    </ul>
                </section>
                <section v-else class="card flex flex-col justify-center gap-2 px-[18px] py-4">
                    <h2 class="font-sans text-[15px] font-bold">Where leads came from</h2>
                    <p class="text-[14px] text-muted">Lead sources, shares by platform, staff performance and CSV exports come with Pro.</p>
                    <Link :href="route('dealer.billing', lot.slug)" class="text-[14px] font-semibold">Upgrade</Link>
                </section>
            </div>

            <section class="card px-[18px] py-4" aria-labelledby="trend-heading">
                <div class="mb-3 flex flex-wrap items-baseline justify-between gap-2">
                    <h2 id="trend-heading" class="font-sans text-[15px] font-bold">Listing views per day</h2>
                    <span class="text-[13px] text-muted" aria-live="polite">
                        <template v-if="hoverDay">{{ hoverDay.label }}: {{ n(hoverDay.views) }} views · {{ hoverDay.leads }} leads</template>
                        <template v-else>{{ n(totalTrendViews) }} views in total</template>
                    </span>
                </div>
                <div class="flex h-32 items-end gap-[2px]" role="list" :aria-label="`Views per day, ${data.range}`">
                    <button
                        v-for="(d, i) in data.trend"
                        :key="d.date"
                        type="button"
                        role="listitem"
                        class="group flex h-full min-w-0 flex-1 items-end focus:outline-none"
                        :aria-label="`${d.label}: ${d.views} views, ${d.leads} leads`"
                        @mouseenter="hovered = i"
                        @mouseleave="hovered = null"
                        @focus="hovered = i"
                        @blur="hovered = null"
                    >
                        <span
                            class="w-full rounded-t-[4px] bg-forest transition-colors group-hover:bg-clay group-focus-visible:bg-clay"
                            :style="{ height: d.views ? `${Math.max(3, (d.views / maxViews) * 100)}%` : '2px', opacity: d.views ? 1 : 0.25 }"
                        />
                    </button>
                </div>
                <div class="mt-1.5 flex justify-between text-[12px] text-muted"><span>{{ data.trend[0]?.label }}</span><span>{{ data.trend[data.trend.length - 1]?.label }}</span></div>
            </section>

            <div class="grid gap-3 xl:grid-cols-[1.3fr_1fr]">
                <section class="card overflow-x-auto px-[18px] py-4" aria-labelledby="cars-heading">
                    <h2 id="cars-heading" class="mb-2 font-sans text-[15px] font-bold">Cars</h2>
                    <p v-if="data.cars.length === 0" class="text-[14px] text-muted">No cars in stock.</p>
                    <table v-else class="w-full min-w-[520px] text-left text-[13px]">
                        <thead>
                            <tr class="text-[12px] text-muted">
                                <th scope="col" class="py-2 font-semibold">Car</th>
                                <th scope="col" class="py-2 text-right font-semibold">Views</th>
                                <th scope="col" class="py-2 text-right font-semibold">Leads</th>
                                <th scope="col" class="py-2 text-right font-semibold">Days</th>
                                <th scope="col" class="py-2 pl-4 font-semibold">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr v-for="c in data.cars" :key="c.ulid" class="border-t border-divider">
                                <td class="py-2">
                                    {{ c.title }}
                                    <span v-if="c.guide === 'above'" class="ml-1 text-[12px] text-clay-dark">· priced above similar cars</span>
                                </td>
                                <td class="py-2 text-right tabular-nums">{{ n(c.views) }}</td>
                                <td class="py-2 text-right tabular-nums">{{ c.leads }}</td>
                                <td class="py-2 text-right tabular-nums" :class="{ 'font-semibold text-clay-dark': c.flag === 'ageing', 'font-semibold text-danger': c.flag === 'stale' }">{{ c.days ?? '—' }}</td>
                                <td class="py-2 pl-4">
                                    <span v-if="c.flag === 'ageing' || c.flag === 'stale'" class="flex flex-wrap gap-x-3">
                                        <span class="font-semibold" :class="flag[c.flag].tone">{{ flag[c.flag].label }}</span>
                                        <Link :href="c.price_url" class="font-semibold">Reduce price</Link>
                                        <Link v-if="c.spotlight" :href="route('dealer.vehicles.index', { lot: lot.slug, status: 'available' })" class="font-semibold">Spotlight</Link>
                                    </span>
                                    <span v-else class="font-semibold" :class="flag[c.flag].tone">{{ flag[c.flag].label }}</span>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </section>

                <div class="flex flex-col gap-3">
                    <section v-if="data.staff" class="card overflow-x-auto px-[18px] py-4" aria-labelledby="staff-heading">
                        <h2 id="staff-heading" class="mb-2 font-sans text-[15px] font-bold">Staff</h2>
                        <table class="w-full text-left text-[13px]">
                            <thead>
                                <tr class="text-[12px] text-muted">
                                    <th scope="col" class="py-2 font-semibold">Name</th>
                                    <th scope="col" class="py-2 text-right font-semibold">Leads</th>
                                    <th scope="col" class="py-2 text-right font-semibold">First reply</th>
                                    <th scope="col" class="py-2 text-right font-semibold">Visits</th>
                                    <th scope="col" class="py-2 text-right font-semibold">Sold</th>
                                </tr>
                            </thead>
                            <tbody>
                                <tr v-for="s in data.staff" :key="s.name" class="border-t border-divider">
                                    <td class="py-2">{{ s.name }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ s.leads }}</td>
                                    <td class="py-2 text-right">{{ s.reply ?? '—' }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ s.bookings ?? '—' }}</td>
                                    <td class="py-2 text-right tabular-nums">{{ s.sold ?? '—' }}</td>
                                </tr>
                            </tbody>
                        </table>
                    </section>

                    <section v-if="data.shares" class="card px-[18px] py-4" aria-labelledby="shares-heading">
                        <h2 id="shares-heading" class="mb-3 font-sans text-[15px] font-bold">Shares by platform</h2>
                        <p v-if="data.shares.length === 0" class="text-[14px] text-muted">No shares in this period yet.</p>
                        <ul v-else class="flex flex-col gap-2">
                            <li v-for="s in data.shares" :key="s.label" class="grid grid-cols-[100px_1fr_auto] items-center gap-2.5 text-[13px]">
                                <span>{{ s.label }}</span>
                                <span class="h-3 rounded-[6px] bg-[#F1EEE8]"><span class="block h-3 rounded-[6px] bg-forest" :style="{ width: `${(s.value / maxShare) * 100}%` }" /></span>
                                <span class="text-right tabular-nums">{{ s.value }} · {{ s.clicks }} visits</span>
                            </li>
                        </ul>
                    </section>
                </div>
            </div>
        </template>
    </DealerLayout>
</template>
