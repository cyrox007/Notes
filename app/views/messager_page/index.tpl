{extends file='core/base.tpl'}
{block name=title}
	Мессенджер
{/block}
{block name=body}
	<section class="messenger">
		<div class="messenger__dialog-list">
			<header class="messenger__dialog-list__header">
				<div class="messenger__dialog-list__header--btn">
					<button id="new-dialog-btn">Новый диалог</button>
				</div>
				<div class="messenger__dialog-list__header--search">
					<input type="search" id="dialog-search" placeholder="Search...">
					<span><i class="fa fa-search" aria-hidden="true"></i></span>
				</div>
			</header>
			<div class="messenger__dialog-list__items" id="dialog-list">
				{if !$dialogues}
					<div id="dialogues-empty" class="messenger__empty">
						<p>Диалогов нет. Создать?</p>
					</div>
				{else}
					{foreach $userToDialogs as $utd}
						<div class="messenger__dialog-list__item" data-dialog_id="{$utd.dialogs.uid}">
							<div class="messenger__dialog-list__item--img">
								<img src="/assets/img/default_avatar.png" alt="Аватар">
							</div>
							<div class="messenger__dialog-list__item--body">
								<div class="messenger__dialog-list__item--header">
									<span class="user-fullname">
										<b>{$utd.users.u_firstname} {$utd.users.lastname}</b>
									</span>
									<span class="message-time">14:30</span>
								</div>
								<div class="messenger__dialog-list__item--msg">
									<span class="last-message">I would like to share my p ...</span>
								</div>
							</div>
						</div>
					{/foreach}
				{/if}
			</div>
		</div>
		<div class="messenger__dialog-window" id="messager-window" data-uid="">
			<div id="messager-disable" class="messenger__placeholder">
				<div class="messenger__placeholder-content">
					<i class="fa fa-comments"></i>
					<p>Выберите диалог для начала общения</p>
				</div>
			</div>
			<div id="messager-viewer" class="messenger__viewer">
				<header class="messenger__dialog-window__header" id="msg-header">
					<div class="messenger__dialog-window__header--img">
						<img src="/assets/img/default_avatar.png" alt="Аватар">
					</div>
					<div class="messenger__dialog-window__header--body">
						<span class="chat-name"><b id="userfullname">Имя диалога</b></span>
						<span class="chat-status" id="chat-status"></span>
					</div>
				</header>

				<div class="messenger__dialog-window__viewer">
					<div class="messenger__dialog-window__messages" id="msg-view"></div>
				</div>

				<div class="messenger__dialog-window__control">
					<div class="messenger__dialog-window__control_typing">
						<span id="typing-indicator"></span>
					</div>
					<div class="messenger__dialog-window__control_panel">
						<div class="messenger__dialog-window__control--file" id="attach-file" title="Прикрепить файл">
							<i class="fa fa-paperclip" aria-hidden="true"></i>
						</div>
						<div class="messenger__dialog-window__control--message">
							<input type="text" name="message" id="message-field" placeholder="Введите сообщение..." autocomplete="off">
						</div>
						<div class="messenger__dialog-window__control--send">
							<button id="message-send" type="button">
								<i class="fa fa-paper-plane" aria-hidden="true"></i>
							</button>
						</div>
					</div>
				</div>
			</div>
		</div>
	</section>

	<!-- Модальное окно создания нового диалога -->
	<div id="new-dialog-modal" class="new-dialog-modal" style="display: none;">
		<div class="new-dialog-modal-content">
			<div class="new-dialog-modal-header">
				<h3>Новый диалог</h3>
				<span class="close-modal">&times;</span>
			</div>
			<div class="new-dialog-modal-body">
				<input type="text" class="search-user-input" id="user-search" placeholder="Поиск пользователя...">
				<div class="users-list" id="users-list">
					<!-- Список пользователей будет загружен здесь -->
				</div>
			</div>
		</div>
	</div>

	<script>
		// Инициализация переменных для мессенджера
		const messengerData = {
			userId: '{$user['id']}',
			dialogs: {foreach $userToDialogs as $utd}{$utd.dialogs.uid|@intval}:{'firstname':'{$utd.users.u_firstname}','lastname':'{$utd.users.lastname}'}{if !$utd@last},{/if}{/foreach}
		};

		document.addEventListener('DOMContentLoaded', function () {
			// Инициализация мессенджера если WebSocket доступен
			if (wspace.core && wspace.core.data && wspace.core.data.socket) {
				var messenger = new MessengerConnect();
				messenger.init(wspace.core.data.socket, messengerData.userId);
				messenger.listenWebSocket();
				
				// Загружаем список диалогов
				messenger.getDialogs();
				
				// Делаем messenger доступным глобально
				window.messenger = messenger;
			}
			
			// Обработчик кнопки открытия модального окна
			const newDialogBtn = document.getElementById('new-dialog-btn');
			const modal = document.getElementById('new-dialog-modal');
			const closeModal = modal.querySelector('.close-modal');
			
			if (newDialogBtn) {
				newDialogBtn.addEventListener('click', function() {
					modal.style.display = 'flex';
				});
			}
			
			if (closeModal) {
				closeModal.addEventListener('click', function() {
					modal.style.display = 'none';
				});
			}
			
			// Закрытие модального окна по клику вне его
			window.addEventListener('click', function(event) {
				if (event.target === modal) {
					modal.style.display = 'none';
				}
			});
			
			// Обработчики для элементов списка диалогов
			const dialogItems = document.querySelectorAll('.messenger__dialog-list__item');
			dialogItems.forEach(function(item) {
				item.addEventListener('click', function() {
					// Удаляем класс selected у всех элементов
					dialogItems.forEach(i => i.classList.remove('selected'));
					// Добавляем класс selected текущему элементу
					this.classList.add('selected');
					
					const dialogId = this.getAttribute('data-dialog_id');
					openDialog(dialogId);
				});
			});
			
			// Функция открытия диалога
			function openDialog(dialogId) {
				const viewer = document.getElementById('messager-viewer');
				const placeholder = document.getElementById('messager-disable');
				
				if (viewer && placeholder) {
					placeholder.style.display = 'none';
					viewer.style.display = 'flex';
				}
				
				// Загружаем сообщения для выбранного диалога
				if (window.messenger) {
					window.messenger.loadMessages(dialogId);
				}
			}
			
			// Обработчик отправки сообщения
			const sendBtn = document.getElementById('message-send');
			const messageField = document.getElementById('message-field');
			
			if (sendBtn && messageField) {
				sendBtn.addEventListener('click', function() {
					sendMessage();
				});
				
				messageField.addEventListener('keypress', function(e) {
					if (e.key === 'Enter') {
						sendMessage();
					}
				});
			}
			
			function sendMessage() {
				const text = messageField.value.trim();
				if (!text || !window.messenger || !window.messenger.currentDialog) {
					return;
				}
				
				window.messenger.sendMessage(
					messengerData.userId,
					window.messenger.currentDialog,
					text
				);
				
				messageField.value = '';
			}
			
			// Поиск по диалогам
			const searchInput = document.getElementById('dialog-search');
			if (searchInput) {
				searchInput.addEventListener('input', function() {
					const filter = this.value.toLowerCase();
					const dialogItems = document.querySelectorAll('.messenger__dialog-list__item');
					
					dialogItems.forEach(function(item) {
						const fullname = item.querySelector('.user-fullname').textContent.toLowerCase();
						const lastMessage = item.querySelector('.last-message').textContent.toLowerCase();
						
						if (fullname.includes(filter) || lastMessage.includes(filter)) {
							item.style.display = 'flex';
						} else {
							item.style.display = 'none';
						}
					});
				});
			}
		});
		
		// Глобальные функции для рендеринга
		window.renderDialogs = function(dialogs) {
			console.log('Rendering dialogs:', dialogs);
			// TODO: Реализовать рендеринг списка диалогов
		};
		
		window.renderMessages = function(messages) {
			console.log('Rendering messages:', messages);
			const msgView = document.getElementById('msg-view');
			if (!msgView) return;
			
			msgView.innerHTML = '';
			
			if (Array.isArray(messages)) {
				messages.forEach(function(msg) {
					appendMessageToView(msg);
				});
				// Прокрутка к последнему сообщению
				msgView.scrollTop = msgView.scrollHeight;
			}
		};
		
		window.appendMessage = function(message) {
			appendMessageToView(message);
			const msgView = document.getElementById('msg-view');
			if (msgView) {
				msgView.scrollTop = msgView.scrollHeight;
			}
		};
		
		function appendMessageToView(msg) {
			const msgView = document.getElementById('msg-view');
			if (!msgView) return;
			
			const messageEl = document.createElement('div');
			messageEl.className = 'messenger__dialog-window__message';
			messageEl.setAttribute('data-direction', msg.from_id == messengerData.userId ? 'me' : 'other');
			messageEl.setAttribute('data-status', msg.status || 'read');
			
			messageEl.innerHTML = `
				<div class="messenger__dialog-window__message_wrapper">
					<div class="messenger__dialog-window__message--img">
						<img src="/assets/img/default_avatar.png" alt="Аватар">
					</div>
					<div class="messenger__dialog-window__message--data">
						<div class="messenger__dialog-window__message--data_header">
							<span class="sender-name">${msg.sender_name || ''}</span>
							<span class="message-time">${formatTime(msg.created_at)}</span>
						</div>
						<div class="messenger__dialog-window__message--data_content">
							${escapeHtml(msg.text)}
						</div>
					</div>
				</div>
			`;
			
			msgView.appendChild(messageEl);
		}
		
		window.showTypingIndicator = function(data) {
			const indicator = document.getElementById('typing-indicator');
			if (indicator) {
				indicator.textContent = 'Печатает...';
			}
		};
		
		window.hideTypingIndicator = function(data) {
			const indicator = document.getElementById('typing-indicator');
			if (indicator) {
				indicator.textContent = '';
			}
		};
		
		window.updateMessageStatus = function(data) {
			console.log('Updating message status:', data);
			// TODO: Обновить статус сообщения в интерфейсе
		};
		
		function formatTime(timestamp) {
			if (!timestamp) return '';
			const date = new Date(timestamp);
			return date.toLocaleTimeString([], {hour: '2-digit', minute:'2-digit'});
		}
		
		function escapeHtml(text) {
			const div = document.createElement('div');
			div.textContent = text;
			return div.innerHTML;
		}
	</script>
{/block}