<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { json } from '@/lib/http';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface Item {
    ulid: string;
    type: 'viewing' | 'test_drive' | 'inspection' | 'trade_in';
    type_label: string;
    status: 'pending' | 'confirmed' | 'completed' | 'no_show' | 'cancelled';
    status_label: string;
    date: string;
    time: string;
    minutes: number;
    when: string;
    customer: string;
    phone: string;
    phone_display: string;
    whatsapp: string;
    car: string | null;
    notes: string | null;
    staff: { ulid: string; name: string | null } | null;
    checked_in: boolean;
    past: boolean;
}

const props = defineProps<{
    week: {
        start: string;
        label: string;
        prev: string;
        next: string;
        days: { date: string; weekday: string; day: number; today: boolean; closed: boolean }[];
        first_hour: number;
        last_hour: number;
    };
    appointments: Item[];
    today: { label: string; items: Item[] };
    pending: Item[];
    types: { value: string; label: string }[];
    staff: { ulid: string; name: string }[];
    canAssign: boolean;
    isCurrentWeek: boolean;
}>();

const { currentLot } = useShared();
const slug = computed(() => currentLot.value!.slug);

const HOUR_PX = 60;
const hours = computed(() => Array.from({ length: props.week.last_hour - props.week.first_hour }, (_, i) => props.week.first_hour + i));

const colours: Record<Item['type'], string> = {
    viewing: 'bg-map text-forest',
    test_drive: 'bg-blush text-clay-dark',
    trade_in: 'bg-[#FEF3C7] text-[#92400E]',
    inspection: 'bg-[#E0ECF8] text-[#1E3A8A]',
};

const hidden = ref<string[]>([]);
const visible = computed(() => props.appointments.filter((a) => !hidden.value.includes(a.type)));
const byDay = (date: string) => visible.value.filter((a) => a.date === date);

// Closed days (e.g. Sunday) are left out unless something is booked on them.
const shownDays = computed(() => props.week.days.filter((d) => !d.closed || props.appointments.some((a) => a.date === d.date)));

// Bookings that overlap share the column side by side: lane = position, lanes = width.
const lanes = computed(() => {
    const out: Record<string, { lane: number; lanes: number }> = {};
    const minutes = (a: Item) => Number(a.time.slice(0, 2)) * 60 + Number(a.time.slice(3));

    for (const d of props.week.days) {
        const items = byDay(d.date).sort((x, y) => minutes(x) - minutes(y));
        let group: Item[] = [];
        let groupEnd = -1;
        const ends: number[] = [];

        const flush = () => {
            group.forEach((a) => (out[a.ulid].lanes = ends.length));
            group = [];
            ends.length = 0;
        };

        for (const a of items) {
            const start = minutes(a);
            if (start >= groupEnd) flush();
            let lane = ends.findIndex((end) => end <= start);
            if (lane === -1) lane = ends.push(0) - 1;
            ends[lane] = start + a.minutes;
            out[a.ulid] = { lane, lanes: 1 };
            group.push(a);
            groupEnd = Math.max(groupEnd, start + a.minutes);
        }
        flush();
    }

    return out;
});
const bookedCount = computed(() => props.appointments.filter((a) => a.status !== 'no_show').length);

function top(a: Item) {
    const [h, m] = a.time.split(':').map(Number);
    return ((h - props.week.first_hour) * 60 + m) * (HOUR_PX / 60);
}

/* Mobile: one day at a time */
const mobileDay = ref(props.week.days.find((d) => d.today)?.date ?? props.week.days[0].date);

/* Actions */
function act(a: Item, action: string) {
    router.patch(route('dealer.appointments.update', [slug.value, a.ulid]), { action }, { preserveScroll: true, onSuccess: () => selected.value && refreshSelected() });
}

/* Drag to reschedule (desktop week grid) */
const dragging = ref<Item | null>(null);

function onDrop(e: DragEvent, date: string) {
    const a = dragging.value;
    dragging.value = null;
    if (!a) return;
    const rect = (e.currentTarget as HTMLElement).getBoundingClientRect();
    const minutes = Math.max(0, Math.round(((e.clientY - rect.top) / HOUR_PX) * 60 / 15) * 15);
    const h = props.week.first_hour + Math.floor(minutes / 60);
    const m = minutes % 60;
    const time = `${String(h).padStart(2, '0')}:${String(m).padStart(2, '0')}`;
    if (date === a.date && time === a.time) return;
    reschedule(a, date, time);
}

