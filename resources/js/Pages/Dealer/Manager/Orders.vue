<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import Pager from '@/components/manager/Pager.vue';
import { statusBadge, type OrderRow, type Paginated } from '@/components/manager/types';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    orders: Paginated<OrderRow>;
    filters: { status: string };
    counts: { open: number; delivered: number; cancelled: number };
    limit: number | null;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);

const tabs = computed(() => [
    { key: 'open', label: 'Open', count: props.counts.open },
    { key: 'delivered', label: 'Delivered', count: props.counts.delivered },
    { key: 'cancelled', label: 'Cancelled', count: props.counts.cancelled },
]);

function filter(status: string) {
    router.get(route('dealer.manager.orders.index', lot.value.slug), { status: status === 'open' ? undefined : status }, { preserveState: true });
}
</script>

<template>
    <Head title="Orders" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">Orders</h1>
                <span class="text-[14px] text-muted">
                    {{ counts.open }} open<template v-if="limit !== null"> of {{ limit }} on your plan</template> · {{ counts.delivered }} delivered
                </span>
            </div>
            <Link :href="route('dealer.manager.orders.create', lot.slug)" class="btn btn-primary h-11 px-4 text-[14px]">
                <Icon name="plus" :size="16" :stroke-width="2.4" /> New order
            </Link>
        </div>

        <nav aria-label="Filter by status" class="flex gap-1">
            <button
                v-for="tab in tabs"
                :key="tab.key"
                type="button"
                class="h-10 shrink-0 rounded-full px-3.5 text-[14px]"
                :class="filters.status === tab.key ? 'bg-forest font-semibold text-white' : 'border border-line bg-white'"
                :aria-pressed="filters.status === tab.key"
                @click="filter(tab.key)"
            >
                {{ tab.label }} {{ tab.count }}
            </button>
        </nav>

        <div v-if="orders.data.length === 0" class="card flex flex-col items-center gap-3 px-6 py-12 text-center">
            <h2 class="text-xl font-bold">{{ filters.status === 'open' ? 'No open orders' : 'Nothing here yet' }}</h2>
            <p class="max-w-sm text-[15px] text-muted">Start an order when a customer agrees a price. Deposits reserve the car; delivery marks it sold.</p>
        </div>

        <div v-else class="card overflow-hidden">
            <ul class="divide-y divide-divider">
                <li v-for="o in orders.data" :key="o.ulid">
                    <Link :href="route('dealer.manager.orders.show', [lot.slug, o.ulid])" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 text-ink no-underline hover:bg-ivory md:flex-nowrap">
                        <span class="flex min-w-0 grow flex-col">
                            <span class="truncate text-[15px] font-semibold">{{ o.car }}</span>
                            <span class="truncate text-[13px] text-muted">{{ o.order_no }} · {{ o.customer?.name }}</span>
                        </span>
                        <span class="flex w-40 flex-col gap-1">
                            <span class="text-[13px] text-muted">{{ o.paid }} of {{ o.total }}</span>
                            <span class="h-1.5 overflow-hidden rounded-full bg-sand"><span class="block h-full rounded-full bg-forest" :style="{ width: `${o.progress}%` }" /></span>
                        </span>
                        <span class="w-28 text-right font-display font-bold" :class="o.balance_minor > 0 && o.open ? 'text-clay-dark' : 'text-muted'">{{ o.open && o.balance_minor > 0 ? o.balance : '' }}</span>
                        <span class="w-28"><span class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="statusBadge[o.status]">{{ o.status_label }}</span></span>
                    </Link>
                </li>
            </ul>
            <Pager :links="orders.links" :last-page="orders.last_page" />
        </div>
    </DealerLayout>
</template>
