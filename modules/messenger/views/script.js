{literal}
(() => {
    'use strict';

    class MessengerApp {
        constructor(root) {
            this.root = root;
            this.userUid = root.dataset.userUid || '';
            this.userName = root.dataset.userName || 'Вы';
            this.canUseProfile = root.dataset.canUseProfile === '1';
            this.socket = null;
            this.socketAuthorized = false;
            this.sessionUnavailable = false;
            this.transportSuspended = false;
            this.reconnectTimer = null;
            this.reconnectAttempt = 0;
            this.longPollActive = false;
            this.longPollCursor = '';
            this.longPollRevision = null;
            this.longPollActivityCursor = '';
            this.longPollAbortController = null;
            this.longPollGeneration = 0;
            this.longPollRetryTimer = null;
            this.longPollWatchdogTimer = null;
            this.longPollRetryAttempt = 0;
            this.typingTimer = null;
            this.typingSent = false;
            this.pendingOpenUid = null;
            const deepLink = new URLSearchParams(window.location.search);
            this.requestedDialogUid = String(deepLink.get('dialog') || '').trim();
            this.requestedMessageUid = String(deepLink.get('message') || '').trim();
            this.requestedMessageAttempts = 0;
            if (this.requestedDialogUid) this.pendingOpenUid = this.requestedDialogUid;

            this.dialogs = [];
            this.dialogMap = new Map();
            this.dialogSnapshotState = 'loading';
            this.dialogSnapshotTimer = null;
            this.dialogCacheKey = `wspace:messenger-dialogs:v1:${this.userUid || 'anonymous'}`;
            this.currentDialog = null;
            this.messages = [];
            this.readCursors = new Map();
            this.replyTo = null;
            this.editing = null;
            this.composerPending = false;
            this.hasMore = false;

            this.el = {
                connection: document.getElementById('messenger-connection'),
                connectionText: document.getElementById('messenger-connection-text'),
                dialogSearch: document.getElementById('dialog-search'),
                dialogList: document.getElementById('dialog-list'),
                dialogLoading: document.getElementById('dialog-list-loading'),
                dialogEmpty: document.getElementById('dialog-list-empty'),
                dialogEmptyTitle: document.getElementById('dialog-list-empty-title'),
                dialogEmptyText: document.getElementById('dialog-list-empty-text'),
                dialogRetry: document.getElementById('dialog-list-retry'),
                newChatButton: document.getElementById('new-chat-button'),
                newChatDialog: document.getElementById('new-chat-dialog'),
                newChatName: document.getElementById('new-chat-name'),
                contactSearch: document.getElementById('contact-search'),
                contactList: document.getElementById('contact-list'),
                createChatButton: document.getElementById('create-chat-button'),
                chat: document.getElementById('messenger-chat'),
                chatEmpty: document.getElementById('chat-empty-state'),
                chatActive: document.getElementById('chat-active'),
                chatBack: document.getElementById('chat-back-button'),
                chatAvatar: document.getElementById('chat-avatar'),
                chatIdentity: document.getElementById('chat-identity'),
                chatTitle: document.getElementById('chat-title'),
                chatSubtitle: document.getElementById('chat-subtitle'),
                messageScroll: document.getElementById('message-scroll'),
                messageList: document.getElementById('message-list'),
                loadOlder: document.getElementById('load-older-button'),
                typingIndicator: document.getElementById('typing-indicator'),
                typingText: document.getElementById('typing-text'),
                composeContext: document.getElementById('compose-context'),
                composeContextTitle: document.getElementById('compose-context-title'),
                composeContextText: document.getElementById('compose-context-text'),
                composeContextClose: document.getElementById('compose-context-close'),
                input: document.getElementById('message-input'),
                send: document.getElementById('message-send-button')
            };
            this.contactAvatarUrls = this.readContactAvatarUrls();
        }

        init() {
            this.bindEvents();
            this.restoreDialogCache();
            this.renderDialogSnapshotState();
            void this.loadInitialDialogs();
            this.connect();
        }

        bindEvents() {
            this.el.dialogSearch?.addEventListener('input', () => this.renderDialogs());
            this.el.newChatButton?.addEventListener('click', () => this.openNewChatDialog());
            this.el.dialogRetry?.addEventListener('click', () => void this.loadInitialDialogs());
            this.el.contactSearch?.addEventListener('input', () => this.filterContacts());
            this.el.createChatButton?.addEventListener('click', () => this.createChat());
            this.el.chatBack?.addEventListener('click', () => this.root.classList.remove('messenger-app--chat-open'));
            [this.el.chatAvatar, this.el.chatIdentity].forEach((element) => {
                element?.addEventListener('click', () => this.openCurrentProfile());
                element?.addEventListener('keydown', (event) => {
                    if (event.key !== 'Enter' && event.key !== ' ') return;
                    event.preventDefault();
                    this.openCurrentProfile();
                });
            });
            this.el.loadOlder?.addEventListener('click', () => this.loadOlder());
            this.el.send?.addEventListener('click', () => this.submitComposer());
            this.el.composeContextClose?.addEventListener('click', () => this.clearComposeContext());

            this.el.input?.addEventListener('keydown', (event) => {
                if (event.key === 'Enter' && !event.shiftKey) {
                    event.preventDefault();
                    this.submitComposer();
                }
                if (event.key === 'Escape') {
                    this.clearComposeContext();
                }
            });

            this.el.input?.addEventListener('input', () => {
                this.autosizeComposer();
                this.notifyTyping();
            });

            window.addEventListener('focus', () => this.markCurrentRead());
            document.addEventListener('wspace:update-install-start', () => this.suspendTransportForUpdate());
        }

        suspendTransportForUpdate() {
            if (this.transportSuspended) return;
            this.transportSuspended = true;

            if (this.reconnectTimer) {
                window.clearTimeout(this.reconnectTimer);
                this.reconnectTimer = null;
            }
            this.stopLongPoll();
            this.socketAuthorized = false;

            const currentSocket = this.socket;
            this.socket = null;
            try {
                currentSocket?.close();
            } catch (_) {
                // Уже закрытое соединение не требует отдельной обработки.
            }
        }

        connect() {
            if (this.sessionUnavailable || this.transportSuspended) return;
            const wspace = window.wspace = window.wspace || {};
            const runtime = window.wspaceRuntime && typeof window.wspaceRuntime === 'object'
                ? window.wspaceRuntime
                : {};
            const existing = wspace.socketConfig && typeof wspace.socketConfig === 'object'
                ? wspace.socketConfig
                : {};
            const rawUrl = String(
                existing.url
                || runtime.socketUrl
                || this.root.dataset.socketUrl
                || ''
            ).trim();
            const ticket = String(
                existing.ticket
                || runtime.socketTicket
                || this.root.dataset.socketTicket
                || ''
            ).trim();
            const resolveSocketUrl = typeof window.wspaceResolveSocketUrl === 'function'
                ? window.wspaceResolveSocketUrl
                : (value) => {
                    if (!value || !value.startsWith('/')) return value;
                    const protocol = window.location.protocol === 'https:' ? 'wss:' : 'ws:';
                    return `${protocol}//${window.location.host}${value}`;
                };
            const url = resolveSocketUrl(rawUrl);

            // Messenger остаётся автономным, даже если общий runtime bootstrap
            // задержался из-за браузера или кэша.
            wspace.socketConfig = { url, ticket };

            // Long Poll — основной обязательный транспорт. Фоновый WebSocket
            // не должен менять видимый статус, пока Long Poll работает.
            this.startLongPoll();

            if (!url || !ticket) {
                return;
            }

            try {
                const separator = url.includes('?') ? '&' : '?';
                this.socket = new WebSocket(`${url}${separator}ticket=${encodeURIComponent(ticket)}`);
            } catch (error) {
                console.error(error);
                this.startLongPoll();
                this.scheduleReconnect();
                return;
            }

            this.socket.addEventListener('open', () => {
                this.reconnectAttempt = 0;
            });

            this.socket.addEventListener('message', (event) => this.handleSocketMessage(event));
            this.socket.addEventListener('error', () => {
                this.socketAuthorized = false;
                this.startLongPoll();
            });
            this.socket.addEventListener('close', () => {
                this.socketAuthorized = false;
                this.startLongPoll();
                this.scheduleReconnect();
            });
        }

        scheduleReconnect() {
            if (this.sessionUnavailable || this.transportSuspended || this.reconnectTimer) return;
            const delay = Math.min(10000, 1000 * (2 ** Math.min(this.reconnectAttempt, 3)));
            this.reconnectAttempt += 1;
            this.reconnectTimer = window.setTimeout(() => {
                this.reconnectTimer = null;
                this.connect();
            }, delay);
        }

        handleSocketMessage(event) {
            let data;
            try {
                data = JSON.parse(event.data);
            } catch (error) {
                console.warn('Invalid messenger payload', error);
                return;
            }

            switch (data.action) {
                case 'Ping':
                    this.sendEvent('PingSocket:index', { ping: 'Pong' });
                    break;
                case 'Authorized':
                    this.socketAuthorized = true;
                    this.stopLongPoll();
                    this.setConnectionTransport('websocket');
                    this.setConnectionState('online', 'WebSocket');
                    this.sendEvent('MessangerSocket:get_dialogs', {});
                    break;
                case 'get_dialogs':
                    this.applyDialogs(Array.isArray(data.dialogs) ? data.dialogs : []);
                    break;
                case 'get_messages':
                    this.applyMessages(data);
                    break;
                case 'dialog_created':
                    this.pendingOpenUid = data.dialog?.uid || null;
                    this.closeNewChatDialog();
                    this.sendEvent('MessangerSocket:get_dialogs', {});
                    break;
                case 'new_dialog':
                    this.sendEvent('MessangerSocket:get_dialogs', {});
                    break;
                case 'send_message':
                    this.receiveMessage(data);
                    break;
                case 'message_edited':
                    this.receiveEditedMessage(data);
                    break;
                case 'message_deleted':
                    this.receiveDeletedMessage(data);
                    break;
                case 'read_update':
                    this.receiveReadUpdate(data);
                    break;
                case 'sync_required':
                    this.syncDurableState();
                    break;
                case 'user_typing':
                    this.receiveTyping(data, true);
                    break;
                case 'typing_stop':
                    this.receiveTyping(data, false);
                    break;
                case 'error':
                    this.showToast(data.message || 'Ошибка мессенджера');
                    break;
                default:
                    break;
            }
        }

        syncDurableState() {
            this.sendEvent('MessangerSocket:get_dialogs', {});
            this.sendEvent('DialogStateSocket:list', {});
            if (this.currentDialog?.uid) {
                this.sendEvent('MessangerSocket:load', { dialog_uid: this.currentDialog.uid });
                this.sendEvent('ReceiptSocket:list', { dialog_uid: this.currentDialog.uid });
            }
        }

        sendEvent(action, data = {}) {
            if (this.socket && this.socket.readyState === WebSocket.OPEN && this.socketAuthorized) {
                this.socket.send(JSON.stringify({ action, data }));
                return true;
            }

            return this.sendHttpEvent(action, data);
        }

        async sendEventConfirmed(action, data = {}) {
            if (this.socket && this.socket.readyState === WebSocket.OPEN && this.socketAuthorized) {
                this.socket.send(JSON.stringify({ action, data }));
                return true;
            }

            return this.sendHttpEventConfirmed(action, data);
        }

        markSessionUnavailable() {
            if (this.sessionUnavailable) return;
            this.sessionUnavailable = true;
            this.stopLongPoll();
            this.socketAuthorized = false;
            this.setConnectionState('offline', 'Сессия завершена');
            this.showToast('Сессия завершена. Обновите страницу и войдите снова.');
            document.dispatchEvent(new CustomEvent('wspace:messenger-session-unavailable'));
            try {
                this.socket?.close();
            } catch (_) {
                // Уже закрытое соединение не требует отдельной обработки.
            }
        }

        sendHttpEvent(action, data = {}) {
            if (navigator.onLine === false) {
                this.showToast('Нет подключения к интернету');
                return false;
            }

            void this.performHttpEvent(action, data).catch((error) => {
                if (error?.code === 'session_unavailable') return;
                console.warn('Messenger HTTP fallback action failed', error);
                this.showToast('Резервный канал временно недоступен');
            });
            return true;
        }

        async sendHttpEventConfirmed(action, data = {}) {
            if (navigator.onLine === false) {
                this.showToast('Нет подключения к интернету');
                return false;
            }

            try {
                await this.performHttpEvent(action, data);
                return true;
            } catch (error) {
                if (error?.code === 'session_unavailable') return false;
                console.warn('Messenger HTTP fallback action failed', error);
                this.showToast('Резервный канал временно недоступен');
                return false;
            }
        }

        async performHttpEvent(action, data = {}) {
            const body = new URLSearchParams();
            body.set('action', String(action || ''));
            body.set('data', JSON.stringify(data || {}));

            const endpoint = typeof window.wspace?.path === 'function'
                ? window.wspace.path('/messenger/realtime/action')
                : '/messenger/realtime/action';

            const resumeLongPoll = this.pauseLongPollRequest();
            try {
                const csrfToken = String(window.wspace?.security?.getCSRFToken?.() || '').trim();
                const headers = { 'Accept': 'application/json' };
                if (csrfToken) {
                    headers['X-CSRF-Token'] = csrfToken;
                }

                const response = await fetch(endpoint, {
                    method: 'POST',
                    credentials: 'same-origin',
                    headers,
                    body
                });
                if (response.status === 401 || response.status === 403) {
                    this.markSessionUnavailable();
                    const error = new Error('Сессия Messenger завершена');
                    error.code = 'session_unavailable';
                    throw error;
                }
                if (!response.ok) {
                    throw new Error(`HTTP ${response.status}`);
                }

                const payload = await response.json();
                if (payload?.status !== 'ok') {
                    throw new Error(payload?.message || 'Резервный обработчик отклонил действие');
                }

                this.dispatchRealtimeEvents(payload.events);
                return payload;
            } finally {
                if (resumeLongPoll && this.longPollActive && !this.socketAuthorized) {
                    this.resumeLongPoll();
                }
            }
        }

        dispatchRealtimeEvents(events) {
            (Array.isArray(events) ? events : []).forEach((payload) => {
                if (!payload || typeof payload !== 'object') return;
                this.handleSocketMessage({ data: JSON.stringify(payload) });
            });
        }

        startLongPoll() {
            if (this.sessionUnavailable || this.transportSuspended) return;

            this.setConnectionTransport('long-poll');

            if (!this.longPollActive) {
                this.longPollActive = true;
                this.longPollRetryAttempt = 0;
            }

            if (navigator.onLine === false) {
                this.setConnectionState('offline', 'Нет интернета');
                return;
            }

            this.setConnectionState('online', 'Long Poll');

            // Если предыдущий запрос завершился из-за браузерной/proxy-гонки,
            // активный флаг не должен оставлять Messenger без нового poll.
            if (!this.longPollAbortController && !this.longPollRetryTimer) {
                this.resumeLongPoll();
            }
        }

        stopLongPoll() {
            if (!this.longPollActive && !this.longPollAbortController) return;
            this.longPollActive = false;
            this.longPollGeneration += 1;
            this.longPollRetryAttempt = 0;
            if (this.longPollRetryTimer) {
                window.clearTimeout(this.longPollRetryTimer);
                this.longPollRetryTimer = null;
            }
            if (this.longPollWatchdogTimer) {
                window.clearTimeout(this.longPollWatchdogTimer);
                this.longPollWatchdogTimer = null;
            }
            if (this.longPollAbortController) {
                this.longPollAbortController.abort();
                this.longPollAbortController = null;
            }
        }

        pauseLongPollRequest() {
            if (!this.longPollActive) return false;
            this.longPollGeneration += 1;
            if (this.longPollRetryTimer) {
                window.clearTimeout(this.longPollRetryTimer);
                this.longPollRetryTimer = null;
            }
            if (this.longPollWatchdogTimer) {
                window.clearTimeout(this.longPollWatchdogTimer);
                this.longPollWatchdogTimer = null;
            }
            if (this.longPollAbortController) {
                this.longPollAbortController.abort();
                this.longPollAbortController = null;
            }
            return true;
        }

        resumeLongPoll() {
            if (this.sessionUnavailable || this.transportSuspended || !this.longPollActive || this.socketAuthorized || this.longPollAbortController || this.longPollRetryTimer) {
                return;
            }
            if (navigator.onLine === false) {
                this.setConnectionState('offline', 'Нет интернета');
                return;
            }
            const generation = ++this.longPollGeneration;
            void this.runLongPoll(generation);
        }

        longPollRetryDelay() {
            return Math.min(10000, 600 * (2 ** Math.min(this.longPollRetryAttempt, 4)));
        }

        async runLongPoll(generation) {
            while (this.longPollActive && generation === this.longPollGeneration) {
                const query = new URLSearchParams();
                if (this.longPollCursor) query.set('cursor', this.longPollCursor);
                if (Number.isInteger(this.longPollRevision) && this.longPollRevision >= 0) {
                    query.set('revision', String(this.longPollRevision));
                }
                if (this.longPollActivityCursor) {
                    query.set('activity_cursor', this.longPollActivityCursor);
                }
                if (this.currentDialog?.uid) query.set('dialog_uid', this.currentDialog.uid);

                const path = `/messenger/realtime/poll?${query.toString()}`;
                const endpoint = typeof window.wspace?.path === 'function'
                    ? window.wspace.path(path)
                    : path;
                const controller = new AbortController();
                let watchdogExpired = false;
                this.longPollAbortController = controller;
                const watchdogTimer = window.setTimeout(() => {
                    watchdogExpired = true;
                    if (this.longPollAbortController === controller) {
                        controller.abort();
                    }
                }, 32000);
                this.longPollWatchdogTimer = watchdogTimer;

                try {
                    const response = await fetch(endpoint, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'Accept': 'application/json' },
                        signal: controller.signal
                    });
                    if (!this.longPollActive || generation !== this.longPollGeneration) return;
                    if (response.status === 401 || response.status === 403) {
                        this.markSessionUnavailable();
                        return;
                    }
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    if (payload?.status !== 'ok') {
                        throw new Error(payload?.message || 'Long Poll failed');
                    }

                    if (Number.isInteger(Number(payload.revision)) && Number(payload.revision) >= 0) {
                        this.longPollRevision = Number(payload.revision);
                    }
                    if (typeof payload.activity_cursor === 'string' && /^[a-f0-9]{64}$/u.test(payload.activity_cursor)) {
                        this.longPollActivityCursor = payload.activity_cursor;
                    }

                    if (payload.suspended === true) {
                        const retryAfter = Math.max(
                            500,
                            Math.min(10000, Number(payload.retry_after_ms || 3000))
                        );
                        this.setConnectionState('fallback', 'Синхронизация временно приостановлена…');
                        await new Promise((resolve) => {
                            this.longPollRetryTimer = window.setTimeout(resolve, retryAfter);
                        });
                        this.longPollRetryTimer = null;
                        continue;
                    }

                    if (typeof payload.cursor === 'string' && payload.cursor) {
                        this.longPollCursor = payload.cursor;
                    }
                    if (payload.changed) {
                        this.dispatchRealtimeEvents(payload.events);
                    }
                    this.longPollRetryAttempt = 0;
                    this.setConnectionState('online', 'Long Poll');
                } catch (error) {
                    const manuallyAborted = error?.name === 'AbortError' && !watchdogExpired;
                    if (manuallyAborted) return;
                    if (!this.longPollActive || generation !== this.longPollGeneration) return;

                    if (navigator.onLine === false) {
                        this.setConnectionState('offline', 'Нет интернета');
                        return;
                    }

                    this.longPollRetryAttempt += 1;
                    const delay = this.longPollRetryDelay();
                    console.warn('Messenger long poll will reconnect', error);
                    this.setConnectionState('fallback', 'Восстанавливаем синхронизацию…');
                    await new Promise((resolve) => {
                        this.longPollRetryTimer = window.setTimeout(resolve, delay);
                    });
                    this.longPollRetryTimer = null;
                } finally {
                    window.clearTimeout(watchdogTimer);
                    if (this.longPollWatchdogTimer === watchdogTimer) {
                        this.longPollWatchdogTimer = null;
                    }
                    if (this.longPollAbortController === controller) {
                        this.longPollAbortController = null;
                    }
                }
            }
        }

        setConnectionTransport(transport) {
            if (!this.el.connection) return;
            this.el.connection.dataset.transport = transport;
        }

        setConnectionState(state, text) {
            if (!this.el.connection) return;
            this.el.connection.dataset.state = state;
            if (state === 'offline') this.setConnectionTransport('none');
            if (this.el.connectionText) this.el.connectionText.textContent = text;
            if (this.el.send) this.el.send.disabled = state !== 'online' && state !== 'fallback';
        }

        applyDialogs(dialogs) {
            this.dialogSnapshotState = 'loaded';
            if (this.dialogSnapshotTimer !== null) {
                window.clearTimeout(this.dialogSnapshotTimer);
                this.dialogSnapshotTimer = null;
            }
            this.dialogs = dialogs;
            this.dialogMap = new Map(dialogs.map((dialog) => [dialog.uid, dialog]));
            this.storeDialogCache(dialogs);
            this.renderDialogSnapshotState();
            this.renderDialogs();
            document.dispatchEvent(new CustomEvent('wspace:messenger-dialogs', {
                detail: { dialogs: this.dialogs }
            }));

            if (this.currentDialog && this.dialogMap.has(this.currentDialog.uid)) {
                this.currentDialog = this.dialogMap.get(this.currentDialog.uid);
                this.renderChatHeader();
            }

            if (this.pendingOpenUid && this.dialogMap.has(this.pendingOpenUid)) {
                const uid = this.pendingOpenUid;
                this.pendingOpenUid = null;
                this.openDialog(uid);
            }
        }

        async loadInitialDialogs() {
            if (this.sessionUnavailable || this.transportSuspended) return;

            this.dialogSnapshotState = this.dialogs.length > 0 ? 'syncing' : 'loading';
            this.renderDialogSnapshotState();

            if (this.dialogSnapshotTimer !== null) {
                window.clearTimeout(this.dialogSnapshotTimer);
            }
            this.dialogSnapshotTimer = window.setTimeout(() => {
                if (!['loading', 'syncing'].includes(this.dialogSnapshotState)) return;
                this.dialogSnapshotState = this.dialogs.length > 0 ? 'stale' : 'error';
                this.renderDialogSnapshotState();
            }, 7000);

            try {
                await this.performHttpEvent('MessangerSocket:get_dialogs', {});
            } catch (error) {
                if (error?.code === 'session_unavailable') return;
                console.warn('Initial messenger snapshot failed', error);
                if (this.dialogSnapshotState !== 'loaded') {
                    this.dialogSnapshotState = this.dialogs.length > 0 ? 'stale' : 'error';
                    this.renderDialogSnapshotState();
                }
            }
        }

        restoreDialogCache() {
            try {
                const raw = sessionStorage.getItem(this.dialogCacheKey);
                if (!raw) return;
                const parsed = JSON.parse(raw);
                if (!Array.isArray(parsed) || parsed.length === 0) return;
                const dialogs = parsed
                    .filter((dialog) => dialog && typeof dialog === 'object' && typeof dialog.uid === 'string')
                    .slice(0, 200);
                if (dialogs.length === 0) return;
                this.dialogs = dialogs;
                this.dialogMap = new Map(dialogs.map((dialog) => [dialog.uid, dialog]));
                this.dialogSnapshotState = 'syncing';
            } catch (_) {
                try { sessionStorage.removeItem(this.dialogCacheKey); } catch (_) {}
            }
        }

        storeDialogCache(dialogs) {
            try {
                const safe = (Array.isArray(dialogs) ? dialogs : []).slice(0, 200).map((dialog) => ({
                    uid: String(dialog.uid || ''),
                    type: String(dialog.type || ''),
                    title: String(dialog.title || 'Диалог'),
                    avatar: dialog.avatar || null,
                    updated_at: dialog.updated_at || null,
                    unread_count: Number(dialog.unread_count || 0),
                    last_message_preview: null,
                    last_message_at: dialog.last_message_at || null,
                    partner: dialog.partner ? {
                        uid: String(dialog.partner.uid || ''),
                        username: String(dialog.partner.username || ''),
                        firstname: String(dialog.partner.firstname || ''),
                        lastname: String(dialog.partner.lastname || ''),
                        avatar: dialog.partner.avatar || null
                    } : null,
                    participants: []
                })).filter((dialog) => dialog.uid !== '');
                sessionStorage.setItem(this.dialogCacheKey, JSON.stringify(safe));
            } catch (_) {
                // Кэш — только ускоритель интерфейса; Messenger не зависит от него.
            }
        }

        renderDialogSnapshotState() {
            const state = this.dialogSnapshotState;
            const loading = state === 'loading';
            const syncing = state === 'syncing' || state === 'stale';
            const failed = state === 'error';

            if (this.el.dialogLoading) this.el.dialogLoading.hidden = !loading;
            if (this.el.newChatButton) {
                this.el.newChatButton.disabled = state !== 'loaded';
                this.el.newChatButton.title = state === 'loaded'
                    ? 'Новый чат'
                    : 'Список диалогов ещё синхронизируется';
            }

            if (this.el.dialogRetry) this.el.dialogRetry.hidden = !failed && state !== 'stale';

            if (failed && this.el.dialogEmpty) {
                this.el.dialogEmpty.hidden = false;
                if (this.el.dialogEmptyTitle) this.el.dialogEmptyTitle.textContent = 'Не удалось загрузить диалоги';
                if (this.el.dialogEmptyText) this.el.dialogEmptyText.textContent = 'Список чатов не подтверждён сервером. Повторите загрузку.';
            }

            if (syncing && this.dialogs.length > 0) {
                if (this.el.dialogLoading) {
                    this.el.dialogLoading.hidden = false;
                    const label = this.el.dialogLoading.querySelector('.messenger-dialog-loading__label');
                    if (label) label.textContent = state === 'stale'
                        ? 'Показан сохранённый список. Повторяем синхронизацию…'
                        : 'Показываем сохранённый список. Синхронизируем…';
                    this.el.dialogLoading.classList.add('messenger-dialog-loading--compact');
                }
                if (this.el.dialogList) this.el.dialogList.hidden = false;
            } else if (this.el.dialogLoading) {
                this.el.dialogLoading.classList.remove('messenger-dialog-loading--compact');
                const label = this.el.dialogLoading.querySelector('.messenger-dialog-loading__label');
                if (label) label.textContent = 'Загружаем диалоги…';
            }
        }

        renderDialogs() {
            const query = (this.el.dialogSearch?.value || '').trim().toLocaleLowerCase('ru');
            const dialogs = this.dialogs.filter((dialog) => {
                const haystack = `${dialog.title || ''} ${dialog.last_message_preview || ''}`.toLocaleLowerCase('ru');
                return !query || haystack.includes(query);
            });

            this.el.dialogList.replaceChildren();

            const confirmed = this.dialogSnapshotState === 'loaded';
            const showEmptyState = confirmed && this.dialogs.length === 0 && query === '';
            this.el.dialogList.hidden = this.dialogs.length === 0;
            if (this.el.dialogEmpty) {
                this.el.dialogEmpty.hidden = !showEmptyState;
                if (showEmptyState) {
                    if (this.el.dialogEmptyTitle) this.el.dialogEmptyTitle.textContent = 'Диалогов пока нет';
                    if (this.el.dialogEmptyText) this.el.dialogEmptyText.textContent = 'Создайте первый чат с коллегой.';
                    if (this.el.dialogRetry) this.el.dialogRetry.hidden = true;
                }
            }

            dialogs.forEach((dialog) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'messenger-dialog-item';
                if (this.currentDialog?.uid === dialog.uid) {
                    button.classList.add('messenger-dialog-item--active');
                }
                button.addEventListener('click', () => this.openDialog(dialog.uid));

                const avatar = this.createAvatar(
                    dialog.title,
                    'messenger-avatar',
                    dialog.type === 'private' ? dialog.partner : null
                );
                const body = document.createElement('span');
                body.className = 'messenger-dialog-item__body';

                const top = document.createElement('span');
                top.className = 'messenger-dialog-item__top';
                const title = document.createElement('strong');
                title.textContent = dialog.title || 'Диалог';
                const time = document.createElement('time');
                time.textContent = this.formatListTime(dialog.last_message_at || dialog.updated_at);
                top.append(title, time);

                const bottom = document.createElement('span');
                bottom.className = 'messenger-dialog-item__bottom';
                const preview = document.createElement('span');
                preview.className = 'messenger-dialog-item__preview';
                preview.textContent = dialog.last_message_preview || 'Нет сообщений';
                bottom.append(preview);

                const unread = Number(dialog.unread_count || 0);
                if (unread > 0) {
                    const badge = document.createElement('b');
                    badge.className = 'messenger-dialog-item__unread';
                    badge.textContent = unread > 99 ? '99+' : String(unread);
                    bottom.append(badge);
                }

                body.append(top, bottom);
                button.append(avatar, body);
                this.el.dialogList.append(button);
            });
        }

        openDialog(uid) {
            const dialog = this.dialogMap.get(uid);
            if (!dialog) return;

            this.currentDialog = dialog;
            this.messages = [];
            this.replyTo = null;
            this.editing = null;
            this.clearComposeContext();
            this.el.chatEmpty.hidden = true;
            this.el.chatActive.hidden = false;
            this.root.classList.add('messenger-app--chat-open');
            this.renderDialogs();
            this.renderChatHeader();
            this.el.messageList.replaceChildren();
            this.el.loadOlder.hidden = true;
            this.sendEvent('MessangerSocket:load', { dialog_uid: uid });
        }

        renderChatHeader() {
            if (!this.currentDialog) return;
            this.el.chatTitle.textContent = this.currentDialog.title || 'Диалог';
            const profileUser = this.currentDialog.type === 'private' ? this.currentDialog.partner : null;
            this.setAvatar(this.el.chatAvatar, this.currentDialog.title || '?', profileUser);
            this.configureProfileShortcut(profileUser);

            if (this.currentDialog.type === 'private') {
                const online = Boolean(this.currentDialog.partner?.online);
                this.el.chatSubtitle.textContent = online ? 'в сети' : 'не в сети';
                this.el.chatSubtitle.dataset.state = online ? 'online' : 'offline';
            } else {
                const count = Array.isArray(this.currentDialog.participants)
                    ? this.currentDialog.participants.length
                    : 0;
                const online = Number(this.currentDialog.online_count || 0);
                this.el.chatSubtitle.textContent = `${count} участников${online ? `, ${online} онлайн` : ''}`;
                this.el.chatSubtitle.dataset.state = '';
            }
        }

        applyMessages(data) {
            if (!this.currentDialog || data.dialog_uid !== this.currentDialog.uid) return;

            const incoming = Array.isArray(data.messages) ? data.messages : [];
            if (data.dialog) {
                this.currentDialog = { ...this.currentDialog, ...data.dialog };
                this.dialogMap.set(this.currentDialog.uid, this.currentDialog);
                this.renderChatHeader();
            }

            if (data.prepend) {
                const existing = new Set(this.messages.map((message) => message.uid));
                this.messages = [
                    ...incoming.filter((message) => !existing.has(message.uid)),
                    ...this.messages
                ];
                this.renderMessages({ preservePosition: true });
            } else {
                this.messages = incoming;
                this.renderMessages({ scrollBottom: true });
            }

            this.hasMore = Boolean(data.has_more);
            this.el.loadOlder.hidden = !this.hasMore;
            this.markCurrentRead();
            this.focusRequestedMessage();
        }

        renderMessages(options = {}) {
            const oldHeight = this.el.messageScroll.scrollHeight;
            const oldTop = this.el.messageScroll.scrollTop;
            this.el.messageList.replaceChildren();

            let lastDay = '';
            this.messages.forEach((message) => {
                const day = this.dayKey(message.created_at);
                if (day !== lastDay) {
                    lastDay = day;
                    const separator = document.createElement('div');
                    separator.className = 'messenger-day';
                    separator.textContent = this.formatDay(message.created_at);
                    this.el.messageList.append(separator);
                }
                this.el.messageList.append(this.renderMessage(message));
            });

            if (options.preservePosition) {
                const newHeight = this.el.messageScroll.scrollHeight;
                this.el.messageScroll.scrollTop = oldTop + (newHeight - oldHeight);
            } else if (options.scrollBottom) {
                requestAnimationFrame(() => this.scrollToBottom());
            }
        }

        renderMessage(message) {
            const own = message.user?.uid === this.userUid;
            const row = document.createElement('article');
            row.className = `messenger-message ${own ? 'messenger-message--own' : 'messenger-message--other'}`;
            row.dataset.uid = message.uid;
            row.dataset.id = String(message.id || 0);

            const bubble = document.createElement('div');
            bubble.className = 'messenger-message__bubble';

            if (!own && this.currentDialog?.type === 'group') {
                const author = document.createElement('strong');
                author.className = 'messenger-message__author';
                author.textContent = this.displayUser(message.user);
                bubble.append(author);
            }

            if (message.reply) {
                const quote = document.createElement('button');
                quote.type = 'button';
                quote.className = 'messenger-message__reply';
                const quoteName = document.createElement('strong');
                quoteName.textContent = message.reply.user_name || 'Сообщение';
                const quoteText = document.createElement('span');
                quoteText.textContent = this.truncate(message.reply.message || '', 110);
                quote.append(quoteName, quoteText);
                quote.addEventListener('click', () => this.scrollToMessage(message.reply.uid));
                bubble.append(quote);
            }

            const body = document.createElement('div');
            body.className = 'messenger-message__text';
            this.renderMessageText(body, message.message || '');
            bubble.append(body);

            const meta = document.createElement('div');
            meta.className = 'messenger-message__meta';
            if (message.edited_at) {
                const edited = document.createElement('span');
                edited.textContent = 'изменено';
                meta.append(edited);
            }
            const time = document.createElement('time');
            time.textContent = this.formatMessageTime(message.created_at);
            meta.append(time);
            if (own) {
                const status = document.createElement('span');
                status.className = 'messenger-message__status';
                status.textContent = this.isMessageRead(message) ? '✓✓' : '✓';
                meta.append(status);
            }
            bubble.append(meta);

            const actions = document.createElement('div');
            actions.className = 'messenger-message__actions';
            actions.append(
                this.messageAction('Ответить', 'fa-reply', () => this.startReply(message)),
                this.messageAction('Удалить у меня', 'fa-trash-o', () => this.deleteMessage(message, false))
            );
            if (own) {
                actions.append(
                    this.messageAction('Изменить', 'fa-pencil', () => this.startEdit(message)),
                    this.messageAction('Удалить у всех', 'fa-trash', () => this.deleteMessage(message, true))
                );
            }

            const actionsToggle = document.createElement('button');
            actionsToggle.type = 'button';
            actionsToggle.className = 'messenger-message__actions-toggle';
            actionsToggle.title = 'Действия с сообщением';
            actionsToggle.setAttribute('aria-label', 'Показать действия с сообщением');
            actionsToggle.setAttribute('aria-expanded', 'false');

            const actionsId = `message-actions-${String(message.uid || message.id || 'item').replace(/[^a-zA-Z0-9_-]/g, '-')}`;
            actions.id = actionsId;
            actionsToggle.setAttribute('aria-controls', actionsId);

            const toggleIcon = document.createElement('i');
            toggleIcon.className = 'fa fa-ellipsis-h';
            toggleIcon.setAttribute('aria-hidden', 'true');
            actionsToggle.append(toggleIcon);

            actionsToggle.addEventListener('click', (event) => {
                event.stopPropagation();
                const opened = row.classList.toggle('messenger-message--actions-open');
                actionsToggle.setAttribute('aria-expanded', opened ? 'true' : 'false');
                actionsToggle.setAttribute(
                    'aria-label',
                    opened ? 'Скрыть действия с сообщением' : 'Показать действия с сообщением'
                );
            });

            row.append(bubble, actionsToggle, actions);
            return row;
        }

        messageAction(label, icon, handler) {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'messenger-message__action';
            button.title = label;
            button.setAttribute('aria-label', label);
            const i = document.createElement('i');
            i.className = `fa ${icon}`;
            i.setAttribute('aria-hidden', 'true');
            button.append(i);
            button.addEventListener('click', handler);
            return button;
        }

        receiveMessage(data) {
            const message = data.message;
            if (!message) return;

            if (this.currentDialog?.uid === data.dialog_uid) {
                if (!this.messages.some((item) => item.uid === message.uid)) {
                    this.messages.push(message);
                }
                this.renderMessages({ scrollBottom: true });
                if (message.user?.uid !== this.userUid) {
                    this.markCurrentRead();
                }
            }

            this.sendEvent('MessangerSocket:get_dialogs', {});
        }

        receiveEditedMessage(data) {
            if (!data.message) return;
            const index = this.messages.findIndex((message) => message.uid === data.message.uid);
            if (index !== -1) {
                this.messages[index] = data.message;
                this.renderMessages();
            }
            this.sendEvent('MessangerSocket:get_dialogs', {});
        }

        receiveDeletedMessage(data) {
            const index = this.messages.findIndex((message) => message.uid === data.message_uid);
            if (index !== -1) {
                this.messages.splice(index, 1);
                this.renderMessages();
            }
            if (this.replyTo?.uid === data.message_uid || this.editing?.uid === data.message_uid) {
                this.clearComposeContext();
            }
            this.sendEvent('MessangerSocket:get_dialogs', {});
        }

        receiveReadUpdate(data) {
            if (!data.user_uid) return;
            this.readCursors.set(data.user_uid, Number(data.last_read_message_id || 0));
            if (this.currentDialog?.uid === data.dialog_uid) {
                this.renderMessages();
            }
            this.sendEvent('MessangerSocket:get_dialogs', {});
        }

        receiveTyping(data, typing) {
            if (!this.currentDialog || data.dialog_uid !== this.currentDialog.uid || data.user_uid === this.userUid) {
                return;
            }
            this.el.typingIndicator.hidden = !typing;
            if (typing) {
                const participant = (this.currentDialog.participants || []).find((item) => item.uid === data.user_uid);
                this.el.typingText.textContent = `${this.displayUser(participant)} печатает…`;
            }
        }

        startReply(message) {
            this.replyTo = message;
            this.editing = null;
            this.el.composeContextTitle.textContent = `Ответ: ${this.displayUser(message.user)}`;
            this.el.composeContextText.textContent = this.truncate(message.message || '', 120);
            this.el.composeContext.hidden = false;
            this.el.input.focus();
        }

        startEdit(message) {
            if (message.user?.uid !== this.userUid) return;
            this.editing = message;
            this.replyTo = null;
            this.el.composeContextTitle.textContent = 'Редактирование сообщения';
            this.el.composeContextText.textContent = this.truncate(message.message || '', 120);
            this.el.composeContext.hidden = false;
            this.el.input.value = message.message || '';
            this.autosizeComposer();
            this.el.input.focus();
            this.el.input.setSelectionRange(this.el.input.value.length, this.el.input.value.length);
        }

        clearComposeContext() {
            this.replyTo = null;
            this.editing = null;
            if (this.el.composeContext) this.el.composeContext.hidden = true;
            if (this.el.composeContextTitle) this.el.composeContextTitle.textContent = '';
            if (this.el.composeContextText) this.el.composeContextText.textContent = '';
        }

        async submitComposer() {
            if (!this.currentDialog || this.composerPending) return;

            const draftValue = this.el.input.value || '';
            const text = draftValue.trim();
            if (!text) return;

            const submittedEditing = this.editing;
            const submittedReply = this.replyTo;
            this.composerPending = true;
            if (this.el.send) this.el.send.disabled = true;

            try {
                let sent = false;
                if (submittedEditing) {
                    sent = await this.sendEventConfirmed('MessangerSocket:edit_message', {
                        dialog_uid: this.currentDialog.uid,
                        message_uid: submittedEditing.uid,
                        new_text: text
                    });
                } else {
                    sent = await this.sendEventConfirmed('MessangerSocket:message_send', {
                        dialog_uid: this.currentDialog.uid,
                        message: text,
                        reply_to_uid: submittedReply?.uid || null
                    });
                }

                if (!sent) return;

                // Очищаем только тот черновик, который действительно был
                // подтверждён. Текст, набранный во время ожидания HTTP-ответа,
                // и новый reply/edit context не должны исчезнуть из-за позднего ответа.
                if (this.el.input.value === draftValue) {
                    this.el.input.value = '';
                    this.autosizeComposer();
                    this.stopTyping();

                    if (this.editing === submittedEditing && this.replyTo === submittedReply) {
                        this.clearComposeContext();
                    }
                }
            } finally {
                this.composerPending = false;
                if (this.el.send) this.el.send.disabled = false;
            }
        }

        deleteMessage(message, forAll) {
            const question = forAll
                ? 'Удалить это сообщение у всех участников?'
                : 'Удалить это сообщение только у вас?';
            if (!window.confirm(question)) return;

            this.sendEvent('MessangerSocket:delete_message', {
                message_uid: message.uid,
                for_all: forAll
            });
        }

        notifyTyping() {
            if (!this.currentDialog) return;
            if (!this.typingSent) {
                this.typingSent = this.sendEvent('MessangerSocket:user_typing', {
                    dialog_uid: this.currentDialog.uid
                });
            }
            window.clearTimeout(this.typingTimer);
            this.typingTimer = window.setTimeout(() => this.stopTyping(), 1400);
        }

        stopTyping() {
            window.clearTimeout(this.typingTimer);
            if (this.typingSent && this.currentDialog) {
                this.sendEvent('MessangerSocket:stop_typing', {
                    dialog_uid: this.currentDialog.uid
                });
            }
            this.typingSent = false;
        }

        markCurrentRead() {
            if (!this.currentDialog || document.hidden || this.messages.length === 0) return;
            const last = this.messages[this.messages.length - 1];
            if (last.user?.uid === this.userUid) return;
            this.sendEvent('MessangerSocket:mark_read', {
                dialog_uid: this.currentDialog.uid,
                message_uid: last.uid
            });
        }

        loadOlder() {
            if (!this.currentDialog || !this.hasMore || this.messages.length === 0) return;
            this.sendEvent('MessangerSocket:load', {
                dialog_uid: this.currentDialog.uid,
                before_id: Number(this.messages[0].id)
            });
        }

        openNewChatDialog() {
            if (this.dialogSnapshotState !== 'loaded') {
                this.showToast('Сначала дождитесь загрузки существующих диалогов');
                void this.loadInitialDialogs();
                return;
            }
            if (!this.el.newChatDialog) return;
            this.el.newChatName.value = '';
            this.el.contactSearch.value = '';
            this.filterContacts();
            this.el.newChatDialog.querySelectorAll('.messenger-contact__checkbox').forEach((input) => {
                input.checked = false;
            });
            this.el.newChatDialog.showModal();
        }

        closeNewChatDialog() {
            if (this.el.newChatDialog?.open) this.el.newChatDialog.close();
        }

        filterContacts() {
            const query = (this.el.contactSearch?.value || '').trim().toLocaleLowerCase('ru');
            this.el.contactList?.querySelectorAll('.messenger-contact').forEach((contact) => {
                const value = (contact.dataset.contactSearch || '').toLocaleLowerCase('ru');
                contact.hidden = Boolean(query) && !value.includes(query);
            });
        }

        createChat() {
            const selected = Array.from(
                this.el.newChatDialog.querySelectorAll('.messenger-contact__checkbox:checked')
            ).map((input) => input.value);

            if (selected.length === 0) {
                this.showToast('Выберите хотя бы одного участника');
                return;
            }

            const name = (this.el.newChatName.value || '').trim();
            const type = selected.length === 1 && !name ? 'private' : 'group';
            this.sendEvent('MessangerSocket:create_dialog', {
                type,
                participants: selected,
                name: name || null
            });
        }

        isMessageRead(message) {
            if (!this.currentDialog || message.user?.uid !== this.userUid) return false;
            const others = (this.currentDialog.participants || []).filter((member) => member.uid !== this.userUid);
            return others.some((member) => Number(this.readCursors.get(member.uid) || 0) >= Number(message.id));
        }

        focusRequestedMessage() {
            if (!this.requestedMessageUid || !this.currentDialog) return;
            if (this.requestedDialogUid && this.currentDialog.uid !== this.requestedDialogUid) return;

            const found = this.messages.some((message) => message.uid === this.requestedMessageUid);
            if (found) {
                const uid = this.requestedMessageUid;
                this.requestedMessageUid = '';
                this.requestedMessageAttempts = 0;
                requestAnimationFrame(() => this.scrollToMessage(uid));
                return;
            }

            if (this.hasMore && this.messages.length > 0 && this.requestedMessageAttempts < 8) {
                this.requestedMessageAttempts += 1;
                this.sendEvent('MessangerSocket:load', {
                    dialog_uid: this.currentDialog.uid,
                    before_id: Number(this.messages[0].id)
                });
                return;
            }

            this.requestedMessageUid = '';
            this.requestedMessageAttempts = 0;
            this.showToast('Исходное сообщение больше недоступно');
        }

        scrollToMessage(uid) {
            const node = Array.from(this.el.messageList.querySelectorAll('.messenger-message'))
                .find((element) => element.dataset.uid === uid);
            if (!node) return;
            node.scrollIntoView({ behavior: 'smooth', block: 'center' });
            node.classList.add('messenger-message--highlight');
            window.setTimeout(() => node.classList.remove('messenger-message--highlight'), 1200);
        }

        scrollToBottom() {
            this.el.messageScroll.scrollTop = this.el.messageScroll.scrollHeight;
        }

        autosizeComposer() {
            const input = this.el.input;
            if (!input) return;
            const maxHeight = 120;
            input.style.height = 'auto';
            input.style.height = `${Math.min(input.scrollHeight, maxHeight)}px`;
            input.style.overflowY = input.scrollHeight > maxHeight ? 'auto' : 'hidden';
        }

        renderMessageText(container, value) {
            const text = String(value || '');
            const urlPattern = /https?:\/\/[^\s<>"']+/giu;
            let offset = 0;
            for (const match of text.matchAll(urlPattern)) {
                const index = Number(match.index || 0);
                if (index > offset) container.append(document.createTextNode(text.slice(offset, index)));

                let urlText = match[0];
                let trailing = '';
                while (/[),.!?;:]$/.test(urlText)) {
                    trailing = urlText.slice(-1) + trailing;
                    urlText = urlText.slice(0, -1);
                }

                try {
                    const url = new URL(urlText);
                    if (url.protocol === 'http:' || url.protocol === 'https:') {
                        const link = document.createElement('a');
                        link.href = url.href;
                        link.target = '_blank';
                        link.rel = 'noopener noreferrer';
                        link.textContent = urlText;
                        container.append(link);
                    } else {
                        container.append(document.createTextNode(urlText));
                    }
                } catch (_) {
                    container.append(document.createTextNode(urlText));
                }
                if (trailing) container.append(document.createTextNode(trailing));
                offset = index + match[0].length;
            }
            if (offset < text.length) container.append(document.createTextNode(text.slice(offset)));
        }

        createAvatar(title, className, user = null) {
            const avatar = document.createElement('span');
            avatar.className = className;
            this.setAvatar(avatar, title, user);
            return avatar;
        }

        renderAvatarFallback(element, title) {
            if (!element) return;
            element.replaceChildren();
            const value = (title || '?').trim();
            element.textContent = value ? Array.from(value)[0].toLocaleUpperCase('ru') : '?';
        }

        setAvatar(element, title, user = null) {
            if (!element) return;
            const avatarUrl = this.profileAvatarUrl(user);
            if (!avatarUrl) {
                this.renderAvatarFallback(element, title);
                return;
            }

            element.replaceChildren();
            const image = document.createElement('img');
            image.src = avatarUrl;
            image.alt = '';
            image.loading = 'lazy';
            image.addEventListener('error', () => this.renderAvatarFallback(element, title), { once: true });
            element.append(image);
        }

        readContactAvatarUrls() {
            const avatars = new Map();
            if (typeof document?.querySelectorAll !== 'function') return avatars;

            document.querySelectorAll('[data-contact-uid][data-contact-avatar-url]').forEach((element) => {
                const uid = String(element.dataset.contactUid || '').trim();
                const url = String(element.dataset.contactAvatarUrl || '').trim();
                if (uid && url && !avatars.has(uid)) avatars.set(uid, url);
            });
            return avatars;
        }

        profileAvatarUrl(user) {
            if (!this.canUseProfile) return '';

            const uid = String(user?.uid || '').trim();
            if (!uid) return '';

            const storedAvatar = String(user?.avatar || '').trim();
            if (storedAvatar && storedAvatar !== 'default_img') {
                const path = `/profile/avatar/${encodeURIComponent(uid)}`;
                return typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;
            }

            const contactAvatar = this.contactAvatarUrls.get(uid);
            if (contactAvatar) return contactAvatar;

            const defaultPath = '/assets/img/default_avatar.png';
            return typeof window.wspace?.path === 'function' ? window.wspace.path(defaultPath) : defaultPath;
        }

        configureProfileShortcut(user) {
            const active = this.canUseProfile && Boolean(user?.uid);
            [this.el.chatAvatar, this.el.chatIdentity].forEach((element) => {
                if (!element) return;
                element.classList.toggle('messenger-profile-shortcut', active);
                if (active) {
                    element.setAttribute('role', 'link');
                    element.setAttribute('tabindex', '0');
                    element.setAttribute('title', 'Открыть профиль пользователя');
                    element.dataset.profileUid = String(user.uid);
                    return;
                }
                element.removeAttribute('role');
                element.removeAttribute('tabindex');
                element.removeAttribute('title');
                delete element.dataset.profileUid;
            });
        }

        openCurrentProfile() {
            const uid = String(this.currentDialog?.partner?.uid || '').trim();
            if (!this.canUseProfile || !uid || this.currentDialog?.type !== 'private') return;
            const path = `/profile/user/${encodeURIComponent(uid)}`;
            const target = typeof window.wspace?.path === 'function' ? window.wspace.path(path) : path;
            window.location.assign(target);
        }

        displayUser(user) {
            if (!user) return 'Пользователь';
            const name = `${user.firstname || ''} ${user.lastname || ''}`.trim();
            return name || user.username || 'Пользователь';
        }

        truncate(value, length) {
            const text = String(value || '').replace(/\s+/g, ' ').trim();
            return text.length > length ? `${text.slice(0, length - 1)}…` : text;
        }

        parseDate(value) {
            if (!value) return null;
            const normalized = String(value).replace(' ', 'T');
            const date = new Date(normalized);
            return Number.isNaN(date.getTime()) ? null : date;
        }

        formatMessageTime(value) {
            const date = this.parseDate(value);
            return date ? new Intl.DateTimeFormat('ru-RU', { hour: '2-digit', minute: '2-digit' }).format(date) : '';
        }

        formatListTime(value) {
            const date = this.parseDate(value);
            if (!date) return '';
            const today = new Date();
            if (date.toDateString() === today.toDateString()) return this.formatMessageTime(value);
            return new Intl.DateTimeFormat('ru-RU', { day: '2-digit', month: '2-digit' }).format(date);
        }

        dayKey(value) {
            const date = this.parseDate(value);
            return date ? `${date.getFullYear()}-${date.getMonth()}-${date.getDate()}` : '';
        }

        formatDay(value) {
            const date = this.parseDate(value);
            if (!date) return '';
            const today = new Date();
            const yesterday = new Date();
            yesterday.setDate(today.getDate() - 1);
            if (date.toDateString() === today.toDateString()) return 'Сегодня';
            if (date.toDateString() === yesterday.toDateString()) return 'Вчера';
            return new Intl.DateTimeFormat('ru-RU', { day: 'numeric', month: 'long', year: 'numeric' }).format(date);
        }

        showToast(message) {
            let toast = document.getElementById('messenger-toast');
            if (!toast) {
                toast = document.createElement('div');
                toast.id = 'messenger-toast';
                toast.className = 'messenger-toast';
                document.body.append(toast);
            }
            toast.textContent = message;
            toast.classList.add('messenger-toast--visible');
            window.clearTimeout(this.toastTimer);
            this.toastTimer = window.setTimeout(() => toast.classList.remove('messenger-toast--visible'), 3200);
        }
    }

    document.addEventListener('DOMContentLoaded', () => {
        const root = document.getElementById('messenger-app');
        if (!root) return;
        const app = new MessengerApp(root);
        window.wspace.messenger = app;
        app.init();
    });
})();
{/literal}
