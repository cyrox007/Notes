function Messenger () {
    this.messages = []; // Массив сообщений
    this.dialogs = []; // Массив диалогов

    this.currentDialog = null;

}

Messenger.prototype.getDialogs = function () {
    let data = {
        user_id: user_id,
    }
    this.sendMessageToSocket('get_dialogs', {});
}

Messenger.prototype.sendMessageToSocket = function (action, data) {
    let dataSend = {
        action: action,
        data: data
    };
    wspace.core.data.socket.send(JSON.stringify(dataSend));
}

Messenger.prototype.sendMessage = function (user_id, dialog_id, text) {
    let data = {
        from_id: user_id,
        chat_id: dialog_id,
        text: text,
    };
    this.sendMessageToSocket('send_message', data);
}

Messenger.prototype.editMessage = function (message_id, text) {
    let data = {
        message_id: message_id,
        text: text,
    };
    this.sendMessageToSocket('edit_message', data);
}

Messenger.prototype.deleteMessage = function (message_id) {
    let data = {
        message_id: message_id,
    };
    this.sendMessageToSocket('delete_message', data);
}

Messenger.prototype.sendChatAction = function () {
    let data = {
        from_id: user_id,
        chat_id: dialog_id,
        action: 'typing',
    };
    this.sendMessageToSocket('send_chat_action', data);
}

Messenger.prototype.webSocketListener = function () {
    wspace.core.data.socket.addEventListener('message', function (event) {
        const serverData = JSON.parse(event.data);
        // Получение списка сообщений диалога от сервера
        if (serverData.action === 'get_messages') {}
        // Получение сообщения (одного) от сервера
        if (serverData.action === 'get_message') {}
        // Получение списка диалогов от сервера
        if (serverData.action ==='get_gialogs') {}
        // Получение статуса печати в диалоге
        if (serverData.action === 'user_typing') {}
        // Получение статуса завершения печати в диалоге
        if (serverData.action === 'typing_stop') {}
        // Получение обновления статуса сообщения в диалоге
        if (serverData.action === 'update_message_status') {}
    });
};

document.addEventListener('DOMContentLoaded', function () {
    var messenger = new Messenger();
    messenger.webSocketListener();

});