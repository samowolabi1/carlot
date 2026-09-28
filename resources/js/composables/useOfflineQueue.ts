import { json } from '@/lib/http';
import { offlineStore, uuid, type OfflineItem, type OfflineType } from '@/lib/offlineStore';
import { router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

// Shared across pages so the layout banner and the forms see the same queue.
const items = ref<OfflineItem[]>([]);
const syncing = ref(false);
let listening = false;

async function load() {
    try {
        items.value = await offlineStore.all();
    } catch {
        items.value = [];
    }
}

interface SyncResult {
    client_uuid: string;
    status: 'ok' | 'failed';
    message?: string;
}

/**
 * Walk-ins, orders and payments saved while the network is down wait here and are
 * replayed to /dealer/{lot}/manager/sync when it returns. The server upserts on
 * client_uuid, so a retry never makes a duplicate.
 */
export function useOfflineQueue(lotSlug: () => string | undefined) {
    const pending = computed(() => items.value.filter((i) => i.lot === lotSlug()));
    const waiting = computed(() => pending.value.filter((i) => !i.error).length);
    const failed = computed(() => pending.value.filter((i) => i.error));

    async function add(type: OfflineType, data: Record<string, unknown>, label: string, clientUuid = uuid()) {
        const lot = lotSlug();
        if (!lot) return;
        await offlineStore.put({ client_uuid: clientUuid, lot, type, data: JSON.parse(JSON.stringify(data)), label, created_at: new Date().toISOString() });
        await load();
    }

    async function sync() {
        const lot = lotSlug();
        const batch = pending.value.filter((i) => !i.error);
        if (!lot || syncing.value || batch.length === 0 || !navigator.onLine) return;

        syncing.value = true;
        try {
            const { results } = await json<{ results: SyncResult[] }>('POST', route('dealer.manager.sync', lot), {
                items: batch.map(({ type, client_uuid, data }) => ({ type, client_uuid, data })),
            });
            for (const result of results) {
                const item = batch.find((i) => i.client_uuid === result.client_uuid);
                if (!item) continue;
                if (result.status === 'ok') {
                    await offlineStore.remove(item.client_uuid);
                } else {
                    await offlineStore.put({ ...item, error: result.message ?? 'Could not save.' });
                }
            }
            await load();
            router.reload();
        } catch {
            // Still offline or the server is unreachable: keep everything for next time.
        } finally {
            syncing.value = false;
        }
    }

    async function discard(item: OfflineItem) {
        await offlineStore.remove(item.client_uuid);
        await load();
    }

    if (typeof window !== 'undefined' && !listening) {
        listening = true;
        window.addEventListener('online', () => void sync());
        void load().then(() => sync());
    }

    return { pending, waiting, failed, syncing, add, sync, discard };
}

/**
 * Posts an Inertia form with a client_uuid; if the request never reaches the server
 * (no signal), the same payload goes into the offline queue instead.
 */
export function submitOrQueue(options: {
    queue: ReturnType<typeof useOfflineQueue>;
    type: OfflineType;
    label: string;
    data: Record<string, unknown>;
    post: (clientUuid: string, handlers: { onSuccess: () => void; onError: () => void; onFinish: () => void }) => void;
    onQueued: () => void;
    onSuccess?: () => void;
}) {
    const clientUuid = uuid();

    if (!navigator.onLine) {
        void options.queue.add(options.type, options.data, options.label, clientUuid).then(options.onQueued);
        return;
    }

    let answered = false;
    options.post(clientUuid, {
        onSuccess: () => {
            answered = true;
            options.onSuccess?.();
        },
        onError: () => {
            answered = true;
        },
        onFinish: () => {
            if (!answered) {
                void options.queue.add(options.type, options.data, options.label, clientUuid).then(options.onQueued);
            }
        },
    });
}
