<script setup lang="ts">
import LegalFooter from '@/components/LegalFooter.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import SupportViewBar from '@/components/SupportViewBar.vue';
import Logo from '@/components/Logo.vue';
import { Link } from '@inertiajs/vue3';
</script>

<template>
    <SupportViewBar />
    <div class="flex min-h-dvh justify-center md:items-center md:py-10">
        <div class="flex w-full max-w-[440px] flex-col gap-7 px-6 py-7 md:rounded-3xl md:border md:border-line md:bg-ivory md:px-8 md:py-9 md:shadow-sm">
            <Link :href="route('home')" class="no-underline" aria-label="LotLink home"><Logo size="lg" /></Link>
            <slot />
            <LegalFooter compact />
        </div>
        <FlashMessage />
    </div>
</template>
