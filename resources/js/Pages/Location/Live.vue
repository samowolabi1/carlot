<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import LiveMap from '@/components/location/LiveMap.vue';
import { echo } from '@/lib/echo';
import { json } from '@/lib/http';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

type Point = { lat: number; lng: number; accuracy: number | null; at: string | null };

const props = defineProps<{
    role: 'sharer' | 'viewer';
    session: { ulid: string; live: boolean; expires_at: string; point: Point | null; sharer: string; sharer_side: 'customer' | 'lot' };
    appointment: { what: string; when: string; url: string };
    lot: { name: string; lat: number | null; lng: number | null; directions: string | null };
    call: { label: string; phone: string; display: string } | null;
    back: 'booking' | 'calendar';
}>();

const point = ref<Point | null>(props.session.point);
const live = ref(props.session.live);
const now = ref(Date.now());
const error = ref<string | null>(null);
const lotPoint = computed(() => (props.lot.lat !== null && props.lot.lng !== null ? { lat: props.lot.lat, lng: props.lot.lng } : null));

const minutesLeft = computed(() => Math.max(0, Math.ceil((new Date(props.session.expires_at).getTime() - now.value) / 60000)));
const secondsAgo = computed(() => (point.value?.at ? Math.max(0, Math.round((now.value - new Date(point.value.at).getTime()) / 1000)) : null));

// Straight-line distance to the lot; a rough time assumes city traffic (about 25 km/h).
const distanceKm = computed(() => {
    if (!point.value || !lotPoint.value) return null;
    const r = 6371;
    const dLat = ((lotPoint.value.lat - point.value.lat) * Math.PI) / 180;
    const dLng = ((lotPoint.value.lng - point.value.lng) * Math.PI) / 180;
    const a = Math.sin(dLat / 2) ** 2 + Math.cos((point.value.lat * Math.PI) / 180) * Math.cos((lotPoint.value.lat * Math.PI) / 180) * Math.sin(dLng / 2) ** 2;
    return 2 * r * Math.asin(Math.sqrt(a));
});
const eta = computed(() => (distanceKm.value !== null ? Math.max(1, Math.round((distanceKm.value / 25) * 60)) : null));
const distanceLabel = computed(() => (distanceKm.value === null ? null : distanceKm.value < 1 ? `${Math.round(distanceKm.value * 1000)} m` : `${distanceKm.value.toFixed(1)} km`));
const heading = computed(() => {
    if (!live.value) return 'Sharing has stopped';
    if (distanceKm.value !== null && distanceKm.value < 0.15) return 'Arrived';
    return eta.value !== null ? `About ${eta.value} min · ${distanceLabel.value}` : props.role === 'sharer' ? 'Finding your location…' : 'Waiting for their location…';
});

let clock: ReturnType<typeof setInterval> | undefined;
let poller: ReturnType<typeof setInterval> | undefined;
let watchId: number | null = null;
let lastSent = 0;
const channel = `location-session.${props.session.ulid}`;

/** The sharer's phone sends a point at most every 10 s while this page is open (TDD M8). */
function startSharing() {
    if (!('geolocation' in navigator)) {
        error.value = "This browser can't share its location.";
        return;
    }
    watchId = navigator.geolocation.watchPosition(
        async (pos) => {
            error.value = null;
            point.value = { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: Math.round(pos.coords.accuracy), at: new Date().toISOString() };
            if (Date.now() - lastSent < 10_000) return;
            lastSent = Date.now();
            try {
                const res = await json<{ live: boolean }>('POST', route('location.position', props.session.ulid), { lat: pos.coords.latitude, lng: pos.coords.longitude, accuracy: pos.coords.accuracy });
                live.value = res.live;
            } catch {
                // Offline for a moment: the next point tries again.
            }
        },
        (e) => (error.value = e.code === e.PERMISSION_DENIED ? 'Allow location access for this site to share where you are.' : "Couldn't get your location. Check that location is on."),
        { enableHighAccuracy: true, maximumAge: 5000, timeout: 20000 },
    );
}

async function poll() {
    if (document.hidden) return;
    try {
        const res = await json<{ point: Point | null; live: boolean }>('GET', route('location.point', props.session.ulid));
        point.value = res.point ?? point.value;
        live.value = res.live;
    } catch {
        // Try again on the next tick.
    }
}

