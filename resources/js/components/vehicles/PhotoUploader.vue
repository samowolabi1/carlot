<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { HttpError, json, send, xsrfToken } from '@/lib/http';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

export interface MediaItem {
    ulid: string;
    status: 'processing' | 'ready' | 'failed';
    error: string | null;
    is_cover: boolean;
    thumb_url: string | null;
}

interface Item {
    id: string;
    ulid?: string;
    status: 'queued' | 'uploading' | 'processing' | 'ready' | 'failed';
    progress: number;
    preview: string | null;
    error: string | null;
    /** Rotation shown while the server re-renders the photo. */
    turning: number;
    working: boolean;
}

const props = defineProps<{ lotSlug: string; vehicleUlid: string; initial: MediaItem[]; max: number }>();
const emit = defineEmits<{ change: [readyCount: number] }>();

const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 12 * 1024 * 1024;
const MAX_EDGE = 2400;
const PARALLEL = 2;
const action = 'flex h-11 w-11 items-center justify-center rounded-full bg-white/15 text-white transition hover:bg-white/30 disabled:opacity-35 disabled:hover:bg-white/15';

/** The shots buyers look for, in the order that tells the car's story (empty slots show these). */
const SHOTS = [
    'Front ¾ (best cover)',
    'Front',
    'Driver side',
    'Rear ¾',
    'Rear',
    'Passenger side',
    'Dashboard & odometer',
    'Front seats',
    'Rear seats',
    'Engine bay',
    'Wheels & tyres',
    'Boot',
];

const TIPS = [
    { title: 'Landscape', text: 'Hold the phone sideways so the whole car fits.' },
    { title: 'Good light', text: 'Daylight or shade. Avoid night shots and harsh midday glare.' },
    { title: 'Clean and clear', text: 'Wash the car and move other cars, people and clutter out of the shot.' },
    { title: 'Fill the frame', text: 'Stand about 3 steps back for outside shots; get close for the odometer.' },
    { title: 'Be honest', text: 'Show scratches or dents. Buyers trust sellers who do, and it saves wasted visits.' },
];

const items = ref<Item[]>(props.initial.map(fromServer));
const files = new Map<string, File>();
const input = ref<HTMLInputElement | null>(null);
const camera = ref<HTMLInputElement | null>(null);
const notice = ref<string | null>(null);
const dragIndex = ref<number | null>(null);
const dropping = ref(false);
const selected = ref<string | null>(null);
const guideOpen = ref(props.initial.length === 0);
let poll: ReturnType<typeof setInterval> | undefined;
let active = 0;

const readyCount = computed(() => items.value.filter((i) => i.status === 'ready').length);
const uploading = computed(() => items.value.filter((i) => i.status === 'queued' || i.status === 'uploading'));
const busy = computed(() => uploading.value.length > 0);
const remaining = computed(() => props.max - items.value.length);
const ghosts = computed(() => Array.from({ length: Math.max(0, remaining.value) }, (_, k) => ({ n: items.value.length + k, label: SHOTS[items.value.length + k] ?? 'Extra detail' })));
const overall = computed(() => {
    const list = uploading.value;
    return list.length ? list.reduce((sum, i) => sum + i.progress, 0) / list.length : 1;
});
const hint = computed(() => {
    if (readyCount.value === 0) return 'Add at least one photo to publish. Start with the front ¾, it makes the best cover.';
    if (readyCount.value < 8) return `Good start. Cars with 8 or more photos get more enquiries, ${8 - readyCount.value} to go.`;
    if (remaining.value > 0) return 'Great set. Add close-ups of anything special: new tyres, service book, extras.';
    return 'All photo slots used. Remove one to add another.';
});

watch(readyCount, (n) => emit('change', n), { immediate: true });

function fromServer(m: MediaItem): Item {
    return { id: m.ulid, ulid: m.ulid, status: m.status, progress: 1, preview: m.thumb_url, error: m.error, turning: 0, working: false };
}

