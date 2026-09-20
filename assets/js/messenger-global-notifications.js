(() => {
    'use strict';

    const MAX_RECONNECT_DELAY = 10000;
    const PREVIEW_LIMIT = 120;

    document.addEventListener('DOMContentLoaded', () => {
        const wspace = window.wspace = window.wspace || {};
        const link = document.querySelector('.sidebar__menu-link[data-nav-key="messenger"]');
        const badge = document.getElementById('messenger-unread-badge');
        if (!link || !badge) return;

        const dialogs = new Map();
        const states = new Map();
        let socket = null;
        let connecting = false;
        let reconnectTimer = null;
        let reconnectAttempt = 0;
        let stopped = false;
        let userUid = '';

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

        // The Messenger page already owns a full WebSocket client. It publishes
        // dialog updates through wspace:messenger-dialogs, so opening a second
        // socket here would duplicate realtime traffic and notifications.
        if (document.getElementById('messenger-app')) return;

        const socketUrl = String(wspace.socketConfig?.url || '');
        if (socketUrl === '') return;

        function send(action, data = {}) {
            if (!socket || socket.readyState !== WebSocket.OPEN) return false;
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

            if (toast) {
                toast.classList.add('wspace-toast--message');
                toast.title = 'Открыть Мессенджер';
                toast.addEventListener('click', () => {
                    window.location.href = link.href;
                }, { once: true });
            }
        }

        function scheduleReconnect() {
            if (stopped || reconnectTimer || navigator.onLine === false) return;
            const delay = Math.min(MAX_RECONNECT_DELAY, 1000 * (2 ** Math.min(reconnectAttempt, 3)));
            reconnectAttempt += 1;
            reconnectTimer = window.setTimeout(() => {
                reconnectTimer = null;
                connect();
            }, delay);
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

                    switch (data?.action) {
                        case 'Ping':
                            send('PingSocket:index', { ping: 'Pong' });
                            break;
                        case 'Authorized':
                            userUid = String(data.user_uid || '');
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
                });
                nextSocket.addEventListener('close', () => {
                    if (socket === nextSocket) socket = null;
                    scheduleReconnect();
                });
                nextSocket.addEventListener('error', () => {
                    // close will schedule the retry; keep console noise out of normal offline transitions.
                });
            } catch (error) {
                console.warn('Global Messenger notifications are temporarily unavailable', error);
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
            connect();
        });

        window.addEventListener('offline', () => {
            if (reconnectTimer) {
                window.clearTimeout(reconnectTimer);
                reconnectTimer = null;
            }
        });

        connect();
    });
})();
