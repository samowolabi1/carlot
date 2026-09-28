<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import { ref, watch } from 'vue';

const { flash } = useShared();
const visible = ref<{ kind: 'success' | 'error'; text: string } | null>(null);
let timer: ReturnType<typeof setTimeout> | undefined;

watch(
    flash,
    (value) => {
        const text = value.error ?? value.success;
        if (!text) return;
        visible.value = { kind: value.error ? 'error' : 'success', text };
        clearTimeout(timer);
        timer = setTimeout(() => (visible.value = null), 5000);
    },
    { immediate: true, deep: true },
);
</script>

<template>
    <Transition enter-from-class="translate-y-2 opacity-0" leave-to-class="translate-y-2 opacity-0" enter-active-class="transition" leave-active-class="transition">
        <div
            v-if="visible"
            role="status"
            class="fixed inset-x-4 bottom-24 z-50 mx-auto flex max-w-md items-center gap-3 rounded-2xl px-4 py-3 text-[14px] font-medium text-white shadow-lg md:bottom-6"
            :class="visible.kind === 'error' ? 'bg-clay-dark' : 'bg-forest'"
        >
            <span class="grow">{{ visible.text }}</span>
            <button type="button" class="flex h-8 w-8 items-center justify-center rounded-lg hover:bg-white/10" aria-label="Dismiss" @click="visible = null">
                <Icon name="close" :size="16" />
            </button>
        </div>
    </Transition>
</template>
