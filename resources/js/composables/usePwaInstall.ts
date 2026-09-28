import { computed, ref } from 'vue';

// Chrome and Android fire beforeinstallprompt once the PWA is installable; iOS Safari
// never does, so iPhone users get the "Add to Home Screen" hint instead.
interface InstallPromptEvent extends Event {
    prompt: () => Promise<void>;
    userChoice: Promise<{ outcome: 'accepted' | 'dismissed' }>;
}

const deferred = ref<InstallPromptEvent | null>(null);
const installed = ref(false);
let listening = false;

function standalone(): boolean {
    return window.matchMedia?.('(display-mode: standalone)').matches || (navigator as Navigator & { standalone?: boolean }).standalone === true;
}

/** Call once at start-up so the event isn't missed before a page asks for it. */
export function listenForInstall() {
    if (listening || typeof window === 'undefined') return;
    listening = true;
    installed.value = standalone();
    window.addEventListener('beforeinstallprompt', (e) => {
        e.preventDefault();
        deferred.value = e as InstallPromptEvent;
    });
    window.addEventListener('appinstalled', () => {
        installed.value = true;
        deferred.value = null;
    });
}

export function usePwaInstall() {
    listenForInstall();

    const ios = typeof navigator !== 'undefined' && /iphone|ipad|ipod/i.test(navigator.userAgent);
    const canPrompt = computed(() => deferred.value !== null);
    const available = computed(() => !installed.value && (canPrompt.value || ios));
    const hint = computed(() => (ios ? 'Tap the Share button in Safari, then "Add to Home Screen".' : ''));

    async function prompt() {
        const event = deferred.value;
        if (!event) return;
        await event.prompt();
        const { outcome } = await event.userChoice;
        if (outcome === 'accepted') installed.value = true;
        deferred.value = null;
    }

    return { available, canPrompt, hint, prompt, installed };
}
