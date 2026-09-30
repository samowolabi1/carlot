import { ref, watch, type Ref } from 'vue';

export interface Suggestion {
    label: string;
    url: string;
    count?: number;
    detail?: string;
    image?: string | null;
}

export interface Suggestions {
    query: string;
    makes: Suggestion[];
    models: Suggestion[];
    places: Suggestion[];
    lots: Suggestion[];
    cars: Suggestion[];
}

export const MIN_LENGTH = 2;
const DELAY_MS = 120;

// Shared across search boxes for the visit: typing back to an earlier query answers instantly.
const cache = new Map<string, Suggestions>();

/**
 * Suggestions for what's in the search box, fetched as the person types: a short pause so every
 * keystroke doesn't hit the server, older requests cancelled when a newer one starts, and answers
 * remembered so backspacing is instant.
 */
export function useSuggestions(query: Ref<string>, withCars = true) {
    const results = ref<Suggestions | null>(null);
    const loading = ref(false);
    let timer: ReturnType<typeof setTimeout> | undefined;
    let controller: AbortController | null = null;

    async function load(q: string) {
        const key = `${withCars ? 'c' : 'n'}:${q}`;
        const hit = cache.get(key);
        if (hit) {
            results.value = hit;
            loading.value = false;
            return;
        }

        controller?.abort();
        controller = new AbortController();
        loading.value = true;
        try {
            const url = route('search.suggest', { q, cars: withCars ? 1 : 0 });
            const response = await fetch(url, { headers: { Accept: 'application/json' }, signal: controller.signal });
            if (!response.ok) throw new Error(String(response.status));
            const data = (await response.json()) as Suggestions;
            cache.set(key, data);
            // Only show it if it's still what's in the box.
            if (query.value.trim().toLowerCase().replace(/\s+/g, ' ') === q) results.value = data;
        } catch (e) {
            if ((e as Error).name !== 'AbortError') results.value = null;
        } finally {
            loading.value = false;
        }
    }

    watch(query, (value) => {
        clearTimeout(timer);
        const q = value.trim().toLowerCase().replace(/\s+/g, ' ');
        if (q.length < MIN_LENGTH) {
            controller?.abort();
            results.value = null;
            loading.value = false;
            return;
        }
        if (cache.has(`${withCars ? 'c' : 'n'}:${q}`)) return void load(q);
        loading.value = true;
        timer = setTimeout(() => load(q), DELAY_MS);
    });

    return { results, loading };
}