onMounted(() => {
    clock = setInterval(() => {
        now.value = Date.now();
        if (minutesLeft.value <= 0) live.value = false;
    }, 1000);
    if (!live.value) return;

    if (props.role === 'sharer') {
        startSharing();
    } else {
        const socket = echo();
        if (socket) {
            socket.private(channel).listen('.LocationUpdated', (e: { point: Point | null; live: boolean }) => {
                point.value = e.point ?? point.value;
                live.value = e.live;
            });
        } else {
            poller = setInterval(poll, 10_000);
        }
    }
});

onBeforeUnmount(() => {
    clearInterval(clock);
    clearInterval(poller);
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    echo()?.leave(channel);
});

function stop() {
    if (watchId !== null) navigator.geolocation.clearWatch(watchId);
    router.post(route('location.stop', props.session.ulid), { back: props.back });
}

const mapsHref = computed(() => {
    if (props.role === 'sharer' && props.session.sharer_side === 'customer') return props.lot.directions;
    return point.value ? `https://www.google.com/maps/search/?api=1&query=${point.value.lat},${point.value.lng}` : null;
});
</script>

<template>
    <Head title="Live location" />
    <div class="relative flex h-dvh flex-col overflow-hidden bg-map">
        <div class="relative grow">
            <LiveMap :person="point" :lot="lotPoint" :person-label="session.sharer" :lot-label="lot.name" />

            <div class="absolute inset-x-4 top-4 flex items-center gap-2.5 rounded-2xl bg-forest px-3.5 py-3 text-white shadow-lg" role="status">
                <span class="h-2.5 w-2.5 shrink-0 rounded-full" :class="live ? 'bg-[#4ADE80]' : 'bg-mist/50'" />
                <span class="grow text-[14px]">
                    <template v-if="role === 'sharer'"><strong>{{ live ? 'Sharing live location' : 'Not sharing' }}</strong> with {{ session.sharer_side === 'customer' ? lot.name : 'the buyer' }}</template>
                    <template v-else><strong>{{ session.sharer }}</strong> {{ live ? 'is sharing live location' : 'stopped sharing' }}</template>
                </span>
                <span v-if="live" class="shrink-0 text-[13px] text-mist">{{ minutesLeft }} min left</span>
            </div>
        </div>

        <section class="relative -mt-6 flex flex-col gap-3.5 rounded-t-3xl bg-white px-5 pt-5 pb-7 md:mx-auto md:w-full md:max-w-lg">
            <div class="flex items-baseline justify-between gap-3">
                <h1 class="font-display text-[24px] font-bold">{{ heading }}</h1>
                <span v-if="secondsAgo !== null && live" class="shrink-0 text-[13px] text-muted">{{ secondsAgo < 15 ? 'just now' : `${secondsAgo < 60 ? secondsAgo + ' s' : Math.round(secondsAgo / 60) + ' min'} ago` }}</span>
            </div>
            <p class="text-[14px] text-[#4A4D53]">
                <template v-if="role === 'sharer'">
                    {{ session.sharer_side === 'customer' ? lot.name : 'The buyer' }} can see you're on the way to the {{ appointment.what.toLowerCase() }} at {{ appointment.when }}.
                    Only people on this booking can see your location, and it stops when the time runs out. Keep this page open: phones pause sharing when locked.
                </template>
                <template v-else>{{ appointment.what }} at {{ appointment.when }}. The distance is in a straight line, so the drive may be longer.</template>
            </p>
            <p v-if="error" class="rounded-xl bg-[#FDECEC] px-3 py-2.5 text-[14px] text-danger" role="alert">{{ error }}</p>

            <div class="flex gap-2">
                <a v-if="mapsHref" :href="mapsHref" target="_blank" rel="noopener" class="btn btn-primary h-[50px] grow rounded-[14px]">Open in Google Maps</a>
                <a v-if="call" :href="`tel:${call.phone}`" class="btn btn-outline h-[50px] w-[50px] shrink-0 rounded-[14px] px-0" :aria-label="`${call.label}, ${call.display}`">
                    <Icon name="phone" :size="20" />
                </a>
            </div>
            <button v-if="role === 'sharer' && live" type="button" class="h-[46px] rounded-[14px] border border-line text-[15px] font-semibold text-danger" @click="stop">Stop sharing</button>
            <Link v-else :href="appointment.url" class="flex h-[46px] items-center justify-center rounded-[14px] border border-line text-[15px] font-semibold text-ink no-underline">Back to the booking</Link>
        </section>
    </div>
</template>
