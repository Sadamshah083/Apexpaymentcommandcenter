const DEFAULT_ICON = '/notification-icon.svg';

function parsePushPayload(event) {
    if (!event.data) {
        return null;
    }

    try {
        return event.data.json();
    } catch (error) {
        try {
            return JSON.parse(event.data.text());
        } catch {
            return {
                title: 'Workspace update',
                body: event.data.text(),
            };
        }
    }
}

function showSystemNotification(data) {
    const title = data.title || 'Workspace update';
    const options = {
        body: data.body || 'Open the app to view details.',
        icon: data.icon || DEFAULT_ICON,
        badge: data.badge || DEFAULT_ICON,
        vibrate: [120, 60, 120],
        tag: data.tag || 'workspace-notification',
        renotify: true,
        requireInteraction: false,
        data: {
            url: data.url || '/',
        },
    };

    return self.registration.showNotification(title, options);
}

self.addEventListener('push', function (event) {
    const payload = parsePushPayload(event);

    if (payload) {
        event.waitUntil(showSystemNotification(payload));
        return;
    }

    event.waitUntil(
        fetch('/push/latest', { credentials: 'include' })
            .then((response) => response.json())
            .then((data) => showSystemNotification(data))
            .catch(() => showSystemNotification({
                title: 'Workspace update',
                body: 'You have a new update in your workspace.',
                url: '/',
            }))
    );
});

self.addEventListener('notificationclick', function (event) {
    event.notification.close();

    const urlToOpen = new URL(event.notification.data?.url || '/', self.location.origin).href;

    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then((windowClients) => {
            for (const client of windowClients) {
                if (client.url === urlToOpen && 'focus' in client) {
                    return client.focus();
                }
            }

            if (clients.openWindow) {
                return clients.openWindow(urlToOpen);
            }
        })
    );
});
