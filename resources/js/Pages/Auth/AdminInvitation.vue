<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ name: string | null; email: string; role: string | null; role_description: string | null; action: string }>();

const form = useForm({ name: props.name ?? '', password: '', password_confirmation: '' });

function submit() {
    form.post(props.action, { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Join the CarYard admin team" />
    <AuthLayout>
        <form class="flex flex-col gap-5" @submit.prevent="submit">
            <div class="flex flex-col gap-1.5">
                <h1 class="text-[26px] leading-tight font-bold">Join the admin team</h1>
                <p class="text-[15px] text-muted">
                    You're joining as <strong class="text-ink">{{ role }}</strong> with <strong class="text-ink">{{ email }}</strong>. {{ role_description }}
                </p>
            </div>
            <label class="field-label">
                Your name
                <input v-field="'person_name'" v-model="form.name" class="field" autocomplete="name" required />
                <InputError :message="form.errors.name" />
            </label>
            <label class="field-label">
                Password
                <input v-field="{ kind: 'text', max: 72 }" v-model="form.password" type="password" class="field" autocomplete="new-password" required />
                <span class="text-[12px] font-normal text-muted">8 to 72 characters, with letters and numbers.</span>
                <InputError :message="form.errors.password" />
            </label>
            <label class="field-label">
                Password again
                <input v-field="{ kind: 'text', max: 72 }" v-model="form.password_confirmation" type="password" class="field" autocomplete="new-password" required />
            </label>
            <button type="submit" class="btn btn-primary" :disabled="form.processing">{{ form.processing ? 'Saving…' : 'Set password and continue' }}</button>
            <p class="text-[12px] text-muted">Next you'll turn on two-step sign-in with an authenticator app. Everything admins do is recorded in the audit log.</p>
        </form>
    </AuthLayout>
</template>
