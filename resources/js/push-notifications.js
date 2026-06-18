import { showToast } from './toast.js';
import {
    enableOsNotifications,
    ensureServiceWorker,
    isOsNotificationsEnabled,
    showOsNotification,
} from './system-notifications.js';

let pushSubscribed = false;

function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const rawData = window.atob(base64);

    return Uint8Array.from([...rawData], (char) => char.charCodeAt(0));
}

function serializeSubscription(subscription) {
    const json = subscription.toJSON();

    return {
        endpoint: json.endpoint,
        keys: json.keys,
    };
}

function updatePushButton() {
    const btn = document.getElementById('push-btn');
    const dot = document.getElementById('push-status-dot');

    if (!btn) {
        return;
    }

    const enabled = isOsNotificationsEnabled() || pushSubscribed;

    if (enabled) {
        btn.classList.add('is-active');
        btn.setAttribute('title', 'System notifications enabled');
        if (dot) {
            dot.classList.add('is-active');
        }
    } else {
        btn.classList.remove('is-active');
        btn.setAttribute('title', 'Enable system notifications');
        if (dot) {
            dot.classList.remove('is-active');
        }
    }
}

async function checkPushSubscription() {
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        updatePushButton();
        return;
    }

    try {
        const registration = await navigator.serviceWorker.ready;
        const subscription = await registration.pushManager.getSubscription();
        pushSubscribed = subscription !== null;
    } catch {
        pushSubscribed = false;
    }

    updatePushButton();
}

async function saveSubscriptionOnServer(subscription) {
    const url = document.body.dataset.pushSubscribeUrl;
    const token = document.querySelector('meta[name="csrf-token"]')?.content;

    if (!url || !token) {
        return false;
    }

    try {
        const response = await fetch(url, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': token,
            },
            body: JSON.stringify(serializeSubscription(subscription)),
        });
        const data = await response.json();

        return response.ok && !data.error;
    } catch {
        return false;
    }
}

async function trySubscribeWebPush(registration) {
    const vapidUrl = document.body.dataset.pushVapidKeyUrl;
    if (!vapidUrl || !registration?.pushManager) {
        return false;
    }

    try {
        const vapidResponse = await fetch(vapidUrl, {
            headers: { Accept: 'application/json' },
            credentials: 'same-origin',
        });
        const vapidData = await vapidResponse.json();

        if (!vapidResponse.ok || !vapidData.publicKey) {
            return false;
        }

        const existing = await registration.pushManager.getSubscription();
        const subscription = existing || await registration.pushManager.subscribe({
            userVisibleOnly: true,
            applicationServerKey: urlBase64ToUint8Array(vapidData.publicKey),
        });

        const saved = await saveSubscriptionOnServer(subscription);
        if (saved) {
            pushSubscribed = true;
        }

        return saved;
    } catch {
        return false;
    }
}

window.requestPushNotificationPermission = async function requestPushNotificationPermission() {
    if (!('Notification' in window)) {
        showToast('Notifications are not supported in this browser.', 'warning');
        return;
    }

    if (isOsNotificationsEnabled()) {
        const registration = await ensureServiceWorker();
        await trySubscribeWebPush(registration);
        updatePushButton();
        return;
    }

    const result = await enableOsNotifications();
    updatePushButton();

    if (!result.ok) {
        if (result.reason === 'denied') {
            showToast('Notification permission was denied. Enable it in Windows and Chrome settings.', 'warning');
        } else {
            showToast('Notifications are not supported in this browser.', 'warning');
        }
        return;
    }

    const registration = await ensureServiceWorker();
    await trySubscribeWebPush(registration);
    updatePushButton();
};

function initPushNotifications() {
    const btn = document.getElementById('push-btn');

    if (!btn) {
        return;
    }

    if (!('Notification' in window)) {
        btn.style.display = 'none';
        return;
    }

    ensureServiceWorker()
        .then(() => checkPushSubscription())
        .catch(() => updatePushButton());

    if (isOsNotificationsEnabled()) {
        updatePushButton();
    }
}

export { initPushNotifications };
