{literal}
(() => {
    'use strict';

    class MessengerApp {
        constructor(root) {
            this.root = root;
            this.userUid = root.dataset.userUid || '';
            this.userName = root.dataset.userName || 'Вы';
            this.socket = null;
            this.reconnectTimer = null;
            this.reconnectAttempt = 0;
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
            const config = window.wspace?.socketConfig || {};
            if (!config.url || !config.ticket) {
                this.setConnectionState('offline', 'WebSocket не настроен');
                return;
            }

            this.setConnectionState('connecting', this.reconnectAttempt ? 'Переподключение…' : 'Подключение…');

            try {
                const separator = config.url.includes('?') ? '&' : '?';
                this.socket = new WebSocket(`${config.url}${separator}ticket=${encodeURIComponent(config.ticket)}`);
            } catch (error) {
                console.error(error);
                this.scheduleReconnect();
                return;
            }

            this.socket.addEventListener('open', () => {
                this.reconnectAttempt = 0;
            });

            this.socket.addEventListener('message', (event) => this.handleSocketMessage(event));
            this.socket.addEventListener('error', () => this.setConnectionState('offline', 'Ошибка соединения'));
            this.socket.addEventListener('close', () => {
                this.setConnectionState('offline', 'Нет соединения');
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
                    this.setConnectionState('online', 'В сети');
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

        sendEvent(action, data = {}) {
            if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
                this.showToast('Нет соединения с сервером');
                return false;
            }
            this.socket.send(JSON.stringify({ action, data }));
            return true;
        }

        setConnectionState(state, text) {
            if (!this.el.connection) return;
            this.el.connection.dataset.state = state;
            if (this.el.connectionText) this.el.connectionText.textContent = text;
            if (this.el.send) this.el.send.disabled = state !== 'online';
        }

        applyDialogs(dialogs) {
            this.dialogs = dialogs;
            this.dialogMap = new Map(dialogs.map((dialog) => [dialog.uid, dialog]));
            this.renderDialogs();

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
            body.textContent = message.message || '';
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
            input.style.height = 'auto';
            input.style.height = `${Math.min(input.scrollHeight, 132)}px`;
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