const r = (name: string, ...params: string[]) => route(name, [props.lotSlug, props.vehicleUlid, ...params]);
const message = (e: unknown, fallback: string) => (e instanceof Error ? e.message : fallback);

/** Large photos are shrunk in the browser first: less mobile data, and EXIF (GPS) is dropped. */
async function shrink(file: File): Promise<Blob> {
    if (file.size < 2.5 * 1024 * 1024 || !('createImageBitmap' in window)) return file;

    try {
        const bitmap = await createImageBitmap(file, { imageOrientation: 'from-image' });
        const scale = Math.min(1, MAX_EDGE / Math.max(bitmap.width, bitmap.height));
        const canvas = document.createElement('canvas');
        canvas.width = Math.round(bitmap.width * scale);
        canvas.height = Math.round(bitmap.height * scale);
        canvas.getContext('2d')!.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
        bitmap.close();
        const blob = await new Promise<Blob | null>((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.88));
        return blob && blob.size < file.size ? blob : file;
    } catch {
        return file;
    }
}

async function upload(item: Item, file: File) {
    item.status = 'uploading';
    item.progress = 0;
    item.error = null;

    try {
        const blob = await shrink(file);
        const type = blob.type || file.type;
        if (blob.size > MAX_BYTES) throw new HttpError('Photos can be up to 12 MB.', 422);

        const target = await json<{ key: string; method: string; url: string; headers: Record<string, string> }>('POST', r('dealer.vehicles.media.presign'), {
            type,
            size: blob.size,
        });

        if (target.method === 'PUT') {
            // Straight to the bucket (Cloudflare R2) with a pre-signed URL.
            await send('PUT', target.url, blob, target.headers, (p) => (item.progress = p));
        } else {
            const form = new FormData();
            form.append('key', target.key);
            form.append('file', blob, file.name.replace(/\.\w+$/, '') + (type === 'image/jpeg' ? '.jpg' : ''));
            await send('POST', target.url, form, { Accept: 'application/json', 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest' }, (p) => (item.progress = p));
        }

        const media = await json<MediaItem>('POST', r('dealer.vehicles.media.store'), { key: target.key });
        item.ulid = media.ulid;
        item.status = media.status;
        item.error = media.error;
        item.progress = 1;
        files.delete(item.id);
        startPolling();
    } catch (e) {
        item.status = 'failed';
        item.error = message(e, 'Upload failed.');
    }
}

/** Two uploads at a time; when a batch is done the order on screen is saved (uploads can finish out of order). */
function pump() {
    while (active < PARALLEL) {
        const item = items.value.find((i) => i.status === 'queued');
        const file = item && files.get(item.id);
        if (!item || !file) break;
        active++;
        item.status = 'uploading';
        upload(item, file).finally(() => {
            active--;
            if (!busy.value) saveOrder();
            pump();
        });
    }
}

function addFiles(list: FileList | File[] | null | undefined) {
    notice.value = null;
    if (!list?.length) return;

    const all = Array.from(list);
    const accepted = all.filter((f) => ACCEPTED.includes(f.type));

    if (accepted.length < all.length) notice.value = 'Some files were skipped: use JPEG, PNG or WebP photos.';
    if (accepted.length > remaining.value) {
        notice.value = remaining.value > 0 ? `Only ${remaining.value} more ${remaining.value === 1 ? 'photo fits' : 'photos fit'}, so we added the first ${remaining.value}. A car can have ${props.max}.` : `This car already has ${props.max} photos. Remove one to add another.`;
    }

    for (const file of accepted.slice(0, Math.max(0, remaining.value))) {
        const id = crypto.randomUUID();
        files.set(id, file);
        items.value.push({ id, status: 'queued', progress: 0, preview: URL.createObjectURL(file), error: null, turning: 0, working: false });
    }

    for (const el of [input.value, camera.value]) if (el) el.value = '';
    pump();
}

function retry(item: Item) {
    if (!files.has(item.id)) return;
    item.status = 'queued';
    item.error = null;
    pump();
}

function onDropFiles(e: DragEvent) {
    dropping.value = false;
    if (e.dataTransfer?.files.length) addFiles(e.dataTransfer.files);
}

function startPolling() {
    if (poll) return;
    poll = setInterval(async () => {
        if (!items.value.some((i) => i.status === 'processing')) {
            clearInterval(poll);
            poll = undefined;
            return;
        }
        try {
            const media = await json<MediaItem[]>('GET', r('dealer.vehicles.media.index'));
            for (const m of media) {
                const item = items.value.find((i) => i.ulid === m.ulid);
                if (!item || item.status === 'uploading') continue;
                item.status = m.status;
                item.error = m.error;
                if (m.thumb_url) item.preview = m.thumb_url;
            }
        } catch {
            /* keep polling */
        }
    }, 2000);
}

async function saveOrder() {
    const order = items.value.filter((i) => i.ulid && i.status !== 'failed').map((i) => i.ulid!);
    if (order.length < 2) return;
    try {
        await json('PUT', r('dealer.vehicles.media.reorder'), { order });
    } catch (e) {
        notice.value = message(e, 'Could not save the order.');
    }
}

function move(from: number, to: number) {
    if (to < 0 || to >= items.value.length || from === to) return;
    const [item] = items.value.splice(from, 1);
    items.value.splice(to, 0, item);
    if (!busy.value) saveOrder();
}

async function makeCover(index: number) {
    const item = items.value[index];
    if (!item.ulid || index === 0) return;
    items.value.splice(index, 1);
    items.value.unshift(item);
    try {
        await json('POST', r('dealer.vehicles.media.cover', item.ulid));
    } catch (e) {
        notice.value = message(e, 'Could not change the cover.');
    }
}

async function rotate(item: Item, degrees: 90 | -90) {
    if (!item.ulid || item.working) return;
    item.working = true;
    item.turning += degrees;
    try {
        const media = await json<MediaItem>('POST', r('dealer.vehicles.media.rotate', item.ulid), { degrees });
        await new Promise<void>((resolve) => {
            // Swap to the new file once it has loaded, so the tile doesn't flash.
            const img = new Image();
            img.onload = img.onerror = () => resolve();
            img.src = media.thumb_url ?? '';
        });
        item.preview = media.thumb_url;
        item.turning = 0;
    } catch (e) {
        item.turning -= degrees;
        notice.value = message(e, 'Could not rotate the photo.');
    } finally {
        item.working = false;
    }
}

async function remove(index: number) {
    const item = items.value[index];
    if (item.ulid) {
        item.working = true;
        try {
            await json('DELETE', r('dealer.vehicles.media.destroy', item.ulid));
        } catch (e) {
            item.working = false;
            notice.value = message(e, 'Could not remove the photo.');
            return;
        }
    }
    files.delete(item.id);
    if (selected.value === item.id) selected.value = null;
    items.value.splice(index, 1);
}

function toggle(item: Item) {
    if (item.status !== 'ready' && item.status !== 'failed') return;
    selected.value = selected.value === item.id ? null : item.id;
}

function onTileDrop(to: number) {
    if (dragIndex.value !== null) move(dragIndex.value, to);
    dragIndex.value = null;
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') selected.value = null;
}
function onOutside(e: PointerEvent) {
    if (selected.value && !(e.target as HTMLElement).closest('[data-photo-tile]')) selected.value = null;
}

onMounted(() => {
    window.addEventListener('keydown', onKey);
    document.addEventListener('pointerdown', onOutside);
});

if (items.value.some((i) => i.status === 'processing')) startPolling();
onBeforeUnmount(() => {
    clearInterval(poll);
    window.removeEventListener('keydown', onKey);
    document.removeEventListener('pointerdown', onOutside);
});
</script>

<template>
    <div class="grid gap-5 lg:grid-cols-[minmax(0,1fr)_300px] lg:items-start">
        <div class="flex min-w-0 flex-col gap-4">
            <!-- Count, progress and the next thing to do -->
            <div class="flex flex-col gap-2">
                <div class="flex items-baseline justify-between gap-3">
                    <span class="text-[15px] font-semibold">{{ items.length }} of {{ max }} photos</span>
                    <span class="text-[13px] text-muted">First photo is the cover</span>
                </div>
                <div class="h-1.5 overflow-hidden rounded-full bg-sand" aria-hidden="true">
                    <div class="h-full rounded-full bg-forest transition-all" :style="{ width: `${(readyCount / max) * 100}%` }" />
                </div>
                <p v-if="busy" class="flex items-center gap-2 text-[13px] font-medium text-forest" role="status">
                    <span class="h-3.5 w-3.5 animate-spin rounded-full border-2 border-forest/25 border-t-forest" aria-hidden="true" />
                    Uploading {{ uploading.length }} {{ uploading.length === 1 ? 'photo' : 'photos' }} · {{ Math.round(overall * 100) }}%. You can keep arranging while they upload.
                </p>
                <p v-else class="text-[13px] text-muted" role="status">{{ hint }}</p>
            </div>

            <!-- Drop zone: drag files in on a computer; take or choose photos on a phone -->
            <div
                v-if="remaining > 0"
                class="flex flex-col items-center gap-3 rounded-2xl border-2 border-dashed px-4 py-5 text-center transition"
                :class="dropping ? 'border-forest bg-map/60' : 'border-[#C9C2B5] bg-white'"
                @dragover.prevent="dragIndex === null && (dropping = true)"
                @dragleave.self="dropping = false"
                @drop.prevent="onDropFiles"
            >
                <span class="flex h-12 w-12 items-center justify-center rounded-full bg-cream text-clay"><Icon name="upload" :size="24" /></span>
                <div class="flex flex-col gap-0.5">
                    <span class="text-[15px] font-semibold">
                        <span class="hidden md:inline">Drag photos here, or choose them</span>
                        <span class="md:hidden">Add up to {{ remaining }} more {{ remaining === 1 ? 'photo' : 'photos' }}</span>
                    </span>
                    <span class="text-[13px] text-muted">Pick several at once. JPEG, PNG or WebP, up to 12 MB each. Location data is removed.</span>
                </div>
                <div class="flex w-full flex-col gap-2 sm:w-auto sm:flex-row">
                    <button type="button" class="btn btn-primary h-11" @click="input?.click()"><Icon name="image" :size="18" /> Choose photos</button>
                    <button type="button" class="btn btn-outline h-11 md:hidden" @click="camera?.click()"><Icon name="camera" :size="18" /> Take a photo</button>
                </div>
                <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" tabindex="-1" aria-hidden="true" @change="addFiles(($event.target as HTMLInputElement).files)" />
                <input ref="camera" type="file" accept="image/jpeg,image/png,image/webp" capture="environment" class="sr-only" tabindex="-1" aria-hidden="true" @change="addFiles(($event.target as HTMLInputElement).files)" />
            </div>

            <p v-if="notice" class="text-[13px] font-medium text-danger" role="alert">{{ notice }}</p>

            <!-- Photos, then empty slots naming the shots still worth taking -->
            <ol class="grid grid-cols-2 gap-2.5 sm:grid-cols-3 lg:grid-cols-4" aria-label="Photos, in the order buyers see them">
                <li
                    v-for="(item, i) in items"
                    :key="item.id"
                    data-photo-tile
                    class="relative aspect-[4/3] overflow-hidden rounded-xl bg-sand ring-offset-2 ring-offset-ivory transition"
                    :class="[selected === item.id ? 'ring-2 ring-forest' : '', dragIndex === i ? 'opacity-40' : '']"
                    :draggable="!!item.ulid && item.status === 'ready'"
                    @dragstart="dragIndex = i"
                    @dragend="dragIndex = null"
                    @dragover.prevent
                    @drop.prevent.stop="onTileDrop(i)"
                >
                    <button
                        type="button"
                        class="block h-full w-full"
                        :class="item.status === 'ready' ? 'cursor-pointer' : 'cursor-default'"
                        :aria-label="`Photo ${i + 1}${i === 0 ? ', cover' : ''}. ${item.status === 'ready' ? 'Show options' : item.status}`"
                        :aria-expanded="selected === item.id"
                        @click="toggle(item)"
                    >
                        <img
                            v-if="item.preview"
                            :src="item.preview"
                            alt=""
                            class="h-full w-full object-cover transition-transform duration-300"
                            :style="item.turning ? { transform: `rotate(${item.turning}deg) scale(0.75)` } : undefined"
                            draggable="false"
                        />
                    </button>

                    <span v-if="i === 0 && item.status !== 'failed'" class="pointer-events-none absolute top-1.5 left-1.5 rounded-lg bg-forest px-2 py-0.5 text-[11px] font-semibold text-white">Cover</span>
                    <span v-else class="pointer-events-none absolute top-1.5 left-1.5 flex h-6 min-w-6 items-center justify-center rounded-lg bg-ink/60 px-1.5 text-[11px] font-semibold text-white">{{ i + 1 }}</span>

                    <!-- Upload / processing state -->
                    <div
                        v-if="item.status === 'queued' || item.status === 'uploading' || item.status === 'processing' || item.working"
                        class="pointer-events-none absolute inset-0 flex flex-col items-center justify-center gap-2 bg-ink/45 px-3 text-center text-[12px] font-semibold text-white"
                    >
                        <template v-if="item.status === 'uploading'">
                            Uploading {{ Math.round(item.progress * 100) }}%
                            <span class="h-1 w-full max-w-24 overflow-hidden rounded-full bg-white/30"><span class="block h-full bg-white" :style="{ width: `${item.progress * 100}%` }" /></span>
                        </template>
                        <template v-else-if="item.status === 'queued'">Waiting…</template>
                        <template v-else>
                            <span class="h-5 w-5 animate-spin rounded-full border-2 border-white/30 border-t-white" aria-hidden="true" />
                            {{ item.status === 'processing' ? 'Processing…' : 'Saving…' }}
                        </template>
                    </div>

                    <!-- Failed: why, and try again -->
                    <div v-if="item.status === 'failed'" class="absolute inset-0 flex flex-col items-center justify-center gap-1.5 bg-clay-dark/90 p-2 text-center text-[12px] font-semibold text-white">
                        <span class="line-clamp-2">{{ item.error ?? 'Upload failed' }}</span>
                        <div class="flex gap-1.5">
                            <button v-if="files.has(item.id)" type="button" class="flex h-11 items-center gap-1 rounded-lg bg-white px-3 text-ink" @click="retry(item)">
                                <Icon name="refresh" :size="16" /> Retry
                            </button>
                            <button type="button" class="flex h-11 w-11 items-center justify-center rounded-lg bg-white/20" :aria-label="`Remove photo ${i + 1}`" @click="remove(i)">
                                <Icon name="trash" :size="18" />
                            </button>
                        </div>
                    </div>

                    <!-- Options for the chosen photo: big buttons that work with a thumb -->
                    <div
                        v-if="selected === item.id && item.status === 'ready' && !item.working"
                        class="absolute inset-0 grid grid-cols-3 content-center justify-items-center gap-1 bg-ink/70 p-1.5"
                        role="group"
                        :aria-label="`Options for photo ${i + 1}`"
                    >
                        <button type="button" :class="action" title="Rotate left" aria-label="Rotate left" @click="rotate(item, -90)"><Icon name="rotateLeft" :size="20" /></button>
                        <button type="button" :class="action" title="Rotate right" aria-label="Rotate right" @click="rotate(item, 90)"><Icon name="rotateRight" :size="20" /></button>
                        <button type="button" :class="action" title="Make cover" aria-label="Make this the cover photo" :disabled="i === 0" @click="makeCover(i)"><Icon name="star" :size="20" /></button>
                        <button type="button" :class="action" title="Move earlier" :aria-label="`Move photo ${i + 1} earlier`" :disabled="i === 0" @click="move(i, i - 1)"><Icon name="chevronLeft" :size="20" /></button>
                        <button type="button" :class="action" title="Move later" :aria-label="`Move photo ${i + 1} later`" :disabled="i === items.length - 1" @click="move(i, i + 1)"><Icon name="chevronRight" :size="20" /></button>
                        <button type="button" :class="[action, 'hover:bg-danger']" title="Remove" :aria-label="`Remove photo ${i + 1}`" @click="remove(i)"><Icon name="trash" :size="20" /></button>
                    </div>
                </li>

                <li v-for="ghost in ghosts" :key="`ghost-${ghost.n}`" class="aspect-[4/3]" @dragover.prevent @drop.prevent.stop="onDropFiles">
                    <button
                        type="button"
                        class="flex h-full w-full flex-col items-center justify-center gap-1 rounded-xl border-2 border-dashed border-[#DDD6C9] px-2 text-center text-muted transition hover:border-forest hover:text-forest"
                        :aria-label="`Add photo ${ghost.n + 1}: ${ghost.label}`"
                        @click="input?.click()"
                    >
                        <Icon name="plus" :size="18" :stroke-width="2" />
                        <span class="text-[12px] leading-tight font-semibold">{{ ghost.label }}</span>
                    </button>
                </li>
            </ol>

            <p class="text-[12px] text-muted">
                <span class="md:hidden">Tap a photo to rotate it, make it the cover, move it or remove it.</span>
                <span class="hidden md:inline">Drag photos to reorder. Click a photo to rotate it, make it the cover or remove it.</span>
            </p>
        </div>

        <!-- Guide: collapsible on phones, a side panel on big screens -->
        <aside class="rounded-2xl bg-cream lg:sticky lg:top-6">
            <button type="button" class="flex min-h-12 w-full items-center justify-between gap-2 px-4 text-left lg:hidden" :aria-expanded="guideOpen" aria-controls="photo-guide" @click="guideOpen = !guideOpen">
                <span class="flex items-center gap-2 text-[15px] font-semibold text-clay-dark"><Icon name="camera" :size="18" /> Photo guide</span>
                <Icon name="chevronDown" :size="18" class="text-clay-dark transition-transform" :class="{ 'rotate-180': guideOpen }" />
            </button>
            <h2 class="hidden min-h-12 items-center gap-2 px-4 font-sans text-[15px] font-semibold text-clay-dark lg:flex"><Icon name="camera" :size="18" /> Photo guide</h2>
            <div id="photo-guide" class="flex-col gap-4 px-4 pb-4" :class="guideOpen ? 'flex' : 'hidden lg:flex'">
                <div class="flex flex-col gap-1.5">
                    <span class="text-[13px] font-semibold">Shot list</span>
                    <ol class="grid grid-cols-2 gap-x-3 gap-y-1 text-[13px] lg:grid-cols-1">
                        <li v-for="(shot, i) in SHOTS" :key="shot" class="flex items-center gap-2">
                            <span
                                class="flex h-5 w-5 shrink-0 items-center justify-center rounded-full text-[11px] font-semibold"
                                :class="i < readyCount ? 'bg-forest text-white' : 'bg-white text-muted ring-1 ring-line'"
                            >
                                <Icon v-if="i < readyCount" name="check" :size="12" :stroke-width="3" />
                                <template v-else>{{ i + 1 }}</template>
                            </span>
                            {{ shot }}
                        </li>
                    </ol>
                </div>
                <ul class="flex flex-col gap-2.5">
                    <li v-for="tip in TIPS" :key="tip.title" class="text-[13px] leading-snug">
                        <span class="font-semibold">{{ tip.title }}.</span> <span class="text-[#4A4D53]">{{ tip.text }}</span>
                    </li>
                </ul>
            </div>
        </aside>
    </div>
</template>

