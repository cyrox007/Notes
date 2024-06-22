wspace.messeger = {
    data: {
        dialoguesEmpty: document.getElementById('dialogues-empty'), // Кнопка создания диалога
        viewUsers: document.getElementById('view-users'), // Блок создания нового диалога
        dialogLink: document.querySelectorAll('.messager__contact_item'), // Все блоки-кнопки контактов
        msgWindow: document.getElementById("messager-window"), // Окно диалога
        msgView: document.getElementById('msg-view'), // Поле вывода сообщений
        sendBtn: document.getElementById('message-send'), // Кнопка отправки сообщения
        msgInputText: document.getElementById("message-field"), // Поле ввода сообщения
    },
    handler: {
        // Функция для определения выбранного контакта
        getSelectedDialog: () => {
            for (let index = 0; index < wspace.messeger.data.dialogLink.length; index++) {
                const element = wspace.messeger.data.dialogLink[index];
                if (element.classList.contains('selected'))
                    return element;
            }
        },
    },
    methods: {
        dialoguesEmptySet: () => {
            if (wspace.messeger.data.dialoguesEmpty) {
                wspace.messeger.data.dialoguesEmpty.addEventListener('click', ()=>{
                    wspace.messeger.data.dialoguesEmpty.style.display = 'none';
                    wspace.messeger.data.viewUsers.style.display = 'flex';
                });
            }
        },

        // Функция установки активного окна диалога
        loadDialog: () => {
            // Получаем выбранный диалог
            let selectedDialog = wspace.messeger.handler.getSelectedDialog();
            // Get dialog ID
            let dialog_id = selectedDialog.getAttribute("dialog-id");
            let user_id = selectedDialog.getAttribute("user-id");
            // Set correct dialog ID in dialog window
            wspace.messeger.data.msgWindow.setAttribute("dialog-id", dialog_id);
            wspace.messeger.data.msgWindow.setAttribute("to-user", user_id);
        },
        // Функция загрузки сообщений в окно диалога
        loadMessages: () => {
            let dialog_id = wspace.messeger.data.msgWindow.getAttribute("dialog-id");
            let dialog_messeges = wspace.messeger.data.msgView;
            dialog_messeges.innerHTML = null;

            if (wspace.core.data.messagesArray[dialog_id] != null) {
                wspace.core.data.messagesArray[dialog_id].forEach(el=>{
                    let messageBox = document.createElement("div"); // создаем блок сообщения
                    messageBox.setAttribute("class", "messager__view__message"); // устанавливаем ему класс
                    dialog_messeges.appendChild(messageBox);

                    let messageImgWrapper = document.createElement("div"); // создаем блок аватара отправившего
                    let messageImg = document.createElement("img"); // создаем картинку
                    messageImgWrapper.setAttribute("class", "messager__view__message--img"); // добавим класс
                    messageImg.setAttribute("src", el["user_photo"]);
                    messageImgWrapper.appendChild(messageImg); // добавляем картинку в блок аватара
                    messageBox.appendChild(messageImgWrapper); // добавим в блок сообщения

                    let messageBody = document.createElement("div"); // создаем блок тела сообщения
                    messageBody.setAttribute("class", "messager__view__message--body");
                    messageBox.appendChild(messageBody);
                    
                    let messageInfo = document.createElement("div");
                    messageInfo.setAttribute("class", "messager__view__message--body_info");
                    let messageText = document.createElement("div"); // тут будет записан текст сообщения
                    messageText.setAttribute("class", "messager__view__message--body_text");
                    
                    messageBody.appendChild(messageInfo);
                    messageInfo.innerText = el["first_name"]+" "+el["patronymic"]+" - "+el["send_date"];
                    messageBody.appendChild(messageText);
                    messageText.textContent = el["message"];
                });
            }
            dialog_messeges.scrollTop = dialog_messeges.scrollHeight;
        }
    }
};
(function(){
    wspace.messeger.methods.dialoguesEmptySet();
    
    wspace.messeger.data.dialogLink.forEach(element => {
        element.addEventListener('click', (e) => {
            element.classList.add("selected");

            wspace.messeger.methods.loadDialog();
            wspace.messeger.methods.loadMessages();
        });
    });

    /* wspace.core.data.socket.onmessage = (event) => {
        let data = JSON.parse(event.data);
        
    } */
    
    wspace.messeger.data.sendBtn.addEventListener('click', () => {
        let dialog_id = wspace.messeger.data.msgWindow.getAttribute("dialog-id");
        let user_id = wspace.messeger.data.msgWindow.getAttribute("to-user");
        let sendData = {
            "action": "PrivateMessage",
            "to": user_id,
            "toDialogId": dialog_id,
            "text": wspace.messeger.data.msgInputText.value
        };
        wspace.core.data.socket.send(JSON.stringify(sendData));
        wspace.messeger.methods.loadMessages();
        wspace.messeger.data.msgInputText.value = null;
    });
}());