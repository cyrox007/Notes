{literal}
const user_uid = "{/literal}{$user['uid']}{literal}"
document.addEventListener("DOMContentLoaded", function () {

    let sidebarControl = document.getElementById('sidebarControl');
    let sidebar = document.querySelector('.sidebar');
    let content = document.querySelector('.wrapper__content');

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
            data: { ping: "Pong" }
        }));
    };

    conn.onopen = (event) => {
        // действия при открытии соединения, если необходимо
    };
    const messConn = new MessengerConnect();
    conn.onmessage = (event) => {
        handleIncomingMessage(event);
        if (window.location.pathname === "/messenger/") {
            messConn.init();
            messConn.listenWebSocket();
        }
    };

    const handleIncomingMessage = (event) => {
        let server_data = JSON.parse(event.data);
        if (server_data["action"] == "Ping") {
            ping();
        }
    };
});

{/literal}