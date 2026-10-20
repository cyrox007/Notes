/* Фоновый обработчик Notes. Приватные HTML/API-ответы не кэшируются. */
'use strict';

self.addEventListener('install', (event) => {
    event.waitUntil(self.skipWaiting());
});

self.addEventListener('activate', (event) => {
    event.waitUntil(self.clients.claim());
});

function destination(value) {
    const scope = new URL(self.registration.scope);
    const fallback = scope.href;
    if (typeof value !== 'string' || value.length > 1000
        || value.startsWith('//') || /[\\\x00-\x1f\x7f]/.test(value)) {
        return fallback;
    }
    let target;
    try {
        target = new URL(value, self.location.origin);
    } catch (_) {
        return fallback;
    }
    // Повторно проверяем адрес при нажатии. В data.url хранится абсолютный
    // адрес; ссылка не должна уходить за пределы установки Notes.
    if (target.origin !== self.location.origin || target.username || target.password
        || !target.pathname.startsWith(scope.pathname)) {
        return fallback;
    }
    return target.href;
}

self.addEventListener('push', (event) => {
    let data = {};
    try { data = event.data ? event.data.json() : {}; } catch (_) { data = {}; }
    const id = Number.isSafeInteger(data.id) && data.id > 0 ? data.id : 0;
    const url = destination(data.path);
    event.waitUntil(self.registration.showNotification('Notes', {
        body: 'Поступило новое уведомление. Откройте приложение для подробностей.',
        tag: 'notes-notification-' + id,
        icon: new URL('assets/img/workspace-brand-mark.svg', self.registration.scope).href,
        badge: new URL('assets/img/workspace-brand-mark.svg', self.registration.scope).href,
        data: { url },
        renotify: false,
    }));
});

self.addEventListener('notificationclick', (event) => {
    event.notification.close();
    const url = destination(event.notification.data?.url);
    event.waitUntil((async () => {
        const tabs = await self.clients.matchAll({ type: 'window', includeUncontrolled: true });
        for (const tab of tabs) {
            if (new URL(tab.url).origin === self.location.origin) {
                await tab.navigate(url);
                return tab.focus();
            }
        }
        return self.clients.openWindow(url);
    })());
});

self.addEventListener('pushsubscriptionchange', (event) => {
    // Не регистрируем автоматически отозванную браузером подписку без
    // авторизованного пользователя. Интерфейс предложит привязать устройство повторно.
    event.waitUntil(Promise.resolve());
});
