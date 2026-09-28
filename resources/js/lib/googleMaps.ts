/* eslint-disable @typescript-eslint/no-explicit-any */
declare global {
    interface Window {
        google?: any;
        __lotlinkMapsReady?: () => void;
    }
}

export const mapsKey = import.meta.env.VITE_GOOGLE_MAPS_BROWSER_KEY as string | undefined;

let loading: Promise<void> | null = null;

/** Loads the Google Maps JavaScript API once. Pages fall back to a simple map without a key. */
export function loadGoogleMaps(): Promise<void> {
    if (window.google?.maps) return Promise.resolve();
    if (!mapsKey) return Promise.reject(new Error('No Google Maps key'));

    loading ??= new Promise((resolve, reject) => {
        window.__lotlinkMapsReady = () => resolve();
        const script = document.createElement('script');
        script.src = `https://maps.googleapis.com/maps/api/js?key=${encodeURIComponent(mapsKey)}&callback=__lotlinkMapsReady&loading=async`;
        script.async = true;
        script.onerror = () => {
            loading = null;
            reject(new Error('Google Maps failed to load'));
        };
        document.head.appendChild(script);
    });

    return loading;
}
