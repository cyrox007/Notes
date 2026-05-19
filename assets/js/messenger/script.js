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
        this.typingTimeout = null;
        this.reconnectAttempts = 0;
        this.maxReconnectAttempts = 5;
        this.reconnectDelay = 3000;
    }

    /**
     * Инициализация мессенджера
     */
    Messenger.prototype.init = function(socket, userId) {
        this.socket = socket;
        this.userId = userId;
        this.isInitialized = true;
        console.log('Messenger initialized for user:', userId);
        this.listenWebSocket();
    };

    /**
     * Подключение к WebSocket серверу
     */
    Messenger.prototype.connect = function(wsUrl) {
        const self = this;
        this.socket = new WebSocket(wsUrl + '?user_uid=' + this.userId);
        
        this.socket.onopen = function() {
            console.log('WebSocket connected');
            self.reconnectAttempts = 0;
            self.getDialogs();
        };
        
        this.socket.onclose = function(event) {
            console.log('WebSocket disconnected', event.code, event.reason);
            self.attemptReconnect(wsUrl);
        };
        
        this.socket.onerror = function(error) {
            console.error('WebSocket error:', error);
        };
    };

    /**
     * Попытка переподключения
     */
    Messenger.prototype.attemptReconnect = function(wsUrl) {
        const self = this;
        if (this.reconnectAttempts < this.maxReconnectAttempts) {
            this.reconnectAttempts++;
            console.log('Attempting to reconnect... (' + this.reconnectAttempts + '/' + this.maxReconnectAttempts + ')');
            setTimeout(function() {
                self.connect(wsUrl);
            }, this.reconnectDelay * this.reconnectAttempts);
        } else {
            console.error('Max reconnect attempts reached');
            if (typeof window.showConnectionError === 'function') {
                window.showConnectionError();
            }
        }
    };

    /**
     * Получение списка диалогов
     */
    Messenger.prototype.getDialogs = function() {
        if (!this.isInitialized) {
            console.warn('Messenger not initialized');
            return;
        }
        this.sendMessageToSocket('MessengerSocket:getDialogs', {});
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
    Messenger.prototype.sendMessage = function(dialogId, content, contentType, metaData) {
        if (!dialogId) {
            console.error('Dialog ID is required');
            return;
        }
        
        let data = {
            dialog_id: dialogId,
            content: content || '',
            content_type: contentType || 'text',
            meta_data: metaData || {}
        };
        
        this.sendMessageToSocket('MessengerSocket:sendMessage', data);
    };

    /**
     * Отправка медиа-файла
     */
    Messenger.prototype.sendMedia = function(dialogId, fileData, fileName, fileType, fileSize) {
        let data = {
            dialog_id: dialogId,
            file_data: fileData,
            file_name: fileName,
            file_type: fileType,
            file_size: fileSize
        };
        this.sendMessageToSocket('MessengerSocket:sendMedia', data);
    };

    /**
     * Редактирование сообщения
     */
    Messenger.prototype.editMessage = function(messageId, content) {
        let data = {
            message_id: messageId,
            content: content
        };
        this.sendMessageToSocket('MessengerSocket:editMessage', data);
    };

    /**
     * Удаление сообщения
     */
    Messenger.prototype.deleteMessage = function(messageId) {
        let data = {
            message_id: messageId
        };
        this.sendMessageToSocket('MessengerSocket:deleteMessage', data);
    };

    /**
     * Загрузка истории сообщений
     */
    Messenger.prototype.loadHistory = function(dialogId, limit, offset) {
        let data = {
            dialog_id: dialogId,
            limit: limit || 50,
            offset: offset || 0
        };
        this.sendMessageToSocket('MessengerSocket:loadHistory', data);
    };

    /**
     * Отправка статуса прочтения
     */
    Messenger.prototype.markAsRead = function(dialogId, lastMessageId) {
        let data = {
            dialog_id: dialogId,
            last_message_id: lastMessageId || 0
        };
        this.sendMessageToSocket('MessengerSocket:markAsRead', data);
    };

    /**
     * Отправка статуса печати
     */
    Messenger.prototype.sendTyping = function(dialogId) {
        let data = {
            dialog_id: dialogId
        };
        this.sendMessageToSocket('MessengerSocket:typing', data);
    };

    /**
     * Создание диалога
     */
    Messenger.prototype.createDialog = function(type, participants, name) {
        let data = {
            type: type || 'private',
            participants: participants || [],
            name: name || null
        };
        this.sendMessageToSocket('MessengerSocket:createDialog', data);
    };

    /**
     * Выход из диалога
     */
    Messenger.prototype.leaveDialog = function(dialogId) {
        let data = {
            dialog_id: dialogId
        };
        this.sendMessageToSocket('MessengerSocket:leaveDialog', data);
    };

    /**
     * Добавление участника в диалог
     */
    Messenger.prototype.addParticipant = function(dialogId, userId) {
        let data = {
            dialog_id: dialogId,
            user_id: userId
        };
        this.sendMessageToSocket('MessengerSocket:addParticipant', data);
    };

    /**
     * Поиск пользователей
     */
    Messenger.prototype.searchUsers = function(query) {
        let data = {
            query: query || ''
        };
        this.sendMessageToSocket('MessengerSocket:searchUsers', data);
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
            try {
                const serverData = JSON.parse(event.data);
                self.handleServerMessage(serverData);
            } catch (e) {
                console.error('Error parsing WebSocket message:', e);
            }
        };
    };

    /**
     * Обработка сообщений от сервера
     */
    Messenger.prototype.handleServerMessage = function(data) {
        switch (data.action) {
            case 'success':
                this.handleSuccess(data);
                break;
            case 'error':
                this.handleError(data);
                break;
            case 'newMessage':
                this.handleNewMessage(data);
                break;
            case 'messageEdited':
                this.handleMessageEdited(data);
                break;
            case 'messageDeleted':
                this.handleMessageDeleted(data);
                break;
            case 'messagesRead':
                this.handleMessagesRead(data);
                break;
            case 'userTyping':
                this.handleUserTyping(data);
                break;
            case 'newDialog':
                this.handleNewDialog(data);
                break;
            case 'userLeftDialog':
                this.handleUserLeftDialog(data);
                break;
            case 'participantAdded':
                this.handleParticipantAdded(data);
                break;
            case 'Authorized':
                console.log('Authorized on WebSocket server');
                break;
            case 'Ping':
                this.handlePing();
                break;
            default:
                console.log('Unknown action:', data.action);
        }
    };

    /**
     * Обработка успешного ответа
     */
    Messenger.prototype.handleSuccess = function(data) {
        console.log('Success:', data.message, data.data);
        
        if (data.data && data.data.dialogs) {
            if (typeof window.renderDialogs === 'function') {
                window.renderDialogs(data.data.dialogs);
            }
        }
        
        if (data.data && data.data.messages) {
            if (typeof window.renderMessages === 'function') {
                window.renderMessages(data.data.messages);
            }
        }
        
        if (data.data && data.data.users) {
            if (typeof window.renderSearchResults === 'function') {
                window.renderSearchResults(data.data.users);
            }
        }
    };

    /**
     * Обработка ошибки
     */
    Messenger.prototype.handleError = function(data) {
        console.error('Error:', data.message);
        if (typeof window.showError === 'function') {
            window.showError(data.message);
        }
    };

    /**
     * Обработка нового сообщения
     */
    Messenger.prototype.handleNewMessage = function(data) {
        console.log('New message received:', data.data);
        if (typeof window.appendMessage === 'function') {
            window.appendMessage(data.data);
        }
        if (typeof window.updateDialogLastMessage === 'function') {
            window.updateDialogLastMessage(data.data);
        }
    };

    /**
     * Обработка редактирования сообщения
     */
    Messenger.prototype.handleMessageEdited = function(data) {
        console.log('Message edited:', data.data);
        if (typeof window.updateMessage === 'function') {
            window.updateMessage(data.data);
        }
    };

    /**
     * Обработка удаления сообщения
     */
    Messenger.prototype.handleMessageDeleted = function(data) {
        console.log('Message deleted:', data.data);
        if (typeof window.removeMessage === 'function') {
            window.removeMessage(data.data.message_id);
        }
    };

    /**
     * Обработка прочтения сообщений
     */
    Messenger.prototype.handleMessagesRead = function(data) {
        console.log('Messages read:', data.data);
        if (typeof window.updateReadStatus === 'function') {
            window.updateReadStatus(data.data);
        }
    };

    /**
     * Обработка статуса печати пользователя
     */
    Messenger.prototype.handleUserTyping = function(data) {
        console.log('User typing:', data.data);
        if (typeof window.showTypingIndicator === 'function') {
            window.showTypingIndicator(data.data);
        }
    };

    /**
     * Обработка нового диалога
     */
    Messenger.prototype.handleNewDialog = function(data) {
        console.log('New dialog:', data.data);
        if (typeof window.addDialog === 'function') {
            window.addDialog(data.data);
        }
        if (typeof window.refreshDialogs === 'function') {
            window.refreshDialogs();
        }
    };

    /**
     * Обработка выхода пользователя из диалога
     */
    Messenger.prototype.handleUserLeftDialog = function(data) {
        console.log('User left dialog:', data.data);
        if (typeof window.showUserLeftNotification === 'function') {
            window.showUserLeftNotification(data.data);
        }
    };

    /**
     * Обработка добавления участника
     */
    Messenger.prototype.handleParticipantAdded = function(data) {
        console.log('Participant added:', data.data);
        if (typeof window.showParticipantAddedNotification === 'function') {
            window.showParticipantAddedNotification(data.data);
        }
    };

    /**
     * Обработка Ping
     */
    Messenger.prototype.handlePing = function() {
        this.socket.send(JSON.stringify({
            action: 'Ping:Pong',
            data: 'Pong'
        }));
    };

    // Экспортируем в глобальную область видимости
    window.MessengerConnect = Messenger;
})();