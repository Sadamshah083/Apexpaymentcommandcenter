/**
 * Lightweight agent presence for non-dialer portal pages.
 * Do NOT import communications-dialer / webphone here — that pulls sip.js on every page.
 */

function csrfToken() {
    return document.querySelector('meta[name="csrf-token"]')?.content || '';
}

function presenceUrl() {
    const fromBody = document.body?.getAttribute('data-presence-url') || '';
    if (fromBody) {
        return fromBody;
    }

    const hub = document.querySelector('[data-presence-url]');
    return hub?.getAttribute('data-presence-url') || '';
}

function currentExtension() {
    const form = document.querySelector('.ghl-dialer-originate-form');
    const synced = form?.querySelector('[data-dial-extension-sync], [name="from_extension"]');
    const raw = synced?.value || form?.querySelector('select[name="from_extension"]')?.value || '';

    return String(raw || '').replace(/\D/g, '');
}

let presenceTimer = null;
let lastSignature = '';
let lastAt = 0;

async function postPresence(force = false) {
    const url = presenceUrl();
    if (!url || document.hidden) {
        return;
    }

    const signature = `idle|${currentExtension() || ''}`;
    if (!force && signature === lastSignature && (Date.now() - lastAt) < 60000) {
        return;
    }

    lastSignature = signature;
    lastAt = Date.now();

    try {
        await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': csrfToken(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            credentials: 'same-origin',
            body: JSON.stringify({
                dial_mode: 'manual',
                auto_session_active: false,
                auto_paused: false,
                on_call: false,
                in_disposition: false,
                break_status: 'none',
                break_ends_at: null,
                extension: currentExtension() || null,
            }),
        });
    } catch {
        // Presence is best-effort.
    }
}

export function initAgentPresenceLite() {
    if (document.documentElement.dataset.agentPresenceLiteInit === '1') {
        return;
    }

    // Dialer pages use communications-auto-dial presence (full call/disposition state).
    if (document.querySelector('[data-phone-workspace], [data-auto-dial-hub], .ghl-dialer-originate-form')) {
        return;
    }

    if (!presenceUrl()) {
        return;
    }

    document.documentElement.dataset.agentPresenceLiteInit = '1';
    void postPresence(true);

    if (presenceTimer) {
        window.clearInterval(presenceTimer);
    }
    presenceTimer = window.setInterval(() => {
        void postPresence(false);
    }, 120000);

    document.addEventListener('visibilitychange', () => {
        if (!document.hidden) {
            void postPresence(true);
        }
    });
}
