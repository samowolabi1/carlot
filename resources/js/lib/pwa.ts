import { router } from '@inertiajs/vue3';

/**
 * Installable app (TDD: PWA). The service worker needs HTTPS, or localhost while
 * developing; on plain http://carlot.test the browser skips it and the site still works.
 */
export function registerServiceWorker() {
    if (!('serviceWorker' in navigator) || !import.meta.env.PROD) return;

    window.addEventListener('load', () => {
        navigator.serviceWorker.register('/sw.js').catch(() => {
            // Not fatal: the site works without it.
        });
    });

    // Signed out: drop any saved dealer pages from this phone.
    router.on('navigate', (event) => {
        const auth = (event.detail.page.props as { auth?: { user: unknown } }).auth;
        if (auth && auth.user === null) navigator.serviceWorker.controller?.postMessage('clear-private');
    });
}
