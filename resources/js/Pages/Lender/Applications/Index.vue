<script setup lang="ts">
import StatusChip from '@/components/finance/StatusChip.vue';
import Icon from '@/components/Icon.vue';
import Pager from '@/components/manager/Pager.vue';
import { useLiveReload } from '@/composables/useLiveReload';
import LenderLayout from '@/layouts/LenderLayout.vue';
import type { LenderRow } from '@/Pages/Lender/Dashboard.vue';
import type { SharedProps } from '@/types';
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    tab: string;
    q: string;
    applications: { data: LenderRow[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };
}>();

const page = usePage<SharedProps>();
const slug = computed(() => page.props.currentLender!.slug);
const tabs = [
    { key: 'new', label: 'New' },
    { key: 'open', label: 'Open' },
    { key: 'mine', label: 'Given to me' },
    { key: 'documents', label: 'Documents needed' },
    { key: 'approved', label: 'Approved' },
    { key: 'closed', label: 'Closed' },
    { key: 'all', label: 'All' },
];

const search = ref(props.q);
const current = ref(props.tab);
const live = useLiveReload(() => route('lender.applications.index', slug.value), ['applications', 'tab', 'q']);
const params = () => ({ tab: current.value, ...(search.value.trim() ? { q: search.value.trim() } : {}) });

function choose(key: string) {
    current.value = key;
    live.now(params());
}
</script>

<template>
    <Head title="Applications" />
    <LenderLayout>
        <h1 class="text-[30px] font-bold">Applications</h1>

        <div class="flex flex-wrap items-center gap-3">
            <label class="flex h-11 min-w-0 grow items-center gap-2 rounded-xl border border-line bg-white px-3 sm:max-w-sm">
                <Icon name="search" :size="18" class="text-muted" />
                <span class="sr-only">Search applications</span>
                <input
                    v-model="search"
                    v-field="{ kind: 'text', max: 60 }"
                    type="search"
                    maxlength="60"
                    class="w-full bg-transparent text-[15px] outline-none"
                    placeholder="Buyer, car, lot or reference"
                    @input="live.later(params)"
                />
                <Icon v-if="live.searching.value" name="refresh" :size="16" class="animate-spin text-muted" />
            </label>
        </div>

        <div class="flex gap-1 overflow-x-auto border-b border-line" role="tablist" aria-label="Applications">
            <button
                v-for="t in tabs"
                :key="t.key"
                type="button"
                role="tab"
                :aria-selected="current === t.key"
                class="flex h-11 shrink-0 items-center border-b-2 px-3 text-[14px] font-semibold"
                :class="current === t.key ? 'border-clay text-ink' : 'border-transparent text-muted'"
                @click="choose(t.key)"
            >
                {{ t.label }}
            </button>
        </div>

        <div v-if="!applications.data.length" class="card flex flex-col items-center gap-2 p-10 text-center">
            <Icon name="file" :size="28" class="text-muted" />
            <p class="text-[15px] text-muted">{{ search ? `Nothing matches "${search}".` : 'Nothing here.' }}</p>
        </div>
        <ul v-else class="flex flex-col gap-2">
            <li v-for="a in applications.data" :key="a.ulid">
                <Link :href="route('lender.applications.show', [slug, a.ulid])" class="card flex items-center gap-3 p-4 text-ink no-underline hover:border-line-strong">
                    <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="a.unread ? 'bg-clay' : 'bg-transparent'" :aria-label="a.unread ? 'New message' : undefined" />
                    <span class="flex min-w-0 grow flex-col gap-0.5">
                        <strong class="truncate text-[15px]">{{ a.buyer }} · {{ a.car ?? 'Car no longer listed' }}</strong>
                        <span class="truncate text-[13px] text-muted">
                            {{ a.amount }} · {{ a.months }} months · {{ a.lot }} · sent {{ a.date }}<template v-if="a.assignee"> · {{ a.assignee }}</template>
                        </span>
                    </span>
                    <StatusChip :tone="a.tone" :label="a.status_label" />
                </Link>
            </li>
        </ul>

        <Pager :links="applications.links" :last-page="applications.last_page" />
    </LenderLayout>
</template>
