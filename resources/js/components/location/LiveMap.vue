<script setup lang="ts">
/* eslint-disable @typescript-eslint/no-explicit-any */
import { loadGoogleMaps, mapsKey } from '@/lib/googleMaps';
import { computed, onMounted, ref, watch } from 'vue';

type Point = { lat: number; lng: number };

const props = defineProps<{ person: Point | null; lot: Point | null; personLabel: string; lotLabel: string }>();

const el = ref<HTMLElement | null>(null);
const google = ref(false);
let map: any = null;
let personMarker: any = null;
let lotMarker: any = null;

function fit() {
    if (!map) return;
    const g = window.google.maps;
    const bounds = new g.LatLngBounds();
    [props.person, props.lot].filter(Boolean).forEach((p) => bounds.extend(p));
    if (props.person && props.lot) map.fitBounds(bounds, 60);
    else if (props.person ?? props.lot) {
        map.setCenter(props.person ?? props.lot);
        map.setZoom(15);
    }
}

function draw() {
    if (!map) return;
    const g = window.google.maps;
    if (props.person) {
        personMarker ??= new g.Marker({ map, title: props.personLabel, icon: { path: g.SymbolPath.CIRCLE, scale: 9, fillColor: '#2563EB', fillOpacity: 1, strokeColor: '#fff', strokeWeight: 3 } });
        personMarker.setPosition(props.person);
    }
    if (props.lot && !lotMarker) {
        lotMarker = new g.Marker({ map, title: props.lotLabel, position: props.lot });
    }
    fit();
}

onMounted(async () => {
    if (!mapsKey || !el.value) return;
    try {
        await loadGoogleMaps();
        google.value = true;
        map = new window.google.maps.Map(el.value, { center: props.person ?? props.lot ?? { lat: 6.5244, lng: 3.3792 }, zoom: 14, disableDefaultUI: true, clickableIcons: false });
        draw();
    } catch {
        google.value = false;
    }
});

watch(() => props.person, draw);

// Without a Google Maps key: both points on a plain grid, the seller pin top-right.
const drawn = computed(() => {
    const pts = [props.person, props.lot].filter((p): p is Point => !!p);
    if (pts.length === 0) return null;
    const lats = pts.map((p) => p.lat);
    const lngs = pts.map((p) => p.lng);
    const pad = 0.004;
    const [minLat, maxLat] = [Math.min(...lats) - pad, Math.max(...lats) + pad];
    const [minLng, maxLng] = [Math.min(...lngs) - pad, Math.max(...lngs) + pad];
    const at = (p: Point) => ({ x: ((p.lng - minLng) / (maxLng - minLng)) * 100, y: (1 - (p.lat - minLat) / (maxLat - minLat)) * 100 });

    return { person: props.person ? at(props.person) : null, lot: props.lot ? at(props.lot) : null };
});
</script>

<template>
    <div class="relative h-full w-full overflow-hidden bg-map">
        <div v-show="google" ref="el" class="absolute inset-0" />
        <template v-if="!google">
            <span class="absolute inset-x-0 top-[24%] h-3 bg-white" aria-hidden="true" />
            <span class="absolute inset-x-0 top-[58%] h-3.5 bg-white" aria-hidden="true" />
            <span class="absolute inset-y-0 left-[23%] w-3 bg-white" aria-hidden="true" />
            <span class="absolute inset-y-0 left-[69%] w-3.5 bg-white" aria-hidden="true" />
            <svg v-if="drawn?.person && drawn.lot" class="absolute inset-[12%] h-[76%] w-[76%]" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                <line :x1="drawn.person.x" :y1="drawn.person.y" :x2="drawn.lot.x" :y2="drawn.lot.y" stroke="#2563EB" stroke-width="1.2" stroke-dasharray="3 2" vector-effect="non-scaling-stroke" />
            </svg>
            <div class="absolute inset-[12%]">
                <span
                    v-if="drawn?.lot"
                    class="absolute h-[34px] w-[34px] -translate-x-1/2 -translate-y-full -rotate-45 rounded-[17px_17px_17px_4px] bg-clay"
                    :style="{ left: `${drawn.lot.x}%`, top: `${drawn.lot.y}%` }"
                    :title="lotLabel"
                />
                <span
                    v-if="drawn?.person"
                    class="absolute h-5 w-5 -translate-x-1/2 -translate-y-1/2 rounded-full border-[3px] border-white bg-[#2563EB] shadow-[0_0_0_10px_rgba(37,99,235,.2)]"
                    :style="{ left: `${drawn.person.x}%`, top: `${drawn.person.y}%` }"
                    :title="personLabel"
                />
            </div>
        </template>
    </div>
</template>
