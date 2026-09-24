(() => {
    'use strict';

    const MAX_RECONNECT_DELAY = 10000;

    document.addEventListener('DOMContentLoaded', () => {
        const app = window.wspace?.messenger;
        const root = document.getElementById('messenger-app');
        if (!app || !root || typeof app.connect !== 'function') {
            return;
        }

        const connection = document.getElementById('messenger-connection');
        const connectionText = document.getElementById('messenger-connection-text');
        const chatActive = document.getElementById('chat-active');
        const composer = chatActive?.querySelector('.messenger-composer');
        const originalConnect = app.connect.bind(app);
        const originalSetConnectionState = app.setConnectionState.bind(app);

        let refreshPromise = null;
        let reconnectInFlight = false;
        let sessionUnavailable = false;
        let countdownTimer = null;
        let reconnectAt = 0;
        let lastIdentityCheckAt = 0;

        function ticketSubject(ticket) {
            try {
                const encoded = String(ticket || '').split('.')[0] || '';
                if (!encoded) return null;
                const normalized = encoded.replace(/-/g, '+').replace(/_/g, '/');
                const padded = normalized + '='.repeat((4 - (normalized.length % 4)) % 4);
                const payload = JSON.parse(atob(padded));
                const subject = Number(payload?.sub || 0);
                return Number.isInteger(subject) && subject > 0 ? subject : null;
            } catch (error) {
                return null;
            }
        }

        let activeTicketSubject = ticketSubject(window.wspace?.socketConfig?.ticket);

        connection?.setAttribute('role', 'status');
        connection?.setAttribute('aria-live', 'polite');

        const retryButton = document.createElement('button');
        retryButton.type = 'button';
        retryButton.className = 'messenger-connection__retry';
        retryButton.textContent = 'Повторить';
        retryButton.hidden = true;
        retryButton.setAttribute('aria-label', 'Повторить подключение к мессенджеру');
        connection?.append(retryButton);

        const banner = document.createElement('div');
        banner.id = 'messenger-network-banner';
        banner.className = 'messenger-network-banner';
        banner.hidden = true;
        banner.setAttribute('role', 'status');
        banner.setAttribute('aria-live', 'polite');

        const bannerIcon = document.createElement('i');
        bannerIcon.className = 'fa fa-wifi';
        bannerIcon.setAttribute('aria-hidden', 'true');

        const bannerText = document.createElement('span');
        bannerText.className = 'messenger-network-banner__text';

        const bannerAction = document.createElement('button');
        bannerAction.type = 'button';
        bannerAction.className = 'messenger-network-banner__action';
        bannerAction.textContent = 'Повторить';

        banner.append(bannerIcon, bannerText, bannerAction);
        if (chatActive && composer) {
            chatActive.insertBefore(banner, composer);
        }

        function clearCountdown() {
            if (countdownTimer) {
                window.clearInterval(countdownTimer);
                countdownTimer = null;
            }
            reconnectAt = 0;
        }

        function clearReconnectTimer() {
            if (app.reconnectTimer) {
                window.clearTimeout(app.reconnectTimer);
                app.reconnectTimer = null;
            }
            clearCountdown();
        }

        function renderState(state, text, options = {}) {
            originalSetConnectionState(state, text);
            connectionText?.setAttribute('title', text);
            root.dataset.connectionState = state;
            root.dataset.connectionReason = options.reason || '';

            const online = state === 'online';
            const retryAllowed = !online && state !== 'connecting' && !options.hideRetry;
            retryButton.hidden = !retryAllowed;

            if (online) {
                banner.hidden = true;
                bannerText.textContent = '';
                bannerAction.hidden = true;
                clearCountdown();
                return;
            }

            banner.hidden = false;
            banner.dataset.state = state;
            banner.dataset.reason = options.reason || '';
            bannerIcon.className = options.reason === 'network'
                ? 'fa fa-chain-broken'
                : (options.reason === 'session' ? 'fa fa-lock' : 'fa fa-refresh');
            bannerText.textContent = options.bannerText || text;
            bannerAction.hidden = options.hideRetry === true;
            bannerAction.textContent = options.actionText || 'Повторить';
        }

        app.setConnectionState = renderState;

        function updateCountdown() {
            if (!reconnectAt) return;
            const remaining = Math.max(0, Math.ceil((reconnectAt - Date.now()) / 1000));
            const fallback = app.longPollActive === true;
            const state = fallback ? 'fallback' : 'connecting';
            const text = fallback
                ? (remaining > 0
                    ? `Long Poll · WebSocket через ${remaining} с`
                    : 'Long Poll · проверяем WebSocket…')
                : (remaining > 0
                    ? `Связь потеряна · повтор через ${remaining} с`
                    : 'Восстанавливаем соединение…');
            originalSetConnectionState(state, text);
            connectionText?.setAttribute('title', text);
            root.dataset.connectionState = state;
            banner.hidden = false;
            banner.dataset.state = state;
            banner.dataset.reason = 'server';
            bannerIcon.className = fallback ? 'fa fa-exchange' : 'fa fa-refresh';
            bannerText.textContent = fallback
                ? (remaining > 0
                    ? `Работа продолжается через Long Poll. WebSocket переподключится через ${remaining} с.`
                    : 'Работа продолжается через Long Poll. Проверяем WebSocket…')
                : (remaining > 0
                    ? `Соединение прервано. Повторная попытка через ${remaining} с.`
                    : 'Восстанавливаем соединение с сервером…');
            bannerAction.hidden = true;
        }

        async function refreshTicket() {
            if (sessionUnavailable) return false;
            if (refreshPromise) return refreshPromise;

            refreshPromise = (async () => {
                const resumeLongPoll = app.pauseLongPollRequest?.() === true;
                let refreshed = false;
                try {
                    const endpoint = typeof window.wspace?.path === 'function'
                        ? window.wspace.path('/messenger/socket-ticket')
                        : '/messenger/socket-ticket';
                    const response = await fetch(endpoint, {
                        method: 'POST',
                        credentials: 'same-origin',
                        headers: { 'Accept': 'application/json' }
                    });

                    if (response.status === 401 || response.status === 403) {
                        sessionUnavailable = true;
                        clearReconnectTimer();
                        renderState('offline', 'Сессия завершена', {
                            reason: 'session',
                            bannerText: 'Сессия завершена. Обновите страницу и войдите снова.',
                            actionText: 'Обновить страницу'
                        });
                        return false;
                    }

                    if (!response.ok) {
                        throw new Error(`Ticket refresh failed with HTTP ${response.status}`);
                    }

                    const data = await response.json();
                    if (data.status !== 'ok' || typeof data.ticket !== 'string' || data.ticket === '') {
                        throw new Error('Ticket refresh returned an invalid payload');
                    }

                    const nextSubject = ticketSubject(data.ticket);
                    if (activeTicketSubject && nextSubject && nextSubject !== activeTicketSubject) {
                        sessionUnavailable = true;
                        clearReconnectTimer();
                        try {
                            app.socket?.close();
                        } catch (error) {
                            // Ignore close races; the page is about to refresh.
                        }
                        renderState('offline', 'Аккаунт изменён', {
                            reason: 'session',
                            bannerText: 'Обнаружена другая авторизованная учётная запись. Обновляем страницу…',
                            hideRetry: true
                        });
                        window.setTimeout(() => window.location.reload(), 80);
                        return false;
                    }

                    activeTicketSubject = nextSubject || activeTicketSubject;
                    window.wspace.socketConfig = window.wspace.socketConfig || {};
                    window.wspace.socketConfig.ticket = data.ticket;
                    refreshed = true;
                    return true;
                } catch (error) {
                    console.warn('Messenger reconnect ticket refresh failed', error);
                    if (app.longPollActive === true) {
                        renderState('fallback', 'Long Poll · WebSocket недоступен', {
                            reason: 'server',
                            bannerText: 'Messenger работает через Long Poll. WebSocket будет проверен повторно.'
                        });
                    } else {
                        renderState('offline', 'Сервер временно недоступен', {
                            reason: 'server',
                            bannerText: 'Не удалось восстановить соединение. Повторим попытку автоматически.'
                        });
                    }
                    if (resumeLongPoll && !sessionUnavailable) {
                        app.resumeLongPoll?.();
                    }
                    return false;
                } finally {
                    if (!refreshed && resumeLongPoll && !sessionUnavailable && app.longPollActive === true) {
                        app.resumeLongPoll?.();
                    }
                    refreshPromise = null;
                }
            })();

            return refreshPromise;
        }

        async function verifySessionIdentity() {
            if (sessionUnavailable || navigator.onLine === false) return;
            const now = Date.now();
            if (now - lastIdentityCheckAt < 2500) return;
            lastIdentityCheckAt = now;
            await refreshTicket();
        }

        app.connect = async function connectWithFreshTicket() {
            if (sessionUnavailable || reconnectInFlight) return;

            if (navigator.onLine === false) {
                renderState('offline', 'Нет интернета', {
                    reason: 'network',
                    bannerText: 'Нет подключения к интернету. Сообщения останутся на экране и синхронизируются после восстановления связи.',
                    hideRetry: true
                });
                return;
            }

            reconnectInFlight = true;
            renderState(
                app.longPollActive === true ? 'fallback' : 'connecting',
                app.longPollActive === true ? 'Long Poll · проверяем WebSocket…' : 'Восстанавливаем соединение…',
                {
                    reason: 'server',
                    bannerText: app.longPollActive === true
                        ? 'Messenger продолжает работать через Long Poll. Проверяем WebSocket в фоне…'
                        : 'Восстанавливаем соединение с сервером…',
                    hideRetry: true
                }
            );

            try {
                const refreshed = await refreshTicket();
                if (!refreshed) {
                    if (!sessionUnavailable) app.scheduleReconnect();
                    return;
                }
                originalConnect();
            } finally {
                reconnectInFlight = false;
            }
        };

        app.scheduleReconnect = function scheduleConnectionRetry(options = {}) {
            if (sessionUnavailable || app.reconnectTimer) return;

            if (navigator.onLine === false) {
                renderState('offline', 'Нет интернета', {
                    reason: 'network',
                    bannerText: 'Нет подключения к интернету. Повторное подключение начнётся автоматически после восстановления сети.',
                    hideRetry: true
                });
                return;
            }

            const immediate = options.immediate === true;
            const delay = immediate
                ? 0
                : Math.min(MAX_RECONNECT_DELAY, 1000 * (2 ** Math.min(app.reconnectAttempt, 3)));
            if (!immediate) app.reconnectAttempt += 1;

            reconnectAt = Date.now() + delay;
            updateCountdown();
            if (delay > 0) {
                countdownTimer = window.setInterval(updateCountdown, 250);
            }

            app.reconnectTimer = window.setTimeout(() => {
                app.reconnectTimer = null;
                clearCountdown();
                app.connect();
            }, delay);
        };

        function retryNow() {
            if (sessionUnavailable) {
                window.location.reload();
                return;
            }
            clearReconnectTimer();
            if (app.socket && app.socket.readyState === WebSocket.OPEN && app.socketAuthorized === true) return;
            app.scheduleReconnect({ immediate: true });
        }

        retryButton.addEventListener('click', retryNow);
        bannerAction.addEventListener('click', retryNow);

        window.addEventListener('offline', () => {
            clearReconnectTimer();
            renderState('offline', 'Нет интернета', {
                reason: 'network',
                bannerText: 'Нет подключения к интернету. Повторное подключение начнётся автоматически после восстановления сети.',
                hideRetry: true
            });
        });

        window.addEventListener('online', () => {
            if (!sessionUnavailable && (!app.socket || app.socket.readyState !== WebSocket.OPEN)) {
                clearReconnectTimer();
                app.scheduleReconnect({ immediate: true });
            }
            verifySessionIdentity();
        });

        window.addEventListener('focus', () => {
            verifySessionIdentity();
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') return;
            verifySessionIdentity();
            if (
                navigator.onLine !== false
                && !sessionUnavailable
                && (!app.socket || app.socket.readyState !== WebSocket.OPEN)
            ) {
                clearReconnectTimer();
                app.scheduleReconnect({ immediate: true });
            }
        });

        const initialState = connection?.dataset.state || 'connecting';
        root.dataset.connectionState = initialState;
        if (initialState !== 'online' && navigator.onLine === false) {
            renderState('offline', 'Нет интернета', {
                reason: 'network',
                bannerText: 'Нет подключения к интернету. Повторное подключение начнётся автоматически после восстановления сети.',
                hideRetry: true
            });
        } else if (initialState !== 'online') {
            banner.hidden = false;
            banner.dataset.state = initialState;
            banner.dataset.reason = 'server';
            bannerText.textContent = connectionText?.textContent || 'Подключение к мессенджеру…';
            bannerAction.hidden = true;
        }
    });
})();
