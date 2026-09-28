import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

// Real-time over Laravel Reverb (TDD: Realtime channels). Without the VITE_REVERB_* settings
// (e.g. on Laragon without `php artisan reverb:start`) this is null and chat polls instead.
declare global {
    interface Window {
        Pusher: typeof Pusher;
    }
}

let instance: Echo<'reverb'> | null | undefined;

export function echo(): Echo<'reverb'> | null {
    if (instance !== undefined) return instance;

    const key = import.meta.env.VITE_REVERB_APP_KEY as string | undefined;
    if (!key || typeof window === 'undefined') {
        instance = null;
        return instance;
    }

    window.Pusher = Pusher;
    instance = new Echo({
        broadcaster: 'reverb',
        key,
        wsHost: (import.meta.env.VITE_REVERB_HOST as string | undefined) ?? window.location.hostname,
        wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 8080),
        wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
        forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
        enabledTransports: ['ws', 'wss'],
    });

    return instance;
}
