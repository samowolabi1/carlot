<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { json } from '@/lib/http';
import { onBeforeUnmount, onMounted, ref } from 'vue';

type Platform = 'whatsapp' | 'facebook' | 'x' | 'telegram' | 'instagram' | 'sms' | 'native' | 'copy';

interface ShareLink {
    code: string;
    url: string;
    cards: { square: string; story: string } | null;
}

const props = withDefaults(
    defineProps<{
        title: string;
        text?: string;
        url: string;
        /** Car ULID or lot slug: shares go through a tracked /c/{code} link (TDD M9). */
        vehicle?: string;
        lot?: string;
        label?: string;
        compact?: boolean;
        /** Match the lot page's action tiles (icon over label on phones). */
        tile?: boolean;
        /** Offer the share-card images (for Status and Stories). */
        images?: boolean;
    }>(),
    { text: '', label: 'Share', compact: false, tile: false, images: false, vehicle: undefined, lot: undefined },
);

const open = ref(false);
const copied = ref(false);
const note = ref('');
const cards = ref<ShareLink['cards']>(null);
const root = ref<HTMLElement>();
const links = new Map<Platform, Promise<ShareLink | null>>();

/** One tracked link per platform, made when first needed. Falls back to the plain URL. */
function link(platform: Platform): Promise<ShareLink | null> {
    if (!props.vehicle && !props.lot) return Promise.resolve(null);
    if (!links.has(platform)) {
        const request = json<ShareLink>('POST', route('shares.store'), { vehicle: props.vehicle, lot: props.lot, platform })
            .then((result) => {
                if (result.cards) cards.value = result.cards;
                return result;
            })
            .catch(() => null);
        links.set(platform, request);
    }
    return links.get(platform)!;
}

async function urlFor(platform: Platform) {
    return (await link(platform))?.url ?? props.url;
}

const message = (url: string) => `${props.text || props.title} ${url}`.trim();

/** The share card as a file, for share sheets that accept images (WhatsApp Status, Instagram). */
async function cardFile(): Promise<File | null> {
    const square = cards.value?.square;
    if (!square) return null;
    try {
        const blob = await (await fetch(square)).blob();
        return new File([blob], 'lotlink-car.png', { type: 'image/png' });
    } catch {
        return null;
    }
}

async function share() {
    if (!navigator.share) {
        toggle();
        return;
    }
    // Start the request on the tap; share sheets need the tap to be recent.
    const url = await urlFor('native');
    const file = props.vehicle ? await cardFile() : null;
    const data: ShareData = { title: props.title, text: props.text || props.title, url };
    try {
        if (file && navigator.canShare?.({ files: [file] })) {
            await navigator.share({ ...data, files: [file] });
        } else {
            await navigator.share(data);
        }
    } catch (e) {
        const name = (e as DOMException).name;
        if (name === 'AbortError') return;
        toggle(); // blocked or unsupported: show the menu instead
    }
}

function toggle() {
    open.value = !open.value;
    note.value = '';
    // Warm up the card URLs so the image links are ready.
    if (open.value && props.images) void link('copy');
}

/** Opens a tab straight away (so it isn't blocked as a pop-up), then points it at the share page. */
async function go(platform: Platform, target: (url: string) => string) {
    const tab = window.open('about:blank', '_blank');
    const url = target(await urlFor(platform));
    if (tab) tab.location.href = url;
    else window.location.href = url;
    open.value = false;
}

async function copy() {
    const pending = urlFor('copy');
    try {
        if (typeof ClipboardItem !== 'undefined' && navigator.clipboard?.write) {
            // Safari needs the clipboard write to start inside the tap.
            await navigator.clipboard.write([new ClipboardItem({ 'text/plain': pending.then((u) => new Blob([u], { type: 'text/plain' })) })]);
        } else {
            await navigator.clipboard.writeText(await pending);
        }
        copied.value = true;
        setTimeout(() => (copied.value = false), 2000);
    } catch {
        window.prompt('Copy this link', await pending);
    }
}

async function instagram() {
    // Instagram has no web share link: hand over the Story image instead.
    await link('instagram');
    note.value = cards.value ? 'Save the Story image below, then add it to your Instagram Story.' : 'Copy the link and paste it into your Instagram bio or Story.';
}

function onDocumentClick(e: MouseEvent) {
    if (open.value && root.value && !root.value.contains(e.target as Node)) open.value = false;
}
onMounted(() => document.addEventListener('click', onDocumentClick));
onBeforeUnmount(() => document.removeEventListener('click', onDocumentClick));

const item = 'flex h-11 w-full items-center gap-2.5 rounded-xl px-3 text-left text-ink no-underline hover:bg-ivory';
</script>

<template>
    <div ref="root" class="relative">
        <button
            type="button"
            :class="
                compact
                    ? 'flex h-11 w-11 items-center justify-center rounded-full bg-white shadow-sm'
                    : tile
                      ? 'flex h-16 w-full flex-col items-center justify-center gap-1 rounded-[14px] border border-line bg-white text-[12px] font-semibold text-ink md:h-11 md:flex-row md:gap-2 md:px-4 md:text-[14px]'
                      : 'btn btn-outline h-11 px-4 text-[14px]'
            "
            :aria-label="compact ? label : undefined"
            :aria-expanded="open"
            aria-haspopup="menu"
            @pointerdown="link('native')"
            @click="share"
        >
            <Icon name="share" :size="compact || tile ? 20 : 18" /><span v-if="!compact">{{ label }}</span>
        </button>
        <div v-if="open" class="absolute right-0 z-40 mt-2 flex w-64 flex-col rounded-2xl border border-line bg-white p-1.5 text-[14px] shadow-xl" role="menu">
            <button type="button" :class="item" role="menuitem" @click="go('whatsapp', (u) => `https://wa.me/?text=${encodeURIComponent(message(u))}`)"><Icon name="whatsapp" :size="18" /> WhatsApp</button>
            <button type="button" :class="item" role="menuitem" @click="go('facebook', (u) => `https://www.facebook.com/sharer/sharer.php?u=${encodeURIComponent(u)}`)"><Icon name="share" :size="18" /> Facebook</button>
            <button type="button" :class="item" role="menuitem" @click="go('x', (u) => `https://twitter.com/intent/tweet?text=${encodeURIComponent(message(u))}`)"><Icon name="share" :size="18" /> X (Twitter)</button>
            <button type="button" :class="item" role="menuitem" @click="go('telegram', (u) => `https://t.me/share/url?url=${encodeURIComponent(u)}&text=${encodeURIComponent(text || title)}`)"><Icon name="share" :size="18" /> Telegram</button>
            <button v-if="vehicle" type="button" :class="item" role="menuitem" @click="instagram"><Icon name="share" :size="18" /> Instagram</button>
            <button type="button" :class="item" role="menuitem" @click="copy"><Icon name="copy" :size="18" /> {{ copied ? 'Link copied' : 'Copy link' }}</button>
            <p v-if="note" class="px-3 py-2 text-[13px] text-muted" role="status">{{ note }}</p>
            <template v-if="images && cards">
                <div class="mx-2 my-1 border-t border-divider" />
                <a :href="cards.story" download="lotlink-status.png" target="_blank" rel="noopener" :class="item" role="menuitem"><Icon name="download" :size="18" /> Image for Status and Stories</a>
                <a :href="cards.square" download="lotlink-post.png" target="_blank" rel="noopener" :class="item" role="menuitem"><Icon name="download" :size="18" /> Image for posts</a>
            </template>
        </div>
    </div>
</template>
