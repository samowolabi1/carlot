<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { nextTick, ref } from 'vue';

const props = defineProps<{ method: 'whatsapp' | 'email' }>();

const form = useForm({ method: props.method, phone: '', email: '' });
const phoneInput = ref<HTMLInputElement | null>(null);
const emailInput = ref<HTMLInputElement | null>(null);

function choose(method: 'whatsapp' | 'email') {
    form.method = method;
    form.clearErrors();
    nextTick(() => (method === 'email' ? emailInput : phoneInput).value?.focus());
}

function submit() {
    form.post(route('login.send'));
}
</script>

<template>
    <Head title="Sign in" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">Sign in or create an account</h1>
            <p class="text-[15px] text-muted">We'll send you a 6-digit code. No password to remember. New here? This creates your account.</p>
        </div>

        <div class="grid grid-cols-2 gap-2 rounded-2xl bg-sand p-1" role="radiogroup" aria-label="Get your code by">
            <button
                v-for="m in (['whatsapp', 'email'] as const)"
                :key="m"
                type="button"
                role="radio"
                :aria-checked="form.method === m"
                class="flex h-12 items-center justify-center gap-2 rounded-xl text-[15px] font-semibold transition"
                :class="form.method === m ? 'bg-white text-ink shadow-sm' : 'text-muted hover:text-ink'"
                @click="choose(m)"
            >
                <Icon :name="m === 'whatsapp' ? 'whatsapp' : 'chat'" :size="18" :class="m === 'whatsapp' && form.method === m ? 'text-[#1FAF57]' : ''" />
                {{ m === 'whatsapp' ? 'WhatsApp' : 'Email' }}
            </button>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label v-if="form.method === 'whatsapp'" class="field-label">
                Your WhatsApp number
                <span class="flex gap-2">
                    <span class="flex h-12 items-center rounded-xl border border-line-strong bg-white px-3 text-[15px] font-medium">+234</span>
                    <input ref="phoneInput" v-model="form.phone" type="tel" inputmode="tel" autocomplete="tel" required autofocus class="field" placeholder="0803 123 4567" />
                </span>
                <span class="font-normal text-muted">The code comes as a WhatsApp message, so there's no SMS charge.</span>
                <InputError :message="form.errors.phone" />
            </label>
            <label v-else class="field-label">
                Your email address
                <input ref="emailInput" v-model="form.email" type="email" inputmode="email" autocomplete="email" required class="field" placeholder="you@example.com" />
                <span class="font-normal text-muted">Check your inbox (and spam folder) for the code.</span>
                <InputError :message="form.errors.email" />
            </label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Sending…' : 'Send code' }}</button>
        </form>

        <div class="mt-auto flex flex-col gap-3.5 pt-6">
            <div class="flex flex-col gap-1.5 rounded-2xl bg-forest p-4 text-white">
                <span class="text-[15px] font-semibold">Own a car lot?</span>
                <span class="text-[13px] text-mist">Sign in the same way, then put your stock online, take bookings and share cars in one tap. Lots in every state in Nigeria are welcome.</span>
                <Link :href="route('dealer.home')" class="text-[14px] font-semibold text-peach hover:text-white">List your lot</Link>
            </div>
            <p class="text-center text-[12px] text-muted">By continuing you agree to the Terms and Privacy Policy.</p>
        </div>
    </AuthLayout>
</template>
