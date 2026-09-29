<script setup lang="ts">
import AuthLayout from '@/layouts/AuthLayout.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{ what: string }>();
</script>

<template>
    <Head title="Unsubscribed" />
    <AuthLayout>
        <div class="flex flex-col gap-2">
            <h1 class="text-[30px] leading-[1.1] font-bold">You're unsubscribed</h1>
            <p class="text-[15px] text-muted">We won't email you "{{ what }}" any more. They'll still show in your notifications on LotLink.</p>
        </div>
        <Link :href="route('notifications.settings')" class="btn btn-outline no-underline">Change notification settings</Link>
    </AuthLayout>
</template>
