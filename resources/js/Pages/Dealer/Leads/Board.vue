<script setup lang="ts">
import { useLiveReload } from '@/composables/useLiveReload';
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface LeadCard {
    ulid: string;
    name: string;
    car: string | null;
    source: string;
    source_label: string;
    stage: string;
    assignee: string | null;
    age: string;
    follow_up: string | null;
    follow_up_due: boolean;
    unread: number;
}

const props = defineProps<{
    columns: { stage: string; label: string; leads: LeadCard[] }[];
    lost: number;
    filters: { q: string; who: string };
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const search = ref(props.filters.q);
const dragging = ref<LeadCard | null>(null);
const over = ref<string | null>(null);

const list = useLiveReload(() => route('dealer.leads.index', lot.value.slug), ['columns', 'lost', 'filters']);
const searching = list.searching;
const params = (next: Partial<{ q: string; who: string }>) => ({ q: search.value.trim() || undefined, who: props.filters.who === 'everyone' ? undefined : props.filters.who, ...next });
const filter = (next: Partial<{ q: string; who: string }>) => list.now(params(next));
watch(search, () => list.later(() => params({})));

function drop(stage: string) {
    const lead = dragging.value;
    dragging.value = null;
    over.value = null;
    if (!lead || lead.stage === stage) return;
    router.patch(route('dealer.leads.update', [lot.value.slug, lead.ulid]), { stage }, { preserveScroll: true, only: ['columns', 'lost', 'currentLot', 'flash'] });
}

const sourceTone: Record<string, string> = {
    chat: 'bg-map text-forest',
    whatsapp: 'bg-[#DCF8E6] text-[#0B3D1F]',
    booking: 'bg-[#E0ECF8] text-[#1E3A8A]',
    call: 'bg-sand text-ink',
    offer: 'bg-blush text-clay-dark',
    trade_in: 'bg-[#EDE7F8] text-[#5B21B6]',
    reservation: 'bg-[#E0ECF8] text-[#1E3A8A]',
};

</script>

<template>
    <Head title="Leads" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">Leads</h1>
                <span class="text-[14px] text-muted">Every chat, booking, WhatsApp tap and call lands here</span>
            </div>
            <div class="flex flex-wrap items-center gap-2.5">
                <label class="relative w-full sm:w-60">
                    <span class="sr-only">Search leads</span>
                    <Icon name="search" class="absolute top-3.5 left-3 text-muted" :size="18" />
                    <input v-field="{ kind: 'text', max: 60 }" v-model="search" type="search" class="field h-11 pl-10" placeholder="Search name, phone or car" />
                <Icon v-if="searching" name="refresh" class="absolute top-3.5 right-3 animate-spin text-muted" :size="16" aria-label="Searching" />
                </label>
                <label>
                    <span class="sr-only">Whose leads</span>
                    <select :value="filters.who" class="field h-11 w-auto" @change="filter({ who: ($event.target as HTMLSelectElement).value })">
                        <option value="everyone">Everyone</option>
                        <option value="mine">Mine</option>
                        <option value="unassigned">Unassigned</option>
                    </select>
                </label>
            </div>
        </div>

        <div class="-mx-5 grid auto-cols-[minmax(240px,1fr)] grid-flow-col gap-2.5 overflow-x-auto px-5 pb-2 lg:mx-0 lg:grid-flow-row lg:grid-cols-5 lg:px-0">
            <section
                v-for="col in columns"
                :key="col.stage"
                class="flex min-h-[320px] min-w-0 flex-col gap-2 rounded-[14px] p-2.5 transition"
                :class="over === col.stage ? 'bg-map ring-2 ring-forest' : 'bg-divider'"
                :aria-labelledby="`col-${col.stage}`"
                @dragover.prevent="over = col.stage"
                @dragleave="over = over === col.stage ? null : over"
                @drop.prevent="drop(col.stage)"
            >
                <h2 :id="`col-${col.stage}`" class="flex items-center justify-between px-1 pb-1 font-sans text-[13px] font-bold">
                    {{ col.label }} <span class="text-[12px] font-medium text-muted">{{ col.leads.length }}{{ col.stage === 'won' ? ' this month' : '' }}</span>
                </h2>
                <Link
                    v-for="lead in col.leads"
                    :key="lead.ulid"
                    :href="route('dealer.leads.show', [lot.slug, lead.ulid])"
                    draggable="true"
                    class="flex flex-col gap-1 rounded-xl border bg-white px-3 py-2.5 text-ink no-underline hover:border-forest"
                    :class="lead.unread ? 'border-2 border-clay' : 'border-line'"
                    @dragstart="dragging = lead"
                    @dragend="dragging = null"
                >
                    <span class="flex items-center justify-between gap-2">
                        <span class="truncate text-[14px] font-semibold">{{ lead.name }}</span>
                        <span v-if="lead.unread" class="rounded-full bg-clay px-1.5 text-[11px] font-semibold text-white">{{ lead.unread }}</span>
                    </span>
                    <span v-if="lead.car" class="truncate text-[13px] text-muted">{{ lead.car }}</span>
                    <span class="flex items-center justify-between gap-2 text-[11px] text-muted">
                        <span class="rounded-lg px-2 py-0.5 font-semibold" :class="sourceTone[lead.source]">{{ lead.source_label }}</span>
                        <span :class="lead.follow_up_due ? 'font-semibold text-clay-dark' : ''">{{ lead.follow_up ?? lead.assignee ?? lead.age }}</span>
                    </span>
                </Link>
                <p v-if="col.leads.length === 0" class="px-1 py-4 text-center text-[12px] text-muted">Drag a lead here</p>
            </section>
        </div>
        <p class="text-[13px] text-muted">{{ lost }} lost this month. Drag a card to move it along; open it to chat, assign it or set a follow-up.</p>
    </DealerLayout>
</template>
