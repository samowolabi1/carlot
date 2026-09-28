<script setup lang="ts">
import CarGlyph from '@/components/CarGlyph.vue';
import ChatComposer from '@/components/chat/ChatComposer.vue';
import ChatThread from '@/components/chat/ChatThread.vue';
import Icon from '@/components/Icon.vue';
import { useChat, type ChatMessage } from '@/composables/useChat';
import { useShared } from '@/composables/useShared';
import { Head, Link } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    conversation: string;
    lot: { name: string; initials: string; logo_url: string | null; phone: string | null; url: string };
    car: { title: string; price: string | null; url: string; image: string | null } | null;
    messages: ChatMessage[];
    bookUrl: string;
}>();

const { user } = useShared();
const chat = useChat(computed(() => props.conversation), computed(() => props.messages));
const composer = ref<InstanceType<typeof ChatComposer>>();

const quick = ['Is the price negotiable?', 'Can I see the papers?', 'Is it still available?'];
</script>

<template>
    <Head :title="`Chat with ${lot.name}`" />
    <div class="mx-auto flex h-dvh max-w-2xl flex-col bg-ivory md:border-x md:border-line">
        <header class="flex flex-col gap-2.5 border-b border-line bg-white px-4 py-3">
            <div class="flex items-center gap-2.5">
                <Link :href="route('conversations.index')" aria-label="Back to messages" class="-ml-2 flex h-11 w-11 items-center justify-center text-ink"><Icon name="chevronLeft" :size="22" :stroke-width="2" /></Link>
                <img v-if="lot.logo_url" :src="lot.logo_url" alt="" class="h-10 w-10 rounded-xl object-cover" />
                <span v-else class="flex h-10 w-10 items-center justify-center rounded-xl bg-clay text-[14px] font-bold text-white">{{ lot.initials }}</span>
                <Link :href="lot.url" class="flex grow flex-col text-ink no-underline">
                    <span class="text-[15px] font-semibold">{{ lot.name }}</span>
                    <span class="text-[12px] text-success">{{ chat.typing.value ? 'typing…' : 'Usually replies in minutes' }}</span>
                </Link>
                <a v-if="lot.phone" :href="`tel:${lot.phone}`" class="flex h-11 w-11 items-center justify-center text-ink" aria-label="Call the lot"><Icon name="phone" :size="20" /></a>
            </div>
            <div v-if="car" class="flex items-center gap-2.5 rounded-xl bg-ivory p-2">
                <Link :href="car.url" class="flex min-w-0 grow items-center gap-2.5 text-ink no-underline">
                    <img v-if="car.image" :src="car.image" alt="" class="h-9 w-12 rounded-lg object-cover" />
                    <span v-else class="flex h-9 w-12 shrink-0 items-center justify-center rounded-lg bg-sand"><CarGlyph :width="32" /></span>
                    <span class="truncate text-[13px]"><strong>{{ car.title }}</strong><template v-if="car.price"> · {{ car.price }}</template></span>
                </Link>
                <Link :href="bookUrl" class="flex h-11 shrink-0 items-center px-1 text-[13px] font-semibold">Book a visit</Link>
            </div>
        </header>

        <ChatThread :messages="chat.messages.value" me="customer" :typing="chat.typing.value" :empty-text="`Ask ${lot.name} anything about the car.`" />

        <div class="flex gap-2 overflow-x-auto px-4 pb-2 [scrollbar-width:none]">
            <button v-for="q in quick" :key="q" type="button" class="h-9 shrink-0 rounded-full border border-line-strong bg-white px-3 text-[13px] font-medium" @click="composer?.send(q)">{{ q }}</button>
        </div>
        <div class="border-t border-line bg-white px-3 pt-2.5 pb-5">
            <ChatComposer
                ref="composer"
                :url="route('conversations.reply', conversation)"
                :placeholder="`Message ${lot.name}`"
                round
                @typing="chat.whisperTyping(user?.name?.split(' ')[0] ?? 'Buyer')"
            />
        </div>
    </div>
</template>
