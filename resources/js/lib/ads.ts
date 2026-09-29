import { xsrfToken } from '@/lib/http';

export interface AdBanner {
    ulid: string;
    placement: 'home_banner' | 'search_banner';
    headline: string;
    subtext: string | null;
    cta: string;
    image: string | null;
    lot: string;
    logo: string | null;
    url: string;
}

const sent = new Set<string>();

/** Counts a banner view once per page (the server also dedupes per visit and ignores bots and the lot's staff). */
export function markSeen(ad: AdBanner): void {
    if (sent.has(ad.ulid)) return;
    sent.add(ad.ulid);
    fetch(route('ads.seen', ad.ulid), {
        method: 'POST',
        credentials: 'same-origin',
        keepalive: true,
        headers: { 'X-XSRF-TOKEN': xsrfToken(), 'X-Requested-With': 'XMLHttpRequest', Accept: 'application/json' },
    }).catch(() => undefined);
}

/** Calls back once the element is at least half on screen. */
export function whenVisible(el: Element, callback: () => void): () => void {
    if (!('IntersectionObserver' in window)) {
        callback();
        return () => undefined;
    }
    const observer = new IntersectionObserver(
        (entries) => {
            if (entries.some((e) => e.isIntersecting)) {
                callback();
                observer.disconnect();
            }
        },
        { threshold: 0.5 },
    );
    observer.observe(el);
    return () => observer.disconnect();
}
