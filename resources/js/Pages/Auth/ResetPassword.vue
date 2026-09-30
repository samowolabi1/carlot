<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{ token: string; email: string }>();

const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const show = ref(false);

function submit() {
    form.post(route('password.store'), { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Choose a new password" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">Choose a new password</h1>
            <p class="text-[15px] text-muted">For {{ email || 'your account' }}. You'll be signed in when it's saved.</p>
        </div>

        <form class="flex flex-col gap-4" @submit.prevent="submit">
            <label v-if="!props.email" class="field-label">
                Email address
                <input v-field="'email'" v-model="form.email" type="email" autocomplete="email" required class="field" />
            </label>
            <InputError :message="form.errors.email" />
            <label class="field-label">
                New password
                <input v-field="'new_password'" v-model="form.password" :type="show ? 'text' : 'password'" autocomplete="new-password" required minlength="8" autofocus class="field" />
                <span class="font-normal text-muted">At least 8 characters, with letters and numbers.</span>
                <InputError :message="form.errors.password" />
            </label>
            <label class="field-label">
                Type it again
                <input v-field="{ kind: 'text', max: 72 }" v-model="form.password_confirmation" :type="show ? 'text' : 'password'" autocomplete="new-password" required class="field" />
            </label>
            <label class="flex min-h-11 cursor-pointer items-center gap-3 text-[14px]"><input v-model="show" type="checkbox" class="h-5 w-5 accent-forest" />Show passwords</label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Save and sign in' }}</button>
        </form>

        <p v-if="form.errors.email" class="text-[14px]">
            <Link :href="route('password.request', { email: form.email })" class="font-semibold text-forest hover:text-clay">Send a new link</Link>
        </p>
    </AuthLayout>
</template>
