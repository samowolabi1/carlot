<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = defineProps<{ maskedPhone: string; resendAfter: number }>();

const LENGTH = 6;
const digits = ref<string[]>(Array(LENGTH).fill(''));
const inputs = ref<HTMLInputElement[]>([]);
const form = useForm({ code: '' });
const secondsLeft = ref(props.resendAfter);
const resending = ref(false);
let timer: ReturnType<typeof setInterval> | undefined;

const complete = computed(() => digits.value.every((d) => d !== ''));

function startCountdown() {
    secondsLeft.value = props.resendAfter;
    clearInterval(timer);
    timer = setInterval(() => {
        secondsLeft.value = Math.max(0, secondsLeft.value - 1);
        if (secondsLeft.value === 0) clearInterval(timer);
    }, 1000);
}

function focus(i: number) {
    nextTick(() => inputs.value[Math.min(Math.max(i, 0), LENGTH - 1)]?.focus());
}

function onInput(i: number, event: Event) {
    const value = (event.target as HTMLInputElement).value.replace(/\D/g, '');
    if (value.length > 1) {
        fill(value, i);
        return;
    }
    digits.value[i] = value;
    if (value) focus(i + 1);
    if (complete.value) submit();
}

function onKeydown(i: number, event: KeyboardEvent) {
    if (event.key === 'Backspace' && !digits.value[i]) focus(i - 1);
    if (event.key === 'ArrowLeft') focus(i - 1);
    if (event.key === 'ArrowRight') focus(i + 1);
}

function fill(value: string, from = 0) {
    value.slice(0, LENGTH - from).split('').forEach((d, k) => (digits.value[from + k] = d));
    focus(from + value.length);
    if (complete.value) submit();
}

function onPaste(event: ClipboardEvent) {
    const text = event.clipboardData?.getData('text').replace(/\D/g, '') ?? '';
    if (text) {
        event.preventDefault();
        fill(text);
    }
}

function submit() {
    if (form.processing) return;
    form.code = digits.value.join('');
    form.post(route('login.check'), {
        onError: () => {
            digits.value = Array(LENGTH).fill('');
            focus(0);
        },
    });
}

function resend() {
    resending.value = true;
    router.post(route('login.resend'), {}, { preserveScroll: true, onFinish: () => (resending.value = false), onSuccess: startCountdown });
}

const countdown = computed(() => `0:${String(secondsLeft.value).padStart(2, '0')}`);

onMounted(() => {
    startCountdown();
    focus(0);
});
onBeforeUnmount(() => clearInterval(timer));
</script>

<template>
    <Head title="Enter your code" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">Enter the code we sent you</h1>
            <p class="text-[15px] text-muted">
                A 6-digit code went to <strong class="text-ink">{{ maskedPhone }}</strong> by SMS.
                <Link :href="route('login')">Change number</Link>
            </p>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <fieldset>
                <legend class="sr-only">One-time code</legend>
                <div class="grid grid-cols-6 gap-2">
                    <input
                        v-for="(_, i) in digits"
                        :key="i"
                        :ref="(el) => (inputs[i] = el as HTMLInputElement)"
                        :value="digits[i]"
                        :aria-label="`Digit ${i + 1}`"
                        inputmode="numeric"
                        :autocomplete="i === 0 ? 'one-time-code' : 'off'"
                        maxlength="6"
                        class="h-14 w-full rounded-xl border bg-white text-center font-display text-[22px] font-bold text-ink outline-none focus:border-2 focus:border-clay"
                        :class="digits[i] ? 'border-2 border-forest' : 'border-line-strong'"
                        @input="onInput(i, $event)"
                        @keydown="onKeydown(i, $event)"
                        @paste="onPaste"
                    />
                </div>
            </fieldset>
            <InputError :message="form.errors.code" />

            <div class="flex justify-between text-[14px]">
                <span v-if="secondsLeft > 0" class="text-muted">Resend code in {{ countdown }}</span>
                <button v-else type="button" class="font-semibold text-clay hover:text-clay-dark" :disabled="resending" @click="resend">Send a new code</button>
            </div>

            <button type="submit" class="btn btn-primary" :disabled="!complete || form.processing">Continue</button>
        </form>
    </AuthLayout>
</template>
