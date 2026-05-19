{literal}
const user_id = "{/literal}{$user['id']}{literal}";

// Глобальный объект для хранения состояния WebSocket
wspace = wspace || {};
wspace.core = {
	data: {
		socket: null,
		messagesArray: null,
		userID: user_id,
		unreadCount: 0,
		isConnected: false
	}
};

document.addEventListener("DOMContentLoaded", function () {
	// Sidebar functionality
	const sidebarControl = document.getElementById('sidebarControl');
	if (!sidebarControl) {
		console.error("Element with id 'sidebarControl' not found.");
	} else {
		const sidebar = document.querySelector('.sidebar');
		const content = document.querySelector('.wrapper__content');

		sidebarControl.addEventListener('click', (e) => {
			e.preventDefault();
			content.classList.toggle('sidebar--active');
			sidebar.classList.toggle('active');
		});
	}

	// Инициализация WebSocket - глобальное подключение для всех страниц
	initializeWebSocket();
});

/**
 * Инициализация WebSocket соединения
 */
function initializeWebSocket() {
	try {
		const conn = new WebSocket(`ws://localhost:27800?user_uid=${user_id}`);
		wspace.core.data.socket = conn;

		const ping = () => {
			if (conn.readyState === WebSocket.OPEN) {
				conn.send(JSON.stringify({
					action: "PingSocket:index",
					data: { ping: "Pong" }
				}));
			}
		};

		conn.onopen = (event) => {
			console.log('WebSocket connected');
			wspace.core.data.isConnected = true;

			// Запрашиваем количество непрочитанных сообщений при подключении
			setTimeout(() => {
				conn.send(JSON.stringify({
					action: "get_unread_count",
					data: { user_id: user_id }
				}));
			}, 500);
		};

		conn.onclose = (event) => {
			console.log('WebSocket disconnected');
			wspace.core.data.isConnected = false;

			// Попытка переподключения через 5 секунд
			setTimeout(() => {
				if (!wspace.core.data.isConnected) {
					console.log('Attempting to reconnect...');
					initializeWebSocket();
				}
			}, 5000);
		};

		conn.onerror = (error) => {
			console.error('WebSocket error:', error);
			wspace.core.data.isConnected = false;
		};

		conn.onmessage = (event) => {
			handleIncomingMessage(event);

			// Если мы на странице мессенджера, передаем событие туда
			if (window.location.pathname === "/messenger/") {
				if (typeof window.messenger !== 'undefined' && window.messenger.isInitialized) {
					window.messenger.listenWebSocket();
				}
			}
		};

	} catch (e) {
		console.warn('WebSocket не доступен, функционал мессенджера будет работать в ограниченном режиме');
		wspace.core.data.socket = null;
		wspace.core.data.isConnected = false;
	}
}

/**
 * Обработка входящих WebSocket сообщений
 */
function handleIncomingMessage(event) {
	try {
		const serverData = JSON.parse(event.data);

		switch (serverData.action) {
			case "Ping":
				// Ответ на ping
				if (wspace.core.data.socket && wspace.core.data.socket.readyState === WebSocket.OPEN) {
					wspace.core.data.socket.send(JSON.stringify({
						action: "PingSocket:index",
						data: { ping: "Pong" }
					}));
				}
				break;

			case "Authorized":
				console.log('Authorized on WebSocket server');
				break;

			case "unread_update":
				// Обновление количества непрочитанных сообщений
				updateUnreadCount(serverData.data);
				break;

			case "new_message":
				// Новое сообщение получено
				handleNewMessageNotification(serverData.data);
				break;

			default:
				console.log('WebSocket message received:', serverData.action);
		}
	} catch (e) {
		console.error('Error parsing WebSocket message:', e);
	}
}

/**
 * Обновление счетчика непрочитанных сообщений
 */
