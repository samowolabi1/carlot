/*
 * CarYard service worker (TDD: PWA offline shell).
 *
 * - Built assets (/build/assets/*) are cached on first use; their names change with every
 *   build, so a cached copy is never stale.
 * - Pages are network-first. Sales Manager pages that loaded once are kept, so a seller
 *   can reopen them with no signal and keep recording walk-ins and payments (the offline
 *   queue in IndexedDB sends them later). Anything else falls back to /offline.html.
 * - Nothing that changes data (POST, PUT, ...) and no JSON is ever cached.
 * - Push notifications (Web Push): shows them, and a tap opens (or focuses) the page they're about.
 */
const VERSION = 'v2';
const SHELL = `lotlink-shell-${VERSION}`;
const ASSETS = `lotlink-assets-${VERSION}`;
const PAGES = `lotlink-pages-${VERSION}`;
const OFFLINE_URL = '/offline.html';

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches
            .open(SHELL)
            .then((cache) => cache.addAll([OFFLINE_URL, '/icons/icon-192.png', '/manifest.webmanifest']))
            .then(() => self.skipWaiting()),
    );
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches
            .keys()
            .then((keys) => Promise.all(keys.filter((key) => ![SHELL, ASSETS, PAGES].includes(key)).map((key) => caches.delete(key))))
            .then(() => self.clients.claim()),
    );
});

// Signing out clears saved seller pages, which hold customer details, and stops this device's pushes.
self.addEventListener('message', (event) => {
    if (event.data === 'clear-private') {
        event.waitUntil(
            Promise.all([
                caches.delete(PAGES),
                self.registration.pushManager.getSubscription().then((subscription) => subscription && subscription.unsubscribe()),
            ]),
        );
    }
});

self.addEventListener('push', (event) => {
    let data = {};
    try {
        data = event.data ? event.data.json() : {};
    } catch {
        data = { body: event.data ? event.data.text() : '' };
    }

    event.waitUntil(
        self.registration.showNotification(data.title || 'LotLink', {
            body: data.body || '',
            icon: '/icons/icon-192.png',
            badge: '/icons/badge-72.png',
            tag: data.tag || undefined,
            renotify: Boolean(data.tag),
            data: { url: data.url || '/' },
        }),
    );
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const target = new URL(event.notification.data?.url || '/', self.location.origin);
    if (target.origin !== self.location.origin) return;

    event.waitUntil(
        self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windows) => {
            const open = windows.find((client) => new URL(client.url).origin === self.location.origin);
            if (open) {
                return open.focus().then((client) => (client && 'navigate' in client ? client.navigate(target.href) : undefined));
            }
            return self.clients.openWindow(target.href);
        }),
    );
});

const isManagerPage = (url) => /^\/dealer\/[^/]+\/manager\//.test(url.pathname);

self.addEventListener('fetch', (event) => {
    const request = event.request;
    const url = new URL(request.url);

    if (request.method !== 'GET' || url.origin !== self.location.origin) {
        return;
    }

    if (url.pathname.startsWith('/build/assets/') || url.pathname.startsWith('/icons/')) {
        event.respondWith(
            caches.open(ASSETS).then(async (cache) => {
                const cached = await cache.match(request);
                if (cached) return cached;
                const response = await fetch(request);
                if (response.ok) cache.put(request, response.clone());
                return response;
            }),
        );
        return;
    }

    // Full page loads only; Inertia's JSON visits (X-Inertia) go straight to the network.
    if (request.mode === 'navigate') {
        event.respondWith(
            fetch(request)
                .then((response) => {
                    if (response.ok && isManagerPage(url)) {
                        const copy = response.clone();
                        caches.open(PAGES).then((cache) => cache.put(url.pathname, copy));
                    }
                    return response;
                })
                .catch(async () => (isManagerPage(url) && (await caches.match(url.pathname))) || caches.match(OFFLINE_URL)),
        );
    }
});
