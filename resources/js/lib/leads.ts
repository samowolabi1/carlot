import { xsrfToken } from '@/lib/http';

/**
 * Records a lead when a signed-in buyer taps WhatsApp or Call (TDD M11). The tap itself
 * still opens WhatsApp or the dialler; `keepalive` lets the request finish as the page
 * hands over to the other app.
 */
export function recordIntent(source: 'whatsapp' | 'call', target: { vehicle?: string; lot?: string }): void {
    fetch(route('leads.intent'), {
        method: 'POST',
        keepalive: true,
        credentials: 'same-origin',
        headers: { Accept: 'application/json', 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-XSRF-TOKEN': xsrfToken() },
        body: JSON.stringify({ source, ...target }),
    }).catch(() => {});
}
