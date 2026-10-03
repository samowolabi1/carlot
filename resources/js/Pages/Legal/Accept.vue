<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import Logo from '@/components/Logo.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{ updated: boolean; effective: string; impersonating: boolean }>();

const form = useForm({ agree: false });

function submit() {
    form.post(route('legal.accept.store'));
}
</script>

<template>
    <Head title="Terms and privacy" />
    <div class="flex min-h-dvh items-center justify-center bg-ivory px-5 py-10">
        <form class="card flex w-full max-w-md flex-col gap-4 p-6" @submit.prevent="submit">
            <Logo />
            <h1 class="text-[24px] leading-tight font-bold">{{ updated ? "We've updated our terms" : 'Before you continue' }}</h1>
            <p class="text-[15px] text-[#4A4D53]">
                {{ updated ? `Our Terms of Use and Privacy Policy changed on ${effective}.` : 'Please read and accept our Terms of Use and Privacy Policy.' }}
                They explain what CarYard does and doesn't do (we don't sell cars or lend money), how we use and protect your data under the Nigeria Data Protection Act 2023, and your
                rights.
            </p>
            <ul class="flex flex-col gap-1 text-[15px]">
                <li><Link :href="route('legal.show', 'terms')" target="_blank">Terms of Use</Link></li>
                <li><Link :href="route('legal.show', 'privacy')" target="_blank">Privacy Policy</Link></li>
                <li><Link :href="route('legal.show', 'security')" target="_blank">Security and safety</Link></li>
            </ul>
            <p v-if="impersonating" class="rounded-xl bg-cream px-3 py-2.5 text-[14px] text-clay-dark" role="status">
                You're viewing this account as CarYard support. Only the account holder can accept the terms.
            </p>
            <template v-else>
                <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-line p-3 text-[14px]">
                    <input v-model="form.agree" type="checkbox" class="mt-0.5 h-5 w-5 shrink-0 accent-forest" />
                    <span>I'm 18 or older and I agree to the Terms of Use and the Privacy Policy.</span>
                </label>
                <InputError :message="form.errors.agree" />
                <button type="submit" class="btn btn-primary h-12" :disabled="form.processing || !form.agree">Accept and continue</button>
            </template>
            <p class="text-[12px] text-muted">
                Don't agree? You can still close your account from Account → Privacy and my data, or
                <Link :href="route('logout')" method="post" as="button" class="font-semibold text-forest underline">sign out</Link>.
            </p>
        </form>
    </div>
</template>
