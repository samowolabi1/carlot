import type { SharedProps } from '@/types';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export function useShared() {
    const page = usePage<SharedProps>();

    return {
        user: computed(() => page.props.auth.user),
        lots: computed(() => page.props.lots),
        currentLot: computed(() => page.props.currentLot),
        flash: computed(() => page.props.flash),
    };
}
