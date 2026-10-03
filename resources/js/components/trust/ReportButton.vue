<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import { router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

type Kind = 'vehicle' | 'lot' | 'review' | 'message';

const props = withDefaults(defineProps<{ kind: Kind; id: string; label?: string }>(), { label: '' });

// Mirrors ReportReason::for() in PHP.
const reasons: Record<Kind, { value: string; label: string }[]> = {
    vehicle: [
        { value: 'scam', label: 'Looks like a scam' },
        { value: 'misleading', label: 'Wrong or misleading details' },
        { value: 'sold', label: 'Car is no longer for sale' },
        { value: 'duplicate', label: 'Listed more than once' },
        { value: 'other', label: 'Something else' },
    ],
    lot: [
        { value: 'scam', label: 'Looks like a scam' },
        { value: 'misleading', label: 'Wrong or misleading details' },
        { value: 'offensive', label: 'Rude or offensive' },
        { value: 'other', label: 'Something else' },
    ],
    review: [
        { value: 'offensive', label: 'Rude or offensive' },
        { value: 'misleading', label: 'Wrong or misleading details' },
        { value: 'spam', label: 'Spam' },
        { value: 'other', label: 'Something else' },
    ],
    message: [
        { value: 'scam', label: 'Looks like a scam' },
        { value: 'offensive', label: 'Rude or offensive' },
        { value: 'spam', label: 'Spam' },
        { value: 'other', label: 'Something else' },
    ],
};
const nouns: Record<Kind, string> = { vehicle: 'this listing', lot: 'this lot', review: 'this review', message: 'this message' };

const { user } = useShared();
const open = ref(false);
const dialog = ref<HTMLDialogElement | null>(null);
const form = useForm({ kind: props.kind, id: props.id, reason: '', details: '' });
const text = computed(() => props.label || `Report ${nouns[props.kind]}`);

async function show() {
    if (!user.value) {
        router.visit(route('login'));
        return;
    }
    open.value = true;
    await nextTick();
    dialog.value?.showModal();
}

function close() {
    dialog.value?.close();
    open.value = false;
}

function submit() {
    form.post(route('reports.store'), {
        preserveScroll: true,
        onSuccess: () => {
            form.reset('reason', 'details');
            close();
        },
    });
}
</script>

<template>
    <button type="button" class="inline-flex min-h-11 items-center gap-1.5 text-[13px] font-semibold text-muted hover:text-danger" @click="show">
        <Icon name="flag" :size="15" /> {{ text }}
    </button>

    <Teleport to="body">
        <dialog
            v-if="open"
            ref="dialog"
            class="m-0 mt-auto w-full max-w-none rounded-t-3xl bg-white p-0 backdrop:bg-ink/50 md:m-auto md:max-w-md md:rounded-3xl"
            :aria-label="text"
            @close="open = false"
            @click.self="close"
        >
            <form class="flex flex-col gap-4 p-5 pb-8" @submit.prevent="submit">
                <div class="flex items-center justify-between">
                    <h2 class="font-sans text-[20px] font-bold">{{ text }}</h2>
                    <button type="button" class="-mr-2 flex h-11 w-11 items-center justify-center" aria-label="Close" @click="close"><Icon name="close" :size="22" /></button>
                </div>
                <fieldset class="flex flex-col gap-2">
                    <legend class="mb-2 text-[14px] text-muted">What's wrong? Our team checks every report; the lot isn't told who sent it.</legend>
                    <label v-for="r in reasons[kind]" :key="r.value" class="flex min-h-11 cursor-pointer items-center gap-3 rounded-xl border px-3.5 text-[15px]" :class="form.reason === r.value ? 'border-forest bg-map' : 'border-line'">
                        <input v-model="form.reason" type="radio" name="reason" :value="r.value" class="h-4 w-4 accent-forest" />
                        {{ r.label }}
                    </label>
                    <InputError :message="form.errors.reason" />
                </fieldset>
                <label class="field-label">
                    <span>More details <span class="font-normal text-muted">(optional)</span></span>
                    <textarea v-field="{ kind: 'text', max: 500 }" v-model="form.details" rows="3" class="field h-auto py-2" />
                    <InputError :message="form.errors.details || form.errors.id" />
                </label>
                <button type="submit" class="btn btn-primary h-[52px] rounded-[14px]" :disabled="form.processing || !form.reason">Send report</button>
            </form>
        </dialog>
    </Teleport>
</template>
