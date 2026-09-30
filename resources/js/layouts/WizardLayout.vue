<script setup lang="ts">
import LegalFooter from '@/components/LegalFooter.vue';
import FlashMessage from '@/components/FlashMessage.vue';
import Icon from '@/components/Icon.vue';
import { Link } from '@inertiajs/vue3';

defineProps<{ closeHref: string; title: string; status?: string; wide?: boolean }>();
</script>

<template>
    <!-- Phone-first full-screen flow (D13–D15); centred column on larger screens. -->
    <div class="min-h-dvh bg-ivory">
        <div class="mx-auto flex min-h-dvh max-w-xl flex-col md:py-6" :class="{ 'lg:max-w-5xl': wide }">
            <div class="flex grow flex-col gap-4 px-5 pt-4 pb-32 md:rounded-3xl md:border md:border-line md:bg-ivory md:px-7">
                <div class="flex items-center justify-between">
                    <Link :href="closeHref" aria-label="Close" class="-ml-2.5 flex h-11 w-11 items-center justify-center text-ink">
                        <Icon name="close" :size="22" :stroke-width="2" />
                    </Link>
                    <span class="text-[13px] text-muted">{{ status }}</span>
                </div>
                <h1 class="text-[26px] leading-tight font-bold">{{ title }}</h1>
                <slot name="steps" />
                <slot />
                <LegalFooter compact class="mt-auto pt-6" />
            </div>
        </div>
        <div class="fixed inset-x-0 bottom-0 z-30 border-t border-line bg-white">
            <div class="mx-auto flex max-w-xl gap-2.5 px-5 pt-3 pb-6" :class="{ 'lg:max-w-5xl lg:px-7': wide }">
                <slot name="actions" />
            </div>
        </div>
        <FlashMessage />
    </div>
</template>
