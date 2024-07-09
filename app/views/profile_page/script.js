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
});

{/literal}