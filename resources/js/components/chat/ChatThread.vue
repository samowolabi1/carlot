<script setup lang="ts">
import ReportButton from '@/components/trust/ReportButton.vue';
import type { ChatMessage } from '@/composables/useChat';
import { computed, nextTick, onMounted, ref, watch } from 'vue';

// Messages are plain text: links become anchors, everything else is escaped by Vue.
const props = defineProps<{ messages: ChatMessage[]; me: 'customer' | 'lot'; typing?: string | null; emptyText?: string }>();

const scroller = ref<HTMLElement>();

const items = computed(() => {
    const out: ({ type: 'day'; day: string } | { type: 'message'; message: ChatMessage; showMeta: boolean })[] = [];
    let lastDay = '';
    props.messages.forEach((m, i) => {
        if (m.day !== lastDay) {
            out.push({ type: 'day', day: m.day });
            lastDay = m.day;
        }
        const next = props.messages[i + 1];
        out.push({ type: 'message', message: m, showMeta: !next || next.side !== m.side || next.day !== m.day });
    });
    return out;
});

const URL_PATTERN = /(https?:\/\/[^\s<]+[^\s<.,;:!?)"'])/g;
function parts(text: string) {
    return text.split(URL_PATTERN).map((part, i) => ({ link: i % 2 === 1, text: part }));
}

function scrollToEnd() {
    void nextTick(() => scroller.value?.scrollTo({ top: scroller.value.scrollHeight }));
}
watch(() => props.messages.length, scrollToEnd);
onMounted(scrollToEnd);
</script>

<template>
    <div ref="scroller" class="flex grow flex-col gap-2.5 overflow-y-auto px-4 py-4 md:px-6" aria-live="polite">
        <p v-if="messages.length === 0" class="m-auto max-w-xs text-center text-[14px] text-muted">{{ emptyText ?? 'No messages yet.' }}</p>
        <template v-for="(item, i) in items" :key="i">
            <span v-if="item.type === 'day'" class="self-center text-[11px] text-muted">{{ item.day }}</span>
            <template v-else>
                <div v-if="item.message.side === 'system'" class="self-center rounded-xl bg-map px-3 py-2 text-center text-[13px] font-semibold text-forest">{{ item.message.body }}</div>
                <template v-else>
                    <div
                        class="max-w-[78%] px-3.5 py-2.5 text-[15px] leading-snug break-words whitespace-pre-line"
                        :class="
                            item.message.side === me
                                ? 'self-end rounded-[18px_18px_6px_18px] bg-forest text-white'
                                : 'self-start rounded-[18px_18px_18px_6px] border border-line bg-white text-ink'
                        "
                    >
                        <a v-if="item.message.image" :href="item.message.image" target="_blank" rel="noopener" class="mb-1.5 block">
                            <img :src="item.message.image" alt="Photo" class="max-h-64 rounded-xl object-cover" loading="lazy" />
                        </a>
                        <template v-for="(part, j) in parts(item.message.body)" :key="j">
                            <a v-if="part.link" :href="part.text" target="_blank" rel="noopener nofollow ugc" class="underline" :class="item.message.side === me ? 'text-peach' : ''">{{ part.text }}</a>
                            <template v-else>{{ part.text }}</template>
                        </template>
                    </div>
                    <span v-if="item.showMeta" class="flex items-center gap-2 text-[11px] text-muted" :class="item.message.side === me ? 'self-end' : 'self-start'">
                        <span><template v-if="item.message.sender">{{ item.message.sender }} · </template>{{ item.message.time }}</span>
                        <ReportButton v-if="item.message.side !== me" kind="message" :id="String(item.message.id)" label="Report" />
                    </span>
                </template>
            </template>
        </template>
        <span v-if="typing" class="self-start text-[12px] text-muted italic">{{ typing }} is typing…</span>
    </div>
</template>
