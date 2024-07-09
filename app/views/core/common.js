{literal}
const user_uid = "{/literal}{$user['uid']}{literal}"
document.addEventListener("DOMContentLoaded", function () {
	// Sidebar functionality
	const sidebarControl = document.getElementById('sidebarControl');
	if (!sidebarControl) {
		console.error("Element with id 'sidebarControl' not found.");
		return;
	}
	const sidebar = document.querySelector('.sidebar');
	const content = document.querySelector('.wrapper__content');

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
		// Actions to perform when the connection is opened, if necessary
	};

	let messConn; // Declare messConn variable in the global scope

	if (typeof MessengerConnect !== 'undefined') {
		messConn = new MessengerConnect();
	}

	conn.onmessage = (event) => {
		handleIncomingMessage(event);
		if (window.location.pathname === "/messenger/") {
			if (typeof messConn !== 'undefined') {
				messConn.init();
				messConn.listenWebSocket();
			}
		}
	};

	const handleIncomingMessage = (event) => {
		const serverData = JSON.parse(event.data);
		if (serverData.action === "Ping") {
			ping();
		}
	};
});

{/literal}