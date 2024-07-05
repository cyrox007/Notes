{literal}

class Messenger {
    constructor() {
        this.currentDialog = null;
        this.data = {
            dialoguesEmpty: document.getElementById('dialogues-empty'), // Кнопка создания диалога
            viewUsers: document.getElementById('view-users'), // Блок создания нового диалога
            dialogLinks: document.querySelectorAll('.messager__contact_item'), // Все блоки-кнопки контактов
            msgWindow: document.getElementById("messager-window"), // Окно диалога
            msgView: document.getElementById('msg-view'), // Поле вывода сообщений
            sendBtn: document.getElementById('message-send'), // Кнопка отправки сообщения
            msgInputText: document.getElementById("message-field"), // Поле ввода сообщения

            dialogWindowContent: document.getElementById('msg-content'),
            dialogWindowEmpty: document.getElementById('msg-empty'),
            typingNotification: document.getElementById('typingNotification') // Уведомление о печати
        };
    }

    loadDialog() {
        if (!this.currentDialog) return;

        let dialog_uid = this.currentDialog.dataset.duid;
        let user_uid = this.currentDialog.getAttribute('user-id');
        let username = this.currentDialog.querySelector('.messager__username').textContent;

        this.data.dialogWindowEmpty.style.display = 'none';
        this.data.dialogWindowContent.style.display = 'flex';

        this.data.dialogWindowContent.parentElement.dataset.uid = dialog_uid;
        this.data.dialogWindowContent.querySelector('#msg-header').innerHTML = `<a href="/users/${user_uid}">${username}</a>`;
    }
    
    loadMessages(messages) {
        let dialogMessages = this.data.msgView;
        dialogMessages.innerHTML = '';
        
        messages.forEach(msg => {
            let messageBox = document.createElement("div");
            messageBox.classList.add("messager__view__message");

            let messageImgWrapper = document.createElement("div");
            messageImgWrapper.classList.add("messager__view__message--img");

            let messageImg = document.createElement("img");
            messageImg.setAttribute("src", msg["u_user_image"] != 'default_img' ? msg['u_user_image'] : '/assets/img/default_avatar.png');
            messageImgWrapper.appendChild(messageImg);
            messageBox.appendChild(messageImgWrapper);

            let messageBody = document.createElement("div");
            messageBody.classList.add("messager__view__message--body");
            messageBox.appendChild(messageBody);

            let messageInfo = document.createElement("div");
            messageInfo.classList.add("messager__view__message--body_info");
            messageInfo.innerText = `${msg["u_firstname"]} ${msg["u_surname"]} - ${msg["msg_created_at"]}`;
            messageBody.appendChild(messageInfo);

            let messageText = document.createElement("div");
            messageText.classList.add("messager__view__message--body_text");
            messageText.textContent = msg["msg_message"];
            messageBody.appendChild(messageText);

            dialogMessages.appendChild(messageBox);
        });
    
        // Прокрутка экрана к последнему сообщению
        dialogMessages.scrollTop = dialogMessages.scrollHeight;
    }
    
    updateMessages(userDialoges) {
        // Получаем текущий активный диалог
        let currentDialogId = this.data.msgWindow.getAttribute("dialog-id");
    
        // Обновляем сообщения в messagesArray
        wspace.core.data.messagesArray = userDialoges;
    
        // Если активный диалог присутствует в массиве сообщений, загружаем его сообщения
        if (wspace.core.data.messagesArray[currentDialogId]) {
            this.loadMessages();
        }
    }
    
    showTypingNotification() {
        this.data.typingNotification.style.display = 'block';
    }
    
    hideTypingNotification() {
        this.data.typingNotification.style.display = 'none';
    }
}


class MessengerConnect {
    constructor() {
        if (MessengerConnect.instance) {
            return MessengerConnect.instance;
        }
        MessengerConnect.instance = this;

        this.messenger = new Messenger();
        this.init();
        this.typingTimeouts = {};
        this.listenWebSocket();
    }

    onDialogClick = (event) => {
        this.messenger.currentDialog = event.currentTarget;
        const uid = event.currentTarget.dataset.duid;
        this.loadMessages(uid);
        this.messenger.loadDialog();
    };

    loadMessages = (dialogUid) => {
        this.sendMessageToSocket({
            action: "MessangerSocket:load",
            data: {
                user_uid: user_uid,
                dialog_uid: dialogUid
            }
        });
    };

    sendMessage = (dialogUid, msg) => {
        if (!msg) return;

        this.sendMessageToSocket({
            action: "MessangerSocket:send_message",
            data: {
                user_uid: user_uid,
                dialog_uid: dialogUid,
                message: msg,
            }
        });
    };

    userTyping = (dialog_uid) => {
        clearTimeout(this.typingTimeouts[dialog_uid]);
        this.sendMessageToSocket({
            action: "MessangerSocket:user_typing",
            data: {
                user_uid: user_uid,
                dialog_uid: dialog_uid
            }
        });
        this.typingTimeouts[dialog_uid] = setTimeout(() => {
            this.stopTyping(dialog_uid);
        }, 5000);
    };

    stopTyping = (dialog_uid) => {
        this.sendMessageToSocket({
            action: "MessangerSocket:stop_typing",
            data: {
                user_uid: user_uid,
                dialog_uid: dialog_uid
            }
        });
    };

    sendMessageToSocket = (data) => {
        wspace.core.data.socket.send(JSON.stringify(data));
    };

    debounce = (func, wait, immediate) => {
        let timeout;
        return function () {
            const context = this;
            const args = arguments;
            const later = function () {
                timeout = null;
                if (!immediate) func.apply(context, args);
            };
            const callNow = immediate && !timeout;
            clearTimeout(timeout);
            timeout = setTimeout(later, wait);
            if (callNow) func.apply(context, args);
        };
    };

    init = () => {
        const dialogItems = document.querySelectorAll('.messager__contact_item');
        dialogItems.forEach(dialog => dialog.addEventListener('click', this.onDialogClick));

        this.messenger.data.msgInputText.addEventListener('input', this.debounce(() => {
            this.userTyping(this.messenger.currentDialog.dataset.duid);
        }, 500));
    };

    listenWebSocket = () => {
        wspace.core.data.socket.addEventListener('message', (event) => {
            const serverData = JSON.parse(event.data);
            if (serverData.action === 'get_messages') {
                this.messenger.loadMessages(serverData.messages);
            }
    
            if (serverData.action === 'send_message') {
                // Handle the sent message
            }
    
            if (serverData.action === 'user_typing') {
                this.messenger.showTypingNotification();
            }
    
            if (serverData.action === 'typing_stop') {
                this.messenger.hideTypingNotification();
            }
        });
    };
}

{/literal}