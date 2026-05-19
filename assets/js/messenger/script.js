/**
 * Messenger Module
 * Глобальный модуль для работы с мессенджером через WebSocket
 */

(function() {
    'use strict';

    function Messenger() {
        this.messages = []; // Массив сообщений
        this.dialogs = []; // Массив диалогов
        this.currentDialog = null;
        this.socket = null;
        this.userId = null;
        this.isInitialized = false;
    }

    /**
     * Инициализация мессенджера
     */
    Messenger.prototype.init = function(socket, userId) {
        this.socket = socket;
        this.userId = userId;
        this.isInitialized = true;
        console.log('Messenger initialized for user:', userId);
    };

    /**
     * Получение списка диалогов
     */
    Messenger.prototype.getDialogs = function() {
        if (!this.isInitialized) {
            console.warn('Messenger not initialized');
            return;
        }
        this.sendMessageToSocket('get_dialogs', {});
    };

    /**
     * Отправка команды в WebSocket
     */
    Messenger.prototype.sendMessageToSocket = function(action, data) {
        if (!this.socket || this.socket.readyState !== WebSocket.OPEN) {
            console.warn('WebSocket connection is not available');
            return;
        }

        let dataSend = {
            action: action,
            data: data
        };
        this.socket.send(JSON.stringify(dataSend));
    };

    /**
     * Отправка сообщения
     */
    Messenger.prototype.sendMessage = function(userId, dialogId, text) {
        let data = {
            from_id: userId,
            chat_id: dialogId,
            text: text,
        };
        this.sendMessageToSocket('send_message', data);
    };

    /**
     * Редактирование сообщения
     */
    Messenger.prototype.editMessage = function(messageId, text) {
        let data = {
            message_id: messageId,
            text: text,
        };
        this.sendMessageToSocket('edit_message', data);
    };

    /**
     * Удаление сообщения
     */
    Messenger.prototype.deleteMessage = function(messageId) {
        let data = {
            message_id: messageId,
        };
        this.sendMessageToSocket('delete_message', data);
    };

    /**
     * Отправка статуса печати
     */
    Messenger.prototype.sendChatAction = function(userId, dialogId, action) {
        let data = {
            from_id: userId,
            chat_id: dialogId,
            action: action || 'typing',
        };
        this.sendMessageToSocket('send_chat_action', data);
    };

    /**
     * Загрузка сообщений диалога
     */
    Messenger.prototype.loadMessages = function(dialogId) {
        this.sendMessageToSocket('get_messages', { dialog_id: dialogId });
    };

    /**
     * Обработчик входящих WebSocket сообщений
     */
    Messenger.prototype.listenWebSocket = function() {
        if (!this.socket) {
            console.warn('No socket connection available');
            return;
        }

        const self = this;

        this.socket.onmessage = function(event) {
            const serverData = JSON.parse(event.data);

            switch (serverData.action) {
                case 'get_messages':
                    self.handleGetMessages(serverData);
                    break;
                case 'get_message':
                    self.handleNewMessage(serverData);
                    break;
                case 'get_dialogs':
                    self.handleGetDialogs(serverData);
                    break;
                case 'user_typing':
                    self.handleUserTyping(serverData);
                    break;
                case 'typing_stop':
                    self.handleTypingStop(serverData);
                    break;
                case 'update_message_status':
                    self.handleMessageStatusUpdate(serverData);
                    break;
                default:
                    console.log('Unknown action:', serverData.action);
            }
        };
    };

    /**
     * Обработка получения списка сообщений
     */
    Messenger.prototype.handleGetMessages = function(data) {
        console.log('Messages received:', data);
        // TODO: Рендеринг сообщений в интерфейсе
        if (typeof window.renderMessages === 'function') {
            window.renderMessages(data.data);
        }
    };

    /**
     * Обработка нового сообщения
     */
    Messenger.prototype.handleNewMessage = function(data) {
        console.log('New message received:', data);
        // TODO: Добавление сообщения в интерфейс
        if (typeof window.appendMessage === 'function') {
            window.appendMessage(data.data);
        }
    };

    /**
     * Обработка получения списка диалогов
     */
    Messenger.prototype.handleGetDialogs = function(data) {
        console.log('Dialogs received:', data);
        // TODO: Рендеринг списка диалогов
        if (typeof window.renderDialogs === 'function') {
            window.renderDialogs(data.data);
        }
    };

    /**
     * Обработка статуса печати пользователя
     */
    Messenger.prototype.handleUserTyping = function(data) {
        console.log('User typing:', data);
        if (typeof window.showTypingIndicator === 'function') {
            window.showTypingIndicator(data.data);
        }
    };

    /**
     * Обработка завершения печати
     */
    Messenger.prototype.handleTypingStop = function(data) {
        console.log('Typing stopped:', data);
        if (typeof window.hideTypingIndicator === 'function') {
            window.hideTypingIndicator(data.data);
        }
    };

    /**
     * Обработка обновления статуса сообщения
     */
    Messenger.prototype.handleMessageStatusUpdate = function(data) {
        console.log('Message status updated:', data);
        if (typeof window.updateMessageStatus === 'function') {
            window.updateMessageStatus(data.data);
        }
    };

    // Экспортируем в глобальную область видимости
    window.MessengerConnect = Messenger;
})();