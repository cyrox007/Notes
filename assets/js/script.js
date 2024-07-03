{literal}
const user_uid = "{/literal}{$user['uid']}{literal}"
document.addEventListener("DOMContentLoaded", function () {

    let sidebarControl = document.getElementById('sidebarControl');
    let sidebar = document.querySelector('.sidebar');
    let content = document.querySelector('.wrapper__content');

    const dialogAll = document.querySelectorAll('.messager__contact_item');

    dialogAll.forEach(dialog=>{
        dialog.addEventListener('click', (e)=>{
            let uid = dialog.dataset.duid;
            loadMessages(uid);
        });
    });

    sidebarControl.addEventListener('click', (e) => {
        e.preventDefault();
        content.classList.toggle('sidebar--active');
        sidebar.classList.toggle('active');
    });

    // Проверка существующего WebSocket подключения
    wspace.core = {
        data: {
            socket: new WebSocket(`ws://localhost:27800?user_uid=${user_uid}`),
            messagesArray: null, // Здесь будут храниться сообщения, которые придут от WS сервера
            userID: null,
        }
    };

    const conn = wspace.core.data.socket;

    const ping = () => {
        conn.send(JSON.stringify({
            action: "PingSocket:index",
            data: {ping:"Pong"}
        }));
    };

    const loadMessages = (dialog_uid) => {
        conn.send(JSON.stringify({
            action: "MessangerSocket:load",
            data: {
                user_uid: user_uid,
                dialog_uid: dialog_uid
            }
        }));
    };

    const sendMessage = (dialog_uid, msg) => {
        if (msg === '') return;

        let data = {
            user_uid: user_uid,
            request: "send_message",
            dialog_uid: dialog_uid,
            message: msg,
        };
        conn.send(JSON.stringify(data));
    };

    wspace.core.data.socket.onopen = (event) => {
        // действия при открытии соединения, если необходимо
    };

    wspace.core.data.socket.onmessage = (event) => {
        let server_data = JSON.parse(event.data);
        const messenger = new Messenger();

        if (server_data["action"] == "Ping") {
            ping();
        } /* else {
            if (typeof messenger !== 'undefined' && server_data.messages) {
                messenger.loadMessages(server_data.messages);
            }
        } */

        if (server_data['action'] == 'get_messages') {
            messenger.loadMessages(server_data['messages']);
        }
    };
});
{/literal}