<script setup lang="ts">
import { Link } from '@inertiajs/vue3';

/** The platform's legal links and the one-line disclaimer, on every layout (marketplace, dealer, lender, sign-in, mini-sites). */
withDefaults(defineProps<{ tone?: 'light' | 'dark'; compact?: boolean }>(), { tone: 'light', compact: false });

const links = [
    { key: 'terms', label: 'Terms' },
    { key: 'privacy', label: 'Privacy' },
    { key: 'security', label: 'Security and safety' },
    { key: 'lender-terms', label: 'Lender Terms' },
];
</script>

<template>
    <footer class="flex flex-col gap-1.5 text-[12px]" :class="tone === 'dark' ? 'text-mist/80' : 'text-muted'">
        <nav aria-label="Legal" class="flex flex-wrap gap-x-3 gap-y-1">
            <Link
                v-for="l in links"
                :key="l.key"
                :href="route('legal.show', l.key)"
                class="inline-flex min-h-8 items-center no-underline hover:underline"
                :class="tone === 'dark' ? 'text-mist/90 hover:text-white' : 'text-muted hover:text-ink'"
                >{{ l.label }}</Link
            >
        </nav>
        <p v-if="!compact" class="max-w-3xl leading-snug">
            LotLink is a marketplace: car lots sell the cars and lenders make the loans. LotLink never asks you to pay for a car, a deposit or a loan fee into a LotLink account.
            Never share your sign-in code.
        </p>
    </footer>
</template>
