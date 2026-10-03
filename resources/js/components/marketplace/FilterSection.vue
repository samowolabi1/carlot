<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { ref, useId } from 'vue';

// One filter group that folds away, so a long list of filters stays easy to scan. Groups with a choice made start open.
const props = withDefaults(defineProps<{ title: string; selected?: number; open?: boolean }>(), { selected: 0, open: false });

const isOpen = ref(props.open || props.selected > 0);
const id = useId();
</script>

<template>
    <section class="border-b border-divider last-of-type:border-b-0">
        <h3 class="font-sans">
            <button type="button" class="flex min-h-12 w-full items-center justify-between gap-2 py-2 text-left text-[15px] font-semibold" :aria-expanded="isOpen" :aria-controls="id" @click="isOpen = !isOpen">
                <span class="flex items-center gap-2">
                    {{ title }}
                    <span v-if="selected" class="flex h-5 min-w-5 items-center justify-center rounded-full bg-forest px-1.5 text-[11px] font-semibold text-white" :aria-label="`${selected} chosen`">{{ selected }}</span>
                </span>
                <Icon name="chevronDown" :size="18" :stroke-width="2" class="shrink-0 text-muted transition-transform" :class="{ 'rotate-180': isOpen }" />
            </button>
        </h3>
        <div v-show="isOpen" :id="id" class="pb-4">
            <slot />
        </div>
    </section>
</template>
