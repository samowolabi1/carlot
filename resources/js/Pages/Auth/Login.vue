<script setup lang="ts">
import GoogleLogo from '@/components/GoogleLogo.vue';
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed, nextTick, ref } from 'vue';

const props = defineProps<{ method: 'whatsapp' | 'email' | 'password'; google: boolean }>();

// A one-time code (WhatsApp or email) is the default and also creates accounts; a password only
// works for accounts that added one in Account → Sign-in and security.
const mode = ref<'code' | 'password'>(props.method === 'password' ? 'password' : 'code');
const form = useForm({ method: props.method === 'email' ? 'email' : 'whatsapp', phone: '', email: '' });
const passwordForm = useForm({ login: '', password: '' });
const showPassword = ref(false);
// "Keep me signed in for a week": a remember cookie that lasts 7 days. Off: signed out after 2 hours without using LotLink.
const remember = ref(true);
const googleHref = computed(() => route('login.google', remember.value ? { remember: 1 } : {}));
const phoneInput = ref<HTMLInputElement | null>(null);
const emailInput = ref<HTMLInputElement | null>(null);
const loginInput = ref<HTMLInputElement | null>(null);

function choose(method: 'whatsapp' | 'email') {
    form.method = method;
    form.clearErrors();
    nextTick(() => (method === 'email' ? emailInput : phoneInput).value?.focus());
}

function useMode(next: 'code' | 'password') {
    mode.value = next;
    nextTick(() => (next === 'password' ? loginInput : form.method === 'email' ? emailInput : phoneInput).value?.focus());
}

function submit() {
    form.transform((data) => ({ ...data, remember: remember.value })).post(route('login.send'));
}

function submitPassword() {
    passwordForm.transform((data) => ({ ...data, remember: remember.value })).post(route('login.password'), { onFinish: () => passwordForm.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">Sign in or create an account</h1>
            <p v-if="mode === 'code'" class="text-[15px] text-muted">We'll send you a 6-digit code. New here? This creates your account.</p>
            <p v-else class="text-[15px] text-muted">For accounts that added a password.</p>
        </div>

        <template v-if="google">
            <a :href="googleHref" class="btn btn-outline flex items-center justify-center gap-3 bg-white no-underline">
                <GoogleLogo />
                Continue with Google
            </a>
            <div class="flex items-center gap-3 text-[13px] text-muted" aria-hidden="true">
                <span class="h-px grow bg-divider" />or<span class="h-px grow bg-divider" />
            </div>
        </template>

        <template v-if="mode === 'code'">
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
                    <Icon :name="m === 'whatsapp' ? 'whatsapp' : 'mail'" :size="18" :class="m === 'whatsapp' && form.method === m ? 'text-[#1FAF57]' : ''" />
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
                <label class="flex min-h-11 cursor-pointer items-center gap-3 text-[14px]">
                    <input v-model="remember" type="checkbox" class="h-5 w-5 shrink-0 accent-forest" />
                    Keep me signed in for a week
                </label>
                <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Sending…' : 'Send code' }}</button>
            </form>
            <button type="button" class="flex min-h-11 items-center justify-center gap-2 text-[15px] font-semibold text-forest hover:text-clay" @click="useMode('password')">
                <Icon name="key" :size="18" />
                Sign in with a password
            </button>
        </template>

        <template v-else>
            <form class="flex flex-col gap-4" @submit.prevent="submitPassword">
                <label class="field-label">
                    Email or WhatsApp number
                    <input ref="loginInput" v-model="passwordForm.login" type="text" autocomplete="username" autocapitalize="none" spellcheck="false" required autofocus class="field" placeholder="you@example.com or 0803 123 4567" />
                    <InputError :message="passwordForm.errors.login" />
                </label>
                <label class="field-label">
                    <span class="flex items-center justify-between">
                        Password
                        <Link :href="route('password.request', passwordForm.login.includes('@') ? { email: passwordForm.login.trim() } : {})" class="-my-2 flex min-h-11 items-center text-[14px] font-semibold text-forest hover:text-clay">Forgot password?</Link>
                    </span>
                    <span class="relative flex">
                        <input v-model="passwordForm.password" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required class="field pr-12" />
                        <button
                            type="button"
                            class="absolute inset-y-0 right-0 flex w-12 items-center justify-center text-muted hover:text-ink"
                            :aria-label="showPassword ? 'Hide password' : 'Show password'"
                            :aria-pressed="showPassword"
                            @click="showPassword = !showPassword"
                        >
                            <Icon :name="showPassword ? 'eyeOff' : 'eye'" :size="20" />
                        </button>
                    </span>
                    <InputError :message="passwordForm.errors.password" />
                </label>
                <label class="flex min-h-11 cursor-pointer items-center gap-3 text-[14px]">
                    <input v-model="remember" type="checkbox" class="h-5 w-5 shrink-0 accent-forest" />
                    Keep me signed in for a week
                </label>
                <button type="submit" class="btn btn-primary" :disabled="passwordForm.processing">{{ passwordForm.processing ? 'Signing in…' : 'Sign in' }}</button>
            </form>
            <p class="text-center text-[13px] text-muted">Never set a password? Sign in with a code, then add one in Account → Sign-in and security.</p>
            <button type="button" class="flex min-h-11 items-center justify-center gap-2 text-[15px] font-semibold text-forest hover:text-clay" @click="useMode('code')">
                <Icon name="chat" :size="18" />
                Get a one-time code instead
            </button>
        </template>

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
