(() => {
    'use strict';

    const MAX_RECONNECT_DELAY = 15000;
    const PREVIEW_LIMIT = 120;

    document.addEventListener('DOMContentLoaded', () => {
        const wspace = window.wspace = window.wspace || {};
        const link = document.querySelector('.sidebar__menu-link[data-nav-key="messenger"]');
        const badge = document.getElementById('messenger-unread-badge');
        if (!link || !badge) return;

        const dialogs = new Map();
        const states = new Map();
        const seenEventIds = new Set();
        const seenEventOrder = [];
        let socket = null;
        let connecting = false;
        let reconnectTimer = null;
        let reconnectAttempt = 0;
        let stopped = false;
        let userUid = '';
        let eventCursor = 0;
        let cursorReady = false;
        let longPollActive = false;
        let longPollAbortController = null;
        let longPollFailures = 0;

        function appPath(path) {
            return typeof wspace.path === 'function' ? wspace.path(path) : path;
        }

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

        // The Messenger page owns the full transport client and publishes dialog
        // state into this shell through wspace:messenger-dialogs.
        if (document.getElementById('messenger-app')) return;

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

            if (toast) {
                toast.classList.add('wspace-toast--message');
                toast.title = 'Открыть Мессенджер';
                toast.addEventListener('click', () => {
                    window.location.href = link.href;
                }, { once: true });
            }
        }

        function acceptEvent(data) {
            const eventId = Number.parseInt(String(data?.event_id || '0'), 10);
            if (eventId <= 0) return true;
            if (seenEventIds.has(eventId)) return false;

            seenEventIds.add(eventId);
            seenEventOrder.push(eventId);
            if (seenEventOrder.length > 500) {
                const expired = seenEventOrder.shift();
                if (expired) seenEventIds.delete(expired);
            }
            eventCursor = Math.max(eventCursor, eventId);
            return true;
        }

        function handlePayload(data) {
            if (!data || typeof data !== 'object' || !acceptEvent(data)) return;

            switch (data?.action) {
                case 'Ping':
                    send('PingSocket:index', { ping: 'Pong' });
                    break;
                case 'Authorized':
                    userUid = String(data.user_uid || '');
                    eventCursor = Math.max(
                        eventCursor,
                        Number.parseInt(String(data.transport_cursor || '0'), 10) || 0
                    );
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
                    send('MessangerSocket:get_dialogs', {});
                    break;
                case 'new_dialog':
                case 'message_edited':
                case 'message_deleted':
                case 'read_update':
                    send('MessangerSocket:get_dialogs', {});
                    break;
                default:
                    break;
            }
        }

        function consumeEnvelope(body) {
            (Array.isArray(body?.events) ? body.events : []).forEach(handlePayload);
            const cursor = Number.parseInt(String(body?.cursor || '0'), 10);
            if (cursor > 0) eventCursor = Math.max(eventCursor, cursor);
        }

        async function ensureCursor() {
            if (cursorReady) return true;
            try {
                const response = await fetch(
                    appPath('/messenger/transport/poll') + '?cursor=latest',
                    {
                        method: 'GET',
                        credentials: 'same-origin',
                        headers: {
                            'Accept': 'application/json',
                            'X-Requested-With': 'XMLHttpRequest'
                        }
                    }
                );
                if (response.status === 401 || response.status === 403) {
                    stopped = true;
                    return false;
                }
                const body = await response.json().catch(() => null);
                if (!response.ok || !body || body.status !== 'ok') {
                    throw new Error(body?.message || 'Cursor bootstrap failed');
                }
                eventCursor = Math.max(0, Number.parseInt(String(body.cursor || '0'), 10) || 0);
                cursorReady = true;
                return true;
            } catch (error) {
                console.warn('Messenger fallback cursor is temporarily unavailable', error);
                return false;
            }
        }

        async function sendHttp(action, data = {}) {
            if (!(await ensureCursor()) || stopped) return false;

            const params = new URLSearchParams();
            params.set('action', String(action || ''));
            params.set('payload', JSON.stringify(data || {}));
            params.set('cursor', String(eventCursor));

            try {
                const response = await fetch(appPath('/messenger/transport/send'), {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers: {
                        'Accept': 'application/json',
                        'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: params.toString()
                });
                if (response.status === 401 || response.status === 403) {
                    stopped = true;
                    stopLongPoll();
                    return false;
                }
                const body = await response.json().catch(() => null);
                if (!response.ok || !body || !Array.isArray(body.events)) {
                    throw new Error(body?.message || 'Messenger fallback send failed');
                }
                consumeEnvelope(body);
                return body.status === 'ok';
            } catch (error) {
                console.warn('Global Messenger fallback send failed', error);
                return false;
            }
        }

        function send(action, data = {}) {
            if (socket && socket.readyState === WebSocket.OPEN) {
                socket.send(JSON.stringify({ action, data }));
                return true;
            }
            void sendHttp(action, data);
            return true;
        }

        function requestState() {
            send('MessangerSocket:get_dialogs', {});
            send('DialogStateSocket:list', {});
        }

        function scheduleReconnect() {
            if (stopped || reconnectTimer || navigator.onLine === false) return;
            const delay = Math.min(MAX_RECONNECT_DELAY, 1000 * (2 ** Math.min(reconnectAttempt, 4)));
            reconnectAttempt += 1;
            reconnectTimer = window.setTimeout(() => {
                reconnectTimer = null;
                void connect();
            }, delay);
        }

        async function freshTicket() {
            const response = await fetch(appPath('/messenger/socket-ticket'), {
                method: 'POST',
                credentials: 'same-origin',
                headers: { 'Accept': 'application/json' }
            });

            if (response.status === 401 || response.status === 403) {
                stopped = true;
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

        function resolveSocketUrl(value) {
            const raw = String(value || '').trim();
            if (raw === '' || !raw.startsWith('/')) return raw;
            const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
            return protocol + '//' + window.location.host + raw;
        }

        function stopLongPoll() {
            longPollActive = false;
            if (longPollAbortController) {
                longPollAbortController.abort();
                longPollAbortController = null;
            }
            longPollFailures = 0;
        }

        async function longPollLoop() {
            while (longPollActive && !stopped) {
                const controller = typeof AbortController === 'function'
                    ? new AbortController()
                    : null;
                longPollAbortController = controller;

                try {
                    const response = await fetch(
                        appPath('/messenger/transport/poll')
                            + '?cursor=' + encodeURIComponent(String(eventCursor)),
                        {
                            method: 'GET',
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest'
                            },
                            signal: controller?.signal
                        }
                    );
                    if (response.status === 401 || response.status === 403) {
                        stopped = true;
                        break;
                    }
                    const body = await response.json().catch(() => null);
                    if (!response.ok || !body || body.status !== 'ok') {
                        throw new Error(body?.message || 'Messenger long-poll failed');
                    }
                    longPollFailures = 0;
                    consumeEnvelope(body);
                } catch (error) {
                    if (!longPollActive || error?.name === 'AbortError') break;
                    longPollFailures += 1;
                    console.warn('Global Messenger long-poll is temporarily unavailable', error);
                    const delay = Math.min(5000, 500 * (2 ** Math.min(longPollFailures, 3)));
                    await new Promise((resolve) => window.setTimeout(resolve, delay));
                } finally {
                    if (longPollAbortController === controller) {
                        longPollAbortController = null;
                    }
                }
            }
        }

        async function startLongPoll() {
            if (longPollActive || stopped || navigator.onLine === false) return;
            if (!(await ensureCursor()) || stopped) {
                scheduleReconnect();
                return;
            }
            longPollActive = true;
            longPollFailures = 0;
            requestState();
            void longPollLoop();
        }

        async function connect() {
            if (
                stopped
                || connecting
                || navigator.onLine === false
                || socket?.readyState === WebSocket.OPEN
                || socket?.readyState === WebSocket.CONNECTING
            ) {
                return;
            }

            if (!(await ensureCursor()) || stopped) {
                await startLongPoll();
                scheduleReconnect();
                return;
            }

            // Keep the compatibility stream alive until WebSocket has actually
            // authorized. That closes the network/handshake gap without losing
            // journaled events.
            await startLongPoll();

            const socketUrl = resolveSocketUrl(String(wspace.socketConfig?.url || ''));
            if (socketUrl === '' || typeof WebSocket !== 'function') {
                return;
            }

            connecting = true;
            try {
                const ticket = await freshTicket();
                if (ticket === '' || stopped) return;

                const separator = socketUrl.includes('?') ? '&' : '?';
                const nextSocket = new WebSocket(
                    socketUrl
                    + separator
                    + 'ticket=' + encodeURIComponent(ticket)
                    + '&cursor=' + encodeURIComponent(String(eventCursor))
                );
                socket = nextSocket;

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
                    handlePayload(data);
                });
                nextSocket.addEventListener('close', () => {
                    if (socket === nextSocket) socket = null;
                    void startLongPoll();
                    scheduleReconnect();
                });
                nextSocket.addEventListener('error', () => {
                    void startLongPoll();
                });
            } catch (error) {
                console.warn('Global Messenger WebSocket is temporarily unavailable', error);
                await startLongPoll();
                scheduleReconnect();
            } finally {
                connecting = false;
            }
        }

        window.addEventListener('online', () => {
            if (reconnectTimer) {
                window.clearTimeout(reconnectTimer);
                reconnectTimer = null;
            }
            void startLongPoll();
            void connect();
        });

        window.addEventListener('offline', () => {
            if (reconnectTimer) {
                window.clearTimeout(reconnectTimer);
                reconnectTimer = null;
            }
            stopLongPoll();
        });

        void connect();
    });
})();