function updateUnreadCount(data) {
	if (data && typeof data.count !== 'undefined') {
		wspace.core.data.unreadCount = data.count;

		// Обновляем бейдж в интерфейсе если он есть
		const badge = document.querySelector('.messenger-badge');
		if (badge) {
			if (data.count > 0) {
				badge.textContent = data.count;
				badge.style.display = 'inline-block';
			} else {
				badge.style.display = 'none';
			}
		}

		// Обновляем title страницы
		if (data.count > 0) {
			document.title = `(${data.count}) ${document.title.replace(/\(\d+\)\s*/, '')}`;
		} else {
			document.title = document.title.replace(/\(\d+\)\s*/, '');
		}
	}
}

/**
 * Обработка уведомления о новом сообщении
 */
function handleNewMessageNotification(data) {
	console.log('New message notification:', data);

	// Увеличиваем счетчик непрочитанных
	wspace.core.data.unreadCount++;
	updateUnreadCount({ count: wspace.core.data.unreadCount });

	// Показываем всплывающее уведомление в интерфейсе
	showPopupNotification(data);

	// Показываем браузерное уведомление если разрешено
	if (Notification.permission === "granted") {
		new Notification("Новое сообщение", {
			body: data.text || "У вас новое сообщение",
			icon: "/assets/img/default_avatar.png"
		});
	} else if (Notification.permission !== "denied") {
		Notification.requestPermission().then(permission => {
			if (permission === "granted") {
				new Notification("Новое сообщение", {
					body: data.text || "У вас новое сообщение",
					icon: "/assets/img/default_avatar.png"
				});
			}
		});
	}

	// Воспроизводим звук уведомления (опционально)
	playNotificationSound();
}

/**
 * Показ всплывающего уведомления в интерфейсе
 */
function showPopupNotification(data) {
	const popup = document.querySelector('.notification-popup');
	if (!popup) return;

	// Заполняем данные
	const img = popup.querySelector('.notification-popup__image img');
	const nameEl = popup.querySelector('.notification-popup__name');
	const msgEl = popup.querySelector('.notification-popup__message');

	if (img && data.sender_avatar) {
		img.src = data.sender_avatar;
	}
	if (nameEl && data.sender_name) {
		nameEl.textContent = data.sender_name;
	}
	if (msgEl && data.text) {
		msgEl.textContent = data.text;
	}

	// Показываем уведомление с анимацией
	popup.classList.add('notification-popup--visible');
	popup.style.display = 'block';

	// Авто-скрытие через 5 секунд
	if (wspace.core.data.notificationTimeout) {
		clearTimeout(wspace.core.data.notificationTimeout);
	}

	wspace.core.data.notificationTimeout = setTimeout(() => {
		hidePopupNotification();
	}, 5000);
}

/**
 * Скрытие всплывающего уведомления
 */
function hidePopupNotification() {
	const popup = document.querySelector('.notification-popup');
	if (!popup) return;

	popup.classList.remove('notification-popup--visible');
	
	setTimeout(() => {
		popup.style.display = 'none';
	}, 300); // Ждем завершения анимации
}

// Делаем функцию доступной глобально для onclick в HTML
window.hidePopupNotification = hidePopupNotification;

/**
 * Воспроизведение звука уведомления
 */
function playNotificationSound() {
	// Можно добавить звуковой файл для уведомлений
	// const audio = new Audio('/assets/sounds/notification.mp3');
	// audio.play().catch(e => console.log('Sound play failed:', e));
}

// Запрашиваем разрешение на уведомления при загрузке
if (typeof Notification !== 'undefined' && Notification.permission === "default") {
	// Создаем функцию для запроса разрешений, которую можно вызвать по клику
	window.requestNotificationPermission = function () {
		Notification.requestPermission().then(permission => {
			console.log('Notification permission:', permission);
		});
	};

	// Добавляем кнопку для запроса разрешений если есть элемент с id 'notification-permission-btn'
	document.addEventListener('DOMContentLoaded', function () {
		const btn = document.getElementById('notification-permission-btn');
		if (btn) {
			btn.addEventListener('click', function () {
				window.requestNotificationPermission();
				btn.style.display = 'none';
			});
		}
	});
}

{/literal}