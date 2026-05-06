{literal}
document.addEventListener('DOMContentLoaded', () => {
	let btnEditProfile = document.querySelector('.profile__edit_user-info');
	let cardInfo = document.querySelector('.profile__card-info--data');
	let cardEdit = document.querySelector('.profile__card-info--edit');

	btnEditProfile.addEventListener('click', (e) => {
		e.preventDefault();
		btnEditProfile.classList.toggle('invisible-btn');
		btnEditProfile.disabled = true;
		cardInfo.classList.toggle('visible');
		cardEdit.classList.toggle('visible');
	});

	let closeEdit = document.getElementById('close');
	if (!closeEdit) {
		console.error("Element with id 'close' not found.");
		return;
	}

	closeEdit.addEventListener('click', (e) => {
		e.preventDefault();
		btnEditProfile.classList.toggle('invisible-btn');
		btnEditProfile.disabled = false;
		cardInfo.classList.toggle('visible');
		cardEdit.classList.toggle('visible');
	});

	let fieldNewPassword = document.getElementById('new-password');
	if (!fieldNewPassword) {return;}
	let fieldRepeatPassword = document.getElementById('repeat-new-password');
	if (!fieldRepeatPassword) {return;}
	let errorRepeatMsg = document.getElementById('error-repeat');
	if (!errorRepeatMsg) {return;}

	let changePasswordBtn = document.getElementById('change-password-btn');
	if (!changePasswordBtn) {return;}
	changePasswordBtn.disabled = true;

	fieldRepeatPassword.addEventListener('input', () => {
		if (fieldNewPassword.value != fieldRepeatPassword.value) {
			errorRepeatMsg.innerText = "Пароли не совпадают";
			changePasswordBtn.disabled = true;
		} else {
			errorRepeatMsg.innerText = "";
			changePasswordBtn.disabled = false;
		}
	});
	
	// Кнопка "Написать сообщение" на странице профиля
	const writeMessageBtn = document.querySelector('.profile__write-message-btn');
	if (writeMessageBtn) {
		writeMessageBtn.addEventListener('click', function() {
			const interlocutorUid = this.dataset.userUid;
			
			// Создаем диалог через WebSocket
			if (wspace.core && wspace.core.data && wspace.core.data.socket) {
				wspace.core.data.socket.send(JSON.stringify({
					action: 'MessangerSocket:create_dialog',
					data: {
						user_uid: user_uid,
						interlocutor_uid: interlocutorUid
					}
				}));
				
				// Переходим в мессенджер
				window.location.href = '/messenger/';
			}
		});
	}
	
	// Обработка ответов от сервера о создании диалога
	if (wspace.core && wspace.core.data && wspace.core.data.socket) {
		wspace.core.data.socket.addEventListener('message', function(event) {
			const serverData = JSON.parse(event.data);
			
			if (serverData.action === 'dialog_created' || serverData.action === 'dialog_exists') {
				// Если мы на странице профиля и создали диалог, можно перенаправить
				if (window.location.pathname.includes('/users/')) {
					// Диалог создан или уже существует
					console.log('Диалог:', serverData.dialog_uid || serverData.dialog?.uid);
				}
			}
		});
	}
});
{/literal}