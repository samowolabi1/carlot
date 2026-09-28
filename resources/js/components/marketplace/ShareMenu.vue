<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { ref } from 'vue';

const props = withDefaults(defineProps<{ title: string; text?: string; url: string; label?: string; compact?: boolean }>(), { text: '', label: 'Share', compact: false });
const open = ref(false);
const copied = ref(false);

async function share() {
    // The phone's own share sheet reaches WhatsApp Status, Instagram, Telegram and the rest.
    if (navigator.share) {
        try {
            await navigator.share({ title: props.title, text: props.text, url: props.url });
            return;
        } catch (e) {
            if ((e as DOMException).name === 'AbortError') return;
        }
    }
    open.value = !open.value;
}

async function copy() {
    try {
        await navigator.clipboard.writeText(props.url);
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        window.prompt('Copy this link', props.url);
    }
}

const message = () => encodeURIComponent(`${props.text || props.title} ${props.url}`.trim());
</script>

<template>
    <div class="relative">
        <button
            type="button"
            :class="compact ? 'flex h-11 w-11 items-center justify-center rounded-full bg-white shadow-sm' : 'btn btn-outline h-11 px-4 text-[14px]'"
            :aria-label="compact ? label : undefined"
            :aria-expanded="open"
            @click="share"
        >
            <Icon name="share" :size="compact ? 20 : 18" /><span v-if="!compact">{{ label }}</span>
        </button>
        <div v-if="open" class="absolute right-0 z-30 mt-2 flex w-56 flex-col rounded-2xl border border-line bg-white p-1.5 text-[14px] shadow-xl" role="menu">
            <a :href="`https://wa.me/?text=${message()}`" target="_blank" rel="noopener" class="flex h-11 items-center gap-2.5 rounded-xl px-3 text-ink no-underline hover:bg-ivory" role="menuitem"><Icon name="whatsapp" :size="18" /> WhatsApp</a>
            <a :href="`https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(url)}`" target="_blank" rel="noopener" class="flex h-11 items-center gap-2.5 rounded-xl px-3 text-ink no-underline hover:bg-ivory" role="menuitem">Facebook</a>
            <a :href="`https://twitter.com/intent/tweet?text=${message()}`" target="_blank" rel="noopener" class="flex h-11 items-center gap-2.5 rounded-xl px-3 text-ink no-underline hover:bg-ivory" role="menuitem">X (Twitter)</a>
            <a :href="`https://t.me/share/url?url=${encodeURIComponent(url)}&text=${encodeURIComponent(text || title)}`" target="_blank" rel="noopener" class="flex h-11 items-center gap-2.5 rounded-xl px-3 text-ink no-underline hover:bg-ivory" role="menuitem">Telegram</a>
            <button type="button" class="flex h-11 items-center gap-2.5 rounded-xl px-3 text-left hover:bg-ivory" role="menuitem" @click="copy"><Icon name="copy" :size="18" /> {{ copied ? 'Link copied' : 'Copy link' }}</button>
        </div>
    </div>
</template>
