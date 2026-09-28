(() => {
    'use strict';

    const MAX_RECONNECT_DELAY = 10000;
    const MAX_LONG_POLL_RETRY_DELAY = 10000;
    const LONG_POLL_WATCHDOG_MS = 32000;
    const PREVIEW_LIMIT = 120;

    document.addEventListener('DOMContentLoaded', () => {
        const wspace = window.wspace = window.wspace || {};
        const link = document.querySelector('.sidebar__menu-link[data-nav-key="messenger"]');
        const badge = document.getElementById('messenger-unread-badge');
        if (!link || !badge) return;

        const dialogs = new Map();
        const states = new Map();
        let socket = null;
        let socketAuthorized = false;
        let connecting = false;
        let reconnectTimer = null;
        let reconnectAttempt = 0;
        let stopped = false;
        let userUid = '';

        let longPollActive = false;
        let longPollCursor = '';
        let longPollGeneration = 0;
        let longPollAbortController = null;
        let longPollRetryTimer = null;
        let longPollWatchdogTimer = null;
        let longPollRetryAttempt = 0;
        let longPollFallbackTimer = null;

        function totalUnread() {
            let total = 0;
            dialogs.forEach((dialog) => {
                total += Math.max(0, Number(dialog?.unread_count || 0));
            });
            return total;
        }

        function renderBadge() {
            const total = totalUnread();
            badge.hidden = total <= 0;
            badge.textContent = total > 99 ? '99+' : String(total);
            badge.setAttribute('aria-label', 'Непрочитанных сообщений: ' + total);
            link.title = total > 0 ? 'Мессенджер — непрочитанных: ' + total : 'Мессенджер';
            link.setAttribute('aria-label', link.title);
        }

        function applyDialogs(rows) {
            dialogs.clear();
            (Array.isArray(rows) ? rows : []).forEach((dialog) => {
                if (dialog?.uid) dialogs.set(dialog.uid, dialog);
            });
            renderBadge();
        }

        wspace.messengerNotifications = {
            updateUnread: applyDialogs
        };

        document.addEventListener('wspace:messenger-dialogs', (event) => {
            applyDialogs(event?.detail?.dialogs);
        });

        // Страница Messenger владеет полным transport state machine сама.
        if (document.getElementById('messenger-app')) return;

        const socketUrl = String(wspace.socketConfig?.url || '').trim();

        function send(action, data = {}) {
            if (!socket || socket.readyState !== WebSocket.OPEN || !socketAuthorized) return false;
            socket.send(JSON.stringify({ action, data }));
            return true;
        }

        function requestState() {
            send('MessangerSocket:get_dialogs', {});
            send('DialogStateSocket:list', {});
        }

        function displayUser(user) {
            const name = String((user?.firstname || '') + ' ' + (user?.lastname || '')).trim();
            if (name !== '') return name;
            const username = String(user?.username || '').trim();
            return username !== '' ? '@' + username : 'Пользователь';
        }

        function preview(message) {
            const text = String(message?.message || '').replace(/\s+/g, ' ').trim();
            if (text !== '') {
                return text.length > PREVIEW_LIMIT ? text.slice(0, PREVIEW_LIMIT - 1) + '…' : text;
            }

            switch (String(message?.message_type || '')) {
                case 'image': return 'Изображение';
                case 'audio': return 'Аудио';
                case 'video': return 'Видео';
                case 'voice': return 'Голосовое сообщение';
                case 'file': return 'Файл';
                default: return 'Новое сообщение';
            }
        }

        function isMuted(dialogUid) {
            return Boolean(states.get(dialogUid)?.muted);
        }

        function showIncoming(data) {
            const message = data?.message;
            if (!message || message.user?.uid === userUid || isMuted(data.dialog_uid)) return;

            const text = displayUser(message.user) + ': ' + preview(message);
            const toast = typeof wspace.feedback?.toast === 'function'
                ? wspace.feedback.toast(text, 'info', 6500)
                : null;

            if (!toast) return;
            toast.classList.add('wspace-toast--message');
            toast.title = 'Открыть Мессенджер';
            toast.addEventListener('click', () => {
                window.location.href = link.href;
            }, { once: true });
        }

        function handleRealtimePayload(data) {
            if (!data || typeof data !== 'object') return;

            switch (data.action) {
                case 'Ping':
                    send('PingSocket:index', { ping: 'Pong' });
                    break;
                case 'Authorized':
                    socketAuthorized = true;
                    userUid = String(data.user_uid || '');
                    stopLongPoll();
                    requestState();
                    break;
                case 'get_dialogs':
                    applyDialogs(data.dialogs);
                    break;
                case 'dialog_states':
                    states.clear();
                    (Array.isArray(data.states) ? data.states : []).forEach((state) => {
                        if (state?.dialog_uid) states.set(state.dialog_uid, state);
                    });
                    break;
                case 'dialog_state':
                    if (data.dialog_uid) {
                        states.set(data.dialog_uid, {
                            ...(states.get(data.dialog_uid) || {}),
                            ...data
                        });
                    }
                    break;
                case 'send_message':
                    showIncoming(data);
                    requestState();
                    break;
                case 'new_dialog':
                case 'message_edited':
                case 'message_deleted':
                case 'read_update':
                case 'sync_required':
                    requestState();
                    break;
                default:
                    break;
            }
        }

        function dispatchRealtimeEvents(events) {
            (Array.isArray(events) ? events : []).forEach(handleRealtimePayload);
        }

        function clearReconnectTimer() {
            if (!reconnectTimer) return;
            window.clearTimeout(reconnectTimer);
            reconnectTimer = null;
        }

        function scheduleReconnect() {
            if (stopped || reconnectTimer || navigator.onLine === false || socketUrl === '') return;
            const delay = Math.min(MAX_RECONNECT_DELAY, 1000 * (2 ** Math.min(reconnectAttempt, 3)));
            reconnectAttempt += 1;
            reconnectTimer = window.setTimeout(() => {
                reconnectTimer = null;
                void connect();
            }, delay);
        }

        function scheduleLongPollFallback(delay = 1000) {
            if (stopped || socketAuthorized || longPollActive || longPollFallbackTimer) return;
            longPollFallbackTimer = window.setTimeout(() => {
                longPollFallbackTimer = null;
                if (!socketAuthorized) startLongPoll();
            }, Math.max(0, delay));
        }

        function longPollRetryDelay() {
            return Math.min(
                MAX_LONG_POLL_RETRY_DELAY,
                600 * (2 ** Math.min(longPollRetryAttempt, 4))
            );
        }

        function startLongPoll() {
            if (stopped) return;
            if (longPollFallbackTimer) {
                window.clearTimeout(longPollFallbackTimer);
                longPollFallbackTimer = null;
            }
            if (!longPollActive) {
                longPollActive = true;
                longPollRetryAttempt = 0;
            }
            if (navigator.onLine === false) return;
            resumeLongPoll();
        }

        function stopLongPoll() {
            longPollActive = false;
            longPollGeneration += 1;
            longPollRetryAttempt = 0;

            if (longPollFallbackTimer) {
                window.clearTimeout(longPollFallbackTimer);
                longPollFallbackTimer = null;
            }
            if (longPollRetryTimer) {
                window.clearTimeout(longPollRetryTimer);
                longPollRetryTimer = null;
            }
            if (longPollWatchdogTimer) {
                window.clearTimeout(longPollWatchdogTimer);
                longPollWatchdogTimer = null;
            }
            if (longPollAbortController) {
                longPollAbortController.abort();
                longPollAbortController = null;
            }
        }

        function pauseLongPoll() {
            if (!longPollActive) return;

            longPollGeneration += 1;
            if (longPollRetryTimer) {
                window.clearTimeout(longPollRetryTimer);
                longPollRetryTimer = null;
            }
            if (longPollWatchdogTimer) {
                window.clearTimeout(longPollWatchdogTimer);
                longPollWatchdogTimer = null;
            }
            if (longPollAbortController) {
                longPollAbortController.abort();
                longPollAbortController = null;
            }
        }

        function resumeLongPoll() {
            if (
                stopped
                || !longPollActive
                || socketAuthorized
                || navigator.onLine === false
                || longPollAbortController
                || longPollRetryTimer
            ) {
                return;
            }

            const generation = ++longPollGeneration;
            void runLongPoll(generation);
        }

        async function runLongPoll(generation) {
            while (longPollActive && generation === longPollGeneration && !socketAuthorized) {
                const query = new URLSearchParams();
                if (longPollCursor) query.set('cursor', longPollCursor);
                const path = '/messenger/realtime/poll?' + query.toString();
                const endpoint = typeof wspace.path === 'function' ? wspace.path(path) : path;

                const controller = new AbortController();
                let watchdogExpired = false;
                longPollAbortController = controller;
                const watchdogTimer = window.setTimeout(() => {
                    watchdogExpired = true;
                    if (longPollAbortController === controller) controller.abort();
                }, LONG_POLL_WATCHDOG_MS);
                longPollWatchdogTimer = watchdogTimer;

                try {
                    const response = await fetch(endpoint, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'Accept': 'application/json' },
                        signal: controller.signal
                    });

                    if (!longPollActive || generation !== longPollGeneration || socketAuthorized) return;
                    if (response.status === 401 || response.status === 403) {
                        stopped = true;
                        stopLongPoll();
                        return;
                    }
                    if (!response.ok) throw new Error('HTTP ' + response.status);

                    const payload = await response.json();
                    if (payload?.status !== 'ok') {
                        throw new Error(payload?.message || 'Long Poll failed');
                    }
                    if (typeof payload.cursor === 'string' && payload.cursor !== '') {
                        longPollCursor = payload.cursor;
                    }
                    if (payload.changed) dispatchRealtimeEvents(payload.events);
                    longPollRetryAttempt = 0;
                } catch (error) {
                    const manuallyAborted = error?.name === 'AbortError' && !watchdogExpired;
                    if (manuallyAborted) return;
                    if (!longPollActive || generation !== longPollGeneration || socketAuthorized) return;
                    if (navigator.onLine === false) return;

                    longPollRetryAttempt += 1;
                    console.warn('Global Messenger Long Poll will reconnect', error);
                    await new Promise((resolve) => {
                        longPollRetryTimer = window.setTimeout(resolve, longPollRetryDelay());
                    });
                    longPollRetryTimer = null;
                } finally {
                    window.clearTimeout(watchdogTimer);
                    if (longPollWatchdogTimer === watchdogTimer) {
                        longPollWatchdogTimer = null;
                    }
                    if (longPollAbortController === controller) {
                        longPollAbortController = null;
                    }
                }
            }
        }

        async function freshTicket() {
            const endpoint = typeof wspace.path === 'function'
                ? wspace.path('/messenger/socket-ticket')
                : '/messenger/socket-ticket';
            const response = await fetch(endpoint, {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (response.status === 401 || response.status === 403) {
                stopped = true;
                stopLongPoll();
                return '';
            }
            if (!response.ok) {
                throw new Error('Messenger ticket request failed with HTTP ' + response.status);
            }

            const data = await response.json();
            if (data?.status !== 'ok' || typeof data.ticket !== 'string' || data.ticket === '') {
                throw new Error('Messenger ticket response is invalid');
            }
            return data.ticket;
        }

        async function connect() {
            if (
                stopped
                || socketUrl === ''
                || connecting
                || navigator.onLine === false
                || socket?.readyState === WebSocket.OPEN
                || socket?.readyState === WebSocket.CONNECTING
            ) {
                return;
            }

            connecting = true;
            try {
                const ticket = await freshTicket();
                if (ticket === '' || stopped) return;

                const separator = socketUrl.includes('?') ? '&' : '?';
                const nextSocket = new WebSocket(socketUrl + separator + 'ticket=' + encodeURIComponent(ticket));
                socket = nextSocket;
                scheduleLongPollFallback();

                nextSocket.addEventListener('open', () => {
                    reconnectAttempt = 0;
                });
                nextSocket.addEventListener('message', (event) => {
                    let data;
                    try {
                        data = JSON.parse(event.data);
                    } catch (_) {
                        return;
                    }
                    handleRealtimePayload(data);
                });
                nextSocket.addEventListener('close', () => {
                    if (socket === nextSocket) socket = null;
                    socketAuthorized = false;
                    startLongPoll();
                    scheduleReconnect();
                });
                nextSocket.addEventListener('error', () => {
                    socketAuthorized = false;
                    startLongPoll();
                });
            } catch (error) {
                console.warn('Global Messenger WebSocket temporarily unavailable', error);
                startLongPoll();
                scheduleReconnect();
            } finally {
                connecting = false;
            }
        }

        window.addEventListener('online', () => {
            clearReconnectTimer();
            if (!socketAuthorized) startLongPoll();
            void connect();
        });

        window.addEventListener('offline', () => {
            clearReconnectTimer();
            socketAuthorized = false;
            try {
                socket?.close();
            } catch (_) {
                // Уже закрытый WebSocket не требует обработки.
            }
            startLongPoll();
            pauseLongPoll();
        });

        window.addEventListener('focus', () => {
            if (!socketAuthorized) startLongPoll();
            void connect();
        });

        document.addEventListener('visibilitychange', () => {
            if (document.visibilityState !== 'visible') return;
            if (!socketAuthorized) startLongPoll();
            void connect();
        });

        if (socketUrl === '') {
            startLongPoll();
            return;
        }

        void connect();
    });
})();
