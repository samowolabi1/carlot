import { ref } from 'vue';

export interface BuyerLocation {
    lat: number;
    lng: number;
}

const KEY = 'lotlink:location';

function read(): BuyerLocation | null {
    try {
        const raw = localStorage.getItem(KEY);
        return raw ? (JSON.parse(raw) as BuyerLocation) : null;
    } catch {
        return null;
    }
}

// Shared across components. Kept only in this browser; the server sees it just for the
// request that sorts by distance, and never stores it (TDD: Privacy).
const location = ref<BuyerLocation | null>(typeof window !== 'undefined' ? read() : null);
const locating = ref(false);
const error = ref<string | null>(null);

export function useLocation() {
    function locate(): Promise<BuyerLocation | null> {
        error.value = null;

        if (!('geolocation' in navigator)) {
            error.value = 'Your browser cannot share your location.';
            return Promise.resolve(null);
        }

        locating.value = true;

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                (pos) => {
                    locating.value = false;
                    // ~100 m precision is plenty for "lots near me".
                    const value = { lat: Math.round(pos.coords.latitude * 1000) / 1000, lng: Math.round(pos.coords.longitude * 1000) / 1000 };
                    location.value = value;
                    try {
                        localStorage.setItem(KEY, JSON.stringify(value));
                    } catch {
                        /* private mode */
                    }
                    resolve(value);
                },
                (err) => {
                    locating.value = false;
                    error.value = err.code === err.PERMISSION_DENIED ? 'Location is blocked. Allow it in your browser settings to see cars near you.' : 'We could not find your location. Try again.';
                    resolve(null);
                },
                { enableHighAccuracy: false, timeout: 10000, maximumAge: 10 * 60 * 1000 },
            );
        });
    }

    function forget() {
        location.value = null;
        try {
            localStorage.removeItem(KEY);
        } catch {
            /* ignore */
        }
    }

    return { location, locating, error, locate, forget };
}

export function distanceKm(a: BuyerLocation, b: BuyerLocation): number {
    const rad = (d: number) => (d * Math.PI) / 180;
    const h = Math.sin(rad(b.lat - a.lat) / 2) ** 2 + Math.cos(rad(a.lat)) * Math.cos(rad(b.lat)) * Math.sin(rad(b.lng - a.lng) / 2) ** 2;
    return 2 * 6371 * Math.asin(Math.min(1, Math.sqrt(h)));
}

export function formatDistance(km: number): string {
    if (km < 1) return `${Math.round(km * 20) * 50} m`;
    if (km < 10) return `${km.toFixed(1)} km`;
    return `${Math.round(km)} km`;
}
