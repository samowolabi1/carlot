<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

export type FinanceThreadMessage = {
    ulid: string;
    side: 'buyer' | 'lender' | 'system';
    mine: boolean;
    author: string;
    body: string;
    file: { name: string | null; url: string } | null;
    time: string;
};

/** The messages between a buyer and a lender on one application, with a box to write and attach a document. */
const props = defineProps<{ messages: FinanceThreadMessage[]; action: string; canSend: boolean; hint: string; maxKb: number }>();

const form = useForm<{ body: string; file: File | null }>({ body: '', file: null });
const picker = ref<HTMLInputElement | null>(null);

function pick(e: Event) {
    form.file = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function send() {
    form.post(props.action, { forceFormData: true, preserveScroll: true, onSuccess: () => form.reset() });
}
</script>

<template>
    <section class="card flex flex-col gap-4 p-5" aria-labelledby="thread-title">
        <h2 id="thread-title" class="font-sans text-[17px] font-bold">Messages and documents</h2>
        <ol class="flex flex-col gap-3">
            <li v-for="m in messages" :key="m.ulid" class="flex" :class="m.side === 'system' ? 'justify-center' : m.mine ? 'justify-end' : 'justify-start'">
                <p v-if="m.side === 'system'" class="max-w-[90%] rounded-xl bg-sand px-3 py-2 text-center text-[13px] whitespace-pre-line text-[#4A4D53]">
                    {{ m.body }} <span class="text-muted">· {{ m.time }}</span>
                </p>
                <div v-else class="flex max-w-[85%] flex-col gap-1 rounded-2xl px-3.5 py-2.5 text-[14px]" :class="m.mine ? 'bg-forest text-white' : 'border border-line bg-white'">
                    <span class="text-[12px] font-semibold" :class="m.mine ? 'text-mist' : 'text-muted'">{{ m.author }} · {{ m.time }}</span>
                    <span class="whitespace-pre-line">{{ m.body }}</span>
                    <a v-if="m.file" :href="m.file.url" target="_blank" rel="noopener" class="inline-flex min-h-11 items-center gap-2 font-semibold" :class="m.mine ? 'text-white' : 'text-forest'">
                        <Icon name="paperclip" :size="16" /> {{ m.file.name ?? 'Document' }}
                    </a>
                </div>
            </li>
        </ol>

        <form v-if="canSend" class="flex flex-col gap-2 border-t border-line pt-4" @submit.prevent="send">
            <label class="field-label">
                Write a message
                <textarea v-field="{ kind: 'text', max: 2000 }" v-model="form.body" rows="3" maxlength="2000" class="field h-auto py-2.5" :placeholder="hint" />
                <InputError :message="form.errors.body" />
            </label>
            <input ref="picker" type="file" accept="image/jpeg,image/png,image/webp,application/pdf" class="sr-only" tabindex="-1" aria-hidden="true" @change="pick" />
            <div class="flex flex-wrap items-center gap-2">
                <button type="button" class="btn btn-outline h-11" @click="picker?.click()"><Icon name="paperclip" :size="18" /> {{ form.file ? 'Change document' : 'Add a document' }}</button>
                <span v-if="form.file" class="flex items-center gap-1 text-[13px]">
                    {{ form.file.name }}
                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg text-muted hover:text-danger" aria-label="Remove the document" @click="form.file = null">
                        <Icon name="close" :size="16" />
                    </button>
                </span>
                <button type="submit" class="btn btn-primary ml-auto h-11" :disabled="form.processing || (!form.body.trim() && !form.file)">
                    <Icon name="send" :size="18" /> {{ form.processing ? 'Sending…' : 'Send' }}
                </button>
            </div>
            <span class="text-[12px] text-muted">PDF or photo, up to {{ Math.round(maxKb / 1024) }} MB. Only you and the other side can open it; the seller never sees it.</span>
            <InputError :message="form.errors.file" />
        </form>
    </section>
</template>
