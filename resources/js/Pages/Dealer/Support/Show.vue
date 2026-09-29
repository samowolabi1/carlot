<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import DealerLayout from '@/layouts/DealerLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Message = {
    ulid: string;
    mine: boolean;
    author: string;
    body: string;
    when: string;
    attachment: { name: string; url: string } | null;
};

const props = defineProps<{
    ticket: {
        ulid: string;
        reference: string;
        subject: string;
        category: string;
        priority: string;
        priority_label: string;
        status: string;
        status_label: string;
        opened_by: string | null;
        opened: string;
        car: { title: string; url: string } | null;
        can_reply: boolean;
        can_resolve: boolean;
        can_reopen: boolean;
    };
    messages: Message[];
    maxMb: number;
}>();

const { currentLot } = useShared();
const lot = computed(() => currentLot.value!);
const file = ref<HTMLInputElement | null>(null);
const form = useForm<{ body: string; attachment: File | null }>({ body: '', attachment: null });

function pick(e: Event) {
    form.attachment = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function send() {
    form.post(route('dealer.support.reply', [lot.value.slug, props.ticket.ulid]), {
        forceFormData: true,
        preserveScroll: true,
        onSuccess: () => {
            form.reset();
            if (file.value) file.value.value = '';
        },
    });
}

function setStatus(status: 'resolved' | 'open') {
    router.patch(route('dealer.support.status', [lot.value.slug, props.ticket.ulid]), { status }, { preserveScroll: true });
}

const tone: Record<string, string> = {
    open: 'bg-sand text-ink',
    pending: 'bg-blush text-clay-dark',
    resolved: 'bg-[#DCEFE3] text-[#166534]',
    closed: 'bg-sand text-muted',
};
</script>

<template>
    <Head :title="`${ticket.reference} · Support`" />
    <DealerLayout>
        <Link :href="route('dealer.support.index', lot.slug)" class="-mb-2 inline-flex min-h-11 items-center gap-1 self-start text-[14px] font-semibold no-underline">
            <Icon name="chevronLeft" :size="18" /> All tickets
        </Link>

        <div class="flex flex-wrap items-start justify-between gap-3">
            <div class="flex min-w-0 flex-col gap-1.5">
                <h1 class="text-[26px] leading-tight font-bold">{{ ticket.subject }}</h1>
                <div class="flex flex-wrap items-center gap-2 text-[13px] text-muted">
                    <span class="rounded-lg px-2 py-0.5 font-semibold" :class="tone[ticket.status]">{{ ticket.status_label }}</span>
                    <span v-if="ticket.priority === 'urgent' || ticket.priority === 'high'" class="rounded-lg bg-[#FDECEC] px-2 py-0.5 font-semibold text-danger">{{ ticket.priority_label }}</span>
                    <span>{{ ticket.reference }} · {{ ticket.category }} · opened {{ ticket.opened }}<template v-if="ticket.opened_by"> by {{ ticket.opened_by }}</template></span>
                    <Link v-if="ticket.car" :href="ticket.car.url" class="inline-flex items-center gap-1 font-semibold"><Icon name="car" :size="16" /> {{ ticket.car.title }}</Link>
                </div>
            </div>
            <div class="flex gap-2">
                <button v-if="ticket.can_resolve" type="button" class="btn btn-outline h-11" @click="setStatus('resolved')"><Icon name="check" :size="18" /> Mark as solved</button>
                <button v-if="ticket.can_reopen" type="button" class="btn btn-outline h-11" @click="setStatus('open')"><Icon name="refresh" :size="18" /> Reopen</button>
            </div>
        </div>

        <p v-if="ticket.status === 'pending'" class="rounded-xl bg-cream px-4 py-3 text-[14px] text-clay-dark" role="status">LotLink Support replied and is waiting for you.</p>
        <p v-else-if="ticket.status === 'open'" class="rounded-xl bg-map px-4 py-3 text-[14px] text-forest" role="status">With LotLink Support. We'll reply here and let you know.</p>

        <ol class="flex max-w-3xl flex-col gap-3" aria-label="Messages">
            <li v-for="m in messages" :key="m.ulid" class="flex flex-col gap-1" :class="m.mine ? 'items-end' : 'items-start'">
                <span class="flex items-center gap-1.5 px-1 text-[12px] text-muted">
                    <Icon v-if="!m.mine" name="lifebuoy" :size="14" class="text-forest" />
                    <strong class="text-ink">{{ m.author }}</strong> · {{ m.when }}
                </span>
                <div class="flex max-w-[92%] flex-col gap-2 rounded-2xl px-4 py-3 text-[15px] sm:max-w-[80%]" :class="m.mine ? 'rounded-tr-md bg-forest text-white' : 'rounded-tl-md border border-line bg-white'">
                    <p class="whitespace-pre-line">{{ m.body }}</p>
                    <a
                        v-if="m.attachment"
                        :href="m.attachment.url"
                        target="_blank"
                        rel="noopener"
                        class="inline-flex min-h-9 items-center gap-1.5 self-start rounded-lg px-2.5 text-[13px] font-semibold no-underline"
                        :class="m.mine ? 'bg-forest-700 text-white hover:text-white' : 'bg-ivory text-ink'"
                    >
                        <Icon name="paperclip" :size="16" /> {{ m.attachment.name }}
                    </a>
                </div>
            </li>
        </ol>

        <form v-if="ticket.can_reply" class="card flex max-w-3xl flex-col gap-3 p-4" @submit.prevent="send">
            <label class="field-label">
                {{ ticket.status === 'resolved' ? 'Still need help? Reply to reopen it' : 'Reply' }}
                <textarea v-model="form.body" rows="4" maxlength="5000" class="field h-auto py-2.5" placeholder="Write to LotLink Support" required />
                <InputError :message="form.errors.body" />
            </label>
            <input ref="file" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="sr-only" tabindex="-1" aria-hidden="true" @change="pick" />
            <div class="flex flex-wrap items-center gap-2">
                <button type="submit" class="btn btn-primary h-11" :disabled="form.processing || !form.body.trim()"><Icon name="send" :size="18" /> {{ form.processing ? 'Sending…' : 'Send' }}</button>
                <button type="button" class="btn btn-outline h-11" @click="file?.click()"><Icon name="paperclip" :size="18" /> {{ form.attachment ? 'Change file' : 'Attach' }}</button>
                <span v-if="form.attachment" class="flex items-center gap-1 text-[13px]">
                    {{ form.attachment.name }}
                    <button type="button" class="flex h-9 w-9 items-center justify-center rounded-lg text-muted hover:text-danger" aria-label="Remove the file" @click="form.attachment = null"><Icon name="close" :size="16" /></button>
                </span>
            </div>
            <span class="text-[12px] text-muted">Screenshots, photos or PDFs up to {{ maxMb }} MB.</span>
            <InputError :message="form.errors.attachment" />
        </form>
        <p v-else class="text-[14px] text-muted">This ticket is closed. <Link :href="route('dealer.support.index', lot.slug)" class="font-semibold">Open a new ticket</Link> and mention {{ ticket.reference }} if you still need help.</p>
    </DealerLayout>
</template>
