<script setup lang="ts">
import FlashMessage from '@/components/FlashMessage.vue';
import Icon from '@/components/Icon.vue';
import Logo from '@/components/Logo.vue';

const props = defineProps<{ steps: { key: string; label: string }[]; current: string }>();

const currentIndex = () => props.steps.findIndex((s) => s.key === props.current);
</script>

<template>
    <div class="flex min-h-dvh flex-col bg-ivory lg:flex-row">
        <aside class="flex shrink-0 flex-col gap-7 bg-forest px-6 py-6 text-mist lg:sticky lg:top-0 lg:h-dvh lg:w-[340px] lg:px-9 lg:py-9">
            <Logo inverse />
            <div class="flex flex-col gap-1.5">
                <span class="font-display text-2xl font-bold text-white">Set up your lot</span>
                <span class="text-[14px]">About 10 minutes. You can keep going while we verify you.</span>
            </div>
            <ol class="hidden flex-col lg:flex">
                <li
                    v-for="(step, i) in steps"
                    :key="step.key"
                    class="flex items-center gap-3 py-2.5 text-[14px]"
                    :aria-current="step.key === current ? 'step' : undefined"
                >
                    <span
                        class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full text-[13px] font-bold"
                        :class="
                            i < currentIndex()
                                ? 'bg-forest-700 text-white'
                                : step.key === current
                                  ? 'bg-clay text-white'
                                  : 'border border-line-strong bg-white text-muted'
                        "
                    >
                        <Icon v-if="i < currentIndex()" name="check" :size="14" :stroke-width="2.5" />
                        <template v-else>{{ i + 1 }}</template>
                    </span>
                    <span :class="i <= currentIndex() ? 'text-white' : ''" :style="step.key === current ? 'font-weight:600' : ''">{{ step.label }}</span>
                </li>
            </ol>
            <span class="mt-auto hidden text-[13px] lg:block">Need help? Message LotLink support on WhatsApp.</span>
        </aside>
        <main class="flex grow flex-col gap-5 px-5 py-7 lg:px-16 lg:py-12">
            <slot />
        </main>
        <FlashMessage />
    </div>
</template>
