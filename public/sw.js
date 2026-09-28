/*
 * LotLink service worker (TDD: PWA offline shell).
 *
 * - Built assets (/build/assets/*) are cached on first use; their names change with every
 *   build, so a cached copy is never stale.
 * - Pages are network-first. Lot Manager pages that loaded once are kept, so a dealer
 *   can reopen them with no signal and keep recording walk-ins and payments (the offline
 *   queue in IndexedDB sends them later). Anything else falls back to /offline.html.
 * - Nothing that changes data (POST, PUT, ...) and no JSON is ever cached.
 */
const VERSION = 'v1';
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

// Signing out clears saved dealer pages, which hold customer details.
self.addEventListener('message', (event) => {
    if (event.data === 'clear-private') {
        event.waitUntil(caches.delete(PAGES));
    }
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
