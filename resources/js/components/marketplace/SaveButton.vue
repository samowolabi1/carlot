<script setup lang="ts">
import { useShared } from '@/composables/useShared';
import { Link, router } from '@inertiajs/vue3';
import { ref, watch } from 'vue';

const props = withDefaults(defineProps<{ ulid: string; saved: boolean; size?: 'sm' | 'md' }>(), { size: 'md' });
const { user } = useShared();
const saved = ref(props.saved);
watch(() => props.saved, (v) => (saved.value = v));

function toggle() {
    const was = saved.value;
    saved.value = !was;
    const options = { preserveScroll: true, preserveState: true, onError: () => (saved.value = was) };
    if (was) router.delete(route('favourites.destroy', props.ulid), options);
    else router.post(route('favourites.store', props.ulid), {}, options);
}
</script>

<template>
    <button
        v-if="user"
        type="button"
        class="flex items-center justify-center rounded-full bg-white shadow-sm"
        :class="size === 'sm' ? 'h-10 w-10' : 'h-11 w-11'"
        :aria-label="saved ? 'Remove from saved' : 'Save car'"
        :aria-pressed="saved"
        @click.prevent.stop="toggle"
    >
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" :fill="saved ? '#C2410C' : 'none'" :stroke="saved ? '#C2410C' : '#16181D'" stroke-width="1.8" stroke-linejoin="round">
            <path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" />
        </svg>
    </button>
    <!-- Guests sign in first; the save happens when they come back (TDD M4). -->
    <Link
        v-else
        :href="route('favourites.remember', ulid)"
        class="flex items-center justify-center rounded-full bg-white shadow-sm"
        :class="size === 'sm' ? 'h-10 w-10' : 'h-11 w-11'"
        aria-label="Sign in to save this car"
        @click.stop
    >
        <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" fill="none" stroke="#16181D" stroke-width="1.8" stroke-linejoin="round">
            <path d="M12 20s-7-4.4-7-10a4 4 0 0 1 7-2.6A4 4 0 0 1 19 10c0 5.6-7 10-7 10z" />
        </svg>
    </Link>
</template>
