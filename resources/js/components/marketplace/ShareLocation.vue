<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { ref } from 'vue';

// "Share lot location" (TDD M8): the seller's name, address and a Google Maps link, sent through
// the phone's share sheet, or WhatsApp / SMS / copy where there is no share sheet.
const props = defineProps<{ name: string; address?: string | null; directions: string; block?: boolean }>();

const open = ref(false);
const copied = ref(false);
const text = () => [props.name, props.address].filter(Boolean).join(', ');
const message = () => `${text()}: ${props.directions}`;

async function share() {
    if (navigator.share) {
        try {
            await navigator.share({ title: props.name, text: text(), url: props.directions });
            return;
        } catch (e) {
            if ((e as DOMException).name === 'AbortError') return;
        }
    }
    open.value = !open.value;
}

async function copy() {
    try {
        await navigator.clipboard.writeText(message());
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        window.prompt('Copy this location', message());
    }
}

const item = 'flex h-11 w-full items-center gap-2.5 rounded-xl px-3 text-left text-ink no-underline hover:bg-ivory';
</script>

<template>
    <div class="relative" :class="block ? 'w-full' : ''">
        <button type="button" class="btn btn-outline h-11 px-4 text-[14px]" :class="block ? 'w-full' : ''" :aria-expanded="open" @click="share">
            <Icon name="pin" :size="18" /> Share location
        </button>
        <div v-if="open" class="absolute left-0 z-40 mt-2 flex w-60 flex-col rounded-2xl border border-line bg-white p-1.5 text-[14px] shadow-xl" role="menu">
            <a :href="`https://wa.me/?text=${encodeURIComponent(message())}`" target="_blank" rel="noopener" :class="item" role="menuitem"><Icon name="whatsapp" :size="18" /> WhatsApp</a>
            <a :href="`sms:?&body=${encodeURIComponent(message())}`" :class="item" role="menuitem"><Icon name="phone" :size="18" /> Text message</a>
            <button type="button" :class="item" role="menuitem" @click="copy"><Icon name="copy" :size="18" /> {{ copied ? 'Copied' : 'Copy location' }}</button>
        </div>
    </div>
</template>
