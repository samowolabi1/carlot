<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { Link } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, reactive, ref, watch } from 'vue';

export interface ViewerPhoto {
    src: string;
    srcset: string;
    full: string;
}

/**
 * Full-screen photo viewer: swipe or arrow through the photos, and zoom in with a pinch,
 * the mouse wheel, a double tap or the +/− buttons; drag to look around when zoomed.
 * Used for the quick look on listing cards (with the car's title and a link) and the car page.
 */
const props = withDefaults(
    defineProps<{
        photos: ViewerPhoto[];
        start?: number;
        title?: string;
        price?: string | null;
        href?: string | null;
        loading?: boolean;
    }>(),
    { start: 0, title: '', price: null, href: null, loading: false },
);

const emit = defineEmits<{ close: []; change: [index: number] }>();

const MAX_SCALE = 4;
const index = ref(Math.min(props.start, Math.max(props.photos.length - 1, 0)));
const scale = ref(1);
const pan = reactive({ x: 0, y: 0 });
const swipe = ref(0);
const animate = ref(true);
const stage = ref<HTMLElement | null>(null);
const closeButton = ref<HTMLButtonElement | null>(null);
const strip = ref<HTMLElement | null>(null);
const ratios = reactive<Record<number, number>>({});

const photo = computed(() => props.photos[index.value] ?? null);
const zoomed = computed(() => scale.value > 1.01);
const count = computed(() => props.photos.length);

watch(
    () => props.photos.length,
    (n) => {
        if (index.value >= n) index.value = Math.max(n - 1, 0);
        preload();
    },
);

function go(to: number) {
    if (!count.value) return;
    index.value = (to + count.value) % count.value;
    reset();
    emit('change', index.value);
    preload();
    nextTick(() => strip.value?.querySelector<HTMLElement>(`[data-i="${index.value}"]`)?.scrollIntoView({ block: 'nearest', inline: 'center', behavior: 'smooth' }));
}
const next = () => go(index.value + 1);
const prev = () => go(index.value - 1);

function preload() {
    for (const i of [index.value + 1, index.value - 1]) {
        const p = props.photos[(i + count.value) % Math.max(count.value, 1)];
        if (p) new Image().src = p.full;
    }
}

function reset() {
    scale.value = 1;
    pan.x = 0;
    pan.y = 0;
    swipe.value = 0;
}

/** Size of the photo as fitted on the stage at 1×, used to stop panning past its edges. */
function fitted() {
    const box = stage.value?.getBoundingClientRect() ?? { width: window.innerWidth, height: window.innerHeight };
    const ratio = ratios[index.value] ?? 16 / 10;
    const width = Math.min(box.width, box.height * ratio);
    return { box, width, height: width / ratio };
}

function clamp() {
    const { box, width, height } = fitted();
    const maxX = Math.max(0, (width * scale.value - box.width) / 2);
    const maxY = Math.max(0, (height * scale.value - box.height) / 2);
    pan.x = Math.min(maxX, Math.max(-maxX, pan.x));
    pan.y = Math.min(maxY, Math.max(-maxY, pan.y));
}

/** Zoom to `to`, keeping the point under (cx, cy) — relative to the stage centre — still. */
function zoomTo(to: number, cx = 0, cy = 0) {
    const target = Math.min(MAX_SCALE, Math.max(1, to));
    const k = target / scale.value;
    pan.x = cx - (cx - pan.x) * k;
    pan.y = cy - (cy - pan.y) * k;
    scale.value = target;
    if (target === 1) {
        pan.x = 0;
        pan.y = 0;
    }
    clamp();
}

function centreOffset(clientX: number, clientY: number) {
    const box = stage.value!.getBoundingClientRect();
    return { x: clientX - box.left - box.width / 2, y: clientY - box.top - box.height / 2 };
}

function onWheel(e: WheelEvent) {
    e.preventDefault();
    const { x, y } = centreOffset(e.clientX, e.clientY);
    animate.value = false;
    zoomTo(scale.value * Math.exp(-e.deltaY * 0.0025), x, y);
}

// Pointer gestures: one finger swipes (or pans when zoomed), two fingers pinch, a double tap zooms.
const pointers = new Map<number, { x: number; y: number }>();
let gesture: { x: number; y: number; panX: number; panY: number; dist: number; scale: number; mid: { x: number; y: number }; moved: boolean; at: number } | null = null;
let lastTap = { at: 0, x: 0, y: 0 };

function startGesture() {
    const pts = [...pointers.values()];
    const mid = pts.length > 1 ? { x: (pts[0].x + pts[1].x) / 2, y: (pts[0].y + pts[1].y) / 2 } : pts[0];
    gesture = {
        x: mid.x,
        y: mid.y,
        panX: pan.x,
        panY: pan.y,
        dist: pts.length > 1 ? Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y) : 0,
        scale: scale.value,
        mid: centreOffset(mid.x, mid.y),
        moved: gesture?.moved ?? false,
        at: gesture?.at ?? Date.now(),
    };
}

