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
			typingNotification: document.getElementById('typingNotification'), // Уведомление о печати
			messages: [] // Массив сообщений
		};
	}

	receiveMessage = (messageData) => {
		const newMessage = {
			id: this.data.messages.length + 1, // Генерируйте уникальный ID для сообщения
			...messageData
		};
		this.data.messages.push(newMessage);
		this.loadDialog();
	};

	loadDialog() {
		if (!this.currentDialog) return;

		let dialog_uid = this.currentDialog.dataset.duid;
		let user_uid = this.currentDialog.getAttribute('user-id');
		let username = this.currentDialog.querySelector('.messager__username').textContent;

		this.data.dialogWindowEmpty.style.display = 'none';
		this.data.dialogWindowContent.style.display = 'flex';

		this.data.dialogWindowContent.parentElement.dataset.uid = dialog_uid;
		this.data.dialogWindowContent.querySelector('#msg-header').innerHTML = `<a href="/users/${user_uid}">${username}</a>`;

		this.loadMessages();
	}

	loadMessages() {
		let dialogMessages = this.data.msgView;
		dialogMessages.innerHTML = '';

		this.data.messages.forEach(msg => {
			let messageBox = document.createElement("div");
			messageBox.classList.add("messager__view__message");
			messageBox.dataset.uid = msg.msg_uid;
			messageBox.dataset.user_uid = msg.u_uid;
			messageBox.dataset.affiliation = msg.u_uid == user_uid ? 'my' : 'me';
			messageBox.dataset.status = msg.msg_message_status;

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

		// Обновляем сообщения в messages
		this.data.messages = userDialoges[currentDialogId] || [];

		// Если активный диалог присутствует в массиве сообщений, загружаем его сообщения
		if (this.data.messages.length > 0) {
			this.loadMessages();
		}
	}

	showTypingNotification() {
		this.data.typingNotification.style.display = 'block';
	}

	hideTypingNotification() {
		this.data.typingNotification.style.display = 'none';
	}

	showFilesCount(fileCount) {
		// Отображаем количество выбранных файлов
		const fileCountElement = document.getElementById('file-count');
		fileCountElement.style.display = 'block';
		fileCountElement.textContent = `Выбрано файлов: ${fileCount}`;
	}
 	updateMessageStatusByUid(messages, status) {
		const messageItems = document.querySelectorAll('.messager__view__message');
		messageItems.forEach(messageItem  => {
			/* if (messageItem.dataset.uid === messageUid) {
				messageItem.dataset.status = status;
			} */
			if (messages[messageItem.dataset.uid]) messageItem.dataset.status = status;
			
		});
	}
	setProgressUpload(progress) {
		const progressElement = document.getElementById('progress-bar-upload');
		if (window.getComputedStyle(progressElement).display === 'none') {
			progressElement.style.display = 'block';
		}
		const progressBar = progressElement.querySelector('#progress');
		progressBar.style.width = `${progress}%`;
	}
	hideProgressUpload() {
		const progressElement = document.getElementById('progress-bar-upload');
		progressElement.style.display = 'none';
	}
}


class MessengerConnect {
	constructor() {
		if (MessengerConnect.instance) {
			return MessengerConnect.instance;
		}
		MessengerConnect.instance = this;

		this.messenger = new Messenger();
		this.typingTimeouts = {};
	}

	onDialogClick = (event) => {
		const dialogUid = event.currentTarget.dataset.duid;
		this.getMessages(dialogUid);
		this.messenger.currentDialog = event.currentTarget;
		this.messenger.loadDialog();
	};

	getMessages = (dialogUid) => {
		const data = {
			action: "MessangerSocket:load",
			data: {
				user_uid: user_uid,
				dialog_uid: dialogUid
			}
		};
		this.sendMessageToSocket(data);
	};

	sendMessage = (dialogUid, message, files = false) => {
		const data = {
			action: "MessangerSocket:message_send",
			data: {
				user_uid: user_uid,
				dialog_uid: dialogUid,
				message: message,
				files: files, // Добавляем файлы в объект data
				status: 'unread'
			}
		};
		this.sendMessageToSocket(data);
	};

	sendFiles = (files) => {
		document.querySelector('#file-count').style.display = 'none';
		
		const formData = new FormData();
		formData.append('csrf_token', document.querySelector('input[name="csrf_token"]').value);
		Array.from(files).forEach(file => {
			formData.append('files[]', file);
		});

		let xhr = new XMLHttpRequest();
		xhr.open('POST', '/messenger/send_files', true);

		xhr.upload.onprogress = (e) => {
			if (e.lengthComputable) {
				let percent = (e.loaded / e.total) * 100;
				this.messenger.setProgressUpload(percent);
			}
		};

		xhr.onload = () => {
			if (xhr.status === 200) {
				this.messenger.hideProgressUpload();
				let data = JSON.parse(xhr.responseText);

			}
		};
		xhr.send(formData);
	}

	userTyping = (dialogUid) => {
		clearTimeout(this.typingTimeouts[dialogUid]);

		const data = {
			action: "MessangerSocket:user_typing",
			data: {
				user_uid: user_uid,
				dialog_uid: dialogUid
			}
		};
		this.sendMessageToSocket(data);

		this.typingTimeouts[dialogUid] = setTimeout(() => {
			this.stopTyping(dialogUid);
		}, 5000);
	};

	stopTyping = (dialogUid) => {
		const data = {
			action: "MessangerSocket:stop_typing",
			data: {
				user_uid: user_uid,
				dialog_uid: dialogUid
			}
		};
		this.sendMessageToSocket(data);
	};

	setReadStatusMessage = (msgUidArray = []) => {
		const data = {
			action: "MessangerSocket:update_message_status",
			data: {
				user_uid: user_uid,
				msg_uid_array: msgUidArray,
				status: "read"
			}
		};
		this.sendMessageToSocket(data);
	}

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

		const messageField = document.getElementById('message-field');
		const messageSendBtn = document.getElementById('message-send');

		messageSendBtn.addEventListener('click', () => {
			const dialogUid = this.messenger.currentDialog.dataset.duid;
			const message = messageField.value;
			const selectedFiles = fileInput.files;

			// Проверяем наличие текста и файлов
			if (message.trim() !== '' || selectedFiles.length > 0) {
				this.stopTyping(dialogUid);

				if (selectedFiles.length > 0) {
					//const files = Array.from(selectedFiles).map(file => file.name);
					this.sendFiles(selectedFiles);
					//this.sendMessage(dialogUid, message, true);
				} else {
					this.sendMessage(dialogUid, message);
				}

				messageField.value = '';
				fileInput.value = null; // Сбрасываем выбранные файлы
			}
		});

		messageField.addEventListener('keydown', (event) => {
			if (event.key === 'Enter' && !event.shiftKey) {
				const dialogUid = this.messenger.currentDialog.dataset.duid;
				const message = messageField.value;
				const selectedFiles = fileInput.files;

				// Проверяем наличие текста и файлов
				if (message.trim() !== '' || selectedFiles.length > 0) {
					this.stopTyping(dialogUid);

					if (selectedFiles.length > 0) {
						const files = Array.from(selectedFiles).map(file => file.name);
						sendFiles(files);
						this.sendMessage(dialogUid, message, files);
					} else {
						this.sendMessage(dialogUid, message);
					}

					messageField.value = '';
					fileInput.value = null; // Сбрасываем выбранные файлы
				}
			}
		});


		this.messenger.data.msgInputText.addEventListener('input', this.debounce(() => {
			const dialogUid = this.messenger.currentDialog.dataset.duid;
			this.userTyping(dialogUid);
		}, 500));

		const observer = new IntersectionObserver(entries => {
			const msgUids = [];
			entries.forEach(entry => {
				if (entry.isIntersecting) {
					const status = entry.target.dataset.status;
					const affiliation = entry.target.dataset.affiliation;

					if (status !== 'read' && affiliation !== 'my') {
						const messageUid = entry.target.dataset.uid;
						msgUids.push(messageUid);
					}
				}
			});
			if (msgUids.length > 0) {
				clearTimeout(this.readStatusTimeout);
				this.readStatusTimeout = setTimeout(() => {
					this.setReadStatusMessage(msgUids);
				}, 500); // Задержка в 500 миллисекунд перед отправкой запроса
			}
		});

		const messageItems = document.querySelectorAll('.messager__view__message');
		messageItems.forEach(message => {
			observer.observe(message);
		});

		const fileButton = document.getElementById('attach-file');
		const fileInput = document.createElement('input');
		fileInput.type = 'file';
		fileInput.multiple = true; // Разрешить выбор нескольких файлов

		// Обработчик события выбора файла
		fileInput.addEventListener('change', () => {
			const selectedFiles = fileInput.files;
			const fileCount = Math.min(selectedFiles.length, 10); // Ограничить количество файлов до 10

			this.messenger.showFilesCount(fileCount);
		});

		// Добавляем обработчик события клика на каждую кнопку
		fileButton.addEventListener('click', () => {
			// Получаем тип файла, соответствующий кнопке
			// const fileType = fileButton.getAttribute('name');

			// Устанавливаем типы файлов для input
			// fileInput.accept = getFileAcceptType(fileType);

			// Вызываем диалоговое окно для выбора файла
			fileInput.click();
		});
	};

	listenWebSocket = () => {
		wspace.core.data.socket.addEventListener('message', (event) => {
			const serverData = JSON.parse(event.data);
			if (serverData.action === 'get_messages') {
				this.messenger.data.messages = serverData.messages;
				this.messenger.loadMessages();
			}

			if (serverData.action === 'send_message') {
				this.messenger.receiveMessage(serverData.message);
			}

			if (serverData.action === 'user_typing') {
				this.messenger.showTypingNotification();
			}

			if (serverData.action === 'typing_stop') {
				this.messenger.hideTypingNotification();
			}

			if (serverData.action === 'update_message_status') {
				const { messages, status } = serverData;
				this.messenger.updateMessageStatusByUid(messages, status);
			}
		});
	};
}

{/literal}