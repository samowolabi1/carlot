<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { HttpError, json, send, xsrfToken } from '@/lib/http';
import { computed, onBeforeUnmount, ref, watch } from 'vue';

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
}

const props = defineProps<{ lotSlug: string; vehicleUlid: string; initial: MediaItem[]; max: number }>();
const emit = defineEmits<{ change: [readyCount: number] }>();

const ACCEPTED = ['image/jpeg', 'image/png', 'image/webp'];
const MAX_BYTES = 12 * 1024 * 1024;
const MAX_EDGE = 2400;

const items = ref<Item[]>(props.initial.map(fromServer));
const input = ref<HTMLInputElement | null>(null);
const notice = ref<string | null>(null);
const dragIndex = ref<number | null>(null);
let poll: ReturnType<typeof setInterval> | undefined;
let queue: Promise<void> = Promise.resolve();

const readyCount = computed(() => items.value.filter((i) => i.status === 'ready').length);
const busy = computed(() => items.value.some((i) => ['queued', 'uploading'].includes(i.status)));
const remaining = computed(() => props.max - items.value.length);

watch(readyCount, (n) => emit('change', n), { immediate: true });

function fromServer(m: MediaItem): Item {
    return { id: m.ulid, ulid: m.ulid, status: m.status, progress: 1, preview: m.thumb_url, error: m.error };
}

const r = (name: string, ...params: string[]) => route(name, [props.lotSlug, props.vehicleUlid, ...params]);

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
        if (media.thumb_url) item.preview = media.thumb_url;
        startPolling();
    } catch (e) {
        item.status = 'failed';
        item.error = e instanceof Error ? e.message : 'Upload failed.';
    }
}

function addFiles(list: FileList | null) {
    notice.value = null;
    if (!list?.length) return;

    const files = Array.from(list);
    const accepted = files.filter((f) => ACCEPTED.includes(f.type));

    if (accepted.length < files.length) notice.value = 'Some files were skipped: use JPEG, PNG or WebP photos.';
    if (accepted.length > remaining.value) notice.value = `Only ${remaining.value} more ${remaining.value === 1 ? 'photo fits' : 'photos fit'} (${props.max} max).`;

    for (const file of accepted.slice(0, Math.max(0, remaining.value))) {
        const item: Item = { id: crypto.randomUUID(), status: 'queued', progress: 0, preview: URL.createObjectURL(file), error: null };
        items.value.push(item);
        // One at a time: kinder to mobile connections, and keeps the order the dealer chose.
        const reactiveItem = items.value[items.value.length - 1];
        queue = queue.then(() => upload(reactiveItem, file));
    }

    if (input.value) input.value.value = '';
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
                if (!item) continue;
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
    const order = items.value.filter((i) => i.ulid).map((i) => i.ulid!);
    try {
        await json('PUT', r('dealer.vehicles.media.reorder'), { order });
    } catch (e) {
        notice.value = e instanceof Error ? e.message : 'Could not save the order.';
    }
}

function move(from: number, to: number) {
    if (busy.value || to < 0 || to >= items.value.length || from === to) return;
    const [item] = items.value.splice(from, 1);
    items.value.splice(to, 0, item);
    saveOrder();
}

async function remove(index: number) {
    const item = items.value[index];
    if (item.ulid) {
        try {
            await json('DELETE', r('dealer.vehicles.media.destroy', item.ulid));
        } catch (e) {
            notice.value = e instanceof Error ? e.message : 'Could not remove the photo.';
            return;
        }
    }
    items.value.splice(index, 1);
}

function onDrop(to: number) {
    if (dragIndex.value !== null) move(dragIndex.value, to);
    dragIndex.value = null;
}

if (items.value.some((i) => i.status === 'processing')) startPolling();
onBeforeUnmount(() => clearInterval(poll));
</script>

<template>
    <div class="flex flex-col gap-4">
        <div class="flex items-baseline justify-between">
            <span class="text-[15px] font-semibold">{{ items.length }} of {{ max }} photos</span>
            <span class="text-[13px] text-muted">First photo is the cover</span>
        </div>

        <ul class="grid grid-cols-3 gap-2" aria-label="Photos">
            <li
                v-for="(item, i) in items"
                :key="item.id"
                class="group relative h-[104px] overflow-hidden rounded-xl bg-sand"
                :draggable="!busy && !!item.ulid"
                @dragstart="dragIndex = i"
                @dragover.prevent
                @drop="onDrop(i)"
            >
                <img v-if="item.preview" :src="item.preview" alt="" class="h-full w-full object-cover" />
                <span v-if="i === 0 && item.status !== 'failed'" class="absolute bottom-1.5 left-1.5 rounded-lg bg-forest px-2 py-0.5 text-[11px] font-semibold text-white">Cover</span>

                <span
                    v-if="item.status === 'queued' || item.status === 'uploading' || item.status === 'processing'"
                    class="absolute inset-0 flex items-center justify-center bg-ink/45 px-1 text-center text-[12px] font-semibold text-white"
                >
                    {{ item.status === 'queued' ? 'Waiting…' : item.status === 'uploading' ? `Uploading ${Math.round(item.progress * 100)}%` : 'Processing…' }}
                </span>
                <span v-if="item.status === 'failed'" class="absolute inset-0 flex items-center justify-center bg-clay-dark/85 p-2 text-center text-[11px] font-semibold text-white">
                    {{ item.error ?? 'Failed' }}
                </span>

                <div v-if="item.ulid || item.status === 'failed'" class="absolute top-1 right-1 flex gap-1">
                    <button
                        v-if="i > 0 && item.status === 'ready'"
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/90 text-ink shadow-sm"
                        :aria-label="`Move photo ${i + 1} earlier`"
                        :disabled="busy"
                        @click="move(i, i - 1)"
                    >
                        <Icon name="chevronLeft" :size="16" :stroke-width="2.2" />
                    </button>
                    <button
                        type="button"
                        class="flex h-8 w-8 items-center justify-center rounded-lg bg-white/90 text-ink shadow-sm"
                        :aria-label="`Remove photo ${i + 1}`"
                        @click="remove(i)"
                    >
                        <Icon name="close" :size="16" :stroke-width="2.2" />
                    </button>
                </div>
            </li>

            <li v-if="remaining > 0">
                <button
                    type="button"
                    class="flex h-[104px] w-full flex-col items-center justify-center gap-1.5 rounded-xl border-2 border-dashed border-[#C9C2B5] text-[13px] font-semibold text-forest hover:border-forest"
                    @click="input?.click()"
                >
                    <Icon name="plus" :size="22" :stroke-width="2" />Add photos
                </button>
                <input ref="input" type="file" accept="image/jpeg,image/png,image/webp" multiple class="sr-only" @change="addFiles(($event.target as HTMLInputElement).files)" />
            </li>
        </ul>

        <p v-if="notice" class="text-[13px] font-medium text-danger" role="alert">{{ notice }}</p>

        <div v-if="readyCount < 8" class="flex flex-col gap-1 rounded-2xl bg-cream px-3.5 py-3">
            <span class="text-[14px] font-semibold text-clay-dark">Aim for 8 or more photos</span>
            <span class="text-[13px] text-ink">Front, back, both sides, interior, dashboard, engine and tyres. Location data is removed from every photo.</span>
        </div>
    </div>
</template>
