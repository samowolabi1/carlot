<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import Pager from '@/components/manager/Pager.vue';
import { interestBadge, type ManagerOptions, type Paginated, type StockCar, type WalkInRow } from '@/components/manager/types';
import WalkInSheet from '@/components/manager/WalkInSheet.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{ walkIns: Paginated<WalkInRow>; filters: { q: string }; stock: StockCar[]; options: ManagerOptions }>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const adding = ref(false);
const search = ref(props.filters.q);

let timer: ReturnType<typeof setTimeout> | undefined;
watch(search, () => {
    clearTimeout(timer);
    timer = setTimeout(() => router.get(route('dealer.manager.walk-ins.index', lot.value.slug), { q: search.value || undefined }, { preserveState: true, replace: true }), 350);
});
</script>

<template>
    <Head title="Walk-ins" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">Walk-ins</h1>
                <span class="text-[14px] text-muted">{{ walkIns.total }} {{ walkIns.total === 1 ? 'visit' : 'visits' }} recorded</span>
            </div>
            <button type="button" class="btn btn-primary h-11 px-4 text-[14px]" @click="adding = true">
                <Icon name="plus" :size="16" :stroke-width="2.4" /> Walk-in
            </button>
        </div>

        <label class="relative lg:w-80">
            <span class="sr-only">Search walk-ins</span>
            <Icon name="search" class="absolute top-3.5 left-3 text-muted" :size="18" />
            <input v-field="{ kind: 'text', max: 60 }" v-model="search" type="search" class="field h-11 pl-10" placeholder="Search name or phone" />
        </label>

        <div v-if="walkIns.data.length === 0" class="card flex flex-col items-center gap-3 px-6 py-12 text-center">
            <h2 class="text-xl font-bold">{{ filters.q ? 'No matches' : 'Your walk-in register' }}</h2>
            <p class="max-w-sm text-[15px] text-muted">
                {{ filters.q ? 'Try another name or number.' : 'Record everyone who visits the lot. Repeat visitors are matched by phone number.' }}
            </p>
        </div>

        <div v-else class="card overflow-hidden">
            <ul class="divide-y divide-divider">
                <li v-for="w in walkIns.data" :key="w.ulid" class="flex flex-wrap items-center gap-x-4 gap-y-1 px-4 py-3 md:flex-nowrap">
                    <span class="w-32 shrink-0 text-[13px] text-muted">{{ w.when }}</span>
                    <Link v-if="w.customer" :href="route('dealer.manager.customers.show', [lot.slug, w.customer.ulid])" class="flex min-w-0 grow flex-col text-ink no-underline">
                        <span class="truncate text-[15px] font-semibold">{{ w.customer.name }}</span>
                        <span class="truncate text-[13px] text-muted">{{ w.customer.phone_display }}<template v-if="w.cars.length"> · {{ w.cars.join(', ') }}</template></span>
                    </Link>
                    <span class="rounded-full px-2.5 py-1 text-[12px] font-semibold" :class="interestBadge[w.interest]">{{ w.interest_label }}</span>
                    <span class="w-28 text-[13px] text-muted">{{ w.next_step === 'none' ? '' : w.next_step_label }}</span>
                    <span class="w-28 truncate text-[13px] text-muted">{{ w.staff }}</span>
                </li>
            </ul>
            <Pager :links="walkIns.links" :last-page="walkIns.last_page" />
        </div>

        <WalkInSheet :open="adding" :stock="stock" :options="options" @close="adding = false" />
    </DealerLayout>
</template>
