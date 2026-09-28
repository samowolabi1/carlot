<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { onMounted, ref, watch } from 'vue';

/* eslint-disable @typescript-eslint/no-explicit-any */
declare global {
    interface Window {
        google?: any;
        __lotlinkMapsReady?: () => void;
    }
}

export interface PlaceParts {
    address?: string;
    city?: string;
    state?: string;
}

const latitude = defineModel<number | null>('latitude', { required: true });
const longitude = defineModel<number | null>('longitude', { required: true });
const emit = defineEmits<{ place: [parts: PlaceParts] }>();

// Centre of Lagos until the owner drops a pin.
const FALLBACK = { lat: 6.5244, lng: 3.3792 };
const apiKey = import.meta.env.VITE_GOOGLE_MAPS_BROWSER_KEY;

const mapEl = ref<HTMLElement | null>(null);
const locating = ref(false);
const locateError = ref<string | null>(null);
const mapsFailed = ref(false);

let map: any = null;
let marker: any = null;
let geocoder: any = null;

function loadGoogleMaps(): Promise<void> {
    if (window.google?.maps) return Promise.resolve();

    return new Promise((resolve, reject) => {
        window.__lotlinkMapsReady = () => resolve();
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(apiKey!)}&callback=__lotlinkMapsReady&loading=async`;
        script.async = true;
        script.onerror = () => reject(new Error('Google Maps failed to load'));
        document.head.appendChild(script);
    });
}

function round(value: number) {
    return Math.round(value * 1e7) / 1e7;
}

function setPosition(lat: number, lng: number, reverseGeocode = true) {
    latitude.value = round(lat);
    longitude.value = round(lng);

    if (map && marker) {
        marker.setPosition({ lat, lng });
        map.panTo({ lat, lng });
        if (map.getZoom() < 16) map.setZoom(17);
    }

    if (reverseGeocode && geocoder) {
        geocoder.geocode({ location: { lat, lng } }, (results: any[] | null, status: string) => {
            if (status !== 'OK' || !results?.length) return;
            const parts = results[0].address_components as { long_name: string; types: string[] }[];
            const find = (type: string) => parts.find((p) => p.types.includes(type))?.long_name;
            emit('place', {
                address: [find('street_number'), find('route')].filter(Boolean).join(' ') || undefined,
                city: find('sublocality') ?? find('locality') ?? find('administrative_area_level_2'),
                state: find('administrative_area_level_1')?.replace(/ State$/, ''),
            });
        });
    }
}

function useCurrentLocation() {
    locateError.value = null;

    if (!('geolocation' in navigator)) {
        locateError.value = 'This browser cannot share your location. Drag the pin instead.';
        return;
    }

    locating.value = true;
    navigator.geolocation.getCurrentPosition(
        (pos) => {
            locating.value = false;
            setPosition(pos.coords.latitude, pos.coords.longitude);
        },
        (err) => {
            locating.value = false;
            locateError.value =
                err.code === err.PERMISSION_DENIED
                    ? 'Location permission was blocked. Allow it in your browser settings, or drag the pin.'
                    : 'We could not get your location. Try again outside, or drag the pin.';
        },
        { enableHighAccuracy: true, timeout: 15000, maximumAge: 0 },
    );
}

onMounted(async () => {
    if (!apiKey || !mapEl.value) return;

    try {
        await loadGoogleMaps();
    } catch {
        mapsFailed.value = true;
        return;
    }

    const g = window.google.maps;
    const start = latitude.value !== null && longitude.value !== null ? { lat: latitude.value, lng: longitude.value } : FALLBACK;

    map = new g.Map(mapEl.value, {
        center: start,
        zoom: latitude.value !== null ? 17 : 12,
        mapTypeControl: false,
        streetViewControl: false,
        fullscreenControl: false,
        clickableIcons: false,
    });
    marker = new g.Marker({ map, position: start, draggable: true, title: 'Your lot' });
    geocoder = new g.Geocoder();

    marker.addListener('dragend', () => {
        const p = marker.getPosition();
        setPosition(p.lat(), p.lng());
    });
    map.addListener('click', (e: any) => setPosition(e.latLng.lat(), e.latLng.lng()));
});

watch([latitude, longitude], ([lat, lng]) => {
    if (marker && lat !== null && lng !== null) marker.setPosition({ lat, lng });
});
</script>

<template>
    <div class="flex flex-col gap-2">
        <div class="relative h-[320px] overflow-hidden rounded-2xl border border-[#D5E0DB] bg-map">
            <div v-if="apiKey && !mapsFailed" ref="mapEl" class="absolute inset-0" />

            <!-- Without a Maps key: the stylised map from the designs, with GPS and manual entry. -->
            <template v-else>
                <div class="absolute inset-x-0 top-[150px] h-3.5 bg-white" />
                <div class="absolute inset-x-0 top-[260px] h-2.5 bg-white" />
                <div class="absolute inset-y-0 left-[30%] w-3.5 bg-white" />
                <div class="absolute inset-y-0 left-[65%] w-2.5 bg-white" />
                <div class="absolute top-1/2 left-1/2 flex -translate-x-1/2 -translate-y-full flex-col items-center gap-2">
                    <span v-if="latitude !== null" class="rounded-lg bg-ink px-2.5 py-1.5 text-[12px] font-semibold whitespace-nowrap text-white">
                        {{ latitude.toFixed(5) }}, {{ longitude?.toFixed(5) }}
                    </span>
                    <span v-else class="rounded-lg bg-ink px-2.5 py-1.5 text-[12px] font-semibold whitespace-nowrap text-white">No pin yet</span>
                    <span class="h-10 w-10 -rotate-45 rounded-[20px_20px_20px_4px]" :class="latitude !== null ? 'bg-clay' : 'bg-stone'" />
                </div>
            </template>

            <button
                type="button"
                class="btn btn-dark absolute top-3.5 right-3.5 h-[42px] px-3.5 text-[14px] shadow"
                :disabled="locating"
                @click="useCurrentLocation"
            >
                <Icon name="locate" :size="18" />
                {{ locating ? 'Finding you…' : 'Use my current location' }}
            </button>
        </div>

        <p v-if="locateError" class="text-[13px] font-medium text-danger" role="alert">{{ locateError }}</p>
        <p v-else-if="apiKey && !mapsFailed" class="text-[13px] text-muted">Tap the map or drag the pin to the lot gate.</p>

        <details v-if="!apiKey || mapsFailed" class="text-[13px] text-muted">
            <summary class="cursor-pointer font-semibold text-forest">Enter coordinates by hand</summary>
            <div class="mt-2 grid grid-cols-2 gap-3">
                <label class="field-label">
                    Latitude
                    <input v-model.number="latitude" type="number" step="0.0000001" min="-90" max="90" class="field" inputmode="decimal" />
                </label>
                <label class="field-label">
                    Longitude
                    <input v-model.number="longitude" type="number" step="0.0000001" min="-180" max="180" class="field" inputmode="decimal" />
                </label>
            </div>
            <p class="mt-2">Tip: in Google Maps, press and hold on your gate, then copy the numbers shown.</p>
        </details>
    </div>
</template>
