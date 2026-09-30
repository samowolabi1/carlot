<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{ email: string }>();

const form = useForm({ email: props.email });
const sentTo = ref<string | null>(null);

function submit() {
    form.post(route('password.email'), { preserveScroll: true, onSuccess: () => (sentTo.value = form.email) });
}
</script>

<template>
    <Head title="Reset your password" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">Forgot your password?</h1>
            <p class="text-[15px] text-muted">Enter the email on your account and we'll send you a link to choose a new one.</p>
        </div>

        <div v-if="sentTo" class="flex gap-3 rounded-2xl bg-map p-4 text-[14px]" role="status">
            <Icon name="mail" :size="20" class="mt-0.5 shrink-0 text-forest" />
            <span>If {{ sentTo }} has a LotLink account, we've sent a link to reset the password. Check your inbox and spam folder. The link expires in 60 minutes.</span>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="field-label">
                Email address
                <input v-field="'email'" v-model="form.email" type="email" inputmode="email" autocomplete="email" required autofocus class="field" placeholder="you@example.com" />
                <InputError :message="form.errors.email" />
            </label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Sending…' : sentTo ? 'Send again' : 'Send reset link' }}</button>
        </form>

        <p class="text-[14px] text-muted">
            No email on your account, or signed up with WhatsApp? Sign in with a one-time code, then set a new password in Account → Sign-in and security.
        </p>
        <Link :href="route('login')" class="flex min-h-11 items-center justify-center gap-2 text-[15px] font-semibold text-forest no-underline hover:text-clay">
            <Icon name="chat" :size="18" />
            Sign in with a code instead
        </Link>
    </AuthLayout>
</template>
