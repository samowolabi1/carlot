<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { interestBadge, type ManagerOptions, type OrderRow, type StockCar, type TaskRow, type WalkInRow } from '@/components/manager/types';
import WalkInSheet from '@/components/manager/WalkInSheet.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

defineProps<{
    date: string;
    stats: { walk_ins: number; received: string; open_orders: number; outstanding: string };
    walkIns: WalkInRow[];
    tasks: TaskRow[];
    balances: OrderRow[];
    overdue: { order_ulid: string; order_no: string; customer: string; car: string | null; amount: string; due: string }[];
    stock: StockCar[];
    options: ManagerOptions;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const adding = ref(false);

function task(t: TaskRow, action: 'done' | 'tomorrow') {
    router.patch(route('dealer.manager.tasks.update', [lot.value.slug, t.ulid]), { action }, { preserveScroll: true });
}
</script>

<template>
    <Head title="Today" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">Today</h1>
                <span class="text-[14px] text-muted">{{ date }}</span>
            </div>
            <div class="flex gap-2.5">
                <Link :href="route('dealer.manager.orders.create', lot.slug)" class="btn btn-outline h-11 px-4 text-[14px]">New order</Link>
                <button type="button" class="btn btn-primary h-11 px-4 text-[14px]" @click="adding = true">
                    <Icon name="plus" :size="16" :stroke-width="2.4" /> Walk-in
                </button>
            </div>
        </div>

        <div class="grid grid-cols-2 gap-3 xl:grid-cols-4">
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Walk-ins today</div>
                <div class="font-display text-[28px] font-bold">{{ stats.walk_ins }}</div>
            </div>
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Received today</div>
                <div class="font-display text-[28px] font-bold">{{ stats.received }}</div>
            </div>
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Open orders</div>
                <div class="font-display text-[28px] font-bold">{{ stats.open_orders }}</div>
            </div>
            <div class="card p-[18px]">
                <div class="text-[13px] text-muted">Balances to collect</div>
                <div class="font-display text-[28px] font-bold">{{ stats.outstanding }}</div>
            </div>
        </div>

        <div class="grid gap-5 xl:grid-cols-[1fr_380px]">
            <section class="card overflow-hidden" aria-labelledby="walk-ins-heading">
                <div class="flex items-center justify-between border-b border-divider px-4 py-3">
                    <h2 id="walk-ins-heading" class="font-sans text-[16px] font-bold">Walk-ins</h2>
                    <Link :href="route('dealer.manager.walk-ins.index', lot.slug)" class="text-[14px] font-semibold">All walk-ins</Link>
                </div>
                <div v-if="walkIns.length === 0" class="flex flex-col items-center gap-2 px-6 py-10 text-center">
                    <p class="text-[15px] text-muted">No walk-ins yet today. Record each visitor in under 30 seconds.</p>
                    <button type="button" class="btn btn-dark h-11 text-[14px]" @click="adding = true">Record a walk-in</button>
                </div>
                <ul v-else class="divide-y divide-divider">
                    <li v-for="w in walkIns" :key="w.ulid" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3">
                        <span class="w-12 text-[14px] font-semibold text-muted">{{ w.time }}</span>
                        <Link v-if="w.customer" :href="route('dealer.manager.customers.show', [lot.slug, w.customer.ulid])" class="flex min-w-0 grow flex-col text-ink no-underline">
                            <span class="truncate text-[15px] font-semibold">{{ w.customer.name }}</span>
                            <span class="truncate text-[13px] text-muted">{{ w.cars.join(', ') || w.customer.phone_display }}</span>
                        </Link>
                        <span class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="interestBadge[w.interest]">{{ w.interest_label }}</span>
                        <span v-if="w.next_step !== 'none'" class="text-[13px] text-muted">{{ w.next_step_label }}</span>
                    </li>
                </ul>
            </section>

            <div class="flex flex-col gap-5">
                <section class="card overflow-hidden" aria-labelledby="tasks-heading">
                    <h2 id="tasks-heading" class="border-b border-divider px-4 py-3 font-sans text-[16px] font-bold">Follow-ups due</h2>
                    <p v-if="tasks.length === 0" class="px-4 py-6 text-[14px] text-muted">Nothing due. Walk-ins marked "Call back" show up here.</p>
                    <ul v-else class="divide-y divide-divider">
                        <li v-for="t in tasks" :key="t.ulid" class="flex flex-col gap-2 px-4 py-3">
                            <div class="flex items-start justify-between gap-2">
                                <div class="min-w-0">
                                    <Link v-if="t.customer" :href="route('dealer.manager.customers.show', [lot.slug, t.customer.ulid])" class="font-semibold text-ink no-underline">
                                        {{ t.type_label }} {{ t.customer.name }}
                                    </Link>
                                    <p class="text-[13px]" :class="t.overdue ? 'font-semibold text-clay-dark' : 'text-muted'">
                                        {{ t.due }}<template v-if="t.assignee"> · {{ t.assignee }}</template>
                                    </p>
                                    <p v-if="t.note" class="mt-1 text-[13px] text-muted">{{ t.note }}</p>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <a v-if="t.customer" :href="`tel:${t.customer.phone}`" class="btn btn-outline h-10 px-3 text-[13px]"><Icon name="phone" :size="16" /> Call</a>
                                <a v-if="t.customer" :href="`https://wa.me/${t.customer.whatsapp}`" target="_blank" rel="noopener" class="btn btn-outline h-10 px-3 text-[13px]"><Icon name="whatsapp" :size="16" /> WhatsApp</a>
                                <button type="button" class="btn btn-dark h-10 px-3 text-[13px]" @click="task(t, 'done')"><Icon name="check" :size="16" /> Done</button>
                                <button type="button" class="h-10 px-2 text-[13px] font-semibold text-muted hover:text-ink" @click="task(t, 'tomorrow')">Tomorrow</button>
                            </div>
                        </li>
                    </ul>
                </section>

                <section v-if="overdue.length" class="card overflow-hidden border-2 border-danger/40" aria-labelledby="overdue-heading">
                    <h2 id="overdue-heading" class="border-b border-divider px-4 py-3 font-sans text-[16px] font-bold text-danger">Overdue instalments</h2>
                    <ul class="divide-y divide-divider">
                        <li v-for="o in overdue" :key="`${o.order_ulid}-${o.due}`">
                            <Link :href="route('dealer.manager.orders.show', [lot.slug, o.order_ulid])" class="flex items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-ivory">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold">{{ o.customer }}</span>
                                    <span class="block truncate text-[13px] text-muted">{{ o.car }} · {{ o.order_no }} · was due {{ o.due }}</span>
                                </span>
                                <span class="shrink-0 font-display font-bold text-danger">{{ o.amount }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>

                <section class="card overflow-hidden" aria-labelledby="balances-heading">
                    <div class="flex items-center justify-between border-b border-divider px-4 py-3">
                        <h2 id="balances-heading" class="font-sans text-[16px] font-bold">Balances to collect</h2>
                        <Link :href="route('dealer.manager.orders.index', lot.slug)" class="text-[14px] font-semibold">Orders</Link>
                    </div>
                    <p v-if="balances.length === 0" class="px-4 py-6 text-[14px] text-muted">No open balances.</p>
                    <ul v-else class="divide-y divide-divider">
                        <li v-for="o in balances" :key="o.ulid">
                            <Link :href="route('dealer.manager.orders.show', [lot.slug, o.ulid])" class="flex items-center justify-between gap-3 px-4 py-3 text-ink no-underline hover:bg-ivory">
                                <span class="min-w-0">
                                    <span class="block truncate font-semibold">{{ o.customer?.name }}</span>
                                    <span class="block truncate text-[13px] text-muted">{{ o.car }} · {{ o.order_no }}</span>
                                </span>
                                <span class="shrink-0 font-display font-bold text-clay-dark">{{ o.balance }}</span>
                            </Link>
                        </li>
                    </ul>
                </section>
            </div>
        </div>

        <WalkInSheet :open="adding" :stock="stock" :options="options" @close="adding = false" />
    </DealerLayout>
</template>
