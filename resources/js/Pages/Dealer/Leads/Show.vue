<script setup lang="ts">
import ChatComposer from '@/components/chat/ChatComposer.vue';
import ChatThread from '@/components/chat/ChatThread.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import type { Option } from '@/components/manager/types';
import { useChat, type ChatMessage } from '@/composables/useChat';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

interface Lead {
    ulid: string;
    name: string;
    full_name: string | null;
    car: string | null;
    car_url: string | null;
    car_price: string | null;
    source: string;
    source_label: string;
    stage: string;
    phone: { e164: string; display: string; whatsapp: string } | null;
    first_contact: string | null;
    budget: string | null;
    assigned_ulid: string | null;
    follow_up_at: string | null;
    customer_book: string | null;
    conversation: string | null;
    lost_reason: string | null;
}

const props = defineProps<{
    lead: Lead;
    messages: ChatMessage[];
    notes: { body: string; by: string | null; at: string | null }[];
    stages: Option[];
    staff: { ulid: string; name: string }[];
    canAssign: boolean;
    bookUrl: string;
}>();

const { currentLot, user } = useShared();
const lot = computed(() => currentLot.value!);
const chat = useChat(computed(() => props.lead.conversation), computed(() => props.messages), () => void markRead());
const lostReason = ref(props.lead.lost_reason ?? '');
const askLost = ref(false);
const note = useForm({ body: '' });
const error = ref('');

function markRead() {
    return fetch(route('dealer.leads.read', [lot.value.slug, props.lead.ulid]), { method: 'POST', headers: { 'X-XSRF-TOKEN': decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)?.[1] ?? ''), Accept: 'application/json' }, credentials: 'same-origin' });
}

function update(data: Record<string, string | null>) {
    error.value = '';
    router.patch(route('dealer.leads.update', [lot.value.slug, props.lead.ulid]), data, {
        preserveScroll: true,
        onError: (errors) => (error.value = Object.values(errors)[0] ?? ''),
        onSuccess: () => (askLost.value = false),
    });
}

function setStage(stage: string) {
    if (stage === 'lost') {
        askLost.value = true;
        return;
    }
    update({ stage });
}

function preset(kind: 'location' | 'similar' | 'bank') {
    router.post(route('dealer.leads.messages.store', [lot.value.slug, props.lead.ulid]), { preset: kind }, {
        preserveScroll: true,
        onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Could not send.'),
    });
}

const whatsapp = computed(() =>
    props.lead.phone ? `https://wa.me/${props.lead.phone.whatsapp}?text=${encodeURIComponent(`Hi ${props.lead.name.split(' ')[0]}, this is ${lot.value.name} on LotLink${props.lead.car ? ` about the ${props.lead.car}` : ''}.`)}` : null,
);
</script>

