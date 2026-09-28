import { echo } from '@/lib/echo';
import { json } from '@/lib/http';
import { onBeforeUnmount, onMounted, ref, watch, type Ref } from 'vue';

export interface ChatMessage {
    id: number;
    side: 'customer' | 'lot' | 'system';
    body: string;
    image: string | null;
    sender: string | null;
    time: string;
    day: string;
}

/**
 * Live messages for one conversation: Reverb events when it's running, otherwise a poll
 * every 4 seconds. The page's own messages (from Inertia) are the starting point.
 */
export function useChat(conversation: Ref<string | null>, initial: Ref<ChatMessage[]>, onNew?: () => void) {
    const messages = ref<ChatMessage[]>([...initial.value]);
    const typing = ref<string | null>(null);
    let timer: ReturnType<typeof setInterval> | undefined;
    let typingTimer: ReturnType<typeof setTimeout> | undefined;
    let channelName: string | null = null;

    const lastId = () => messages.value.reduce((max, m) => Math.max(max, m.id), 0);

    function add(list: ChatMessage[]) {
        const known = new Set(messages.value.map((m) => m.id));
        const fresh = list.filter((m) => !known.has(m.id));
        if (fresh.length) {
            messages.value = [...messages.value, ...fresh].sort((a, b) => a.id - b.id);
            onNew?.();
        }
    }

    // After the user sends, Inertia reloads the page's messages.
    watch(initial, (list) => add(list));

    async function poll() {
        if (!conversation.value || document.hidden) return;
        try {
            const data = await json<{ messages: ChatMessage[] }>('GET', route('conversations.poll', { conversation: conversation.value, after: lastId() }));
            add(data.messages);
        } catch {
            // Offline for a moment; the next poll catches up.
        }
    }

    function connect() {
        const live = echo();
        if (!conversation.value) return;
        if (live) {
            channelName = `conversation.${conversation.value}`;
            live.private(channelName)
                .listen('.MessageSent', (e: { message: ChatMessage }) => {
                    typing.value = null;
                    add([e.message]);
                })
                .listenForWhisper('typing', (e: { name: string }) => {
                    typing.value = e.name;
                    clearTimeout(typingTimer);
                    typingTimer = setTimeout(() => (typing.value = null), 3000);
                });
        } else {
            timer = setInterval(poll, 4000);
        }
    }

    /** Tells the other side "typing…" (Reverb only). */
    function whisperTyping(name: string) {
        const live = echo();
        if (live && channelName) live.private(channelName).whisper('typing', { name });
    }

    onMounted(connect);
    onBeforeUnmount(() => {
        clearInterval(timer);
        if (channelName) echo()?.leave(channelName);
    });

    return { messages, typing, whisperTyping, poll };
}
