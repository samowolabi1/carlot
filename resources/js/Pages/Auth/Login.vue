<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ phone: '' });

function submit() {
    form.post(route('login.send'));
}
</script>

<template>
    <Head title="Sign in" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">Sign in with your phone</h1>
            <p class="text-[15px] text-muted">We'll text you a 6-digit code. New here? This creates your account.</p>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label class="field-label">
                Phone number
                <span class="flex gap-2">
                    <span class="flex h-12 items-center rounded-xl border border-line-strong bg-white px-3 text-[15px] font-medium">🇳🇬 +234</span>
                    <input
                        v-model="form.phone"
                        type="tel"
                        inputmode="tel"
                        autocomplete="tel"
                        required
                        autofocus
                        class="field"
                        placeholder="0803 123 4567"
                    />
                </span>
                <InputError :message="form.errors.phone" />
            </label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">Send code</button>
        </form>

        <div class="mt-auto flex flex-col gap-3.5 pt-6">
            <div class="flex flex-col gap-1.5 rounded-2xl bg-forest p-4 text-white">
                <span class="text-[15px] font-semibold">Own a car lot?</span>
                <span class="text-[13px] text-mist">Put your stock online, take bookings and share cars in one tap.</span>
                <Link :href="route('dealer.home')" class="text-[14px] font-semibold text-peach hover:text-white">List your lot</Link>
            </div>
            <p class="text-center text-[12px] text-muted">By continuing you agree to the Terms and Privacy Policy.</p>
        </div>
    </AuthLayout>
</template>
