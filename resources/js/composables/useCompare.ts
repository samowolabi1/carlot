import { computed, ref } from 'vue';

const KEY = 'lotlink:compare';
export const COMPARE_MAX = 3;

function read(): string[] {
    try {
        return JSON.parse(localStorage.getItem(KEY) ?? '[]') as string[];
    } catch {
        return [];
    }
}

const ids = ref<string[]>(typeof window !== 'undefined' ? read() : []);

function persist() {
    try {
        localStorage.setItem(KEY, JSON.stringify(ids.value));
    } catch {
        /* private mode */
    }
}

export function useCompare() {
    function has(ulid: string) {
        return ids.value.includes(ulid);
    }

    function toggle(ulid: string): boolean {
        if (has(ulid)) {
            ids.value = ids.value.filter((id) => id !== ulid);
        } else {
            if (ids.value.length >= COMPARE_MAX) return false;
            ids.value = [...ids.value, ulid];
        }
        persist();
        return true;
    }

    function clear() {
        ids.value = [];
        persist();
    }

    const href = computed(() => route('compare', { ids: ids.value.join(',') }));

    return { ids, has, toggle, clear, href };
}
