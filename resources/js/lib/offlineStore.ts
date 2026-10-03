// A tiny IndexedDB store for Sales Manager items saved without a connection
// (TDD M19: Offline mode). Each item keeps the client_uuid the server de-duplicates on.

export type OfflineType = 'walk_in' | 'order' | 'payment';

export interface OfflineItem {
    client_uuid: string;
    lot: string;
    type: OfflineType;
    data: Record<string, unknown>;
    label: string;
    created_at: string;
    error?: string;
}

const DB_NAME = 'lotlink-offline';
const STORE = 'queue';

function open(): Promise<IDBDatabase> {
    return new Promise((resolve, reject) => {
        const request = indexedDB.open(DB_NAME, 1);
        request.onupgradeneeded = () => request.result.createObjectStore(STORE, { keyPath: 'client_uuid' });
        request.onsuccess = () => resolve(request.result);
        request.onerror = () => reject(request.error);
    });
}

async function run<T>(mode: IDBTransactionMode, work: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
    const db = await open();

    return new Promise<T>((resolve, reject) => {
        const tx = db.transaction(STORE, mode);
        const request = work(tx.objectStore(STORE));
        tx.oncomplete = () => {
            db.close();
            resolve(request.result);
        };
        tx.onerror = () => reject(tx.error);
    });
}

export const offlineStore = {
    all: (): Promise<OfflineItem[]> => run('readonly', (s) => s.getAll() as IDBRequest<OfflineItem[]>),
    put: (item: OfflineItem): Promise<IDBValidKey> => run('readwrite', (s) => s.put(item)),
    remove: (uuid: string): Promise<undefined> => run('readwrite', (s) => s.delete(uuid)),
};

export function uuid(): string {
    // randomUUID needs a secure context (https or localhost); fall back to getRandomValues.
    if (typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }

    const b = crypto.getRandomValues(new Uint8Array(16));
    b[6] = (b[6] & 0x0f) | 0x40;
    b[8] = (b[8] & 0x3f) | 0x80;
    const h = Array.from(b, (x: number) => x.toString(16).padStart(2, '0')).join('');

    return `${h.slice(0, 8)}-${h.slice(8, 12)}-${h.slice(12, 16)}-${h.slice(16, 20)}-${h.slice(20)}`;
}
