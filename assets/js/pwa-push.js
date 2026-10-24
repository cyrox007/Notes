(() => {
    'use strict';

    // Подписка на Push привязывается только по явному нажатию пользователя.
    // Сервисный обработчик не кэширует личные страницы и ответы API.
    const base = String(window.wspaceRuntime?.basePath || '').replace(/\/$/, '');
    const path = (suffix) => base + suffix;
    const supported = window.isSecureContext
        && 'serviceWorker' in navigator
        && 'PushManager' in window
        && 'Notification' in window;

    function decodeKey(value) {
        const raw = String(value).replace(/-/g, '+').replace(/_/g, '/');
        const decoded = atob(raw.padEnd(Math.ceil(raw.length / 4) * 4, '='));
        return Uint8Array.from(decoded, (character) => character.charCodeAt(0));
    }

    async function request(suffix, options = {}) {
        const response = await fetch(path('/api/notifications/push' + suffix), {
            credentials: 'same-origin',
            cache: 'no-store',
            headers: { 'Accept': 'application/json', ...(options.headers || {}) },
            ...options,
        });
        const payload = await response.json().catch(() => null);
        if (!response.ok || payload?.status !== 'ok') {
            throw new Error(payload?.message || 'Не удалось связаться с сервером уведомлений');
        }
        return payload;
    }

    const create = (tag, text, className = '') => {
        const element = document.createElement(tag);
        element.textContent = text;
        if (className) element.className = className;
        return element;
    };

    function mount() {
        const settings = document.querySelector('[data-notification-settings-view]');
        if (!settings || settings.querySelector('[data-push-devices]')) return;

        const panel = create('section', '', 'workspace-notification-preference');
        panel.dataset.pushDevices = '';
        panel.append(create('strong', 'Уведомления на этом устройстве'));
        panel.append(create('p', 'Получайте уведомления Notes на телефоне или компьютере, даже когда вкладка закрыта. Текст личных сообщений не передаётся в Push.'));
        const status = create('p', 'Проверяем доступность…');
        status.setAttribute('role', 'status');
        status.setAttribute('aria-live', 'polite');
        const button = create('button', 'Включить Push', 'workspace-notification-center__text-action');
        button.type = 'button';
        const install = create('button', 'Установить Notes', 'workspace-notification-center__text-action');
        install.type = 'button';
        install.hidden = true;
        panel.append(status, button, install);
        settings.insertBefore(panel, settings.querySelector('[data-notification-settings-list]'));

        let registration;
        let subscription;
        let enabled = false;
        let busy = false;
        let installEvent = null;

        const state = (message, error = false) => {
            status.textContent = message;
            status.dataset.state = error ? 'error' : 'success';
            button.disabled = busy || !supported;
            button.textContent = enabled ? 'Отключить Push на этом устройстве' : 'Включить Push';
        };

        window.addEventListener('beforeinstallprompt', (event) => {
            event.preventDefault();
            installEvent = event;
            install.hidden = false;
        });
        install.addEventListener('click', async () => {
            if (!installEvent) return;
            await installEvent.prompt();
            installEvent = null;
            install.hidden = true;
        });

        async function sync() {
            if (!supported) {
                state('Этот браузер не поддерживает Web Push либо сайт открыт без HTTPS.');
                return;
            }
            try {
                registration = await navigator.serviceWorker.register(path('/service-worker.js'), {
                    scope: path('/') || '/',
                    updateViaCache: 'none',
                });
                subscription = await registration.pushManager.getSubscription();
                const server = await request('/status');
                enabled = Boolean(subscription && server.current);
                if (Notification.permission === 'denied') {
                    state('Уведомления запрещены в настройках браузера. Разрешите их для этого сайта.');
                } else if (enabled) {
                    state('Push подключён. Всего привязанных устройств: ' + server.active + '.');
                } else if (subscription) {
                    state('Подписка браузера существует, но не активна в Notes. Нажмите «Включить», чтобы привязать её.');
                } else {
                    state('Push не включён. Разрешение запрашивается только после нажатия кнопки.');
                }
            } catch (error) {
                state(error instanceof Error ? error.message : 'Не удалось проверить устройство', true);
            }
        }

        button.addEventListener('click', async () => {
            if (busy || !supported) return;
            busy = true;
            button.disabled = true;
            status.textContent = 'Обновляем подписку устройства…';
            try {
                if (enabled) {
                    if (!subscription) throw new Error('Подписка браузера не найдена');
                    await request('/unsubscribe', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ endpoint: subscription.endpoint }),
                    });
                    await subscription.unsubscribe();
                    subscription = null;
                    enabled = false;
                    state('Push отключён для этого устройства. Другие устройства не затронуты.');
                    // Доступность категорий пересчитывается после отзыва,
                    // в том числе если это было единственное устройство.
                    document.dispatchEvent(new CustomEvent('wspace:push-device-updated'));
                    return;
                }

                // Вызов requestPermission напрямую из жеста пользователя.
                const permission = await Notification.requestPermission();
                if (permission !== 'granted') {
                    state('Для Push необходимо разрешение браузера.');
                    return;
                }
                const keys = await request('/key');
                if (!keys.available || typeof keys.public_key !== 'string') {
                    throw new Error(keys.message || 'Администратор ещё не настроил Web Push.');
                }
                if (!registration) {
                    registration = await navigator.serviceWorker.register(path('/service-worker.js'), {
                        scope: path('/') || '/',
                        updateViaCache: 'none',
                    });
                }
                subscription = await registration.pushManager.getSubscription();
                if (!subscription) {
                    subscription = await registration.pushManager.subscribe({
                        userVisibleOnly: true,
                        applicationServerKey: decodeKey(keys.public_key),
                    });
                }
                const result = await request('/subscribe', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify(subscription.toJSON()),
                });
                enabled = true;
                state('Push включён. Привязанных устройств: ' + result.active_devices + '. Для сообщений разрешите Browser Push в нужных категориях.');
                document.dispatchEvent(new CustomEvent('wspace:push-device-updated'));
            } catch (error) {
                state(error instanceof Error ? error.message : 'Ошибка подписки Web Push', true);
            } finally {
                busy = false;
                button.disabled = !supported;
                button.textContent = enabled ? 'Отключить Push на этом устройстве' : 'Включить Push';
            }
        });

        void sync();
        document.addEventListener('wspace:push-refresh-request', () => void sync());
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', mount, { once: true });
    } else {
        mount();
    }
})();
