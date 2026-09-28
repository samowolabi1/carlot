<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { useShared } from '@/composables/useShared';
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    token: string;
    lot: { name: string; initials: string; logo_url: string | null; city: string | null };
    role: string;
    pending: boolean;
    forCurrentUser: boolean | null;
}>();

const { user } = useShared();
const form = useForm({});
</script>

<template>
    <Head :title="`Join ${lot.name}`" />
    <AuthLayout>
        <div class="flex items-center gap-4">
            <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-16 w-16 rounded-2xl object-cover" />
            <span v-else class="flex h-16 w-16 items-center justify-center rounded-2xl bg-clay text-xl font-bold text-white">{{ lot.initials }}</span>
            <div>
                <div class="text-[18px] font-semibold">{{ lot.name }}</div>
                <div v-if="lot.city" class="text-[14px] text-muted">{{ lot.city }}</div>
            </div>
        </div>

        <template v-if="!pending">
            <h1 class="text-[28px] leading-[1.1] font-bold">This invitation has expired</h1>
            <p class="text-[15px] text-muted">Ask {{ lot.name }} to send you a new one.</p>
        </template>

        <template v-else>
            <div class="flex flex-col gap-2">
                <h1 class="text-[28px] leading-[1.1] font-bold">Join the team as {{ role }}</h1>
                <p class="text-[15px] text-muted">You'll be able to work on {{ lot.name }}'s stock, bookings and leads from your phone.</p>
            </div>

            <Link v-if="!user" :href="route('login')" class="btn btn-primary">Sign in to accept</Link>
            <template v-else>
                <p v-if="forCurrentUser === false" class="rounded-xl bg-cream p-3 text-[14px] text-clay-dark">
                    You're signed in as {{ user.phone }}, but this invitation was sent to someone else. Sign out and sign in with the invited number or email.
                </p>
                <form v-else @submit.prevent="form.post(route('invitations.accept', props.token))">
                    <button type="submit" class="btn btn-primary w-full" :disabled="form.processing">Accept invitation</button>
                    <InputError :message="(form.errors as Record<string, string>).invitation" />
                </form>
            </template>
        </template>
    </AuthLayout>
</template>
