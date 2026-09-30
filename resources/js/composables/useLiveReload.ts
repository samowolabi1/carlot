import { router } from '@inertiajs/vue3';
import { onBeforeUnmount, ref } from 'vue';

/**
 * Live filtering for a list page: reload only the props that change (the list, its counts, the filters)
 * with the page kept as it is, the URL kept in step, and a newer reload cancelling one still on its way.
 * `later()` waits for a short pause in typing; `now()` is for taps on tabs and selects.
 */
export function useLiveReload(url: () => string, only: string[], delay = 250) {
    const searching = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;

    function now(params: Record<string, unknown>) {
        clearTimeout(timer);
        router.get(url(), params as Record<string, string>, {
            only,
            preserveState: true,
            preserveScroll: true,
            replace: true,
            onStart: () => (searching.value = true),
            onFinish: () => (searching.value = false),
        });
    }

    function later(params: () => Record<string, unknown>) {
        clearTimeout(timer);
        timer = setTimeout(() => now(params()), delay);
    }

    onBeforeUnmount(() => clearTimeout(timer));

    return { searching, now, later };
}
