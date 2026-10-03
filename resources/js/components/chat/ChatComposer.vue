<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

// Message box with an optional photo. Posts through Inertia so the page's messages refresh.
const props = defineProps<{ url: string; placeholder: string; round?: boolean }>();
const emit = defineEmits<{ typing: []; sent: [] }>();

const body = ref('');
const photo = ref<File | null>(null);
const sending = ref(false);
const error = ref('');
const fileInput = ref<HTMLInputElement>();

let lastTyping = 0;
function onInput() {
    if (Date.now() - lastTyping > 2000) {
        lastTyping = Date.now();
        emit('typing');
    }
}

function pick(e: Event) {
    photo.value = (e.target as HTMLInputElement).files?.[0] ?? null;
}

function send(text?: string) {
    const message = (text ?? body.value).trim();
    if (!message && !photo.value) return;
    sending.value = true;
    error.value = '';
    router.post(props.url, { body: message || null, photo: photo.value }, {
        forceFormData: !!photo.value,
        preserveScroll: true,
        preserveState: true,
        onSuccess: () => {
            body.value = '';
            photo.value = null;
            if (fileInput.value) fileInput.value.value = '';
            emit('sent');
        },
        onError: (errors) => (error.value = Object.values(errors)[0] ?? 'Could not send. Try again.'),
        onFinish: () => (sending.value = false),
    });
}

defineExpose({ send });
</script>

<template>
    <form class="flex flex-col gap-1.5" @submit.prevent="send()">
        <p v-if="error" class="text-[13px] text-danger" role="alert">{{ error }}</p>
        <p v-if="photo" class="flex items-center gap-2 text-[13px] text-muted">
            <Icon name="upload" :size="14" /> {{ photo.name }}
            <button type="button" class="font-semibold text-clay" @click="photo = null">Remove</button>
        </p>
        <div class="flex items-center gap-2">
            <label class="flex h-11 w-11 shrink-0 cursor-pointer items-center justify-center rounded-full text-muted hover:bg-ivory has-[:focus-visible]:ring-2 has-[:focus-visible]:ring-forest/30">
                <Icon name="plus" :size="22" :stroke-width="2" />
                <input ref="fileInput" type="file" accept="image/*" class="sr-only" aria-label="Attach a photo" @change="pick" />
            </label>
            <label class="grow">
                <span class="sr-only">Message</span>
                <input
                    v-model="body"
                    class="h-11 w-full border border-line-strong bg-white px-4 text-[15px] outline-none focus:border-forest"
                    :class="round ? 'rounded-full' : 'rounded-xl'"
                    :placeholder="placeholder"
                    maxlength="2000"
                    autocomplete="off"
                    enterkeyhint="send"
                    @input="onInput"
                />
            </label>
            <button type="submit" class="flex h-11 shrink-0 items-center justify-center bg-clay text-white disabled:opacity-60" :class="round ? 'w-11 rounded-full' : 'rounded-xl px-4 text-[14px] font-semibold'" :disabled="sending" aria-label="Send">
                <Icon v-if="round" name="navigate" :size="18" class="rotate-90" /><template v-else>Send</template>
            </button>
        </div>
    </form>
</template>
