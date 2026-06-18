const STORAGE_KEY = 'workspace-os-notifications-enabled';
const ICON = '/notification-icon.svg';

export function isOsNotificationsEnabled() {
    return localStorage.getItem(STORAGE_KEY) === '1' && Notification.permission === 'granted';
}

export function setOsNotificationsEnabled(enabled) {
    localStorage.setItem(STORAGE_KEY, enabled ? '1' : '0');
}

export async function ensureServiceWorker() {
    if (!('serviceWorker' in navigator)) {
        return null;
    }

    try {
        return await navigator.serviceWorker.register('/sw.js');
    } catch {
        return null;
    }
}

export async function showOsNotification({ title, body, url = '/' }) {
    if (!('Notification' in window) || Notification.permission !== 'granted') {
        return false;
    }

    const options = {
        body: body || '',
        icon: ICON,
        badge: ICON,
        tag: `workspace-${String(title).slice(0, 40)}`,
        renotify: true,
        data: { url },
    };

    if ('serviceWorker' in navigator) {
        const registration = await navigator.serviceWorker.ready;
        await registration.showNotification(title, options);
        return true;
    }

    const notification = new Notification(title, options);
    notification.onclick = () => {
        window.focus();
        if (url) {
            window.location.href = url;
        }
        notification.close();
    };

    return true;
}

export async function enableOsNotifications({ title, body, url } = {}) {
    if (!('Notification' in window)) {
        return { ok: false, reason: 'unsupported' };
    }

    const permission = await Notification.requestPermission();
    if (permission !== 'granted') {
        setOsNotificationsEnabled(false);
        return { ok: false, reason: 'denied' };
    }

    await ensureServiceWorker();
    setOsNotificationsEnabled(true);

    await showOsNotification({
        title: title || 'Notifications enabled',
        body: body || 'You will receive Windows alerts for leads and workspace updates.',
        url: url || window.location.href,
    });

    return { ok: true };
}

window.workspaceOsNotifications = {
    isEnabled: isOsNotificationsEnabled,
    enable: enableOsNotifications,
    show: showOsNotification,
};