<template>
    <Head :title="`Lead · ${lead.name}`" />
    <DealerLayout>
        <div class="-mx-5 -my-6 grid min-h-[calc(100dvh-60px)] lg:-mx-8 lg:-my-7 lg:min-h-dvh lg:grid-cols-[1fr_340px]">
            <section class="flex min-w-0 flex-col border-line bg-white lg:h-dvh lg:border-r">
                <div class="flex flex-wrap items-center gap-3 border-b border-line px-4 py-3.5 md:px-6">
                    <Link :href="route('dealer.leads.index', lot.slug)" aria-label="Back to leads" class="-ml-2 flex h-11 w-11 items-center justify-center text-ink"><Icon name="chevronLeft" :size="20" :stroke-width="2" /></Link>
                    <div class="flex min-w-0 grow flex-col">
                        <h1 class="font-sans text-[18px] font-bold">{{ lead.full_name ?? lead.name }}</h1>
                        <span class="truncate text-[13px] text-muted">
                            <template v-if="lead.car">Asking about <a v-if="lead.car_url" :href="lead.car_url" target="_blank" rel="noopener">{{ lead.car }}</a><template v-else>{{ lead.car }}</template> · </template>via {{ lead.source_label.toLowerCase() }}
                        </span>
                    </div>
                    <Link :href="bookUrl" class="btn btn-outline h-11 px-3.5 text-[14px]">Book a visit</Link>
                    <a v-if="whatsapp" :href="whatsapp" target="_blank" rel="noopener" class="btn h-11 bg-[#25D366] px-3.5 text-[14px] text-[#0B3D1F] hover:text-[#0B3D1F]"><Icon name="whatsapp" :size="18" /> Continue on WhatsApp</a>
                </div>

                <ChatThread
                    class="min-h-[320px]"
                    :messages="chat.messages.value"
                    me="lot"
                    :typing="chat.typing.value"
                    :empty-text="lead.conversation ? 'No messages yet.' : `${lead.name} reached you by ${lead.source_label.toLowerCase()}. Send a message to start a chat in their LotLink app.`"
                />

                <div class="flex flex-col gap-2.5 border-t border-line px-4 pt-3 pb-4 md:px-6">
                    <InputError :message="error" />
                    <div class="flex flex-wrap gap-2">
                        <button type="button" class="h-9 rounded-full border border-line-strong bg-white px-3 text-[13px] font-medium" @click="preset('location')">Send lot location</button>
                        <button type="button" class="h-9 rounded-full border border-line-strong bg-white px-3 text-[13px] font-medium" @click="preset('similar')">Suggest similar cars</button>
                        <button type="button" class="h-9 rounded-full border border-line-strong bg-white px-3 text-[13px] font-medium" @click="preset('bank')">Send bank details</button>
                        <span class="flex h-9 items-center gap-1.5 rounded-full border border-dashed border-line-strong px-3 text-[13px] text-muted/70" aria-disabled="true">Send inspection report <span class="text-[10px] font-semibold uppercase">Soon</span></span>
                    </div>
                    <ChatComposer
                        :url="route('dealer.leads.messages.store', [lot.slug, lead.ulid])"
                        :placeholder="`Reply to ${lead.name.split(' ')[0]}`"
                        @typing="chat.whisperTyping(user?.name?.split(' ')[0] ?? lot.name)"
                    />
                </div>
            </section>

            <aside class="flex flex-col gap-4 p-5">
                <section class="card p-4" aria-labelledby="lead-info">
                    <h2 id="lead-info" class="mb-1.5 font-sans text-[15px] font-bold">Lead</h2>
                    <dl class="text-[14px]">
                        <div class="flex justify-between gap-3 border-t border-divider py-2 first:border-0">
                            <dt class="text-muted">Phone</dt>
                            <dd class="font-semibold"><a v-if="lead.phone" :href="`tel:${lead.phone.e164}`" class="text-ink">{{ lead.phone.display }}</a><template v-else>—</template></dd>
                        </div>
                        <div class="flex justify-between gap-3 border-t border-divider py-2"><dt class="text-muted">Source</dt><dd class="font-semibold">{{ lead.source_label }}</dd></div>
                        <div class="flex justify-between gap-3 border-t border-divider py-2"><dt class="text-muted">First contact</dt><dd class="font-semibold">{{ lead.first_contact }}</dd></div>
                        <div v-if="lead.car_price" class="flex justify-between gap-3 border-t border-divider py-2"><dt class="text-muted">Car price</dt><dd class="font-semibold">{{ lead.car_price }}</dd></div>
                        <div class="flex justify-between gap-3 border-t border-divider py-2"><dt class="text-muted">Budget saved</dt><dd class="font-semibold">{{ lead.budget ?? '—' }}</dd></div>
                    </dl>
                    <Link v-if="lead.customer_book" :href="lead.customer_book" class="mt-1 inline-flex h-11 items-center text-[14px] font-semibold">Open in customer book</Link>
                </section>

                <section class="card flex flex-col gap-3 p-4" aria-label="Stage and follow-up">
                    <label class="field-label">
                        Stage
                        <select :value="askLost ? 'lost' : lead.stage" class="field h-11" @change="setStage(($event.target as HTMLSelectElement).value)">
                            <option v-for="s in stages" :key="s.value" :value="s.value">{{ s.label }}</option>
                        </select>
                    </label>
                    <form v-if="askLost" class="flex flex-col gap-2 rounded-xl bg-cream p-3" @submit.prevent="update({ stage: 'lost', lost_reason: lostReason })">
                        <label class="field-label">Why was it lost?<input v-model="lostReason" class="field h-11" required maxlength="120" placeholder="Bought elsewhere" /></label>
                        <button type="submit" class="btn btn-dark h-11 text-[14px]">Mark lost</button>
                    </form>
                    <p v-else-if="lead.stage === 'lost' && lead.lost_reason" class="text-[13px] text-muted">Lost: {{ lead.lost_reason }}</p>
                    <label class="field-label">
                        Assigned to
                        <select
                            :value="lead.assigned_ulid ?? ''"
                            class="field h-11"
                            @change="update({ assigned_to: ($event.target as HTMLSelectElement).value || null })"
                        >
                            <option value="">Nobody yet</option>
                            <option v-for="s in staff" :key="s.ulid" :value="s.ulid" :disabled="!canAssign && s.ulid !== user?.ulid">{{ s.name }}</option>
                        </select>
                    </label>
                    <label class="field-label">
                        Follow up on
                        <input type="datetime-local" :value="lead.follow_up_at ?? ''" class="field h-11" @change="update({ next_follow_up_at: ($event.target as HTMLInputElement).value || null })" />
                    </label>
                </section>

                <section class="card flex flex-col gap-2.5 p-4" aria-labelledby="notes-heading">
                    <h2 id="notes-heading" class="font-sans text-[15px] font-bold">Notes</h2>
                    <form class="flex flex-col gap-2" @submit.prevent="note.post(route('dealer.leads.notes.store', [lot.slug, lead.ulid]), { preserveScroll: true, onSuccess: () => note.reset() })">
                        <label><span class="sr-only">Note</span><textarea v-model="note.body" class="field h-20 py-2.5" placeholder="Only your team sees notes" maxlength="2000" /></label>
                        <button type="submit" class="btn btn-outline h-10 self-start px-3 text-[13px]" :disabled="note.processing || !note.body">Add note</button>
                    </form>
                    <div v-for="(n, i) in notes" :key="i" class="flex flex-col gap-0.5 border-t border-divider pt-2">
                        <p class="text-[13px] whitespace-pre-line text-ink">{{ n.body }}</p>
                        <span class="text-[11px] text-muted">{{ n.by }} · {{ n.at }}</span>
                    </div>
                </section>
            </aside>
        </div>
    </DealerLayout>
</template>
