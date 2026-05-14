{literal}
const user_id = "{/literal}{$user['id']}{literal}"
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

	// Инициализация WebSocket только если сервер доступен (не блокирует остальной функционал)
	try {
		wspace.core = {
			data: {
				socket: new WebSocket(`ws://localhost:27800?user_uid=${user_id}`),
				messagesArray: null,
				userID: null,
			}
		};

		const conn = wspace.core.data.socket;

		const ping = () => {
			if (conn.readyState === WebSocket.OPEN) {
				conn.send(JSON.stringify({
					action: "PingSocket:index",
					data: { ping: "Pong" }
				}));
			}
		};

		conn.onopen = (event) => {
			// Connection opened
		};

		let messConn;

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
	} catch (e) {
		console.warn('WebSocket не доступен, функционал мессенджера будет работать в ограниченном режиме');
		wspace.core = { data: { socket: null, messagesArray: null, userID: null } };
	}
});

{/literal}