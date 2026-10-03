<script setup lang="ts">
import Icon from '@/components/Icon.vue';
import { useShared } from '@/composables/useShared';
import type { SharedProps } from '@/types';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref } from 'vue';

/**
 * "Dashboard" in the marketplace header for anyone who has one: their seller(s), the lender portal(s) they work in, and
 * the admin panel for CarYard staff. One place goes straight there; several open a short menu.
 */
const page = usePage<SharedProps>();
const { user, lots } = useShared();

type Destination = { key: string; label: string; detail: string; href: string; external?: boolean };

const destinations = computed<Destination[]>(() => {
    const list: Destination[] = [];
    for (const lot of lots.value ?? []) {
        list.push({ key: `lot-${lot.slug}`, label: 'Seller dashboard', detail: lot.name, href: route('dealer.dashboard', lot.slug) });
    }
    if (!(lots.value ?? []).length && user.value?.role === 'staff') {
        list.push({ key: 'dealer', label: 'Seller dashboard', detail: 'Set up your business', href: route('dealer.home') });
    }
    for (const lender of page.props.lenders ?? []) {
        list.push({ key: `lender-${lender.slug}`, label: 'Lender portal', detail: lender.name, href: route('lender.dashboard', lender.slug) });
    }
    if (user.value?.role === 'admin') {
        // The admin panel is a separate app (Filament), so a full page load.
        list.push({ key: 'admin', label: 'Admin panel', detail: 'CarYard staff', href: '/admin', external: true });
    }
    return list;
});

const open = ref(false);
const root = ref<HTMLElement | null>(null);
function outside(e: MouseEvent) {
    if (root.value && !root.value.contains(e.target as Node)) open.value = false;
}
function toggle() {
    open.value = !open.value;
    if (open.value) document.addEventListener('click', outside);
    else document.removeEventListener('click', outside);
}
onBeforeUnmount(() => document.removeEventListener('click', outside));

const only = computed(() => (destinations.value.length === 1 ? destinations.value[0] : null));
const buttonClass = 'flex h-11 items-center gap-2 rounded-full border border-forest bg-forest px-3 text-[14px] font-semibold text-white no-underline hover:bg-forest-700 sm:px-4';
</script>

<template>
    <template v-if="destinations.length">
        <a v-if="only?.external" :href="only.href" :class="buttonClass" :aria-label="only.label">
            <Icon name="grid" :size="18" /><span class="hidden sm:inline">{{ only.label }}</span>
        </a>
        <Link v-else-if="only" :href="only.href" :class="buttonClass" :aria-label="`${only.label}, ${only.detail}`">
            <Icon name="grid" :size="18" /><span class="hidden sm:inline">{{ only.label === 'Seller dashboard' ? 'Dashboard' : only.label }}</span>
        </Link>
        <div v-else ref="root" class="relative">
            <button type="button" :class="buttonClass" aria-haspopup="menu" :aria-expanded="open" aria-label="Your dashboards" @click.stop="toggle">
                <Icon name="grid" :size="18" /><span class="hidden sm:inline">Dashboards</span><Icon name="chevronDown" :size="16" class="hidden sm:block" />
            </button>
            <div v-if="open" role="menu" class="absolute top-12 right-0 z-50 w-64 rounded-2xl border border-line bg-white p-1.5 shadow-lg">
                <template v-for="d in destinations" :key="d.key">
                    <a v-if="d.external" :href="d.href" role="menuitem" class="flex min-h-11 flex-col justify-center rounded-xl px-3 py-2 text-ink no-underline hover:bg-ivory">
                        <span class="text-[14px] font-semibold">{{ d.label }}</span><span class="text-[12px] text-muted">{{ d.detail }}</span>
                    </a>
                    <Link v-else :href="d.href" role="menuitem" class="flex min-h-11 flex-col justify-center rounded-xl px-3 py-2 text-ink no-underline hover:bg-ivory" @click="open = false">
                        <span class="text-[14px] font-semibold">{{ d.label }}</span><span class="truncate text-[12px] text-muted">{{ d.detail }}</span>
                    </Link>
                </template>
            </div>
        </div>
    </template>
</template>