// Drops send the lot-local date and time; the drawer sends a slot's exact UTC start.
function reschedule(a: Item, date: string, time: string, startsAt?: string) {
    const payload = startsAt ? { starts_at: startsAt } : { date, time };
    router.patch(route('dealer.appointments.update', [slug.value, a.ulid]), payload, { preserveScroll: true, onSuccess: () => (selected.value = null) });
}

/* Detail drawer */
const selected = ref<Item | null>(null);
const slots = ref<{ date: string; weekday: string; day: number; closed: boolean; slots: { time: string; starts_at: string; available: boolean }[] }[]>([]);
const moveDate = ref('');
const moveError = ref<string | null>(null);
const cancelForm = useForm({ reason: '' });
const cancelling = ref(false);

async function open(a: Item) {
    selected.value = a;
    cancelling.value = false;
    moveError.value = null;
    slots.value = [];
    if (a.status === 'pending' || a.status === 'confirmed') {
        try {
            slots.value = await json('GET', route('dealer.appointments.slots', [slug.value, a.ulid]));
            moveDate.value = slots.value.find((d) => d.date === a.date && !d.closed)?.date ?? slots.value.find((d) => !d.closed)?.date ?? '';
        } catch (e) {
            moveError.value = e instanceof Error ? e.message : 'Could not load free times.';
        }
    }
}

function refreshSelected() {
    const next = [...props.appointments, ...props.today.items, ...props.pending].find((x) => x.ulid === selected.value?.ulid);
    selected.value = next ?? null;
}
watch(() => props.appointments, () => selected.value && refreshSelected());

const moveDay = computed(() => slots.value.find((d) => d.date === moveDate.value));

function assign(ulid: string) {
    if (!selected.value) return;
    router.patch(route('dealer.appointments.update', [slug.value, selected.value.ulid]), { staff: ulid || null }, { preserveScroll: true });
}

function cancel() {
    if (!selected.value) return;
    cancelForm.post(route('dealer.appointments.cancel', [slug.value, selected.value.ulid]), { preserveScroll: true, onSuccess: () => (selected.value = null) });
}

const canMove = (a: Item) => (a.status === 'pending' || a.status === 'confirmed') && !a.checked_in;
</script>

