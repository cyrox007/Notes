{literal}
(() => {
    'use strict';

    class MessengerApp {
        constructor(root) {
            this.root = root;
            this.userUid = root.dataset.userUid || '';
            this.userName = root.dataset.userName || 'Вы';
            this.socket = null;
            this.socketAuthorized = false;
            this.reconnectTimer = null;
            this.reconnectAttempt = 0;
            this.longPollActive = false;
            this.longPollCursor = '';
            this.longPollAbortController = null;
            this.longPollGeneration = 0;
            this.longPollRetryTimer = null;
            this.longPollFallbackTimer = null;
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
            this.currentDialog = null;
            this.messages = [];
            this.readCursors = new Map();
            this.replyTo = null;
            this.editing = null;
            this.hasMore = false;

            this.el = {
                connection: document.getElementById('messenger-connection'),
                connectionText: document.getElementById('messenger-connection-text'),
                dialogSearch: document.getElementById('dialog-search'),
                dialogList: document.getElementById('dialog-list'),
                dialogEmpty: document.getElementById('dialog-list-empty'),
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
        }

        init() {
            this.bindEvents();
            this.connect();
        }

        bindEvents() {
            this.el.dialogSearch?.addEventListener('input', () => this.renderDialogs());
            this.el.newChatButton?.addEventListener('click', () => this.openNewChatDialog());
            this.el.contactSearch?.addEventListener('input', () => this.filterContacts());
            this.el.createChatButton?.addEventListener('click', () => this.createChat());
            this.el.chatBack?.addEventListener('click', () => this.root.classList.remove('messenger-app--chat-open'));
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
        }

        connect() {
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

            // Messenger must stay self-contained even when the shared deferred
            // runtime bootstrap is delayed or blocked by a browser/cache race.
            wspace.socketConfig = { url, ticket };

            if (!url || !ticket) {
                this.startLongPoll('WebSocket не настроен');
                return;
            }

            this.setConnectionState(
                this.longPollActive ? 'fallback' : 'connecting',
                this.longPollActive
                    ? 'Long Poll · WebSocket переподключается'
                    : (this.reconnectAttempt ? 'Переподключение…' : 'Подключение…')
            );

            try {
                const separator = url.includes('?') ? '&' : '?';
                this.socket = new WebSocket(`${url}${separator}ticket=${encodeURIComponent(ticket)}`);
            } catch (error) {
                console.error(error);
                this.scheduleLongPollFallback('WebSocket недоступен');
                this.scheduleReconnect();
                return;
            }

            this.socket.addEventListener('open', () => {
                this.reconnectAttempt = 0;
            });

            this.socket.addEventListener('message', (event) => this.handleSocketMessage(event));
            this.socket.addEventListener('error', () => {
                this.socketAuthorized = false;
                this.scheduleLongPollFallback('WebSocket недоступен');
            });
            this.socket.addEventListener('close', () => {
                this.socketAuthorized = false;
                this.scheduleLongPollFallback('WebSocket отключён');
                this.scheduleReconnect();
            });
        }

        scheduleReconnect() {
            if (this.reconnectTimer) return;
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
                    this.setConnectionState('online', 'WebSocket · в сети');
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

        sendHttpEvent(action, data = {}) {
            if (navigator.onLine === false) {
                this.showToast('Нет подключения к интернету');
                return false;
            }

            const body = new URLSearchParams();
            body.set('action', String(action || ''));
            body.set('data', JSON.stringify(data || {}));

            const endpoint = typeof window.wspace?.path === 'function'
                ? window.wspace.path('/messenger/realtime/action')
                : '/messenger/realtime/action';

            void (async () => {
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
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }
                    const payload = await response.json();
                    if (payload?.status !== 'ok') {
                        throw new Error(payload?.message || 'Long Poll action failed');
                    }
                    this.dispatchRealtimeEvents(payload.events);
                } catch (error) {
                    console.warn('Messenger HTTP fallback action failed', error);
                    this.showToast('Резервный канал временно недоступен');
                } finally {
                    if (resumeLongPoll && this.longPollActive && !this.socketAuthorized) {
                        this.resumeLongPoll();
                    }
                }
            })();

            return true;
        }

        dispatchRealtimeEvents(events) {
            (Array.isArray(events) ? events : []).forEach((payload) => {
                if (!payload || typeof payload !== 'object') return;
                this.handleSocketMessage({ data: JSON.stringify(payload) });
            });
        }

        scheduleLongPollFallback(reason = '', delay = 1500) {
            if (this.socketAuthorized || this.longPollActive || this.longPollFallbackTimer) {
                return;
            }

            this.longPollFallbackTimer = window.setTimeout(() => {
                this.longPollFallbackTimer = null;
                if (this.socketAuthorized) return;
                this.startLongPoll(reason);
            }, Math.max(0, delay));
        }

        startLongPoll(reason = '') {
            if (this.longPollFallbackTimer) {
                window.clearTimeout(this.longPollFallbackTimer);
                this.longPollFallbackTimer = null;
            }
            if (navigator.onLine === false) {
                this.setConnectionState('offline', 'Нет интернета');
                return;
            }
            if (this.longPollActive) {
                this.setConnectionState('fallback', 'Long Poll · резервный канал');
                return;
            }

            this.longPollActive = true;
            this.longPollCursor = '';
            const generation = ++this.longPollGeneration;
            this.setConnectionState(
                'fallback',
                reason ? `Long Poll · ${reason}` : 'Long Poll · резервный канал'
            );
            void this.runLongPoll(generation);
        }

        stopLongPoll() {
            if (this.longPollFallbackTimer) {
                window.clearTimeout(this.longPollFallbackTimer);
                this.longPollFallbackTimer = null;
            }
            if (!this.longPollActive && !this.longPollAbortController) return;
            this.longPollActive = false;
            this.longPollGeneration += 1;
            this.longPollCursor = '';
            if (this.longPollRetryTimer) {
                window.clearTimeout(this.longPollRetryTimer);
                this.longPollRetryTimer = null;
            }
            if (this.longPollAbortController) {
                this.longPollAbortController.abort();
                this.longPollAbortController = null;
            }
        }

        pauseLongPollRequest() {
            if (!this.longPollActive) return false;
            this.longPollGeneration += 1;
            if (this.longPollAbortController) {
                this.longPollAbortController.abort();
                this.longPollAbortController = null;
            }
            return true;
        }

        resumeLongPoll() {
            if (!this.longPollActive || this.socketAuthorized || this.longPollAbortController) {
                return;
            }
            const generation = ++this.longPollGeneration;
            void this.runLongPoll(generation);
        }

        async runLongPoll(generation) {
            while (this.longPollActive && generation === this.longPollGeneration) {
                const query = new URLSearchParams();
                if (this.longPollCursor) query.set('cursor', this.longPollCursor);
                if (this.currentDialog?.uid) query.set('dialog_uid', this.currentDialog.uid);

                const path = `/messenger/realtime/poll?${query.toString()}`;
                const endpoint = typeof window.wspace?.path === 'function'
                    ? window.wspace.path(path)
                    : path;
                const controller = new AbortController();
                this.longPollAbortController = controller;

                try {
                    const response = await fetch(endpoint, {
                        method: 'GET',
                        credentials: 'same-origin',
                        cache: 'no-store',
                        headers: { 'Accept': 'application/json' },
                        signal: controller.signal
                    });
                    if (!this.longPollActive || generation !== this.longPollGeneration) return;
                    if (!response.ok) {
                        throw new Error(`HTTP ${response.status}`);
                    }

                    const payload = await response.json();
                    if (payload?.status !== 'ok') {
                        throw new Error(payload?.message || 'Long Poll failed');
                    }
                    if (typeof payload.cursor === 'string' && payload.cursor) {
                        this.longPollCursor = payload.cursor;
                    }
                    if (payload.changed) {
                        this.dispatchRealtimeEvents(payload.events);
                    }
                    this.setConnectionState('fallback', 'Long Poll · резервный канал');
                } catch (error) {
                    if (error?.name === 'AbortError') return;
                    if (!this.longPollActive || generation !== this.longPollGeneration) return;
                    console.warn('Messenger long poll failed', error);
                    this.setConnectionState('offline', 'Резервный канал недоступен');
                    await new Promise((resolve) => {
                        this.longPollRetryTimer = window.setTimeout(resolve, 1800);
                    });
                    this.longPollRetryTimer = null;
                } finally {
                    if (this.longPollAbortController === controller) {
                        this.longPollAbortController = null;
                    }
                }
            }
        }

        setConnectionState(state, text) {
            if (!this.el.connection) return;
            this.el.connection.dataset.state = state;
            if (this.el.connectionText) this.el.connectionText.textContent = text;
            if (this.el.send) this.el.send.disabled = state !== 'online' && state !== 'fallback';
        }

        applyDialogs(dialogs) {
            this.dialogs = dialogs;
            this.dialogMap = new Map(dialogs.map((dialog) => [dialog.uid, dialog]));
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

        renderDialogs() {
            const query = (this.el.dialogSearch?.value || '').trim().toLocaleLowerCase('ru');
            const dialogs = this.dialogs.filter((dialog) => {
                const haystack = `${dialog.title || ''} ${dialog.last_message_preview || ''}`.toLocaleLowerCase('ru');
                return !query || haystack.includes(query);
            });

            this.el.dialogList.replaceChildren();
            if (this.el.dialogEmpty) this.el.dialogEmpty.hidden = dialogs.length !== 0 || query !== '';

            dialogs.forEach((dialog) => {
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'messenger-dialog-item';
                if (this.currentDialog?.uid === dialog.uid) {
                    button.classList.add('messenger-dialog-item--active');
                }
                button.addEventListener('click', () => this.openDialog(dialog.uid));

                const avatar = this.createAvatar(dialog.title, 'messenger-avatar');
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
            this.setAvatar(this.el.chatAvatar, this.currentDialog.title || '?');

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

            row.append(actions, bubble);
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

        submitComposer() {
            if (!this.currentDialog) return;
            const text = (this.el.input.value || '').trim();
            if (!text) return;

            let sent = false;
            if (this.editing) {
                sent = this.sendEvent('MessangerSocket:edit_message', {
                    dialog_uid: this.currentDialog.uid,
                    message_uid: this.editing.uid,
                    new_text: text
                });
            } else {
                sent = this.sendEvent('MessangerSocket:message_send', {
                    dialog_uid: this.currentDialog.uid,
                    message: text,
                    reply_to_uid: this.replyTo?.uid || null
                });
            }

            if (!sent) return;
            this.el.input.value = '';
            this.autosizeComposer();
            this.stopTyping();
            this.clearComposeContext();
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

        createAvatar(title, className) {
            const avatar = document.createElement('span');
            avatar.className = className;
            this.setAvatar(avatar, title);
            return avatar;
        }

        setAvatar(element, title) {
            if (!element) return;
            const value = (title || '?').trim();
            element.textContent = value ? Array.from(value)[0].toLocaleUpperCase('ru') : '?';
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
