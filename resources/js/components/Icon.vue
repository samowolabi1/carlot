<script setup lang="ts">
import { computed } from 'vue';

// Stroke icons drawn for the LotLink designs (24×24 grid).
const paths = {
    home: 'M4 11l8-7 8 7v9h-5v-6H9v6H4z',
    search: 'M11 4a7 7 0 1 1 0 14a7 7 0 0 1 0-14M20 20l-4-4',
    heart: 'M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z',
    calendar: 'M4 5h16v15H4zM4 10h16M9 3v4M15 3v4',
    user: 'M12 4a4 4 0 1 1 0 8a4 4 0 0 1 0-8M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6',
    pin: 'M12 21s-7-6.2-7-11a7 7 0 0 1 14 0c0 4.8-7 11-7 11zM12 7.5a2.5 2.5 0 1 1 0 5a2.5 2.5 0 0 1 0-5',
    bell: 'M6 16V11a6 6 0 0 1 12 0v5l2 2H4zM10 21h4',
    filters: 'M4 6h16M7 12h10M10 18h4',
    grid: 'M4 4h7v7H4zM13 4h7v7h-7zM4 13h7v7H4zM13 13h7v7h-7z',
    car: 'M3 16v-4l2-5h14l2 5v4zM6 16a1.5 1.5 0 0 0 3 0M15 16a1.5 1.5 0 0 0 3 0',
    leads: 'M9 4.5a3.5 3.5 0 1 1 0 7a3.5 3.5 0 0 1 0-7M3 20c1-3.5 3.3-5 6-5s5 1.5 6 5M16 5a3 3 0 0 1 0 6M18 15c1.6.7 2.6 2.3 3 5',
    tag: 'M3 12V4h8l10 10-8 8zM7.5 7a1.5 1.5 0 1 0 0 3a1.5 1.5 0 0 0 0-3',
    chart: 'M4 20V10M10 20V4M16 20v-7M22 20H2',
    qr: 'M4 4h6v6H4zM14 4h6v6h-6zM4 14h6v6H4zM14 14h2v2h-2zM18 18h2v2h-2z',
    card: 'M3 6h18v13H3zM3 10h18',
    settings: 'M12 9a3 3 0 1 1 0 6a3 3 0 0 1 0-6M12 2v3M12 19v3M2 12h3M19 12h3M4.9 4.9l2.1 2.1M17 17l2.1 2.1M4.9 19.1L7 17M17 7l2.1-2.1',
    check: 'M5 12l5 5L20 7',
    chevronDown: 'M6 9l6 6 6-6',
    chevronLeft: 'M15 6l-6 6 6 6',
    plus: 'M12 5v14M5 12h14',
    close: 'M6 6l12 12M18 6L6 18',
    logout: 'M15 4h4v16h-4M10 8l-4 4 4 4M6 12h10',
    locate: 'M12 8a4 4 0 1 1 0 8a4 4 0 0 1 0-8M12 2v3M12 19v3M2 12h3M19 12h3',
    upload: 'M12 16V4M7 9l5-5 5 5M4 20h16',
    whatsapp: 'M4 20l1.3-4A8 8 0 1 1 8 18.7zM9 9c0 3 3 6 6 6l1.5-1.5-2-1-1 1c-1-.5-2.5-2-3-3l1-1-1-2z',
    phone: 'M6 3h3l2 5-2.5 1.5a11 11 0 0 0 5 5L15 12l5 2v3a2 2 0 0 1-2 2A15 15 0 0 1 4 5a2 2 0 0 1 2-2z',
    share: 'M18 2.5a2.5 2.5 0 1 1 0 5a2.5 2.5 0 0 1 0-5M6 9.5a2.5 2.5 0 1 1 0 5a2.5 2.5 0 0 1 0-5M18 16.5a2.5 2.5 0 1 1 0 5a2.5 2.5 0 0 1 0-5M8.2 10.8l7.6-4.4M8.2 13.2l7.6 4.4',
    navigate: 'M12 3l8 18-8-4-8 4z',
    shield: 'M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6zM9 12l2 2 4-4',
    copy: 'M9 9h11v11H9zM5 15H4V4h11v1',
    clock: 'M12 3a9 9 0 1 1 0 18a9 9 0 0 1 0-18M12 7v5l3 2',
    sun: 'M12 8a4 4 0 1 1 0 8a4 4 0 0 1 0-8M12 2v2M12 20v2M2 12h2M20 12h2M5 5l1.5 1.5M17.5 17.5L19 19M5 19l1.5-1.5M17.5 6.5L19 5',
    walkIn: 'M13 4a1.5 1.5 0 1 1 0 3a1.5 1.5 0 0 1 0-3M10 21l2-6 3 3v3M9 12l2-3.5 3 1 2 3M12 8.5L10 15',
    book: 'M5 4h11a2 2 0 0 1 2 2v14H7a2 2 0 0 1-2-2zM5 18a2 2 0 0 1 2-2h11M9 8h5',
    receipt: 'M6 3h12v18l-3-2-3 2-3-2-3 2zM9 8h6M9 12h6M9 16h3',
    cloudOff: 'M3 3l18 18M7 18h9M18.5 15.5A4 4 0 0 0 17 8h-1A6 6 0 0 0 8.6 6.1M5.5 8.6A5 5 0 0 0 7 18',
    refresh: 'M20 11a8 8 0 0 0-14.7-4M4 5v4h4M4 13a8 8 0 0 0 14.7 4M20 19v-4h-4',
    download: 'M12 4v12M7 11l5 5 5-5M4 20h16',
    chat: 'M4 5h16v11H9l-5 4zM8 9h8M8 12h5',
    swap: 'M4 7h13l-3-3M20 17H7l3 3',
    alert: 'M12 3l10 18H2zM12 10v4M12 17.5v.5',
    file: 'M6 3h8l4 4v14H6zM14 3v4h4M9 13h6M9 17h6',
    image: 'M4 5h16v14H4zM4 16l5-5 4 4 3-3 4 4M15 8.5a1 1 0 1 1 0 2a1 1 0 0 1 0-2',
    camera: 'M4 8h4l2-3h4l2 3h4v11H4zM12 10a3.5 3.5 0 1 1 0 7a3.5 3.5 0 0 1 0-7',
    flag: 'M5 21V4M5 4h11l-2 4 2 4H5',
    star: 'M12 3l2.7 5.6 6.1.9-4.4 4.3 1 6.1L12 17l-5.4 2.9 1-6.1-4.4-4.3 6.1-.9z',
    clipboard: 'M8 4h8v3H8zM6 5.5H5v15.5h14V5.5h-1M9 12l2 2 4-4M9 17h6',
    chevronRight: 'M9 6l6 6-6 6',
    chevronUp: 'M6 15l6-6 6 6',
    zoomIn: 'M11 4a7 7 0 1 1 0 14a7 7 0 0 1 0-14M20 20l-4-4M8 11h6M11 8v6',
    zoomOut: 'M11 4a7 7 0 1 1 0 14a7 7 0 0 1 0-14M20 20l-4-4M8 11h6',
    expand: 'M4 9V4h5M20 9V4h-5M4 15v5h5M20 15v5h-5',
    rotateLeft: 'M4 5v5h5M4.5 10A8 8 0 1 1 6 17',
    rotateRight: 'M20 5v5h-5M19.5 10A8 8 0 1 0 18 17',
    trash: 'M4 7h16M9 7V4h6v3M6 7l1 13h10l1-13M10 11v6M14 11v6',
    move: 'M12 3v18M3 12h18M9 6l3-3 3 3M9 18l3 3 3-3M6 9l-3 3 3 3M18 9l3 3-3 3',
    lifebuoy: 'M12 3a9 9 0 1 1 0 18a9 9 0 0 1 0-18M12 8a4 4 0 1 1 0 8a4 4 0 0 1 0-8M5.6 5.6l3.6 3.6M14.8 14.8l3.6 3.6M18.4 5.6l-3.6 3.6M9.2 14.8l-3.6 3.6',
    paperclip: 'M20 11.5l-8.2 8.2a5 5 0 0 1-7.1-7.1l8.5-8.5a3.3 3.3 0 0 1 4.7 4.7l-8.5 8.5a1.7 1.7 0 0 1-2.4-2.4l7.8-7.8',
    send: 'M4 12l16-8-6 16-3-7zM11 13l9-9',
} as const;

export type IconName = keyof typeof paths;

const props = withDefaults(defineProps<{ name: IconName; size?: number; strokeWidth?: number }>(), { size: 20, strokeWidth: 1.8 });

const d = computed(() => paths[props.name]);
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 24 24"
        aria-hidden="true"
        fill="none"
        stroke="currentColor"
        :stroke-width="strokeWidth"
        stroke-linecap="round"
        stroke-linejoin="round"
    >
        <path :d="d" />
    </svg>
</template>