function onPointerDown(e: PointerEvent) {
    if (e.pointerType === 'mouse' && e.button !== 0) return;
    (e.currentTarget as HTMLElement).setPointerCapture(e.pointerId);
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    if (pointers.size === 1) gesture = null;
    animate.value = false;
    startGesture();
}

function onPointerMove(e: PointerEvent) {
    if (!pointers.has(e.pointerId) || !gesture) return;
    pointers.set(e.pointerId, { x: e.clientX, y: e.clientY });
    const pts = [...pointers.values()];

    if (pts.length > 1) {
        const dist = Math.hypot(pts[0].x - pts[1].x, pts[0].y - pts[1].y);
        const mid = centreOffset((pts[0].x + pts[1].x) / 2, (pts[0].y + pts[1].y) / 2);
        const target = Math.min(MAX_SCALE, Math.max(1, gesture.scale * (dist / Math.max(gesture.dist, 1))));
        const k = target / gesture.scale;
        scale.value = target;
        pan.x = mid.x - (gesture.mid.x - gesture.panX) * k;
        pan.y = mid.y - (gesture.mid.y - gesture.panY) * k;
        clamp();
        gesture.moved = true;
        return;
    }

    const dx = e.clientX - gesture.x;
    const dy = e.clientY - gesture.y;
    if (Math.hypot(dx, dy) > 6) gesture.moved = true;

    if (zoomed.value) {
        pan.x = gesture.panX + dx;
        pan.y = gesture.panY + dy;
        clamp();
    } else if (count.value > 1) {
        swipe.value = dx;
    }
}

function onPointerUp(e: PointerEvent) {
    if (!pointers.has(e.pointerId)) return;
    pointers.delete(e.pointerId);
    animate.value = true;

    if (pointers.size > 0) {
        startGesture();
        return;
    }

    const g = gesture;
    gesture = null;
    if (!g) return;

    if (!zoomed.value && Math.abs(swipe.value) > 60) {
        if (swipe.value > 0) prev();
        else next();
        return;
    }
    swipe.value = 0;
    if (scale.value < 1.05) reset();

    if (!g.moved && Date.now() - g.at < 300) {
        const now = Date.now();
        if (now - lastTap.at < 320 && Math.hypot(e.clientX - lastTap.x, e.clientY - lastTap.y) < 30) {
            const { x, y } = centreOffset(e.clientX, e.clientY);
            zoomTo(zoomed.value ? 1 : 2.5, x, y);
            lastTap = { at: 0, x: 0, y: 0 };
        } else {
            lastTap = { at: now, x: e.clientX, y: e.clientY };
        }
    }
}

function onKey(e: KeyboardEvent) {
    if (e.key === 'Escape') {
        e.preventDefault();
        if (zoomed.value) zoomTo(1);
        else emit('close');
    } else if (e.key === 'ArrowRight' && !zoomed.value) next();
    else if (e.key === 'ArrowLeft' && !zoomed.value) prev();
    else if (e.key === '+' || e.key === '=') zoomTo(scale.value * 1.5);
    else if (e.key === '-') zoomTo(scale.value / 1.5);
    else if (e.key === 'Tab') trapFocus(e);
}

const dialog = ref<HTMLElement | null>(null);
function trapFocus(e: KeyboardEvent) {
    const items = dialog.value?.querySelectorAll<HTMLElement>('a[href], button:not([disabled])');
    if (!items?.length) return;
    const first = items[0];
    const last = items[items.length - 1];
    if (e.shiftKey && document.activeElement === first) {
        e.preventDefault();
        last.focus();
    } else if (!e.shiftKey && document.activeElement === last) {
        e.preventDefault();
        first.focus();
    }
}

function onLoad(e: Event, i: number) {
    const img = e.target as HTMLImageElement;
    if (img.naturalWidth) ratios[i] = img.naturalWidth / img.naturalHeight;
}

const opener = document.activeElement as HTMLElement | null;
const overflow = document.body.style.overflow;
document.body.style.overflow = 'hidden';
window.addEventListener('keydown', onKey);
nextTick(() => {
    closeButton.value?.focus();
    preload();
});

onBeforeUnmount(() => {
    window.removeEventListener('keydown', onKey);
    document.body.style.overflow = overflow;
    opener?.focus?.();
});
</script>

