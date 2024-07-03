{literal}
class Messenger {
    constructor() {
        this.data = {
            dialoguesEmpty: document.getElementById('dialogues-empty'), // Кнопка создания диалога
            viewUsers: document.getElementById('view-users'), // Блок создания нового диалога
            dialogLinks: document.querySelectorAll('.messager__contact_item'), // Все блоки-кнопки контактов
            msgWindow: document.getElementById("messager-window"), // Окно диалога
            msgView: document.getElementById('msg-view'), // Поле вывода сообщений
            sendBtn: document.getElementById('message-send'), // Кнопка отправки сообщения
            msgInputText: document.getElementById("message-field"), // Поле ввода сообщения
        };
    }

    loadDialog() {
        const selectedDialog = this.getSelectedDialog();
        const dialogId = selectedDialog.getAttribute("dialog-id");
        const userId = selectedDialog.getAttribute("user-id");

        this.data.msgWindow.setAttribute("dialog-id", dialogId);
        this.data.msgWindow.setAttribute("to-user", userId);
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
            messageImg.setAttribute("src", msg["user_image"] != 'default_img' ? msg['user_image'] : '/assets/img/default_avatar.png');
            messageImgWrapper.appendChild(messageImg);
            messageBox.appendChild(messageImgWrapper);

            let messageBody = document.createElement("div");
            messageBody.classList.add("messager__view__message--body");
            messageBox.appendChild(messageBody);

            let messageInfo = document.createElement("div");
            messageInfo.classList.add("messager__view__message--body_info");
            messageInfo.innerText = `${msg["firstname"]} ${msg["surname"]} - ${msg["created_at"]}`;
            messageBody.appendChild(messageInfo);

            let messageText = document.createElement("div");
            messageText.classList.add("messager__view__message--body_text");
            messageText.textContent = msg["message"];
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
}
{/literal}