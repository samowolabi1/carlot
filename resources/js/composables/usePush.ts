import { json } from '@/lib/http';
import { onMounted, ref } from 'vue';

/**
 * Push notifications on this device (Web Push). Needs the service worker, so HTTPS (or
 * localhost). iPhones only allow it once LotLink is added to the Home Screen (iOS 16.4+).
 */
export type PushState = 'checking' | 'not-configured' | 'unsupported' | 'needs-install' | 'denied' | 'off' | 'on';

function supported(): boolean {
    return typeof window !== 'undefined' && window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window;
}

function isIosBrowser(): boolean {
    const standalone = window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
    return /iPhone|iPad|iPod/.test(navigator.userAgent) && !standalone;
}

function keyBytes(base64url: string): Uint8Array<ArrayBuffer> {
    const base64 = (base64url + '='.repeat((4 - (base64url.length % 4)) % 4)).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const bytes = new Uint8Array(new ArrayBuffer(raw.length));
    for (let i = 0; i < raw.length; i++) bytes[i] = raw.charCodeAt(i);
    return bytes;
}

async function registration(): Promise<ServiceWorkerRegistration | undefined> {
    return (await navigator.serviceWorker.getRegistration()) ?? navigator.serviceWorker.register('/sw.js');
}

/** The browser's push service can be unreachable (blocked network, no Google services): don't spin forever. */
function withTimeout<T>(promise: Promise<T>, ms = 20000): Promise<T> {
    return Promise.race([promise, new Promise<T>((_, reject) => setTimeout(() => reject(new Error('timeout')), ms))]);
}

async function save(subscription: PushSubscription) {
    await json('POST', route('push.store'), subscription.toJSON());
}

export function usePush(key: string | null) {
    const state = ref<PushState>('checking');
    const busy = ref(false);
    const error = ref<string | null>(null);

    async function refresh() {
        if (!key) {
            state.value = 'not-configured';
            return;
        }
        if (!supported()) {
            state.value = isIosBrowser() ? 'needs-install' : 'unsupported';
            return;
        }
        if (Notification.permission === 'denied') {
            state.value = 'denied';
            return;
        }
        const reg = await navigator.serviceWorker.getRegistration();
        const existing = await reg?.pushManager.getSubscription();
        if (existing && Notification.permission === 'granted') {
            // Re-send it: keys rotate, and the server may have dropped it (signed out elsewhere).
            await save(existing).catch(() => undefined);
            state.value = 'on';
        } else {
            state.value = 'off';
        }
    }

    async function enable() {
        if (!key) return;
        busy.value = true;
        error.value = null;
        try {
            const permission = await Notification.requestPermission();
            if (permission !== 'granted') {
                state.value = permission === 'denied' ? 'denied' : 'off';
                return;
            }
            const reg = await registration();
            if (!reg) throw new Error('no service worker');
            await navigator.serviceWorker.ready;
            const subscription =
                (await reg.pushManager.getSubscription()) ??
                (await withTimeout(reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: keyBytes(key) })));
            await save(subscription);
            state.value = 'on';
        } catch (e) {
            error.value =
                e instanceof Error && e.message === 'timeout'
                    ? "This browser couldn't reach its push service. Check your connection and try again, or try another browser."
                    : "Couldn't turn on notifications on this device. Try again, or check the browser's site settings.";
        } finally {
            busy.value = false;
        }
    }

    async function disable() {
        busy.value = true;
        error.value = null;
        try {
            const reg = await navigator.serviceWorker.getRegistration();
            const subscription = await reg?.pushManager.getSubscription();
            if (subscription) {
                await json('DELETE', route('push.destroy'), { endpoint: subscription.endpoint });
                await subscription.unsubscribe();
            }
            state.value = 'off';
        } catch {
            error.value = "Couldn't turn them off. Try again.";
        } finally {
            busy.value = false;
        }
    }

    onMounted(refresh);

    return { state, busy, error, enable, disable, refresh };
}
