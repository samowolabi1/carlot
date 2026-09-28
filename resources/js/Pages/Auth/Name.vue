<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ name: '' });
</script>

<template>
    <Head title="Welcome" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">Welcome to LotLink</h1>
            <p class="text-[15px] text-muted">What should lots call you when you book a visit or send an enquiry?</p>
        </div>
        <form class="flex flex-col gap-4" @submit.prevent="form.put(route('profile.name.update'))">
            <label class="field-label">
                Your name
                <input v-model="form.name" class="field" required maxlength="80" autocomplete="name" autofocus placeholder="e.g. Chioma Okafor" />
                <InputError :message="form.errors.name" />
            </label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">Continue</button>
        </form>
    </AuthLayout>
</template>