<template>
    <Head title="Calendar" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-4">
            <div>
                <h1 class="text-[30px] font-bold">Calendar</h1>
                <span class="text-[14px] text-muted">{{ week.label }} · {{ bookedCount }} {{ bookedCount === 1 ? 'visit' : 'visits' }} booked</span>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="t in types"
                    :key="t.value"
                    type="button"
                    class="flex h-8 items-center gap-1.5 rounded-full px-3 text-[12px] font-semibold"
                    :class="[colours[t.value as Item['type']], hidden.includes(t.value) ? 'opacity-40' : '']"
                    :aria-pressed="!hidden.includes(t.value)"
                    @click="hidden = hidden.includes(t.value) ? hidden.filter((h) => h !== t.value) : [...hidden, t.value]"
                >
                    {{ t.label.replace(' valuation', '') }}
                </button>
                <span class="ml-2 flex overflow-hidden rounded-xl border border-line bg-white">
                    <Link :href="route('dealer.calendar', { lot: slug, week: week.prev })" class="flex h-10 w-10 items-center justify-center text-ink" aria-label="Previous week" preserve-scroll>‹</Link>
                    <Link :href="route('dealer.calendar', { lot: slug })" class="flex h-10 items-center border-x border-line px-3 text-[14px] font-semibold text-ink no-underline" :class="isCurrentWeek ? 'bg-ivory' : ''">Today</Link>
                    <Link :href="route('dealer.calendar', { lot: slug, week: week.next })" class="flex h-10 w-10 items-center justify-center text-ink" aria-label="Next week" preserve-scroll>›</Link>
                </span>
            </div>
        </div>

        <div class="grid gap-4 xl:grid-cols-[1fr_320px]">
            <!-- Week grid (desktop) -->
            <div class="card hidden overflow-x-auto lg:block">
                <div class="grid min-w-[760px]" :style="{ gridTemplateColumns: `56px repeat(${shownDays.length}, minmax(0, 1fr))` }">
                    <div />
                    <div v-for="d in shownDays" :key="d.date" class="border-b border-l border-divider py-2 text-center" :class="d.today ? 'bg-cream' : ''">
                        <div class="text-[11px] font-semibold text-muted">{{ d.weekday }}</div>
                        <div class="text-[17px] font-bold" :class="d.today ? 'text-clay' : ''">{{ d.day }}</div>
                    </div>

                    <div class="relative" :style="{ height: `${hours.length * HOUR_PX}px` }">
                        <div v-for="(h, i) in hours" :key="h" class="absolute right-2 text-[11px] text-muted" :style="{ top: `${i * HOUR_PX - 6}px` }">{{ String(h).padStart(2, '0') }}:00</div>
                    </div>
                    <div
                        v-for="d in shownDays"
                        :key="d.date"
                        class="relative border-l border-divider"
                        :class="d.today ? 'bg-cream/50' : ''"
                        :style="{ height: `${hours.length * HOUR_PX}px`, backgroundImage: `repeating-linear-gradient(to bottom, transparent 0, transparent ${HOUR_PX - 1}px, #EEEAE3 ${HOUR_PX - 1}px, #EEEAE3 ${HOUR_PX}px)` }"
                        @dragover.prevent
                        @drop="onDrop($event, d.date)"
                    >
                        <button
                            v-for="a in byDay(d.date)"
                            :key="a.ulid"
                            type="button"
                            class="absolute overflow-hidden rounded-lg px-2 py-1 text-left text-[12px] leading-tight shadow-sm"
                            :class="[colours[a.type], a.status === 'pending' ? 'border-2 border-dashed border-current/40' : '', a.status === 'no_show' || a.status === 'completed' ? 'opacity-55' : '']"
                            :style="{
                                top: `${top(a)}px`,
                                height: `${Math.max(28, (a.minutes / 60) * HOUR_PX - 3)}px`,
                                left: `calc(${(lanes[a.ulid].lane / lanes[a.ulid].lanes) * 100}% + 3px)`,
                                width: `calc(${100 / lanes[a.ulid].lanes}% - 6px)`,
                            }"
                            :draggable="canMove(a)"
                            @dragstart="dragging = a"
                            @click="open(a)"
                        >
                            <strong class="block truncate">{{ a.time }} {{ a.type_label.replace(' valuation', '') }}</strong>
                            <span class="block truncate">{{ a.status === 'pending' ? 'Pending · ' : '' }}{{ a.customer }}<template v-if="a.car"> · {{ a.car }}</template></span>
                        </button>
                    </div>
                </div>
                <p class="border-t border-divider px-4 py-2 text-[12px] text-muted">Drag a booking to move it. The buyer is told automatically.</p>
            </div>

            <!-- Day list (phone) -->
            <div class="flex flex-col gap-3 lg:hidden">
                <div class="-mx-5 flex gap-1.5 overflow-x-auto px-5">
                    <button
                        v-for="d in shownDays"
                        :key="d.date"
                        type="button"
                        class="flex h-14 w-12 shrink-0 flex-col items-center justify-center rounded-xl"
                        :class="mobileDay === d.date ? 'bg-forest text-white' : 'border border-line bg-white'"
                        :aria-pressed="mobileDay === d.date"
                        @click="mobileDay = d.date"
                    >
                        <span class="text-[10px]">{{ d.weekday }}</span><span class="text-[16px] font-bold">{{ d.day }}</span>
                    </button>
                </div>
                <p v-if="!byDay(mobileDay).length" class="card px-4 py-6 text-center text-[14px] text-muted">No visits this day.</p>
                <button v-for="a in byDay(mobileDay)" :key="a.ulid" type="button" class="card flex items-center gap-3 p-3 text-left" @click="open(a)">
                    <span class="rounded-lg px-2 py-1 text-[13px] font-bold" :class="colours[a.type]">{{ a.time }}</span>
                    <span class="flex min-w-0 flex-col">
                        <span class="truncate text-[14px] font-semibold">{{ a.customer }} · {{ a.type_label }}</span>
                        <span class="truncate text-[13px] text-muted">{{ a.status_label }}<template v-if="a.car"> · {{ a.car }}</template></span>
                    </span>
                </button>
            </div>

            <!-- Side panel -->
            <aside class="flex flex-col gap-4">
                <section class="card p-4">
                    <h2 class="mb-1 font-sans text-[16px] font-bold">Today · {{ today.label }}</h2>
                    <p v-if="!today.items.length" class="py-2 text-[14px] text-muted">No visits today.</p>
                    <div v-for="a in today.items" :key="a.ulid" class="flex flex-col gap-1.5 border-t border-divider py-2.5 first:border-t-0">
                        <button type="button" class="flex justify-between gap-2 text-left text-[14px]" @click="open(a)">
                            <span><strong>{{ a.time }}</strong> · {{ a.customer }}</span>
                            <span v-if="a.checked_in && a.status === 'confirmed'" class="text-[12px] font-semibold text-success">Checked in</span>
                            <span v-else-if="a.status !== 'confirmed'" class="text-[12px] text-muted">{{ a.status_label }}</span>
                        </button>
                        <span class="text-[13px] text-muted">{{ a.type_label }}<template v-if="a.car"> · {{ a.car }}</template><template v-if="a.staff"> · with {{ a.staff.name?.split(' ')[0] }}</template></span>
                        <div v-if="a.status === 'confirmed' || a.status === 'pending'" class="flex gap-2">
                            <button v-if="!a.checked_in" type="button" class="h-8 rounded-lg bg-forest px-2.5 text-[12px] font-semibold text-white" @click="act(a, 'check_in')">Check in</button>
                            <button v-if="!a.checked_in && a.past" type="button" class="h-8 rounded-lg border border-line px-2.5 text-[12px] font-semibold" @click="act(a, 'no_show')">No-show</button>
                            <button v-if="a.checked_in" type="button" class="h-8 rounded-lg border border-line px-2.5 text-[12px] font-semibold" @click="act(a, 'complete')">Complete</button>
                        </div>
                    </div>
                </section>

                <section v-if="pending.length" class="card border-apricot bg-cream p-4">
                    <h2 class="mb-1 font-sans text-[16px] font-bold text-clay-dark">Needs confirmation ({{ pending.length }})</h2>
                    <div v-for="a in pending" :key="a.ulid" class="flex flex-col gap-1.5 border-t border-[#F6DCCB] py-2.5 first:border-t-0">
                        <span class="text-[14px]"><strong>{{ a.when }}</strong> · {{ a.customer }}</span>
                        <span class="text-[13px] text-muted">{{ a.type_label }}<template v-if="a.car"> · {{ a.car }}</template></span>
                        <div class="flex gap-2">
                            <button type="button" class="h-8 rounded-lg bg-forest px-2.5 text-[12px] font-semibold text-white" @click="act(a, 'confirm')">Confirm</button>
                            <button type="button" class="h-8 rounded-lg border border-line bg-white px-2.5 text-[12px] font-semibold" @click="open(a)">Suggest time</button>
                        </div>
                    </div>
                </section>
            </aside>
        </div>

        <!-- Booking drawer -->
        <div v-if="selected" class="fixed inset-0 z-50 bg-ink/40" @click.self="selected = null">
            <div class="absolute inset-y-0 right-0 flex w-full max-w-md flex-col gap-4 overflow-y-auto bg-white p-5 shadow-2xl" role="dialog" aria-modal="true" aria-labelledby="booking-title">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <span class="rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="colours[selected.type]">{{ selected.type_label }}</span>
                        <h2 id="booking-title" class="mt-2 text-[22px] font-bold">{{ selected.when }}</h2>
                        <p class="text-[14px] text-muted">{{ selected.status_label }}<template v-if="selected.checked_in"> · checked in</template></p>
                    </div>
                    <button type="button" class="flex h-11 w-11 items-center justify-center" aria-label="Close" @click="selected = null"><Icon name="close" :size="22" :stroke-width="2" /></button>
                </div>

                <dl class="flex flex-col gap-2 text-[14px]">
                    <div class="flex justify-between gap-3"><dt class="text-muted">Buyer</dt><dd class="font-semibold">{{ selected.customer }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-muted">Phone</dt><dd class="font-semibold">{{ selected.phone_display }}</dd></div>
                    <div v-if="selected.car" class="flex justify-between gap-3"><dt class="text-muted">Car</dt><dd class="text-right font-semibold">{{ selected.car }}</dd></div>
                    <div v-if="selected.notes" class="flex flex-col gap-1"><dt class="text-muted">Note from buyer</dt><dd class="rounded-xl bg-ivory p-3">{{ selected.notes }}</dd></div>
                </dl>

                <div class="flex gap-2">
                    <a :href="`tel:${selected.phone}`" class="btn btn-outline h-11 grow text-[14px]"><Icon name="phone" :size="18" /> Call</a>
                    <a :href="`https://wa.me/${selected.whatsapp}`" target="_blank" rel="noopener" class="btn btn-outline h-11 grow text-[14px]"><Icon name="whatsapp" :size="18" /> WhatsApp</a>
                </div>

                <div v-if="selected.status === 'pending' || selected.status === 'confirmed'" class="flex flex-wrap gap-2">
                    <button v-if="selected.status === 'pending'" type="button" class="btn btn-primary h-11 text-[14px]" @click="act(selected, 'confirm')">Confirm</button>
                    <button v-if="!selected.checked_in" type="button" class="btn btn-dark h-11 text-[14px]" @click="act(selected, 'check_in')">Check in</button>
                    <button v-if="!selected.checked_in && selected.past" type="button" class="btn btn-outline h-11 text-[14px]" @click="act(selected, 'no_show')">No-show</button>
                    <button v-if="selected.checked_in" type="button" class="btn btn-outline h-11 text-[14px]" @click="act(selected, 'complete')">Complete visit</button>
                </div>

                <label v-if="canAssign" class="field-label">
                    Sales rep
                    <select class="field" :value="selected.staff?.ulid ?? ''" @change="assign(($event.target as HTMLSelectElement).value)">
                        <option value="">Not assigned</option>
                        <option v-for="s in staff" :key="s.ulid" :value="s.ulid">{{ s.name }}</option>
                    </select>
                </label>

                <section v-if="canMove(selected)" class="flex flex-col gap-2 border-t border-divider pt-4">
                    <h3 class="font-sans text-[15px] font-bold">Move to another time</h3>
                    <InputError :message="moveError ?? undefined" />
                    <div v-if="slots.length" class="flex flex-col gap-2">
                        <select v-model="moveDate" class="field" aria-label="Day">
                            <option v-for="d in slots" :key="d.date" :value="d.date" :disabled="d.closed">{{ d.weekday }} {{ d.day }}{{ d.closed ? ' (closed)' : '' }}</option>
                        </select>
                        <div class="grid grid-cols-4 gap-1.5">
                            <button
                                v-for="s in moveDay?.slots ?? []"
                                :key="s.starts_at"
                                type="button"
                                class="h-10 rounded-lg text-[13px]"
                                :class="s.available ? 'border border-line bg-white font-medium hover:border-forest' : 'border border-dashed border-line-strong text-muted/60 line-through'"
                                :disabled="!s.available"
                                @click="reschedule(selected, moveDate, s.time, s.starts_at)"
                            >
                                {{ s.time }}
                            </button>
                        </div>
                        <p class="text-[12px] text-muted">The buyer gets a WhatsApp message with the new time.</p>
                    </div>
                </section>

                <section v-if="selected.status === 'pending' || selected.status === 'confirmed'" class="border-t border-divider pt-4">
                    <button v-if="!cancelling" type="button" class="text-[14px] font-semibold text-clay" @click="cancelling = true">Cancel this booking</button>
                    <form v-else class="flex flex-col gap-2" @submit.prevent="cancel">
                        <label class="field-label">
                            Reason for the buyer
                            <input v-model="cancelForm.reason" class="field" maxlength="200" placeholder="e.g. The car has been sold" />
                        </label>
                        <button type="submit" class="btn btn-primary h-11 text-[14px]" :disabled="cancelForm.processing">Cancel and tell the buyer</button>
                    </form>
                </section>
            </div>
        </div>
    </DealerLayout>
</template>
