<script setup lang="ts">
import { useLiveReload } from '@/composables/useLiveReload';
import Icon from '@/components/Icon.vue';
import Pager from '@/components/manager/Pager.vue';
import type { Customer, ManagerOptions, Paginated } from '@/components/manager/types';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Row = Customer & { visits: number; orders: number };

const props = defineProps<{ customers: Paginated<Row>; filters: { q: string; tag: string | null }; total: number; options: ManagerOptions }>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const search = ref(props.filters.q);

const list = useLiveReload(() => route('dealer.manager.customers.index', lot.value.slug), ['customers', 'filters', 'total']);
const searching = list.searching;
const params = (tag: string | null) => ({ q: search.value.trim() || undefined, tag: tag ?? undefined });
const go = (tag: string | null) => list.now(params(tag));
watch(search, () => list.later(() => params(props.filters.tag)));
</script>

<template>
    <Head title="Customers" />
    <DealerLayout>
        <div>
            <h1 class="text-[30px] font-bold">Customers</h1>
            <span class="text-[14px] text-muted">{{ total }} in your customer book · only your business can see them</span>
        </div>

        <div class="flex flex-col gap-3 lg:flex-row lg:items-center lg:justify-between">
            <nav aria-label="Filter by tag" class="-mx-5 flex gap-1 overflow-x-auto px-5 lg:mx-0 lg:px-0">
                <button
                    type="button"
                    class="h-10 shrink-0 rounded-full px-3.5 text-[14px]"
                    :class="!filters.tag ? 'bg-forest font-semibold text-white' : 'border border-line bg-white'"
                    :aria-pressed="!filters.tag"
                    @click="go(null)"
                >
                    All
                </button>
                <button
                    v-for="t in options.tags"
                    :key="t.value"
                    type="button"
                    class="h-10 shrink-0 rounded-full px-3.5 text-[14px]"
                    :class="filters.tag === t.value ? 'bg-forest font-semibold text-white' : 'border border-line bg-white'"
                    :aria-pressed="filters.tag === t.value"
                    @click="go(t.value)"
                >
                    {{ t.label }}
                </button>
            </nav>
            <label class="relative lg:w-80">
                <span class="sr-only">Search customers</span>
                <Icon name="search" class="absolute top-3.5 left-3 text-muted" :size="18" />
                <input v-field="{ kind: 'text', max: 60 }" v-model="search" type="search" class="field h-11 pl-10" placeholder="Search name or phone" />
                <Icon v-if="searching" name="refresh" class="absolute top-3.5 right-3 animate-spin text-muted" :size="16" aria-label="Searching" />
            </label>
        </div>

        <div v-if="customers.data.length === 0" class="card px-6 py-12 text-center text-[15px] text-muted">
            {{ total === 0 ? 'Customers appear here when you record walk-ins and orders.' : 'No customers match.' }}
        </div>

        <div v-else class="card overflow-hidden">
            <ul class="divide-y divide-divider">
                <li v-for="c in customers.data" :key="c.ulid">
                    <Link :href="route('dealer.manager.customers.show', [lot.slug, c.ulid])" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 text-ink no-underline hover:bg-ivory md:flex-nowrap">
                        <span class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full bg-sand text-[14px] font-bold text-forest">{{ c.name.slice(0, 1).toUpperCase() }}</span>
                        <span class="flex min-w-0 grow flex-col">
                            <span class="truncate text-[15px] font-semibold">{{ c.name }}</span>
                            <span class="truncate text-[13px] text-muted">{{ c.phone_display }} · {{ c.source_label }}</span>
                        </span>
                        <span class="flex flex-wrap gap-1">
                            <span v-for="t in c.tags" :key="t" class="rounded-full bg-blush px-2 py-0.5 text-[12px] font-semibold text-clay-dark capitalize">{{ t }}</span>
                        </span>
                        <span class="w-36 text-[13px] text-muted">{{ c.visits }} {{ c.visits === 1 ? 'visit' : 'visits' }} · {{ c.orders }} {{ c.orders === 1 ? 'order' : 'orders' }}</span>
                        <span class="w-28 text-[13px] text-muted">{{ c.last_seen ?? '' }}</span>
                    </Link>
                </li>
            </ul>
            <Pager :links="customers.links" :last-page="customers.last_page" />
        </div>
    </DealerLayout>
</template>
