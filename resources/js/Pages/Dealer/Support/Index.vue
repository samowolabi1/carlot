<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Option = { value: string; label: string };
type Ticket = {
    ulid: string;
    reference: string;
    subject: string;
    category: string;
    priority: string;
    priority_label: string;
    status: string;
    status_label: string;
    unread: boolean;
    opened_by: string | null;
    messages: number | null;
    opened: string;
    updated: string | null;
};

const props = defineProps<{
    filter: 'active' | 'done';
    counts: { active: number; waiting: number; done: number };
    tickets: { data: Ticket[]; links: { url: string | null; label: string; active: boolean }[]; last_page: number };
    form: { categories: Option[]; priorities: Option[]; cars: Option[]; max_mb: number };
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const composing = ref(props.counts.active + props.counts.done === 0);
const file = ref<HTMLInputElement | null>(null);

const form = useForm<{ category: string; subject: string; priority: string; vehicle: string; body: string; attachment: File | null }>({
    category: '',
    subject: '',
    priority: 'normal',
    vehicle: '',
    body: '',
    attachment: null,
});

function pick(e: Event) {
    form.attachment = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function submit() {
    form.post(route('dealer.support.store', lot.value.slug), { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
}

const tone: Record<string, string> = {
    open: 'bg-sand text-ink',
    pending: 'bg-blush text-clay-dark',
    resolved: 'bg-[#DCEFE3] text-[#166534]',
    closed: 'bg-sand text-muted',
};
</script>

<template>
    <Head title="Help & support" />
    <DealerLayout>
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <h1 class="text-[30px] font-bold">Help & support</h1>
                <p class="text-[14px] text-muted">Message the LotLink team about anything: billing, listings, bookings, payouts or something that isn't working.</p>
            </div>
            <button v-if="!composing" type="button" class="btn btn-primary h-11" @click="composing = true"><Icon name="plus" :size="18" /> New ticket</button>
        </div>

        <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
            <div class="flex min-w-0 flex-col gap-4">
                <form v-if="composing" class="card flex flex-col gap-4 p-5" aria-labelledby="new-ticket" @submit.prevent="submit">
                    <div class="flex items-center justify-between">
                        <h2 id="new-ticket" class="font-sans text-[17px] font-bold">New ticket</h2>
                        <button v-if="counts.active + counts.done > 0" type="button" class="flex h-11 w-11 items-center justify-center rounded-xl text-muted hover:text-ink" aria-label="Close the form" @click="composing = false">
                            <Icon name="close" />
                        </button>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <label class="field-label">
                            What is it about?
                            <select v-model="form.category" class="field h-11" required>
                                <option value="" disabled>Choose a topic</option>
                                <option v-for="c in props.form.categories" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                            <InputError :message="form.errors.category" />
                        </label>
                        <label class="field-label">
                            Which car? <span class="font-normal text-muted">(optional)</span>
                            <select v-model="form.vehicle" class="field h-11">
                                <option value="">Not about a car</option>
                                <option v-for="c in props.form.cars" :key="c.value" :value="c.value">{{ c.label }}</option>
                            </select>
                        </label>
                    </div>

                    <label class="field-label">
                        Subject
                        <input v-field="{ kind: 'text', min: 4, max: 160 }" v-model="form.subject" class="field h-11" required placeholder="e.g. Deposit from a buyer hasn't reached our account" />
                        <InputError :message="form.errors.subject" />
                    </label>

                    <fieldset class="flex flex-col gap-2">
                        <legend class="field-label mb-1">How urgent is it?</legend>
                        <div class="grid grid-cols-2 gap-2 sm:grid-cols-4">
                            <label
                                v-for="p in props.form.priorities"
                                :key="p.value"
                                class="flex min-h-11 cursor-pointer items-center gap-2 rounded-xl border px-3 py-2 text-[13px] font-semibold"
                                :class="form.priority === p.value ? 'border-forest bg-map text-forest' : 'border-line bg-white'"
                            >
                                <input v-model="form.priority" type="radio" name="priority" :value="p.value" class="sr-only" />
                                {{ p.label }}
                            </label>
                        </div>
                    </fieldset>

                    <label class="field-label">
                        Message
                        <textarea v-field="{ kind: 'text', min: 10, max: 5000 }"
                            v-model="form.body"
                            rows="6"
                            maxlength="5000"
                            required
                            class="field h-auto py-2.5"
                            placeholder="What happened, what you expected, and anything you've tried. Include order or receipt numbers if it's about money."
                        />
                        <InputError :message="form.errors.body" />
                    </label>

                    <div class="flex flex-col gap-1.5">
                        <input ref="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="sr-only" tabindex="-1" aria-hidden="true" @change="pick" />
                        <div class="flex flex-wrap items-center gap-2">
                            <button type="button" class="btn btn-outline h-11" @click="file?.click()"><Icon name="paperclip" :size="18" /> {{ form.attachment ? 'Change file' : 'Attach a screenshot or PDF' }}</button>
                            <span v-if="form.attachment" class="flex items-center gap-2 text-[13px]">
                                {{ form.attachment.name }}
                                <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:text-danger" aria-label="Remove the file" @click="form.attachment = null">
                                    <Icon name="close" :size="16" />
                                </button>
                            </span>
                        </div>
                        <span class="text-[12px] text-muted">Up to {{ props.form.max_mb }} MB. Only your team and LotLink Support can open it.</span>
                        <InputError :message="form.errors.attachment" />
                    </div>

                    <div class="flex gap-2">
                        <button type="submit" class="btn btn-primary h-11" :disabled="form.processing">
                            <Icon name="send" :size="18" /> {{ form.processing ? 'Sending…' : 'Send to LotLink' }}
                        </button>
                    </div>
                </form>

                <div class="flex gap-1 border-b border-line" role="tablist" aria-label="Tickets">
                    <Link
                        :href="route('dealer.support.index', lot.slug)"
                        class="flex h-11 items-center gap-2 border-b-2 px-3 text-[14px] font-semibold no-underline"
                        :class="filter === 'active' ? 'border-clay text-ink' : 'border-transparent text-muted'"
                        role="tab"
                        :aria-selected="filter === 'active'"
                        preserve-scroll
                    >
                        Open <span class="rounded-full bg-sand px-2 py-0.5 text-[12px]">{{ counts.active }}</span>
                    </Link>
                    <Link
                        :href="route('dealer.support.index', { lot: lot.slug, show: 'done' })"
                        class="flex h-11 items-center gap-2 border-b-2 px-3 text-[14px] font-semibold no-underline"
                        :class="filter === 'done' ? 'border-clay text-ink' : 'border-transparent text-muted'"
                        role="tab"
                        :aria-selected="filter === 'done'"
                        preserve-scroll
                    >
                        Solved <span class="rounded-full bg-sand px-2 py-0.5 text-[12px]">{{ counts.done }}</span>
                    </Link>
                </div>

                <p v-if="counts.waiting && filter === 'active'" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark" role="status">
                    LotLink is waiting for your reply on {{ counts.waiting }} {{ counts.waiting === 1 ? 'ticket' : 'tickets' }}.
                </p>

                <div v-if="!tickets.data.length" class="card flex flex-col items-center gap-2 p-10 text-center">
                    <Icon name="lifebuoy" :size="28" class="text-muted" />
                    <p class="max-w-sm text-[15px] text-muted">{{ filter === 'active' ? 'No open tickets. If something needs our attention, start a new ticket.' : 'Nothing solved yet.' }}</p>
                </div>

                <ul v-else class="flex flex-col gap-2">
                    <li v-for="t in tickets.data" :key="t.ulid">
                        <Link :href="route('dealer.support.show', [lot.slug, t.ulid])" class="card flex items-start gap-3 p-4 text-ink no-underline hover:border-line-strong">
                            <span class="mt-1.5 h-2.5 w-2.5 shrink-0 rounded-full" :class="t.unread ? 'bg-clay' : 'bg-transparent'" :aria-label="t.unread ? 'New reply' : undefined" />
                            <span class="flex min-w-0 grow flex-col gap-1">
                                <span class="flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <strong class="truncate text-[15px]" :class="{ 'font-bold': t.unread }">{{ t.subject }}</strong>
                                    <span class="rounded-lg px-2 py-0.5 text-[12px] font-semibold" :class="tone[t.status]">{{ t.status_label }}</span>
                                    <span v-if="t.priority === 'urgent' || t.priority === 'high'" class="rounded-lg bg-[#FDECEC] px-2 py-0.5 text-[12px] font-semibold text-danger">{{ t.priority_label }}</span>
                                </span>
                                <span class="text-[13px] text-muted">
                                    {{ t.reference }} · {{ t.category }}<template v-if="t.opened_by"> · by {{ t.opened_by }}</template> · {{ t.messages }} {{ t.messages === 1 ? 'message' : 'messages' }}
                                </span>
                            </span>
                            <span class="shrink-0 text-[12px] text-muted">{{ t.updated }}</span>
                        </Link>
                    </li>
                </ul>

                <nav v-if="tickets.last_page > 1" aria-label="Pages" class="flex flex-wrap gap-1">
                    <template v-for="link in tickets.links" :key="link.label">
                        <Link v-if="link.url" :href="link.url" preserve-scroll class="flex h-9 min-w-9 items-center justify-center rounded-lg px-2.5 text-[14px] no-underline" :class="link.active ? 'bg-forest text-white' : 'text-ink hover:bg-white'"><span v-html="link.label" /></Link>
                    </template>
                </nav>
            </div>

            <aside class="flex flex-col gap-3 rounded-2xl bg-cream p-4 text-[14px]">
                <h2 class="flex items-center gap-2 font-sans text-[15px] font-bold text-clay-dark"><Icon name="clock" :size="18" /> How we help</h2>
                <p>We reply within one working day, faster for urgent tickets. You'll get a notification and an email when we answer.</p>
                <p>Anyone on your team can open a ticket; everyone on the team can follow it here.</p>
                <h3 class="pt-1 font-sans text-[14px] font-bold">Quick answers</h3>
                <ul class="flex flex-col gap-1">
                    <li><Link :href="route('dealer.billing', lot.slug)" class="inline-flex min-h-9 items-center font-semibold">Change plan or card</Link></li>
                    <li><Link :href="route('dealer.staff', lot.slug)" class="inline-flex min-h-9 items-center font-semibold">Add or remove staff</Link></li>
                    <li><Link :href="route('dealer.settings', lot.slug)" class="inline-flex min-h-9 items-center font-semibold">Hours, location and verification</Link></li>
                </ul>
            </aside>
        </div>
    </DealerLayout>
</template>