<template>
    <Teleport to="body">
        <div ref="dialog" class="fixed inset-0 z-[100] flex flex-col bg-[#0E1311] text-white" role="dialog" aria-modal="true" :aria-label="title ? `Photos of ${title}` : 'Photos'">
            <header class="flex min-h-14 shrink-0 items-center gap-3 px-3 pt-[env(safe-area-inset-top)] sm:px-5">
                <div class="min-w-0 grow">
                    <p v-if="title" class="truncate text-[15px] font-semibold">{{ title }}</p>
                    <p class="text-[13px] text-white/70" aria-live="polite">
                        <span v-if="price" class="font-display font-bold text-white">{{ price }} · </span>
                        <template v-if="count">Photo {{ index + 1 }} of {{ count }}</template>
                        <template v-else-if="loading">Loading photos…</template>
                        <template v-else>No photos yet</template>
                    </p>
                </div>
                <Link v-if="href" :href="href" class="btn h-11 shrink-0 border-white/25 bg-white/10 text-white no-underline hover:bg-white/20 hover:text-white">View car</Link>
                <button ref="closeButton" type="button" class="flex h-11 w-11 shrink-0 items-center justify-center rounded-full bg-white/10 hover:bg-white/20" aria-label="Close photos" @click="emit('close')">
                    <Icon name="close" :size="22" />
                </button>
            </header>

            <div
                ref="stage"
                class="relative grow touch-none overflow-hidden select-none"
                :class="zoomed ? 'cursor-grab active:cursor-grabbing' : 'cursor-zoom-in'"
                @wheel="onWheel"
                @pointerdown="onPointerDown"
                @pointermove="onPointerMove"
                @pointerup="onPointerUp"
                @pointercancel="onPointerUp"
            >
                <div v-if="loading && !count" class="absolute inset-0 flex items-center justify-center">
                    <span class="h-10 w-10 animate-spin rounded-full border-2 border-white/25 border-t-white" aria-hidden="true" />
                </div>
                <img
                    v-if="photo"
                    :key="index"
                    :src="photo.full"
                    :alt="`${title || 'Car'} photo ${index + 1} of ${count}`"
                    class="absolute inset-0 m-auto max-h-full max-w-full object-contain will-change-transform"
                    :class="{ 'transition-transform duration-200 ease-out': animate }"
                    :style="{ transform: `translate3d(${pan.x + swipe}px, ${pan.y}px, 0) scale(${scale})` }"
                    draggable="false"
                    @load="onLoad($event, index)"
                />

                <template v-if="count > 1 && !zoomed">
                    <button type="button" class="absolute top-1/2 left-2 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 hover:bg-black/70 sm:flex" aria-label="Previous photo" @pointerdown.stop @click="prev">
                        <Icon name="chevronLeft" :size="26" />
                    </button>
                    <button type="button" class="absolute top-1/2 right-2 hidden h-12 w-12 -translate-y-1/2 items-center justify-center rounded-full bg-black/45 hover:bg-black/70 sm:flex" aria-label="Next photo" @pointerdown.stop @click="next">
                        <Icon name="chevronRight" :size="26" />
                    </button>
                </template>
            </div>

            <footer class="flex shrink-0 flex-col gap-2 px-3 pt-2 pb-[max(env(safe-area-inset-bottom),12px)] sm:px-5">
                <div class="flex items-center justify-center gap-2">
                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-40 sm:hidden" aria-label="Previous photo" :disabled="count < 2" @click="prev">
                        <Icon name="chevronLeft" :size="22" />
                    </button>
                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-40" aria-label="Zoom out" :disabled="!zoomed" @click="zoomTo(scale / 1.5)">
                        <Icon name="zoomOut" :size="22" />
                    </button>
                    <span class="w-12 text-center text-[13px] text-white/70 tabular-nums">{{ Math.round(scale * 100) }}%</span>
                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-40" aria-label="Zoom in" :disabled="scale >= MAX_SCALE || !count" @click="zoomTo(scale * 1.5)">
                        <Icon name="zoomIn" :size="22" />
                    </button>
                    <button type="button" class="flex h-11 w-11 items-center justify-center rounded-full bg-white/10 hover:bg-white/20 disabled:opacity-40 sm:hidden" aria-label="Next photo" :disabled="count < 2" @click="next">
                        <Icon name="chevronRight" :size="22" />
                    </button>
                </div>
                <p class="text-center text-[12px] text-white/55">
                    <span class="sm:hidden">Swipe for more · pinch or double-tap to zoom</span>
                    <span class="hidden sm:inline">Arrow keys for more · scroll or double-click to zoom · drag to look around</span>
                </p>
                <div v-if="count > 1" ref="strip" class="flex gap-2 overflow-x-auto pb-1 [scrollbar-width:none] sm:justify-center">
                    <button
                        v-for="(p, i) in photos"
                        :key="p.src"
                        type="button"
                        :data-i="i"
                        class="h-12 w-[72px] shrink-0 overflow-hidden rounded-md ring-2 transition"
                        :class="i === index ? 'opacity-100 ring-white' : 'opacity-55 ring-transparent hover:opacity-90'"
                        :aria-label="`Photo ${i + 1}`"
                        :aria-current="i === index"
                        @click="go(i)"
                    >
                        <img :src="p.src" alt="" loading="lazy" class="h-full w-full object-cover" />
                    </button>
                </div>
            </footer>
        </div>
    </Teleport>
</template>
